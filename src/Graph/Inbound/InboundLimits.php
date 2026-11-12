<?php

declare(strict_types=1);

namespace Nowo\MailKitBundle\Graph\Inbound;

use function fnmatch;
use function strtolower;

/**
 * Limits of the inbound pipeline (1.9, `nowo_mail_kit.graph.inbound.limits`).
 */
final class InboundLimits
{
    /** Default accepted attachment types (detected by content, `*` wildcards). */
    public const DEFAULT_ALLOWED_TYPES = [
        'application/pdf',
        'image/png',
        'image/jpeg',
        'image/gif',
        'image/webp',
        'text/plain',
        'text/csv',
        'application/zip',
        'application/msword',
        'application/vnd.ms-excel',
        'application/vnd.ms-powerpoint',
        'application/vnd.openxmlformats-officedocument.*',
        'application/vnd.oasis.opendocument.*',
    ];

    /**
     * @param int $maxMessageBytes bytes of the Graph message JSON (headers + body)
     * @param int $maxAttachments attachments read per message (the rest are reported as rejected)
     * @param int $maxAttachmentBytes bytes of one attachment
     * @param int $maxTotalAttachmentBytes bytes of all attachments of one message
     * @param int $maxHtmlBytes bytes of the HTML body passed to the sanitizer (longer: cut)
     * @param list<string> $allowedTypes detected content types accepted (`fnmatch` patterns, lower-case)
     */
    public function __construct(
        public readonly int $maxMessageBytes = 4_194_304,
        public readonly int $maxAttachments = 20,
        public readonly int $maxAttachmentBytes = 26_214_400,
        public readonly int $maxTotalAttachmentBytes = 52_428_800,
        public readonly int $maxHtmlBytes = 1_048_576,
        public readonly array $allowedTypes = self::DEFAULT_ALLOWED_TYPES,
    ) {
    }

    /**
     * True when a detected content type matches an allowed pattern.
     */
    public function allows(string $contentType): bool
    {
        $contentType = strtolower($contentType);
        foreach ($this->allowedTypes as $pattern) {
            if (fnmatch($pattern, $contentType)) {
                return true;
            }
        }

        return false;
    }
}
