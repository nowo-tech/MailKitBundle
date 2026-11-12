<?php

declare(strict_types=1);

namespace Nowo\MailKitBundle\Graph\Send;

use Nowo\MailKitBundle\Graph\GraphApiClient;
use Nowo\MailKitBundle\Graph\GraphException;
use Nowo\MailKitBundle\Graph\GraphMailbox;
use Nowo\MailKitBundle\Graph\GraphMailboxProviderInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\Mime\Address;
use Symfony\Component\Mime\Email;
use Symfony\Component\Mime\Exception\ExceptionInterface as MimeException;
use Throwable;

use function base64_encode;
use function bin2hex;
use function count;
use function fread;
use function is_bool;
use function is_string;
use function max;
use function min;
use function random_bytes;
use function rawurlencode;
use function sprintf;
use function strlen;
use function strrchr;

/**
 * Sends an e-mail from a shared mailbox through Microsoft Graph with app-only credentials (1.8).
 *
 * Flow (every call to the mailbox's `/users/{address}`; ids requested as immutable ids):
 * 1. The headers and bodies are rendered as MIME with the own `Message-ID`, `In-Reply-To`, `References` and
 *    `Reply-To`, and a draft is created from it (`POST /messages`, `text/plain` base64 MIME). Graph's JSON
 *    `internetMessageHeaders` only accepts custom `x-` headers, so the MIME path is the one that keeps the RFC 5322
 *    threading headers. The MIME part is capped ({@see MAX_MIME_BYTES}) to stay under Graph's 4 MB request limit.
 * 2. Bcc recipients are set on the draft (`PATCH bccRecipients`): MIME never carries Bcc.
 * 3. Each attachment is added below {@see LARGE_ATTACHMENT_BYTES} with one `POST /attachments`, from there with an
 *    upload session (`createUploadSession`, then `PUT` chunks of {@see CHUNK_BYTES} read from the stream, no token,
 *    only to the allowed upload hosts).
 * 4. The draft is sent (`POST /messages/{id}/send`; Exchange saves it in Sent Items with the same immutable id).
 * A failure before the send call, or a definite refusal of it, deletes the draft (best effort); an ambiguous send
 * failure checks `isDraft` first and never deletes a sent message (audit NK-1). The result carries the Graph id, the internetMessageId and
 * the conversation id.
 *
 * Stateless (FrankenPHP worker mode): the mailbox and token are resolved per call. Logs carry the profile, the
 * operation, statuses and counts — never addresses, subjects, bodies, file names, tokens or the secret.
 */
final class GraphMailSender
{
    /** Attachments of this size or more go through an upload session (Graph: "3 MB" to 150 MB; 2.9 MB keeps a plain POST's base64 JSON under Graph's 4 MB request limit, audit NK-2). */
    public const LARGE_ATTACHMENT_BYTES = 2_900_000;

    /** Bytes of one upload chunk: 12 × 320 KiB (Graph recommends at most 4 MB per PUT). */
    public const CHUNK_BYTES = 3_932_160;

    /** Largest MIME draft (headers + bodies), so its base64 request stays under 4 MB. */
    public const MAX_MIME_BYTES = 2_900_000;

    /**
     * @param GraphMailboxProviderInterface $mailboxes mailbox port (configured profiles or the host's)
     * @param GraphApiClient $api Graph client
     * @param GraphSendLimits $limits limits checked before any call
     */
    public function __construct(
        private readonly GraphMailboxProviderInterface $mailboxes,
        private readonly GraphApiClient $api,
        private readonly GraphSendLimits $limits = new GraphSendLimits(),
        private readonly ?LoggerInterface $logger = null,
    ) {
    }

