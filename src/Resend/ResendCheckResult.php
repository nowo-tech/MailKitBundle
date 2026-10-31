<?php

declare(strict_types=1);

namespace Nowo\MailKitBundle\Resend;

/**
 * Outcome of a Resend API key check.
 */
final readonly class ResendCheckResult
{
    /**
     * @param array<string, string>|null $domains domain => verification status; null for sending-only keys
     */
    private function __construct(
        public bool $ok,
        public ?array $domains,
        public ?string $error,
    ) {
    }

    /**
     * Valid key.
     *
     * @param array<string, string>|null $domains
     */
    public static function ok(?array $domains): self
    {
        return new self(true, $domains, null);
    }

    /**
     * Rejected key or unreachable API (scrubbed message).
     */
    public static function failed(string $error): self
    {
        return new self(false, null, $error);
    }
}
