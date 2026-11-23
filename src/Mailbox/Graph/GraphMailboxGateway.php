<?php

declare(strict_types=1);

namespace Nowo\MailKitBundle\Mailbox\Graph;

use DateTimeImmutable;
use DateTimeZone;
use Exception;
use finfo;
use Nowo\MailKitBundle\Graph\GraphApiClient;
use Nowo\MailKitBundle\Graph\GraphException;
use Nowo\MailKitBundle\Graph\Inbound\GraphInboundProcessor;
use Nowo\MailKitBundle\Graph\Inbound\GraphMessageNormalizer;
use Nowo\MailKitBundle\Graph\Inbound\InboundAddress;
use Nowo\MailKitBundle\Graph\Inbound\InboundFileTypes;
use Nowo\MailKitBundle\Graph\Send\AttachmentFilename;
use Nowo\MailKitBundle\Graph\Send\GraphAttachment;
use Nowo\MailKitBundle\Mailbox\Engine\ChangeItem;
use Nowo\MailKitBundle\Mailbox\Engine\ChangePage;
use Nowo\MailKitBundle\Mailbox\Engine\CursorExpired;
use Nowo\MailKitBundle\Mailbox\Engine\FetchedMessage;
use Nowo\MailKitBundle\Mailbox\Engine\MailboxGatewayInterface;
use Nowo\MailKitBundle\Mailbox\Engine\MailboxSession;
use Nowo\MailKitBundle\Mailbox\Engine\ProviderAccount;
use Nowo\MailKitBundle\Mailbox\Engine\RemoteSubscription;
use Nowo\MailKitBundle\Mailbox\Exception\MailboxGrantFailure;
use Nowo\MailKitBundle\Mailbox\Exception\MailboxProviderFailure;
use Nowo\MailKitBundle\Mailbox\Html\MailHtmlSanitizer;
use Nowo\MailKitBundle\Mailbox\Value\AttachmentKind;
use Nowo\MailKitBundle\Mailbox\Value\AttachmentRef;
use Nowo\MailKitBundle\Mailbox\Value\FetchedAttachment;
use Nowo\MailKitBundle\Mailbox\Value\FolderRef;
use Nowo\MailKitBundle\Mailbox\Value\GrantFailureReason;
use Nowo\MailKitBundle\Mailbox\Value\MailboxProvider;
use Nowo\MailKitBundle\Mailbox\Value\MessageEnvelope;
use Nowo\MailKitBundle\Mailbox\Value\MessageOrigin;
use Nowo\MailKitBundle\Mailbox\Value\ProviderAttachmentId;
use Nowo\MailKitBundle\Mailbox\Value\ProviderCall;
use Nowo\MailKitBundle\Mailbox\Value\ProviderMessageId;
use Nowo\MailKitBundle\Mailbox\Value\SyncedMessage;
use Nowo\MailKitBundle\Mailbox\Value\ThreadKey;
use Nowo\MailKitBundle\Sender\MailAddress;

use function array_slice;
use function array_values;
use function assert;
use function fclose;
use function fopen;
use function fread;
use function hash;
use function hash_equals;
use function in_array;
use function is_array;
use function is_bool;
use function is_numeric;
use function is_resource;
use function is_string;
use function mb_substr;
use function parse_url;
use function preg_match;
use function preg_replace;
use function rawurldecode;
use function rawurlencode;
use function rewind;
use function str_contains;
use function strlen;
use function strtolower;
use function trim;

use const FILEINFO_MIME_TYPE;
use const PHP_URL_PATH;

/**
 * Microsoft Graph delegated provider of the mailbox engine (2.5.0). Calls **only** `/me/…` paths with the host's
 * token (the token decides the mailbox: a wrong address can never point it at another person's mail) through the 1.8
 * client (pinned `https://graph.microsoft.com/v1.0`, no redirects, one retry with a fresh token on 401, capped
 * answers, errors without bodies or URLs). Delta / next links are followed only when they are a mail-folder message
 * delta of `/me` or of the bound account. Every remote field is validated and bounded; HTML goes through the mail
 * sanitiser. Graph errors become grant failures (pause) only for grant, consent and account problems (design
 * section 13); everything else is a {@see MailboxProviderFailure}, transient or not. Stateless.
 */
