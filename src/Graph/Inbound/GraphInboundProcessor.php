<?php

declare(strict_types=1);

namespace Nowo\MailKitBundle\Graph\Inbound;

use finfo;
use Nowo\MailKitBundle\Graph\GraphApiClient;
use Nowo\MailKitBundle\Graph\GraphException;
use Nowo\MailKitBundle\Graph\GraphMailbox;
use Nowo\MailKitBundle\Graph\GraphMailboxProviderInterface;
use Nowo\MailKitBundle\Graph\Inbound\Store\GraphInboundStore;
use Nowo\MailKitBundle\Graph\Send\AttachmentFilename;
use Nowo\MailKitBundle\Graph\Send\GraphAttachment;
use Psr\Log\LoggerInterface;
use Throwable;

use function assert;
use function base64_decode;
use function count;
use function fclose;
use function fopen;
use function fread;
use function fwrite;
use function is_array;
use function is_bool;
use function is_numeric;
use function is_resource;
use function is_string;
use function preg_match;
use function rawurlencode;
use function rewind;
use function strlen;
use function strtolower;
use function trim;

use const FILEINFO_MIME_TYPE;

/**
 * Processes one inbound Graph message (1.9): fetches it (capped), claims it by internetMessageId (idempotency), reads
 * its attachments into capped temporary streams (type detected by content, names neutralised, limits applied),
 * normalises it, asks the host port for a decision and records it. Temporary streams are closed when the call ends.
 * A host exception releases the claim and is rethrown (Messenger retries).
 *
 * Stateless (FrankenPHP worker mode); logs carry the profile, the decision and counts — never addresses, subjects,
 * bodies or file names.
 */
final class GraphInboundProcessor
{
    /** Graph message / attachment ids accepted in a path. */
    public const ID_PATTERN = '/^[A-Za-z0-9+\/=_-]{1,512}\z/';

    /** Fields read from Graph. */
    public const SELECT = 'id,internetMessageId,conversationId,subject,from,sender,replyTo,toRecipients,ccRecipients,receivedDateTime,body,hasAttachments,internetMessageHeaders';

    /** Bytes of an attachment read for the content type detection. */
    private const SNIFF_BYTES = 65_536;

    /**
     * @param int $staleClaimSeconds a `processing` claim older than this is taken over (crashed worker)
     */
    public function __construct(
        private readonly GraphMailboxProviderInterface $mailboxes,
        private readonly GraphApiClient $api,
        private readonly GraphInboundStore $store,
        private readonly GraphInboundHandlerInterface $handler,
        private readonly GraphMessageNormalizer $normalizer,
        private readonly InboundLimits $limits = new InboundLimits(),
        private readonly int $staleClaimSeconds = 900,
        private readonly ?LoggerInterface $logger = null,
    ) {
    }

    /**
     * Processes a message; null when it is gone, a duplicate or already being processed.
     *
     * @throws GraphException
     */
    public function process(string $profile, string $graphMessageId): ?InboundDecision
    {
        if (preg_match(self::ID_PATTERN, $graphMessageId) !== 1) {
            $this->logger?->warning('Microsoft Graph inbound message id refused.', ['bundle' => 'NowoMailKitBundle', 'profile' => $profile]);

            return null;
        }
        $mailbox = $this->mailboxes->mailbox($profile);
        $path    = GraphApiClient::userPath($mailbox) . '/messages/' . rawurlencode($graphMessageId);

        try {
            $data = $this->api->call($mailbox, 'GET', $path . '?$select=' . self::SELECT, [
                'headers' => ['Prefer' => 'outlook.body-content-type="html", IdType="ImmutableId"'],
            ], [200], 'read message', $this->limits->maxMessageBytes);
        } catch (GraphException $exception) {
            if ($exception->status === 404) {
                $this->logger?->info('Microsoft Graph inbound message gone before it was read.', ['bundle' => 'NowoMailKitBundle', 'profile' => $profile]);

                return null;
            }

            throw $exception;
        }

        $internetMessageId = trim(is_string($data['internetMessageId'] ?? null) ? $data['internetMessageId'] : '', '<> ');
        $key               = $internetMessageId !== '' ? $internetMessageId : 'graph:' . $graphMessageId;
        if (!$this->store->claim($profile, $key, $this->staleClaimSeconds)) {
            $this->logger?->info('Microsoft Graph inbound message already processed.', ['bundle' => 'NowoMailKitBundle', 'profile' => $profile]);

            return null;
        }

        $attachments = [];

        try {
            $attachedMessage = false;
            if (($data['hasAttachments'] ?? false) === true) {
                [$attachments, $attachedMessage] = $this->attachments($mailbox, $path);
            }
            $message  = $this->normalizer->normalize($profile, $mailbox->address, $data, $attachments, $attachedMessage);
            $decision = $this->handler->handle($message);
            $this->store->complete($profile, $key, $decision->action);
        } catch (Throwable $exception) {
            $this->store->release($profile, $key);
            $this->logger?->warning('Microsoft Graph inbound message not processed; it will be retried.', ['bundle' => 'NowoMailKitBundle', 'profile' => $profile, 'error' => $exception::class]);

            throw $exception;
        } finally {
            foreach ($attachments as $attachment) {
                $attachment->release();
            }
        }

        $rejected = 0;
        foreach ($attachments as $attachment) {
            $rejected += $attachment->isAccepted() ? 0 : 1;
        }
        $this->logger?->info('Microsoft Graph inbound message processed.', [
            'bundle'      => 'NowoMailKitBundle',
            'profile'     => $profile,
            'decision'    => $decision->action->value,
            'attachments' => count($attachments),
            'rejected'    => $rejected,
        ]);

        return $decision;
    }

