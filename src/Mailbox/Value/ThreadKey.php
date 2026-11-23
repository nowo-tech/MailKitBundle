<?php

declare(strict_types=1);

namespace Nowo\MailKitBundle\Mailbox\Value;

use InvalidArgumentException;

use function preg_match;

/**
 * The thread a synced message belongs to (2.5.0, design section 12, Q-MB-10), in this order:
 *
 * 1. the provider's thread id (Graph `conversationId`): `provider:graph:<id>`;
 * 2. the root of `References` (its first id): `msgid:<id>`;
 * 3. `In-Reply-To`: `msgid:<id>`;
 * 4. the message's own `Message-ID`: `msgid:<id>` (a new thread);
 * 5. without any of them, the provider message id: `provider:graph:message:<id>`.
 *
 * Joins across providers by header ids are the host's (it has the storage): {@see SyncedMessage} also carries the
 * Message-ID, In-Reply-To and References.
 */
final readonly class ThreadKey
{
    /** Accepted form of a key. */
    public const PATTERN = '/^(?:provider:[a-z]+:(?:message:)?[A-Za-z0-9+\/=_-]{1,512}|msgid:[^<>\s]{1,500})\z/';

    /**
     * @throws InvalidArgumentException when the value is not a thread key
     */
    public function __construct(public string $value)
    {
        if (preg_match(self::PATTERN, $value) !== 1) {
            throw new InvalidArgumentException('Not a thread key.');
        }
    }

    /**
     * The key of a message (see the class description for the order).
     *
     * @param string|null $providerThreadId provider thread id (Graph conversationId), when valid
     * @param list<string> $references References ids without angle brackets, oldest first
     * @param string|null $inReplyTo In-Reply-To id without angle brackets
     * @param string $internetMessageId Message-ID without angle brackets ('' when unknown)
     */
    public static function for(MailboxProvider $provider, ?string $providerThreadId, array $references, ?string $inReplyTo, string $internetMessageId, ProviderMessageId $message): self
    {
        if ($providerThreadId !== null && preg_match('/^[A-Za-z0-9+\/=_-]{1,512}\z/', $providerThreadId) === 1) {
            return new self('provider:' . $provider->value . ':' . $providerThreadId);
        }
        foreach ([$references[0] ?? null, $inReplyTo, $internetMessageId] as $id) {
            if ($id !== null && preg_match('/^[^<>\s]{1,500}\z/', $id) === 1) {
                return new self('msgid:' . $id);
            }
        }

        return new self('provider:' . $provider->value . ':message:' . $message->value);
    }
}
