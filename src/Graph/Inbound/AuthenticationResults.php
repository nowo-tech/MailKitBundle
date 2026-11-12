<?php

declare(strict_types=1);

namespace Nowo\MailKitBundle\Graph\Inbound;

use function in_array;
use function preg_match;
use function strtolower;
use function trim;

/**
 * SPF / DKIM / DMARC verdicts of an inbound message (1.9), read from the **first** (topmost) `Authentication-Results`
 * header — the one added by Exchange Online when it received the message; headers further down were written by
 * other hops or by the sender and are ignored. A verdict is one of {@see VERDICTS}, or null when absent.
 *
 * Trusted only for mail Exchange received from outside the organization (`X-MS-Exchange-Organization-AuthAs:
 * Anonymous`, audit NQ-L4); internal mail, or a message without that marker, has null verdicts. These are a coarse
 * spam / spoofing signal — never a user authentication (neither is a plus-address tag).
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
     * Verdicts of the first `Authentication-Results` value, only when Exchange marked the message as received from
     * outside (`X-MS-Exchange-Organization-AuthAs: Anonymous`); otherwise none.
     */
    public static function fromExchange(?string $authenticationResults, ?string $authAs): self
    {
        return strtolower(trim((string) $authAs)) === 'anonymous' ? self::fromHeader($authenticationResults) : new self();
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
