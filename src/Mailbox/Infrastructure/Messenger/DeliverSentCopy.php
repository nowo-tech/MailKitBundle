<?php

declare(strict_types=1);

namespace Nowo\MailKitBundle\Mailbox\Infrastructure\Messenger;

/**
 * Fetch a message a delegated mailbox just sent and hand it to the sink as `MessageOrigin::SentCopy` (2.5.0 part 2,
 * Q-MB-6), whether or not Sent Items is followed. Enqueued with a short delay after each send; tried again a few times
 * (`send.sent_copy.attempts`) while the provider has not filed it yet. Ids only. Route it to an async transport.
 */
final readonly class DeliverSentCopy
{
    /**
     * @param string $mailboxId the host's mailbox id
     * @param string $messageId provider id of the sent message (the draft's immutable id)
     * @param int $attempt delayed re-enqueues so far (bounded by the handler)
     */
    public function __construct(
        public string $mailboxId,
        public string $messageId,
        public int $attempt = 0,
    ) {
    }
}