    /**
     * @return array{0: list<InboundAttachment>, 1: bool} attachments and whether an e-mail was attached
     */
    private function attachments(GraphMailbox $mailbox, string $path): array
    {
        $list = $this->api->call($mailbox, 'GET', $path . '/attachments?$select=id,name,contentType,size,isInline', [
            'headers' => ['Prefer' => 'IdType="ImmutableId"'],
        ], [200], 'list attachments');
        $attachments     = [];
        $attachedMessage = false;
        $total           = 0;
        foreach (is_array($list['value'] ?? null) ? $list['value'] : [] as $item) {
            if (!is_array($item)) {
                continue;
            }
            $name     = AttachmentFilename::neutralise(is_string($item['name'] ?? null) ? $item['name'] : null);
            $declared = strtolower(trim(is_string($item['contentType'] ?? null) ? $item['contentType'] : ''));
            $declared = preg_match('#^[a-z0-9][a-z0-9!\#$&^_.+-]{0,63}/[a-z0-9][a-z0-9!\#$&^_.+-]{0,126}\z#', $declared) === 1 ? $declared : 'application/octet-stream';
            $size     = is_numeric($item['size'] ?? null) ? (int) $item['size'] : 0;
            $inline   = is_bool($item['isInline'] ?? null) && $item['isInline'];
            $type     = is_string($item['@odata.type'] ?? null) ? $item['@odata.type'] : '';
            $id       = is_string($item['id'] ?? null) ? $item['id'] : '';

            $rejection = match (true) {
                $type !== '#microsoft.graph.fileAttachment' || preg_match(self::ID_PATTERN, $id) !== 1 => InboundAttachment::REJECTED_UNSUPPORTED,
                count($attachments) >= $this->limits->maxAttachments                                   => InboundAttachment::REJECTED_COUNT,
                $size > $this->limits->maxAttachmentBytes                                              => InboundAttachment::REJECTED_SIZE,
                $total + $size > $this->limits->maxTotalAttachmentBytes                                => InboundAttachment::REJECTED_TOTAL_SIZE,
                default                                                                                => null,
            };
            if ($type === '#microsoft.graph.itemAttachment') {
                $attachedMessage = true;
            }
            if ($rejection !== null) {
                $attachments[] = new InboundAttachment($name, $declared, null, $size, $inline, null, null, $rejection);

                continue;
            }

            $attachment    = $this->download($mailbox, $path . '/attachments/' . rawurlencode($id), $name, $declared, $inline);
            $attachments[] = $attachment;
            $total += $attachment->size;
        }

        return [$attachments, $attachedMessage];
    }

    private function download(GraphMailbox $mailbox, string $path, string $name, string $declared, bool $inline): InboundAttachment
    {
        $stream = fopen('php://temp/maxmemory:262144', 'w+');
        assert(is_resource($stream));
        $contentId = null;

        try {
            if ($inline) {
                // Inline parts: the JSON carries the Content-ID the HTML refers to (`cid:`); inline images are small.
                $json  = $this->api->call($mailbox, 'GET', $path, ['headers' => ['Prefer' => 'IdType="ImmutableId"']], [200], 'read attachment', (int) ($this->limits->maxAttachmentBytes * 1.4) + 65_536);
                $bytes = base64_decode(is_string($json['contentBytes'] ?? null) ? $json['contentBytes'] : '', true);
                if ($bytes === false || strlen($bytes) > $this->limits->maxAttachmentBytes) {
                    fclose($stream);

                    return new InboundAttachment($name, $declared, null, 0, true, null, null, InboundAttachment::REJECTED_UNREADABLE);
                }
                fwrite($stream, $bytes);
                $cid       = is_string($json['contentId'] ?? null) ? trim($json['contentId'], '<> ') : '';
                $contentId = preg_match(GraphAttachment::CONTENT_ID_PATTERN, $cid) === 1 ? $cid : null;
                $size      = strlen($bytes);
            } else {
                $size = $this->api->download($mailbox, $path . '/$value', $stream, $this->limits->maxAttachmentBytes, 'read attachment');
            }
        } catch (GraphException $exception) {
            fclose($stream);
            if ($exception->reason === GraphException::REASON_TOO_LARGE) {
                return new InboundAttachment($name, $declared, null, 0, $inline, null, null, InboundAttachment::REJECTED_SIZE);
            }

            throw $exception;
        }

        rewind($stream);
        $detected = (string) (new finfo(FILEINFO_MIME_TYPE))->buffer((string) fread($stream, self::SNIFF_BYTES));
        if (!$this->limits->allows($detected)) {
            fclose($stream);

            return new InboundAttachment($name, $declared, $detected, $size, $inline, $contentId, null, InboundAttachment::REJECTED_TYPE);
        }

        return new InboundAttachment($name, $declared, $detected, $size, $inline, $contentId, $stream, null);
    }
}
