<?php

declare(strict_types=1);

namespace Nowo\MailKitBundle\Graph\Inbound;

use LogicException;

use function fclose;
use function is_resource;
use function rewind;

/**
 * An attachment of an inbound message (1.9). An **accepted** attachment has its bytes in a temporary stream
 * (capped, read-only for the host, deleted when MailKit finishes the message: copy it inside
 * {@see GraphInboundHandlerInterface::handle()}); its content type is the one **detected** from the bytes (finfo),
 * never the declared one. A **rejected** attachment has no stream and a reason: `type` (detected type not allowed),
 * `size`, `count`, `total_size`, `unsupported` (item / reference attachment, e.g. an attached e-mail) or `extension` (a dangerous extension such as `.html`,
 * `.svg`, `.js`, `.exe`, see {@see InboundFileTypes::DANGEROUS_EXTENSIONS}).
 *
 * Store and serve accepted files under {@see $safeFilename} (extension consistent with the detected type), with the
 * detected `Content-Type`, `Content-Disposition: attachment` and `X-Content-Type-Options: nosniff`. ZIP archives and
 * legacy Office files (when a host allows them) are not scanned inside.
 *
 * File names are neutralised (no directory part, control or bidi characters).
 */
final class InboundAttachment
{
    /** Rejection: the detected content type is not allowed. */
    public const REJECTED_TYPE = 'type';

    /** Rejection: larger than `max_attachment_bytes`. */
    public const REJECTED_SIZE = 'size';

    /** Rejection: beyond `max_attachments`. */
    public const REJECTED_COUNT = 'count';

    /** Rejection: the message's attachments exceed `max_total_attachment_bytes`. */
    public const REJECTED_TOTAL_SIZE = 'total_size';

    /** Rejection: not a file attachment (an attached e-mail, a cloud link). */
    public const REJECTED_UNSUPPORTED = 'unsupported';

    /** Rejection: a dangerous extension (`.html`, `.svg`, `.js`, `.exe`…), whatever the bytes (audit NQ-L3). */
    public const REJECTED_EXTENSION = 'extension';

    /**
     * @param string $filename neutralised file name
     * @param string $declaredContentType content type declared by the sender (informative only)
     * @param string|null $contentType content type detected from the bytes (accepted attachments)
     * @param int $size bytes (downloaded, or as declared by Graph when rejected)
     * @param resource|null $stream temporary stream of an accepted attachment
     * @param string|null $rejection one of the REJECTED_* reasons, null when accepted
     * @param string|null $safeFilename name with the extension of the detected type (accepted attachments)
     */
    public function __construct(
        public readonly string $filename,
        public readonly string $declaredContentType,
        public readonly ?string $contentType,
        public readonly int $size,
        public readonly bool $inline,
        public readonly ?string $contentId,
        private readonly mixed $stream,
        public readonly ?string $rejection,
        public readonly ?string $safeFilename = null,
    ) {
    }

    /**
     * True when the bytes are available.
     */
    public function isAccepted(): bool
    {
        return $this->rejection === null;
    }

    /**
     * The bytes, from the start (accepted attachments, until MailKit finishes the message).
     *
     * @return resource
     */
    public function stream(): mixed
    {
        if ($this->rejection !== null || !is_resource($this->stream)) {
            throw new LogicException('This attachment was rejected or already released: it has no content.');
        }
        rewind($this->stream);

        return $this->stream;
    }

    /**
     * Closes the temporary stream (MailKit calls it when the message is finished).
     */
    public function release(): void
    {
        if (is_resource($this->stream)) {
            fclose($this->stream);
        }
    }

    /**
     * Serialization is refused (temporary streams; copy the bytes in the handler).
     *
     * @return array<string, mixed>
     */
    public function __serialize(): array
    {
        throw new LogicException('An inbound attachment cannot be serialized; copy its stream in the handler.');
    }
}
