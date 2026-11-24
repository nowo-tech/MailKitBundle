<?php

declare(strict_types=1);

namespace Nowo\MailKitBundle\Mailbox\Engine;

/**
 * Configured limits of the delegated-mailbox send path (2.5.0, `nowo_mail_kit.mailboxes.send`). Immutable.
 *
 * @internal
 */
final readonly class MailboxSendOptions
{
    /**
     * @param int $staleClaimSeconds `send.stale_claim_seconds`: a claim without progress for this long may be taken over
     *                               (longer than a provider call can last, retry included: more than 8 × `timeout`)
     * @param string $retention `send.retention` (ISO 8601): settled rows are purged after it
     * @param int $maxRecipients `send.max_recipients`
     * @param int $maxAttachments `send.max_attachments`
     * @param int $maxAttachmentBytes `send.max_attachment_bytes`
     * @param int $maxMessageBytes `send.max_message_bytes` (bodies plus attachments)
     * @param int $maxHtmlBytes `send.max_html_bytes`
     * @param int $maxTextBytes `send.max_text_bytes`
     * @param int $maxAttempts `send.max_attempts`: reconcile takeovers of one key before it is `unknown`
     * @param int $sentCopyDelayMs `send.sent_copy.delay_ms`
     * @param int $sentCopyAttempts `send.sent_copy.attempts`
     */
    public function __construct(
        public int $staleClaimSeconds = 300,
        public string $retention = 'P7D',
        public int $maxRecipients = 100,
        public int $maxAttachments = 50,
        public int $maxAttachmentBytes = 36_700_160,
        public int $maxMessageBytes = 36_700_160,
        public int $maxHtmlBytes = 1_048_576,
        public int $maxTextBytes = 1_048_576,
        public int $maxAttempts = 5,
        public int $sentCopyDelayMs = 5000,
        public int $sentCopyAttempts = 3,
    ) {
    }
}
