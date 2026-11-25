<?php

declare(strict_types=1);

namespace Nowo\MailKitBundle\Mailbox\Gmail;

use Closure;
use Nowo\MailKitBundle\Mailbox\Engine\ChangePage;
use Nowo\MailKitBundle\Mailbox\Engine\DraftRef;
use Nowo\MailKitBundle\Mailbox\Engine\DraftState;
use Nowo\MailKitBundle\Mailbox\Engine\FetchedMessage;
use Nowo\MailKitBundle\Mailbox\Engine\MailboxGatewayInterface;
use Nowo\MailKitBundle\Mailbox\Engine\MailboxSession;
use Nowo\MailKitBundle\Mailbox\Engine\PreparedMessage;
use Nowo\MailKitBundle\Mailbox\Engine\ProviderAccount;
use Nowo\MailKitBundle\Mailbox\Engine\RemoteSubscription;
use Nowo\MailKitBundle\Mailbox\Engine\SendOnlyMailboxGatewayInterface;
use Nowo\MailKitBundle\Mailbox\Engine\SubmittedMessage;
use Nowo\MailKitBundle\Mailbox\Engine\SubmitTokenRejected;
use Nowo\MailKitBundle\Mailbox\Exception\MailboxGrantFailure;
use Nowo\MailKitBundle\Mailbox\Exception\MailboxProviderFailure;
use Nowo\MailKitBundle\Mailbox\Html\MailHtmlSanitizer;
use Nowo\MailKitBundle\Mailbox\Html\RemoteImages;
use Nowo\MailKitBundle\Mailbox\Value\FetchedAttachment;
use Nowo\MailKitBundle\Mailbox\Value\FolderRef;
use Nowo\MailKitBundle\Mailbox\Value\GrantFailureReason;
use Nowo\MailKitBundle\Mailbox\Value\MailboxProvider;
use Nowo\MailKitBundle\Mailbox\Value\MessageOrigin;
use Nowo\MailKitBundle\Mailbox\Value\OutgoingAttachment;
use Nowo\MailKitBundle\Mailbox\Value\OutgoingKind;
use Nowo\MailKitBundle\Mailbox\Value\OutgoingMailboxMail;
use Nowo\MailKitBundle\Mailbox\Value\ProviderAttachmentId;
use Nowo\MailKitBundle\Mailbox\Value\ProviderMessageId;
use Nowo\MailKitBundle\Mailbox\Value\SyncedMessage;
use Psr\Log\LoggerInterface;
use SensitiveParameter;
use Symfony\Component\Mime\Address;
use Symfony\Component\Mime\Email;
use Symfony\Component\Mime\Exception\ExceptionInterface as MimeException;
use Symfony\Component\Mime\Header\MailboxListHeader;
use Symfony\Component\Mime\Part\DataPart;

use function array_filter;
use function array_values;
use function in_array;
use function intdiv;
use function is_array;
use function is_bool;
use function is_string;
use function preg_match;
use function str_contains;
use function str_starts_with;
use function strlen;
use function strtolower;
use function trim;

/**
 * Gmail provider of the mailbox engine, **send-only** (phase B2, owner decision D-20261124-09). With the scopes
 * `openid email https://www.googleapis.com/auth/gmail.send` ({@see GmailScopes}) it can only:
 *
 * - check the account behind the token through the OpenID Connect userinfo (`sub` is the bound account id, `email`
 *   counts only when `email_verified` is true), after checking the token's declared scopes include `gmail.send`;
 * - send a **new** message from the mailbox's own address: one RFC 5322 message built by Symfony Mime (the outgoing HTML
 *   sanitised with remote images dropped, Bcc in a header that Gmail strips, a Message-ID derived from the idempotency
 *   key), handed over with one `messages.send` media upload, never retried here.
 *
 * Everything that reads the mailbox (subscriptions, changes, fetch, attachments, drafts) refuses: there is no inbox. A
 * reply or forward is refused too (the original cannot be read). Google errors become grant failures only for grant,
 * scope, policy and "Gmail not enabled" answers; rate limits are transient; nothing is logged with a body, a token or
 * an address. Stateless.
 */