final class GraphMailboxGateway implements MailboxGatewayInterface
{
    /** Fields read by the fetch (one call). */
    public const SELECT = 'id,internetMessageId,conversationId,subject,from,sender,replyTo,toRecipients,ccRecipients,bccRecipients,receivedDateTime,sentDateTime,body,hasAttachments,internetMessageHeaders';

    /** Fields of the delta page: enough for the exclusion policy before the fetch (Q-MB-3). */
    public const DELTA_SELECT = 'id,from,sender,receivedDateTime';

    /** Most attachment references listed per message. */
    public const MAX_ATTACHMENT_REFS = 100;

    /** Inline parts whose Content-ID is read (one call each). */
    public const MAX_INLINE_LOOKUPS = 20;

    /** Graph error codes of a mailbox that does not exist behind the account. */
    public const MAILBOX_NOT_FOUND_CODES = ['MailboxNotEnabledForRESTAPI', 'MailboxNotSupportedForRESTAPI', 'ErrorInvalidUser', 'ErrorNonExistentMailbox'];

    /** Graph error codes of a token without a needed scope. */
    public const SCOPE_MISSING_CODES = ['ErrorInsufficientPermissionsInAccessToken', 'InsufficientScopes'];

    /** Accepted (decoded, lower-cased) path of a delta link: `/me` or one user's mail-folder message delta. */
    private const LINK_PATTERN = "#^/v1\\.0/(?:me|users/([^/()';]+)|users\\('([^/()';]+)'\\))/mailfolders(?:/[^/()';]+|\\('[^/()';]+'\\))/messages/delta\\z#";

    /** Cap of an oversized message's envelope read. */
    private const MAX_OVERSIZED_BYTES = 262_144;

    /** Cap of small JSON answers (account, subscriptions, attachment metadata). */
    private const MAX_SMALL_BYTES = 262_144;

    /** Cap of a delta page or an attachment list. */
    private const MAX_PAGE_BYTES = 4_194_304;

    /** Bytes of an attachment read for the content type detection. */
    private const SNIFF_BYTES = 65_536;

    /**
     * @param GraphApiClient $api the 1.8 client (built for delegated calls: host pin, no app-only tokens needed)
     * @param GraphMessageNormalizer $normalizer the 1.9 normaliser with the mailbox HTML sanitiser
     * @param int $maxMessageBytes `mailboxes.limits.max_message_bytes`
     */
    public function __construct(
        private readonly GraphApiClient $api,
        private readonly GraphMessageNormalizer $normalizer,
        private readonly int $maxMessageBytes = 26_214_400,
    ) {
    }

    /**
     * {@inheritDoc}
     */
    public function provider(): MailboxProvider
    {
        return MailboxProvider::Graph;
    }

    /**
     * {@inheritDoc}
     */
    public function account(MailboxSession $session): ProviderAccount
    {
        $data = $this->call($session, 'GET', '/me?$select=id,mail,userPrincipalName', [], [200], 'account', self::MAX_SMALL_BYTES);
        $id   = is_string($data['id'] ?? null) ? $data['id'] : '';
        if (preg_match('/^[A-Za-z0-9._@-]{1,128}\z/', $id) !== 1) {
            throw new MailboxProviderFailure('Microsoft Graph account returned no usable id.', MailboxProviderFailure::REFUSED, 200);
        }
        $addresses = [];
        foreach (['mail', 'userPrincipalName'] as $field) {
            $value = is_string($data[$field] ?? null) ? strtolower(trim($data[$field])) : '';
            if ($value !== '' && mb_substr($value, 0, 254) === $value && !in_array($value, $addresses, true)) {
                $addresses[] = $value;
            }
        }

        return new ProviderAccount(strtolower($id), $addresses);
    }

