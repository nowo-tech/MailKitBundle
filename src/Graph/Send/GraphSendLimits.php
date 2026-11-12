<?php

declare(strict_types=1);

namespace Nowo\MailKitBundle\Graph\Send;

/**
 * Limits of one Graph send (1.8, `nowo_mail_kit.graph.limits`), checked before any call to Graph.
 */
final class GraphSendLimits
{
    /** Default largest attachment and message: 35 MiB (Exchange Online default message size limit). */
    public const DEFAULT_MAX_BYTES = 36_700_160;

    /** Graph maximum of one attachment (upload session): 150 MiB. */
    public const GRAPH_MAX_ATTACHMENT_BYTES = 157_286_400;

    /**
     * @param int $maxAttachments attachments per message
     * @param int $maxAttachmentBytes bytes of one attachment
     * @param int $maxMessageBytes bytes of all attachments plus the MIME body
     */
    public function __construct(
        public readonly int $maxAttachments = 50,
        public readonly int $maxAttachmentBytes = self::DEFAULT_MAX_BYTES,
        public readonly int $maxMessageBytes = self::DEFAULT_MAX_BYTES,
    ) {
    }
}