final class GmailMailboxGateway implements MailboxGatewayInterface, SendOnlyMailboxGatewayInterface
{
    /** Largest raw message of the media upload (Gmail's 35 MB limit). */
    public const MAX_MIME_BYTES = 36_700_160;

    /** Gmail quota units of `messages.send`. */
    public const SEND_QUOTA_UNITS = 100;

    /** Google error reasons of a token without the scope (403). */
    public const SCOPE_MISSING_REASONS = ['insufficientPermissions', 'ACCESS_TOKEN_SCOPE_INSUFFICIENT'];

    /** Google error reasons of a Workspace policy that forbids the API for the account (403). */
    public const CONSENT_REASONS = ['domainPolicy'];

    /** Google error reasons of an account without Gmail (400). */
    public const MAILBOX_NOT_FOUND_REASONS = ['failedPrecondition', 'FAILED_PRECONDITION'];

    /** Google error reasons of a rate or sending limit (403 / 429): nothing was sent; retry later. */
    public const RATE_LIMIT_REASONS = ['userRateLimitExceeded', 'rateLimitExceeded', 'dailyLimitExceeded', 'quotaExceeded', 'RATE_LIMIT_EXCEEDED', 'RESOURCE_EXHAUSTED'];

    /** Cap of a JSON answer (userinfo, send). */
    private const MAX_SMALL_BYTES = 65_536;

    /** The outgoing HTML sanitiser of a gateway built without one. */
    private readonly MailHtmlSanitizer $outgoing;

    /**
     * @param MailHtmlSanitizer|null $outgoingSanitizer sanitiser of outgoing HTML (null: remote images dropped, 2 MB)
     */
    public function __construct(
        private readonly GmailApiClient $api,
        ?MailHtmlSanitizer $outgoingSanitizer = null,
        private readonly ?LoggerInterface $logger = null,
    ) {
        $this->outgoing = $outgoingSanitizer ?? new MailHtmlSanitizer(RemoteImages::Dropped, 2_000_000);
    }

    /**
     * {@inheritDoc}
     */
    public function provider(): MailboxProvider
    {
        return MailboxProvider::Gmail;
    }

    /**
     * {@inheritDoc}
     *
     * A token whose declared scopes lack `gmail.send` pauses the mailbox `ScopeMissing` before any call. A 401 is
     * retried once with a fresh token (the host refreshes it); a second one is `Revoked`.
     */
    public function account(MailboxSession $session): ProviderAccount
    {
        $scopes = $session->token()->scopes;
        if (!in_array(GmailScopes::SEND, $scopes, true)) {
            throw new MailboxGrantFailure(GrantFailureReason::ScopeMissing, 'The Gmail token was not granted the gmail.send scope.');
        }
        $broader = array_values(array_filter($scopes, static fn (string $scope): bool => $scope !== GmailScopes::SEND && (str_starts_with($scope, 'https://www.googleapis.com/auth/gmail.') || $scope === 'https://mail.google.com/')));
        if ($broader !== []) {
            // Audit ZBU-I4: D-20261124-09 allows gmail.send only; MailKit still calls only the two fixed endpoints.
            $this->logger?->warning('Gmail token declares Gmail scopes beyond gmail.send; request exactly GmailScopes::REQUESTED.', ['bundle' => 'NowoMailKitBundle', 'mailbox' => $session->mailbox->value, 'scopes' => $broader]);
        }
        for ($attempt = 1;; ++$attempt) {
            $answer = $this->api->request($session, 'GET', GmailApiClient::USERINFO_URL, [], 'account', 0, self::MAX_SMALL_BYTES);
            if ($answer['status'] === 401 && $attempt === 1) {
                $session->rejected();

                continue;
            }
            if ($answer['status'] !== 200) {
                throw self::failure($answer['status'], $answer['data'], $answer['retryAfter'], 'account');
            }
            break;
        }
        $data = $answer['data'];
        $id   = is_string($data['sub'] ?? null) ? $data['sub'] : '';
        if (preg_match('/^[A-Za-z0-9._-]{1,255}\z/', $id) !== 1) {
            throw new MailboxProviderFailure('Gmail account returned no usable id.', MailboxProviderFailure::REFUSED, 200);
        }
        $email = is_string($data['email'] ?? null) ? strtolower(trim($data['email'])) : '';
        // Only a verified address counts: an unverified one never matches the directory (AccountMismatch).
        $verified = is_bool($data['email_verified'] ?? null) && $data['email_verified'];

        return new ProviderAccount($id, $verified && $email !== '' && strlen($email) <= 254 ? [$email] : []);
    }

