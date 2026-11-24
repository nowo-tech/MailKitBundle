<?php

declare(strict_types=1);

namespace Nowo\MailKitBundle\Mailbox\Value;

use DateTimeImmutable;
use InvalidArgumentException;
use LogicException;
use Nowo\MailKitBundle\Mailbox\Port\MailboxTokenProviderInterface;
use SensitiveParameter;
use SensitiveParameterValue;

use function assert;
use function hash;
use function is_string;
use function preg_match;
use function strlen;
use function substr;

/**
 * A current access token of one mailbox, handed over by the host's token port (2.5.0). MailKit puts it only in the
 * `Authorization` header of calls to the provider's pinned API host and keeps it for one Messenger message at most:
 * never logged, persisted, cached or put in an exception. Dumps show `[redacted]`; serialisation is refused.
 *
 * The value must be an RFC 6750 bearer token (letters, digits, `-._~+/`, trailing `=`), at most 16 KiB, so it can
 * never inject a header.
 */
final class MailboxAccessToken
{
    /** Longest accepted token. */
    public const MAX_LENGTH = 16_384;

    /** The token, wrapped so that var_export(), (array) casts and reflection dumps never show it (audit ZAT-I1). */
    private readonly SensitiveParameterValue $value;

    /**
     * @param string $value the bearer token
     * @param DateTimeImmutable $expiresAt expiry the host knows (informational; MailKit asks again per message)
     * @param list<string> $scopes granted scopes, when the host knows them (informational)
     *
     * @throws InvalidArgumentException when the value is not a bearer token
     */
    public function __construct(
        #[SensitiveParameter]
        string $value,
        public readonly DateTimeImmutable $expiresAt,
        public readonly array $scopes = [],
    ) {
        if (strlen($value) > self::MAX_LENGTH || preg_match('/^[A-Za-z0-9\-._~+\/]+=*\z/', $value) !== 1) {
            throw new InvalidArgumentException('The mailbox access token is not a bearer token (RFC 6750 characters, at most 16 KiB).');
        }
        $this->value = new SensitiveParameterValue($value);
    }

    /**
     * The token itself, for the Authorization header only.
     */
    public function reveal(): string
    {
        $value = $this->value->getValue();
        assert(is_string($value));

        return $value;
    }

    /**
     * First 12 hex characters of the token's SHA-256: names the token in {@see MailboxTokenProviderInterface::rejected()}
     * without revealing it.
     */
    public function fingerprint(): string
    {
        return substr(hash('sha256', $this->reveal()), 0, 12);
    }

    /**
     * Debug output without the value.
     *
     * @return array{value: string, expiresAt: DateTimeImmutable, scopes: list<string>}
     */
    public function __debugInfo(): array
    {
        return ['value' => '[redacted]', 'expiresAt' => $this->expiresAt, 'scopes' => $this->scopes];
    }

    /**
     * Serialisation is refused (a token must never reach a cache, a queue or a log).
     *
     * @return array<string, mixed>
     */
    public function __serialize(): array
    {
        throw new LogicException('A mailbox access token cannot be serialized.');
    }

    /**
     * Unserialisation is refused.
     *
     * @param array<string, mixed> $data
     */
    public function __unserialize(array $data): void
    {
        throw new LogicException('A mailbox access token cannot be unserialized.');
    }
}
