<?php

declare(strict_types=1);

namespace Nowo\MailKitBundle\Graph\Inbound;

use DateTimeImmutable;

/**
 * One inbound message, normalised (1.9). Everything here comes from the sender and must be treated as untrusted:
 * escape the text and names on output; `$html` is already sanitised (symfony/html-sanitizer: no scripts, event
 * handlers, forms, iframes or styles with URLs; links http(s) / mailto only; images `cid:` only unless remote images
 * are allowed) but still belongs in a sandboxed / CSP-restricted view.
 *
 * Threading: `$internetMessageId`, `$inReplyTo`, `$references` (oldest first, without angle brackets) and the Outlook
 * `$conversationId`. Routing: `$plusTags` (tags of the receiving mailbox found in To / Cc and in envelope headers).
 */
final class GraphInboundMessage
{
    /**
     * @param string $profile mailbox profile
     * @param string $graphId immutable Graph id
     * @param string $internetMessageId Message-ID without angle brackets
     * @param list<string> $references References, oldest first
     * @param list<InboundAddress> $replyTo
     * @param list<InboundAddress> $to
     * @param list<InboundAddress> $cc
     * @param string $text plain text (from the text body, or derived from the sanitised HTML)
     * @param string|null $html sanitised HTML, or null for a text-only message
     * @param list<InboundAttachment> $attachments accepted and rejected attachments
     * @param list<string> $plusTags distinct plus-address tags of the mailbox (To, Cc, envelope headers)
     * @param bool $forwarded the message looks forwarded (subject prefix, attached e-mail, forward markers)
     * @param bool $oversized over `limits.max_message_bytes`: no body, headers, threading headers, authentication
     *                        results or attachments were read, and no recipients when even the envelope is huge
     *                        (quarantine it, or fetch it yourself)
     */
    public function __construct(
        public readonly string $profile,
        public readonly string $graphId,
        public readonly string $internetMessageId,
        public readonly ?string $inReplyTo,
        public readonly array $references,
        public readonly ?string $conversationId,
        public readonly ?InboundAddress $from,
        public readonly ?InboundAddress $sender,
        public readonly array $replyTo,
        public readonly array $to,
        public readonly array $cc,
        public readonly string $subject,
        public readonly string $text,
        public readonly ?string $html,
        public readonly ?DateTimeImmutable $receivedAt,
        public readonly array $attachments,
        public readonly AuthenticationResults $authentication,
        public readonly bool $forwarded,
        public readonly array $plusTags,
        public readonly bool $oversized = false,
    ) {
    }
}