    /**
     * {@inheritDoc}
     */
    public function subscribe(MailboxSession $session, FolderRef $folder, string $notificationUrl, string $clientState, int $expiresAt): RemoteSubscription
    {
        $answer = $this->call($session, 'POST', '/subscriptions', [
            'headers' => ['Prefer' => 'IdType="ImmutableId"'],
            'json'    => [
                'changeType'                => 'created',
                'notificationUrl'           => $notificationUrl,
                'lifecycleNotificationUrl'  => $notificationUrl,
                'resource'                  => "me/mailFolders('" . $folder->key . "')/messages",
                'expirationDateTime'        => $this->iso($expiresAt),
                'clientState'               => $clientState,
                'latestSupportedTlsVersion' => 'v1_2',
            ],
        ], [201], 'subscribe', self::MAX_SMALL_BYTES, true);
        $id = is_string($answer['id'] ?? null) ? $answer['id'] : '';
        if (preg_match('/^[A-Za-z0-9-]{1,128}\z/', $id) !== 1) {
            throw new MailboxProviderFailure('Microsoft Graph subscribe returned no usable id.', MailboxProviderFailure::REFUSED, 201);
        }

        return new RemoteSubscription($id, $this->timestamp($answer['expirationDateTime'] ?? null) ?? $expiresAt);
    }

    /**
     * {@inheritDoc}
     */
    public function renew(MailboxSession $session, string $subscriptionId, int $expiresAt): ?int
    {
        try {
            $answer = $this->call($session, 'PATCH', '/subscriptions/' . rawurlencode($subscriptionId), ['json' => ['expirationDateTime' => $this->iso($expiresAt)]], [200], 'renew', self::MAX_SMALL_BYTES);
        } catch (MailboxProviderFailure $failure) {
            if ($failure->reason === MailboxProviderFailure::NOT_FOUND) {
                return null;
            }

            throw $failure;
        }

        return $this->timestamp($answer['expirationDateTime'] ?? null) ?? $expiresAt;
    }

    /**
     * {@inheritDoc}
     */
    public function unsubscribe(MailboxSession $session, string $subscriptionId): void
    {
        $this->call($session, 'DELETE', '/subscriptions/' . rawurlencode($subscriptionId), [], [204, 404], 'unsubscribe', self::MAX_SMALL_BYTES);
    }

    /**
     * {@inheritDoc}
     */
    public function changes(MailboxSession $session, FolderRef $folder, ?string $cursor, int $since, int $pageSize): ChangePage
    {
        $options = ['headers' => ['Prefer' => 'odata.maxpagesize=' . $pageSize . ', IdType="ImmutableId"']];

        try {
            if ($cursor === null) {
                $data = $this->call($session, 'GET', '/me/mailFolders/' . rawurlencode($folder->key) . '/messages/delta?$select=' . self::DELTA_SELECT . '&$filter=' . rawurlencode('receivedDateTime ge ' . $this->iso($since)), $options, [200], 'delta', self::MAX_PAGE_BYTES);
            } else {
                $this->assertDeltaLink($session, $cursor);
                $data = $this->call($session, 'GET', $cursor, $options, [200], 'delta', self::MAX_PAGE_BYTES, false, true);
            }
        } catch (MailboxProviderFailure $failure) {
            if ($failure->status === 410) {
                throw new CursorExpired();
            }

            throw $failure;
        }

        $items = [];
        foreach (is_array($data['value'] ?? null) ? $data['value'] : [] as $item) {
            if (!is_array($item) || !is_string($item['id'] ?? null) || preg_match(ProviderMessageId::PATTERN, $item['id']) !== 1) {
                continue;
            }
            $id = new ProviderMessageId($item['id']);
            if (isset($item['@removed'])) {
                $items[] = new ChangeItem($id, true);

                continue;
            }
            $hasEnvelope = isset($item['from']) || isset($item['sender']);
            $items[]     = new ChangeItem($id, false, $hasEnvelope, $this->address($item['from'] ?? null), $this->address($item['sender'] ?? null), $this->date($item['receivedDateTime'] ?? null));
        }
        $delta = $data['@odata.deltaLink'] ?? null;
        $next  = $data['@odata.nextLink'] ?? null;
        $link  = is_string($delta) ? $delta : (is_string($next) ? $next : null);
        if ($link === null) {
            throw new MailboxProviderFailure('Microsoft Graph delta answered without a next or delta link.', MailboxProviderFailure::REFUSED, 200);
        }
        $this->assertDeltaLink($session, $link);

        return new ChangePage($items, $link, is_string($delta));
    }

