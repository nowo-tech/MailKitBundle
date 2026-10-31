<?php

declare(strict_types=1);

namespace Nowo\MailKitBundle\Sender;

use Nowo\MailKitBundle\Settings\MailSettings;
use Symfony\Component\Mime\Address;
use Symfony\Component\Mime\Message;
use Symfony\Component\Mime\RawMessage;

/**
 * Fills the runtime sender of outgoing messages: `From` (settings `mailer.from` / `mailer.from_name`, or
 * `resend.from` when Resend is active) and `Reply-To` (`mailer.reply_to`) — only when the message has none, so
 * code that sets its own sender keeps it.
 */
final readonly class SenderPolicy
{
    /**
     * @param MailSettings $settings typed view of the mail settings
     */
    public function __construct(
        private MailSettings $settings,
    ) {
    }

    /**
     * Adds the missing From / Reply-To headers in place (raw, non-MIME messages are left untouched).
     */
    public function apply(RawMessage $message): void
    {
        if (!$message instanceof Message) {
            return;
        }
        $headers = $message->getHeaders();
        if (!$headers->has('From')) {
            $from = $this->settings->from();
            if ($from instanceof Address) {
                $headers->addMailboxListHeader('From', [$from]);
            }
        }
        if (!$headers->has('Reply-To')) {
            $replyTo = $this->settings->replyTo();
            if ($replyTo instanceof Address) {
                $headers->addMailboxListHeader('Reply-To', [$replyTo]);
            }
        }
    }
}
