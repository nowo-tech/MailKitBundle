<?php

declare(strict_types=1);

namespace Nowo\MailKitBundle\Graph;

use LogicException;
use SensitiveParameter;

use function hash;
use function trim;

/**
 * Client secret of a Microsoft Entra app registration (1.8). Never printed: `var_dump()` / `print_r()` / exception
 * traces show `[redacted]`, string casting is not supported and serialization is refused, so the value cannot reach a
 * cache, the Messenger transport or a log by accident. Read it only with {@see reveal()} when building the token request.
 */
final class GraphSecret
{
    private readonly string $value;

    /**
     * @param string $value the client secret (non-blank)
     */
    public function __construct(#[SensitiveParameter] string $value)
    {
        if (trim($value) === '') {
            throw GraphException::configuration('The Microsoft Graph client secret is empty.');
        }
        $this->value = $value;
    }

    /**
     * The secret itself, for the token request only.
     */
    public function reveal(): string
    {
        return $this->value;
    }

    /**
     * SHA-256 of the secret: part of the token cache key, so a rotated secret never reuses the old token.
     */
    public function fingerprint(): string
    {
        return hash('sha256', $this->value);
    }

    /**
     * Debug output without the value.
     *
     * @return array{value: string}
     */
    public function __debugInfo(): array
    {
        return ['value' => '[redacted]'];
    }

    /**
     * Serialization is refused (the secret must never be stored by a cache or a queue).
     *
     * @return array<string, mixed>
     */
    public function __serialize(): array
    {
        throw new LogicException('A Microsoft Graph client secret cannot be serialized.');
    }

    /**
     * Unserialization is refused.
     *
     * @param array<string, mixed> $data
     */
    public function __unserialize(array $data): void
    {
        throw new LogicException('A Microsoft Graph client secret cannot be unserialized.');
    }
}