    /**
     * {@inheritDoc}
     */
    public function fetch(MailboxSession $session, FolderRef $folder, ProviderMessageId $message): ?FetchedMessage
    {
        $path      = '/me/messages/' . rawurlencode($message->value);
        $oversized = false;

        try {
            $data = $this->call($session, 'GET', $path . '?$select=' . self::SELECT, ['headers' => ['Prefer' => 'outlook.body-content-type="html", IdType="ImmutableId"']], [200], 'get_message', $this->maxMessageBytes);
        } catch (MailboxProviderFailure $failure) {
            if ($failure->reason === MailboxProviderFailure::NOT_FOUND) {
                return null;
            }
            if ($failure->reason !== MailboxProviderFailure::TOO_LARGE) {
                throw $failure;
            }
            // Over limits.max_message_bytes: the envelope without body or headers, flagged oversized (as 1.9).
            $oversized = true;

            try {
                $data = $this->call($session, 'GET', $path . '?$select=' . GraphInboundProcessor::SELECT_OVERSIZED, ['headers' => ['Prefer' => 'IdType="ImmutableId"']], [200], 'get_message', self::MAX_OVERSIZED_BYTES);
            } catch (MailboxProviderFailure $envelope) {
                if ($envelope->reason !== MailboxProviderFailure::TOO_LARGE) {
                    throw $envelope;
                }
                $data = $this->call($session, 'GET', $path . '?$select=' . GraphInboundProcessor::SELECT_MINIMAL, ['headers' => ['Prefer' => 'IdType="ImmutableId"']], [200], 'get_message', self::MAX_OVERSIZED_BYTES);
            }
        }
        // The id in the answer must be the one asked for (immutable ids); anything else is not this message.
        if (($data['id'] ?? null) !== $message->value) {
            throw new MailboxProviderFailure('Microsoft Graph get_message answered for another id.', MailboxProviderFailure::REFUSED, 200);
        }
        $subject  = mb_substr(trim((string) preg_replace('/[\x00-\x1F\x7F\s]+/u', ' ', is_string($data['subject'] ?? null) ? $data['subject'] : '')), 0, GraphMessageNormalizer::MAX_SUBJECT);
        $envelope = new MessageEnvelope(
            $session->mailbox,
            $folder,
            $message,
            $this->address($data['from'] ?? null),
            $this->address($data['sender'] ?? null),
            GraphMessageNormalizer::listIdOf($data),
            $subject,
            $this->date($data['receivedDateTime'] ?? null),
            true,
        );

        return new FetchedMessage($message, $folder, $envelope, $data, $oversized);
    }

    /**
     * {@inheritDoc}
     */
    public function attachments(MailboxSession $session, FetchedMessage $message): array
    {
        if ($message->oversized || ($message->data['hasAttachments'] ?? false) !== true) {
            return [];
        }
        $path    = '/me/messages/' . rawurlencode($message->id->value) . '/attachments';
        $list    = $this->call($session, 'GET', $path . '?$select=id,name,contentType,size,isInline', ['headers' => ['Prefer' => 'IdType="ImmutableId"']], [200], 'list', self::MAX_PAGE_BYTES);
        $refs    = [];
        $lookups = 0;
        foreach (array_slice(is_array($list['value'] ?? null) ? array_values($list['value']) : [], 0, self::MAX_ATTACHMENT_REFS) as $item) {
            if (!is_array($item) || !is_string($item['id'] ?? null) || preg_match(ProviderAttachmentId::PATTERN, $item['id']) !== 1) {
                continue;
            }
            $kind = match ($item['@odata.type'] ?? null) {
                '#microsoft.graph.fileAttachment'      => AttachmentKind::File,
                '#microsoft.graph.itemAttachment'      => AttachmentKind::Item,
                '#microsoft.graph.referenceAttachment' => AttachmentKind::Reference,
                default                                => null,
            };
            if ($kind === null) {
                continue;
            }
            $inline    = is_bool($item['isInline'] ?? null) && $item['isInline'];
            $contentId = null;
            if ($inline && $kind === AttachmentKind::File && $lookups < self::MAX_INLINE_LOOKUPS) {
                ++$lookups;
                $meta      = $this->call($session, 'GET', $path . '/' . rawurlencode($item['id']) . '?$select=id,contentId,isInline', ['headers' => ['Prefer' => 'IdType="ImmutableId"']], [200], 'get_attachment', self::MAX_SMALL_BYTES);
                $cid       = is_string($meta['contentId'] ?? null) ? trim($meta['contentId'], '<> ') : '';
                $contentId = preg_match(GraphAttachment::CONTENT_ID_PATTERN, $cid) === 1 ? $cid : null;
            }
            $refs[] = new AttachmentRef(
                new ProviderAttachmentId($item['id']),
                $kind,
                AttachmentFilename::neutralise(is_string($item['name'] ?? null) ? $item['name'] : null),
                $this->contentType($item['contentType'] ?? null),
                is_numeric($item['size'] ?? null) && (int) $item['size'] > 0 ? (int) $item['size'] : 0,
                $inline,
                $contentId,
            );
        }

        return $refs;
    }

