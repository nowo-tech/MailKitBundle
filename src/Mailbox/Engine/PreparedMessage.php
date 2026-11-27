<?php

declare(strict_types=1);

namespace Nowo\MailKitBundle\Mailbox\Engine;

use LogicException;

/**
 * A message built for a send-only provider (phase B2): the RFC 5322 bytes and their Message-ID (without angle
 * brackets). Held in memory for one send() call only; never logged, queued or serialised.
 *
 * @internal
 */
final readonly class PreparedMessage
{
    /**
     * @param string $mime the whole message, Bcc header included (the provider strips it)
     * @param string $messageId Message-ID without angle brackets
     */
    public function __construct(
        public string $mime,
        public string $messageId,
    ) {
    }

    /**
     * Debug output without the message.
     *
     * @return array{mime: string, messageId: string}
     */
    public function __debugInfo(): array
    {
        return ['mime' => '[redacted]', 'messageId' => $this->messageId];
    }

    /**
     * Serialisation is refused (the message holds personal data).
     *
     * @return array<string, mixed>
     */
    public function __serialize(): array
    {
        throw new LogicException('A prepared mailbox message cannot be serialized.');
    }
}
