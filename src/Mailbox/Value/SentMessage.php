<?php

declare(strict_types=1);

namespace Nowo\MailKitBundle\Mailbox\Value;

use DateTimeImmutable;

/**
 * The result of a delegated-mailbox send (2.5.0, design section 5.3): the provider ids of the sent message (Graph
 * immutable ids survive the move to Sent Items, so `$id` is the sent copy's id too), its Message-ID (without angle
 * brackets; '' when the provider gave none yet: the sent copy delivered to the sink carries it), the provider thread,
 * the thread key and when MailKit recorded the send. `$replayed` is true when the idempotency key had already been
 * sent: nothing was sent by this call.
 */
final readonly class SentMessage
{
    /**
     * @param string $internetMessageId Message-ID without angle brackets, or '' when unknown
     * @param string|null $providerThreadId provider thread id (Graph conversationId)
     * @param bool $replayed the key was already sent; this call sent nothing
     */
    public function __construct(
        public ProviderMessageId $id,
        public string $internetMessageId,
        public ?string $providerThreadId,
        public ThreadKey $threadKey,
        public DateTimeImmutable $sentAt,
        public bool $replayed,
    ) {
    }
}