    /**
     * {@inheritDoc}
     */
    public function normalize(MailboxSession $session, FetchedMessage $message, array $attachments, MessageOrigin $origin): SyncedMessage
    {
        $address     = $session->address ?? '';
        $normalized  = $this->normalizer->normalize($session->mailbox->value, $address, $message->data, [], false, $message->oversized);
        $from        = $this->fromInbound($normalized->from);
        $fromMailbox = $from instanceof MailAddress && $address !== '' && strtolower($from->email) === $address;
        $text        = $normalized->text;

        return new SyncedMessage(
            mailbox: $session->mailbox,
            provider: MailboxProvider::Graph,
            folder: $message->folder,
            id: $message->id,
            providerThreadId: $normalized->conversationId,
            threadKey: ThreadKey::for(MailboxProvider::Graph, $normalized->conversationId, $normalized->references, $normalized->inReplyTo, $normalized->internetMessageId, $message->id),
            internetMessageId: $normalized->internetMessageId,
            inReplyTo: $normalized->inReplyTo,
            references: $normalized->references,
            from: $from,
            sender: $this->fromInbound($normalized->sender),
            replyTo: $this->list($normalized->replyTo),
            to: $this->list($normalized->to),
            cc: $this->list($normalized->cc),
            bcc: $fromMailbox ? $this->recipients($message->data['bccRecipients'] ?? null) : [],
            subject: $normalized->subject,
            snippet: mb_substr(trim((string) preg_replace('/\s+/u', ' ', mb_substr($text, 0, 4 * SyncedMessage::MAX_SNIPPET))), 0, SyncedMessage::MAX_SNIPPET),
            text: $text,
            html: $normalized->html,
            remoteImagesBlocked: $normalized->html !== null ? MailHtmlSanitizer::blockedImages($normalized->html) : 0,
            receivedAt: $normalized->receivedAt,
            sentAt: $this->date($message->data['sentDateTime'] ?? null),
            fromMailbox: $fromMailbox,
            listId: $normalized->listId,
            attachments: $attachments,
            authentication: $normalized->authentication,
            oversized: $message->oversized,
            origin: $origin,
        );
    }

