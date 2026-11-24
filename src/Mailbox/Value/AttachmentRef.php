<?php

declare(strict_types=1);

namespace Nowo\MailKitBundle\Mailbox\Value;

use Nowo\MailKitBundle\Mailbox\Service\MailboxAttachmentReaderInterface;

/**
 * An attachment of a synced message, without its content (2.5.0): provider id, kind, neutralised file name, declared
 * content type (validated `type/subtype`, else `application/octet-stream`), declared size, inline flag and the
 * Content-ID an inline image is referenced by (`cid:`). Only {@see AttachmentKind::File} is downloadable.
 */
final readonly class AttachmentRef
{
    /**
     * @param string $filename neutralised name (no path, no control or bidi characters)
     * @param string $contentType declared type (not trusted: the reader detects the type from the bytes)
     * @param int $size declared size in bytes (0 when unknown)
     * @param string|null $contentId Content-ID without angle brackets, for inline parts
     */
    public function __construct(
        public ProviderAttachmentId $id,
        public AttachmentKind $kind,
        public string $filename,
        public string $contentType,
        public int $size,
        public bool $inline,
        public ?string $contentId,
    ) {
    }

    /**
     * True when {@see MailboxAttachmentReaderInterface::open()} can read it.
     */
    public function isDownloadable(): bool
    {
        return $this->kind === AttachmentKind::File;
    }
}