    /**
     * {@inheritDoc}
     */
    public function prepare(MailboxSession $session, OutgoingMailboxMail $mail, string $messageId): PreparedMessage
    {
        if ($mail->kind !== OutgoingKind::New) {
            throw $this->sendOnly('reply or forward');
        }
        if (self::minimumMimeBytes($mail) > self::MAX_MIME_BYTES) {
            // Audit ZBU-L3: refused before the message is rendered in memory (rendering peaks at about 5x its size).
            throw new MailboxProviderFailure('The message is too large for Gmail (35 MB with its attachments encoded).', MailboxProviderFailure::TOO_LARGE);
        }
        $handles = [];

        try {
            $email = (new Email())->from(new Address((string) $session->address))->subject((string) $mail->subject)->text($mail->text);
            foreach ($mail->to as $recipient) {
                $email->addTo(new Address($recipient->email, $recipient->name ?? ''));
            }
            foreach ($mail->cc as $recipient) {
                $email->addCc(new Address($recipient->email, $recipient->name ?? ''));
            }
            if ($mail->html !== null) {
                $email->html($this->outgoing->sanitize($mail->html));
            }
            foreach ($mail->attachments as $attachment) {
                $handle    = $attachment->open();
                $handles[] = [$attachment, $handle];
                $email->addPart($this->part($attachment, $handle));
            }
            $email->getHeaders()->addIdHeader('Message-ID', $messageId);
            if ($mail->to === [] && $mail->cc === []) {
                // Bcc-only: Mime renders no message without a To, Cc or Bcc header; toString() strips the Bcc again.
                $email->addBcc(new Address($mail->bcc[0]->email));
            }
            $mime = $email->toString();
            if ($mail->bcc !== []) {
                // Gmail delivers to the Bcc header's addresses and removes it from the sent message.
                $bcc = [];
                foreach ($mail->bcc as $recipient) {
                    $bcc[] = new Address($recipient->email, $recipient->name ?? '');
                }
                $mime = (new MailboxListHeader('Bcc', $bcc))->toString() . "\r\n" . $mime;
            }
        } catch (MimeException) {
            throw new MailboxProviderFailure('The message could not be rendered as MIME (invalid address or Content-ID).', MailboxProviderFailure::REFUSED);
        } finally {
            foreach ($handles as [$attachment, $handle]) {
                $attachment->close($handle);
            }
        }
        if (strlen($mime) > self::MAX_MIME_BYTES) {
            throw new MailboxProviderFailure('The message is too large for Gmail (35 MB with its attachments encoded).', MailboxProviderFailure::TOO_LARGE);
        }

        return new PreparedMessage($mime, $messageId);
    }