    /**
     * {@inheritDoc}
     */
    public function open(MailboxSession $session, ProviderMessageId $message, ProviderAttachmentId $attachment, int $maxBytes): FetchedAttachment
    {
        $path = '/me/messages/' . rawurlencode($message->value) . '/attachments/' . rawurlencode($attachment->value);
        $meta = $this->call($session, 'GET', $path . '?$select=id,name,contentType,size,isInline', ['headers' => ['Prefer' => 'IdType="ImmutableId"']], [200], 'get_attachment', self::MAX_SMALL_BYTES);
        if (($meta['@odata.type'] ?? null) !== '#microsoft.graph.fileAttachment') {
            throw new MailboxProviderFailure('Microsoft Graph attachment is not a file; it cannot be downloaded.', MailboxProviderFailure::NOT_DOWNLOADABLE, 200);
        }
        if (is_numeric($meta['size'] ?? null) && (int) $meta['size'] > $maxBytes) {
            throw new MailboxProviderFailure('Microsoft Graph attachment exceeds the requested size limit.', MailboxProviderFailure::TOO_LARGE, 200);
        }
        $stream = fopen('php://temp/maxmemory:262144', 'w+');
        assert(is_resource($stream));

        try {
            $size = $this->download($session, $path . '/$value', $stream, $maxBytes);
            rewind($stream);
            $detected = (string) (new finfo(FILEINFO_MIME_TYPE))->buffer((string) fread($stream, self::SNIFF_BYTES));
            rewind($stream);
        } catch (MailboxProviderFailure|MailboxGrantFailure $failure) {
            fclose($stream);

            throw $failure;
        }
        $detected = preg_match('#^[a-z0-9][a-z0-9!\#$&^_.+-]{0,63}/[a-z0-9][a-z0-9!\#$&^_.+-]{0,126}\z#', $detected) === 1 ? $detected : 'application/octet-stream';
        $name     = AttachmentFilename::neutralise(is_string($meta['name'] ?? null) ? $meta['name'] : null);

        return new FetchedAttachment($stream, $size, $detected, InboundFileTypes::safeFilename($name, $detected));
    }

    /**
     * A Graph call with the session's token, its failure mapped (design section 13).
     *
     * @param array<string, mixed> $options
     * @param list<int> $expected
     * @param string $operation label of {@see ProviderCall::OPERATIONS}
     * @param bool $subscription refusals other than 401 are not grant failures (a personal account without
     *                           subscriptions stays sweep-only, Q-MB-21)
     * @param bool $absolute `$path` is an absolute link returned by Graph
     *
     * @return array<mixed>
     */
    private function call(MailboxSession $session, string $method, string $path, array $options, array $expected, string $operation, int $maxBytes, bool $subscription = false, bool $absolute = false): array
    {
        $credential = new DelegatedGraphCredential($session);

        try {
            return $absolute
                ? $this->api->callUrl($credential, $method, $path, $options, $expected, $operation, $maxBytes)
                : $this->api->call($credential, $method, $path, $options, $expected, $operation, $maxBytes);
        } catch (GraphException $failure) {
            throw self::failure($failure, $operation, $subscription);
        }
    }

    /**
     * Streams a 2xx body into a sink (attachment `$value`), failure mapped.
     *
     * @param resource $sink
     */
    private function download(MailboxSession $session, string $path, mixed $sink, int $maxBytes): int
    {
        try {
            return $this->api->download(new DelegatedGraphCredential($session), $path, $sink, $maxBytes, 'get_attachment');
        } catch (GraphException $failure) {
            throw self::failure($failure, 'get_attachment', false);
        }
    }

    /**
     * Grant failure (pause) or provider failure of a Graph error (design section 13).
     */
    public static function failure(GraphException $failure, string $operation, bool $subscription = false): MailboxGrantFailure|MailboxProviderFailure
    {
        $status = $failure->status;
        $code   = $failure->graphCode;
        $reason = match (true) {
            $status === 401                                                                       => GrantFailureReason::Revoked,
            $status === 403 && !$subscription && in_array($code, self::SCOPE_MISSING_CODES, true) => GrantFailureReason::ScopeMissing,
            $status === 403 && !$subscription                                                     => GrantFailureReason::ConsentMissing,
            $status === 404 && in_array($code, self::MAILBOX_NOT_FOUND_CODES, true)               => GrantFailureReason::MailboxNotFound,
            default                                                                               => null,
        };
        if ($reason !== null) {
            return new MailboxGrantFailure($reason, 'Microsoft Graph ' . $operation . ' refused the mailbox grant (HTTP ' . (int) $status . ($code !== null ? ', ' . $code : '') . ').');
        }
        $kind = match (true) {
            $failure->isRetryable()                               => MailboxProviderFailure::TRANSIENT,
            $failure->reason === GraphException::REASON_TOO_LARGE => MailboxProviderFailure::TOO_LARGE,
            $status === 404                                       => MailboxProviderFailure::NOT_FOUND,
            default                                               => MailboxProviderFailure::REFUSED,
        };

        return new MailboxProviderFailure($failure->getMessage(), $kind, $status, $failure->retryAfter);
    }

