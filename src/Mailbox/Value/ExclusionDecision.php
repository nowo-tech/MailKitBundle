<?php

declare(strict_types=1);

namespace Nowo\MailKitBundle\Mailbox\Value;

use InvalidArgumentException;

use function preg_match;

/**
 * Answer of the host's exclusion policy (2.5.0): keep the message, or exclude it under a rule id the host counts
 * (1 to 64 characters among letters, digits, `_`, `.`, `:` and `-`). An excluded message is never normalised, logged
 * or handed to the sink.
 */
final readonly class ExclusionDecision
{
    /**
     * @param string|null $ruleId null = keep
     */
    private function __construct(public ?string $ruleId)
    {
    }

    /**
     * Keep the message.
     */
    public static function keep(): self
    {
        return new self(null);
    }

    /**
     * Exclude the message under the host's rule id.
     *
     * @throws InvalidArgumentException when the rule id is not 1 to 64 safe characters
     */
    public static function exclude(string $ruleId): self
    {
        if (preg_match('/^[A-Za-z0-9_.:-]{1,64}\z/', $ruleId) !== 1) {
            throw new InvalidArgumentException('An exclusion rule id must be 1 to 64 characters among letters, digits, "_", ".", ":" and "-".');
        }

        return new self($ruleId);
    }

    /**
     * True when the message is excluded.
     */
    public function isExcluded(): bool
    {
        return $this->ruleId !== null;
    }
}