    /**
     * Sends the e-mail from the profile's mailbox (null = the provider's default profile).
     *
     * @throws GraphException
     */
    public function send(GraphOutgoingMail $mail, ?string $profile = null): GraphSendResult
    {
        $mailbox = $this->mailboxes->mailbox($profile ?? $this->mailboxes->defaultProfile());
        $this->checkLimits($mail);
        $mime  = $this->mime($mail, $mailbox);
        $user  = GraphApiClient::userPath($mailbox);
        $draft = $this->api->call($mailbox, 'POST', $user . '/messages', [
            'headers' => ['Content-Type' => 'text/plain', 'Prefer' => 'IdType="ImmutableId"'],
            'body'    => base64_encode($mime['mime']),
        ], [201], 'create draft');
        $id = $draft['id'] ?? null;
        if (!is_string($id) || $id === '' || strlen($id) > 512) {
            throw new GraphException('Microsoft Graph create draft returned no message id.', GraphException::REASON_API, 201);
        }
        $messagePath = $user . '/messages/' . rawurlencode($id);

        try {
            if ($mail->bcc !== []) {
                $this->api->call($mailbox, 'PATCH', $messagePath, [
                    'headers' => ['Prefer' => 'IdType="ImmutableId"'],
                    'json'    => ['bccRecipients' => $this->recipientsJson($mail->bcc)],
                ], [200], 'set bcc');
            }
            foreach ($mail->attachments as $attachment) {
                $this->attach($mailbox, $messagePath, $attachment);
            }
        } catch (Throwable $exception) {
            // Before the send call: the message is certainly still a draft.
            $this->discardDraft($mailbox, $messagePath);

            throw $exception;
        }

        try {
            $this->api->call($mailbox, 'POST', $messagePath . '/send', [
                'headers' => ['Prefer' => 'IdType="ImmutableId"'],
                'body'    => '',
            ], [202], 'send');
        } catch (GraphException $exception) {
            if (!$this->ambiguous($exception)) {
                // A definite refusal (4xx): nothing was sent.
                $this->discardDraft($mailbox, $messagePath);

                throw $exception;
            }
            // Timeout, network error, 5xx, 429 / 503: Exchange may have sent it. With immutable ids the sent copy keeps
            // the draft's id, so deleting it blindly would move a sent message to Deleted Items (audit NK-1).
            $isDraft = $this->isDraft($mailbox, $messagePath);
            if ($isDraft === true) {
                $this->discardDraft($mailbox, $messagePath);

                throw $exception;
            }
            if ($isDraft === null) {
                $this->logger?->notice('Microsoft Graph send outcome unknown; the draft is kept and the send is not retried.', ['bundle' => 'NowoMailKitBundle', 'profile' => $mailbox->profile, 'status' => $exception->status]);

                throw GraphException::ambiguous($exception);
            }
            $this->logger?->notice('Microsoft Graph send call failed but the message was sent.', ['bundle' => 'NowoMailKitBundle', 'profile' => $mailbox->profile, 'status' => $exception->status]);
        }

        $internetId = is_string($draft['internetMessageId'] ?? null) && $draft['internetMessageId'] !== '' ? $draft['internetMessageId'] : '<' . $mime['messageId'] . '>';
        if ($internetId !== '<' . $mime['messageId'] . '>') {
            $this->logger?->notice('Microsoft Graph replaced the Message-ID of a draft; the reported internetMessageId is returned.', ['bundle' => 'NowoMailKitBundle', 'profile' => $mailbox->profile]);
        }
        $this->logger?->info('E-mail sent through Microsoft Graph.', [
            'bundle'      => 'NowoMailKitBundle',
            'profile'     => $mailbox->profile,
            'attachments' => count($mail->attachments),
        ]);

        return new GraphSendResult($id, $internetId, is_string($draft['conversationId'] ?? null) ? $draft['conversationId'] : null, $mailbox->profile);
    }

