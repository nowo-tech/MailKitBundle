<?php

declare(strict_types=1);

namespace Nowo\MailKitBundle\Graph\Inbound;

use function in_array;
use function preg_match;
use function strtolower;

/**
 * SPF / DKIM / DMARC verdicts of an inbound message (1.9), read from the **first** (topmost) `Authentication-Results`
 * header — the one added by Exchange Online when it received the message; headers further down were written by
 * other hops or by the sender and are ignored. A verdict is one of {@see VERDICTS}, or null when absent.
 */
final class AuthenticationResults
{
    /** Accepted verdict values (RFC 8601 plus Exchange's `bestguesspass`). */
    public const VERDICTS = ['pass', 'fail', 'softfail', 'neutral', 'none', 'temperror', 'permerror', 'policy', 'bestguesspass'];

    /**
     * @param string|null $spf SPF verdict
     * @param string|null $dkim DKIM verdict
     * @param string|null $dmarc DMARC verdict
     */
    public function __construct(
        public readonly ?string $spf = null,
        public readonly ?string $dkim = null,
        public readonly ?string $dmarc = null,
    ) {
    }

    /**
     * Parses an `Authentication-Results` value (null = header absent).
     */
    public static function fromHeader(?string $value): self
    {
        if ($value === null) {
            return new self();
        }

        return new self(self::verdict($value, 'spf'), self::verdict($value, 'dkim'), self::verdict($value, 'dmarc'));
    }

    /**
     * True when SPF or DKIM passed and DMARC did not fail (a coarse signal; the host decides).
     */
    public function aligned(): bool
    {
        return ($this->spf === 'pass' || $this->dkim === 'pass') && $this->dmarc !== 'fail';
    }

    private static function verdict(string $value, string $method): ?string
    {
        if (preg_match('/(?:^|[\s;])' . $method . '\s*=\s*([A-Za-z]+)/i', $value, $match) !== 1) {
            return null;
        }
        $verdict = strtolower($match[1]);

        return in_array($verdict, self::VERDICTS, true) ? $verdict : null;
    }
}