    /**
     * A delta / next link is followed only on `https://graph.microsoft.com/v1.0/` (the 1.8 SSRF guard) when its path
     * is exactly a mail-folder message delta of `/me`, of the session's address or of the account MailKit bound
     * (`users/{id}` whose SHA-256 is the stored account hash): case-insensitive, no `.` / `..` segment or `;`, even
     * after a second URL-decoding.
     */
    private function assertDeltaLink(MailboxSession $session, string $url): void
    {
        try {
            $this->api->assertGraphUrl($url, 'delta');
        } catch (GraphException $failure) {
            throw self::failure($failure, 'delta');
        }
        $once  = strtolower(rawurldecode((string) parse_url($url, PHP_URL_PATH)));
        $twice = rawurldecode($once);
        foreach ([$once, $twice] as $path) {
            if (str_contains($path, ';') || preg_match('#(?:^|/)\.{1,2}(?:/|$)#', $path) === 1) {
                throw new MailboxProviderFailure('Microsoft Graph delta returned a link outside the mailbox; refused.', MailboxProviderFailure::REFUSED);
            }
        }
        if (preg_match(self::LINK_PATTERN, $once, $match) !== 1) {
            throw new MailboxProviderFailure('Microsoft Graph delta returned a link outside the mailbox; refused.', MailboxProviderFailure::REFUSED);
        }
        $user = ($match[1] ?? '') !== '' ? $match[1] : ($match[2] ?? '');
        if ($user === '' || $user === $session->address || ($session->accountHash !== null && hash_equals($session->accountHash, hash('sha256', $user)))) {
            return;
        }

        throw new MailboxProviderFailure('Microsoft Graph delta returned a link outside the mailbox; refused.', MailboxProviderFailure::REFUSED);
    }

    /**
     * @return list<MailAddress>
     */
    private function recipients(mixed $list): array
    {
        $out = [];
        foreach (is_array($list) ? array_slice($list, 0, 500) : [] as $recipient) {
            $address = $this->address($recipient);
            if ($address instanceof MailAddress) {
                $out[] = $address;
            }
        }

        return $out;
    }

    /**
     * A Graph `recipient` as a MailAddress (address lower-cased and stripped like 1.9, display name cleaned), or null.
     */
    private function address(mixed $recipient): ?MailAddress
    {
        $email = is_array($recipient) && is_array($recipient['emailAddress'] ?? null) ? $recipient['emailAddress'] : null;
        if ($email === null || !is_string($email['address'] ?? null) || !str_contains($email['address'], '@')) {
            return null;
        }

        return $this->fromInbound(new InboundAddress($email['address'], is_string($email['name'] ?? null) ? $email['name'] : null, ''));
    }

    private function fromInbound(?InboundAddress $address): ?MailAddress
    {
        return $address instanceof InboundAddress && str_contains($address->address, '@') ? new MailAddress($address->address, $address->name) : null;
    }

    /**
     * @param list<InboundAddress> $addresses
     *
     * @return list<MailAddress>
     */
    private function list(array $addresses): array
    {
        $out = [];
        foreach ($addresses as $address) {
            $mail = $this->fromInbound($address);
            if ($mail instanceof MailAddress) {
                $out[] = $mail;
            }
        }

        return $out;
    }

    private function contentType(mixed $value): string
    {
        $type = strtolower(trim(is_string($value) ? $value : ''));

        return preg_match('#^[a-z0-9][a-z0-9!\#$&^_.+-]{0,63}/[a-z0-9][a-z0-9!\#$&^_.+-]{0,126}\z#', $type) === 1 ? $type : 'application/octet-stream';
    }

    private function date(mixed $value): ?DateTimeImmutable
    {
        if (!is_string($value) || $value === '' || strlen($value) > 64) {
            return null;
        }

        try {
            return new DateTimeImmutable($value);
        } catch (Exception) {
            return null;
        }
    }

    private function timestamp(mixed $value): ?int
    {
        return $this->date($value)?->getTimestamp();
    }

    private function iso(int $timestamp): string
    {
        return (new DateTimeImmutable('@' . $timestamp))->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d\TH:i:s\Z');
    }
}