    private function checkLimits(GraphOutgoingMail $mail): void
    {
        if (count($mail->attachments) > $this->limits->maxAttachments) {
            throw GraphException::invalidMessage(sprintf('Too many attachments (at most %d).', $this->limits->maxAttachments));
        }
        $total = strlen((string) $mail->text) + strlen((string) $mail->html);
        foreach ($mail->attachments as $attachment) {
            if ($attachment->size > $this->limits->maxAttachmentBytes || $attachment->size > GraphSendLimits::GRAPH_MAX_ATTACHMENT_BYTES) {
                throw GraphException::invalidMessage(sprintf('An attachment exceeds the size limit (%d bytes).', $this->limits->maxAttachmentBytes));
            }
            $total += $attachment->size;
        }
        if ($total > $this->limits->maxMessageBytes) {
            throw GraphException::invalidMessage(sprintf('The e-mail exceeds the size limit (%d bytes).', $this->limits->maxMessageBytes));
        }
    }

    /**
     * @return array{mime: string, messageId: string}
     */
    private function mime(GraphOutgoingMail $mail, GraphMailbox $mailbox): array
    {
        $messageId = $mail->messageId ?? bin2hex(random_bytes(16)) . strrchr($mailbox->address, '@');

        try {
            $email = (new Email())->from(new Address($mailbox->address, $mail->fromName ?? ''))->subject($mail->subject);
            foreach ($mail->to as $recipient) {
                $email->addTo($this->address($recipient));
            }
            foreach ($mail->cc as $recipient) {
                $email->addCc($this->address($recipient));
            }
            foreach ($mail->replyTo as $recipient) {
                $email->addReplyTo($this->address($recipient));
            }
            if ($mail->text !== null) {
                $email->text($mail->text);
            }
            if ($mail->html !== null) {
                $email->html($mail->html);
            }
            $headers = $email->getHeaders();
            $headers->addIdHeader('Message-ID', $messageId);
            if ($mail->inReplyTo !== null) {
                $headers->addIdHeader('In-Reply-To', $mail->inReplyTo);
            }
            if ($mail->references !== []) {
                $headers->addIdHeader('References', $mail->references);
            }
            if ($mail->to === [] && $mail->cc === []) {
                // Bcc-only: Symfony Mime refuses to render a message without a To, Cc or Bcc header. Email::toString()
                // strips Bcc again, so the MIME carries no recipient; delivery relies on the PATCH of bccRecipients.
                $email->addBcc($this->address($mail->bcc[0]));
            }
            $mime = $email->toString();
        } catch (MimeException) {
            throw GraphException::invalidMessage('The e-mail could not be rendered as MIME (invalid address or message id).');
        }
        if (strlen($mime) > self::MAX_MIME_BYTES) {
            throw GraphException::invalidMessage(sprintf('The e-mail body is too large for Microsoft Graph (at most %d bytes of headers and bodies).', self::MAX_MIME_BYTES));
        }

        return ['mime' => $mime, 'messageId' => $messageId];
    }

    private function attach(GraphMailbox $mailbox, string $messagePath, GraphAttachment $attachment): void
    {
        $handle = $attachment->open();

        try {
            if ($attachment->size < self::LARGE_ATTACHMENT_BYTES) {
                $bytes = $this->read($handle, $attachment->size);
                $this->api->call($mailbox, 'POST', $messagePath . '/attachments', ['json' => [
                    '@odata.type'  => '#microsoft.graph.fileAttachment',
                    'name'         => $attachment->filename,
                    'contentType'  => $attachment->contentType,
                    'contentBytes' => base64_encode($bytes),
                    'isInline'     => $attachment->inline,
                ] + ($attachment->contentId !== null ? ['contentId' => $attachment->contentId] : [])], [201], 'add attachment');

                return;
            }

            $this->upload($mailbox, $messagePath, $attachment, $handle);
        } finally {
            $attachment->close($handle);
        }
    }