    /**
     * {@inheritDoc}
     */
    public function submit(MailboxSession $session, #[SensitiveParameter] PreparedMessage $message): SubmittedMessage
    {
        $answer = $this->api->request($session, 'POST', GmailApiClient::SEND_URL, ['headers' => ['Content-Type' => 'message/rfc822'], 'body' => $message->mime], 'send', self::SEND_QUOTA_UNITS, self::MAX_SMALL_BYTES);
        if ($answer['status'] === 401) {
            // Google took nothing; the host refreshes the token before the sender's one retry.
            $session->rejected();

            throw new SubmitTokenRejected('Gmail');
        }
        if ($answer['status'] !== 200) {
            throw self::failure($answer['status'], $answer['data'], $answer['retryAfter'], 'send');
        }
        $id     = is_string($answer['data']['id'] ?? null) ? $answer['data']['id'] : '';
        $thread = is_string($answer['data']['threadId'] ?? null) ? $answer['data']['threadId'] : null;
        if (preg_match('/^[A-Za-z0-9_-]{1,128}\z/', $id) !== 1) {
            // Sent, but without a usable id: the sender reports the outcome as unknown (never sent again).
            throw new MailboxProviderFailure('Gmail send answered without a usable message id.', MailboxProviderFailure::REFUSED, 200);
        }

        return new SubmittedMessage(new ProviderMessageId($id), $thread !== null && preg_match('/^[A-Za-z0-9_-]{1,128}\z/', $thread) === 1 ? $thread : null);
    }

    /**
     * Grant failure (pause) or provider failure of a Google error answer (design section 13): only grant, scope,
     * Workspace-policy and "Gmail not enabled" answers pause; rate and sending limits (403 / 429) and 5xx are transient.
     *
     * @param array<mixed> $data the decoded error answer
     */
    public static function failure(int $status, array $data, ?int $retryAfter, string $operation): MailboxGrantFailure|MailboxProviderFailure
    {
        $error  = is_array($data['error'] ?? null) ? $data['error'] : [];
        $reason = self::reason($error);
        $grant  = match (true) {
            $status === 401                                                             => GrantFailureReason::Revoked,
            $status === 403 && in_array($reason, self::SCOPE_MISSING_REASONS, true)     => GrantFailureReason::ScopeMissing,
            $status === 403 && in_array($reason, self::CONSENT_REASONS, true)           => GrantFailureReason::ConsentMissing,
            $status === 400 && in_array($reason, self::MAILBOX_NOT_FOUND_REASONS, true) => GrantFailureReason::MailboxNotFound,
            default                                                                     => null,
        };
        $suffix = ' (HTTP ' . $status . ($reason !== null ? ', ' . $reason : '') . ').';
        if ($grant !== null) {
            return new MailboxGrantFailure($grant, 'Gmail ' . $operation . ' refused the mailbox grant' . $suffix);
        }
        $kind = match (true) {
            $status === 429, $status >= 500, in_array($reason, self::RATE_LIMIT_REASONS, true) => MailboxProviderFailure::TRANSIENT,
            $status === 404                                                                    => MailboxProviderFailure::NOT_FOUND,
            $status === 413                                                                    => MailboxProviderFailure::TOO_LARGE,
            default                                                                            => MailboxProviderFailure::REFUSED,
        };

        return new MailboxProviderFailure('Gmail ' . $operation . ' failed' . $suffix, $kind, $status, $retryAfter, $reason);
    }

    /**
     * {@inheritDoc}
     */
    public function subscribe(MailboxSession $session, FolderRef $folder, string $notificationUrl, string $clientState, int $expiresAt): RemoteSubscription
    {
        throw $this->sendOnly('subscribe');
    }

    /**
     * {@inheritDoc}
     */
    public function renew(MailboxSession $session, string $subscriptionId, int $expiresAt): ?int
    {
        throw $this->sendOnly('renew');
    }

    /**
     * {@inheritDoc}
     */
    public function unsubscribe(MailboxSession $session, string $subscriptionId): void
    {
        throw $this->sendOnly('unsubscribe');
    }

    /**
     * {@inheritDoc}
     */
    public function changes(MailboxSession $session, FolderRef $folder, #[SensitiveParameter] ?string $cursor, int $since, int $pageSize): ChangePage
    {
        throw $this->sendOnly('changes');
    }

    /**
     * {@inheritDoc}
     */
    public function fetch(MailboxSession $session, FolderRef $folder, ProviderMessageId $message): ?FetchedMessage
    {
        throw $this->sendOnly('fetch');
    }

    /**
     * {@inheritDoc}
     */
    public function stillExists(MailboxSession $session, ProviderMessageId $message): bool
    {
        throw $this->sendOnly('read');
    }

    /**
     * {@inheritDoc}
     */
    public function attachments(MailboxSession $session, FetchedMessage $message): array
    {
        throw $this->sendOnly('attachments');
    }

    /**
     * {@inheritDoc}
     */
    public function normalize(MailboxSession $session, FetchedMessage $message, array $attachments, MessageOrigin $origin): SyncedMessage
    {
        throw $this->sendOnly('normalize');
    }

    /**
     * {@inheritDoc}
     */
    public function open(MailboxSession $session, ProviderMessageId $message, ProviderAttachmentId $attachment, int $maxBytes): FetchedAttachment
    {
        throw $this->sendOnly('attachment download');
    }

    /**
     * {@inheritDoc}
     */
    public function createDraft(MailboxSession $session, OutgoingMailboxMail $mail, string $messageId): DraftRef
    {
        throw $this->sendOnly('draft');
    }

    /**
     * {@inheritDoc}
     */
    public function updateDraft(MailboxSession $session, DraftRef $draft, OutgoingMailboxMail $mail): void
    {
        throw $this->sendOnly('draft');
    }

    /**
     * {@inheritDoc}
     */
    public function addAttachment(MailboxSession $session, DraftRef $draft, OutgoingAttachment $attachment, Closure $progress): void
    {
        throw $this->sendOnly('draft');
    }

    /**
     * {@inheritDoc}
     */
    public function sendDraft(MailboxSession $session, ProviderMessageId $draft): void
    {
        throw $this->sendOnly('draft');
    }

    /**
     * {@inheritDoc}
     *
     * A send-only grant cannot read a draft: always unreadable.
     */
    public function draftState(MailboxSession $session, ProviderMessageId $draft): DraftState
    {
        return DraftState::Unreadable;
    }

    /**
     * {@inheritDoc}
     */
    public function deleteDraft(MailboxSession $session, ProviderMessageId $draft): void
    {
        throw $this->sendOnly('draft');
    }

    /**
     * The MIME part of one attachment, read from its stream while the message is rendered.
     *
     * @param resource $handle
     */
    private function part(OutgoingAttachment $attachment, mixed $handle): DataPart
    {
        $part = new DataPart($handle, $attachment->filename, $attachment->contentType);
        if ($attachment->contentId !== null) {
            if (!str_contains($attachment->contentId, '@')) {
                throw new MailboxProviderFailure('Gmail needs a Content-ID of the form "name@domain" for an inline part.', MailboxProviderFailure::REFUSED);
            }
            $part->setContentId($attachment->contentId);
        }

        return $attachment->inline ? $part->asInline() : $part;
    }

    /**
     * A lower bound of the rendered message: the bodies as given plus every attachment in base64 with Mime's 76-character
     * CRLF lines. A message above it can never fit, whatever its headers; one below is still checked once rendered.
     */
    public static function minimumMimeBytes(OutgoingMailboxMail $mail): int
    {
        $bytes = strlen($mail->text) + strlen((string) $mail->html);
        foreach ($mail->attachments as $attachment) {
            $encoded = intdiv($attachment->size + 2, 3) * 4;
            $bytes += $encoded + 2 * intdiv($encoded + 75, 76);
        }

        return $bytes;
    }

    /**
     * The Google error reason: `errors[0].reason`, else the `ErrorInfo` detail reason, else `status`; a short token or null.
     *
     * @param array<mixed> $error
     */
    private static function reason(array $error): ?string
    {
        $candidates = [];
        if (is_array($error['errors'] ?? null) && is_array($error['errors'][0] ?? null)) {
            $candidates[] = $error['errors'][0]['reason'] ?? null;
        }
        foreach (is_array($error['details'] ?? null) ? $error['details'] : [] as $detail) {
            $candidates[] = is_array($detail) ? ($detail['reason'] ?? null) : null;
        }
        $candidates[] = $error['status'] ?? null;
        foreach ($candidates as $candidate) {
            if (is_string($candidate) && preg_match('/^[A-Za-z0-9_.-]{1,64}\z/', $candidate) === 1) {
                return $candidate;
            }
        }

        return null;
    }

    /**
     * The refusal of an operation a send-only mailbox does not have.
     */
    private function sendOnly(string $operation): MailboxProviderFailure
    {
        return new MailboxProviderFailure('Gmail mailboxes are send-only (no inbox): ' . $operation . ' is not available.', MailboxProviderFailure::REFUSED);
    }
}