    /**
     * @param resource $handle
     */
    private function upload(GraphMailbox $mailbox, string $messagePath, GraphAttachment $attachment, mixed $handle): void
    {
        $session = $this->api->call($mailbox, 'POST', $messagePath . '/attachments/createUploadSession', ['json' => ['AttachmentItem' => [
            'attachmentType' => 'file',
            'name'           => $attachment->filename,
            'size'           => $attachment->size,
            'contentType'    => $attachment->contentType,
            'isInline'       => $attachment->inline,
        ] + ($attachment->contentId !== null ? ['contentId' => $attachment->contentId] : [])]], [201], 'create upload session');
        $url = $session['uploadUrl'] ?? null;
        if (!is_string($url)) {
            throw new GraphException('Microsoft Graph create upload session returned no upload URL.', GraphException::REASON_API, 201);
        }
        $this->api->assertUploadUrl($url);

        $status = 0;

        try {
            $offset = 0;
            while ($offset < $attachment->size) {
                $chunk  = $this->read($handle, min(self::CHUNK_BYTES, $attachment->size - $offset));
                $status = $this->api->uploadChunk($mailbox, $url, $chunk, $offset, $attachment->size);
                $offset += strlen($chunk);
                if ($status === 201 && $offset < $attachment->size) {
                    throw new GraphException('Microsoft Graph attachment upload ended before the last chunk.', GraphException::REASON_API, 201);
                }
            }
            if ($status !== 201) {
                throw new GraphException('Microsoft Graph attachment upload did not complete.', GraphException::REASON_API, $status);
            }
        } catch (GraphException $exception) {
            $this->api->cancelUpload($mailbox, $url);

            throw $exception;
        }
    }

    /**
     * Reads exactly `$length` bytes (a shorter stream is an error: the declared size was wrong).
     *
     * @param resource $handle
     */
    private function read(mixed $handle, int $length): string
    {
        $data = '';
        while (strlen($data) < $length) {
            $part = fread($handle, max(1, $length - strlen($data)));
            if ($part === false || $part === '') {
                throw GraphException::invalidMessage('An attachment is shorter than its declared size.');
            }
            $data .= $part;
        }

        return $data;
    }

    /**
     * A send failure that does not prove the message stayed unsent.
     */
    private function ambiguous(GraphException $exception): bool
    {
        return $exception->reason === GraphException::REASON_TRANSPORT
            || $exception->reason === GraphException::REASON_THROTTLED
            || ($exception->status !== null && $exception->status >= 500);
    }

    /**
     * `isDraft` of the message after an ambiguous send: true / false, or null when it cannot be read.
     */
    private function isDraft(GraphMailbox $mailbox, string $messagePath): ?bool
    {
        try {
            $state = $this->api->call($mailbox, 'GET', $messagePath . '?$select=isDraft', ['headers' => ['Prefer' => 'IdType="ImmutableId"']], [200], 'read draft state');
        } catch (GraphException) {
            return null;
        }
        $isDraft = $state['isDraft'] ?? null;

        return is_bool($isDraft) ? $isDraft : null;
    }

    private function discardDraft(GraphMailbox $mailbox, string $messagePath): void
    {
        try {
            $this->api->call($mailbox, 'DELETE', $messagePath, [], [204, 404], 'delete draft');
        } catch (GraphException) {
            $this->logger?->notice('Microsoft Graph draft of a failed send could not be deleted.', ['bundle' => 'NowoMailKitBundle', 'profile' => $mailbox->profile]);
        }
    }

    private function address(GraphRecipient $recipient): Address
    {
        return new Address($recipient->address, $recipient->name ?? '');
    }

    /**
     * @param list<GraphRecipient> $recipients
     *
     * @return list<array{emailAddress: array{address: string, name?: string}}>
     */
    private function recipientsJson(array $recipients): array
    {
        $list = [];
        foreach ($recipients as $recipient) {
            $list[] = ['emailAddress' => ['address' => $recipient->address] + ($recipient->name !== null ? ['name' => $recipient->name] : [])];
        }

        return $list;
    }
}
