<?php

declare(strict_types=1);

namespace Nowo\MailKitBundle\Mailbox\Value;

use InvalidArgumentException;

use function preg_match;

/**
 * A provider message id (2.5.0): a Graph immutable id (or a Gmail hex id in 2.6.0), 1 to 512 characters among
 * letters, digits, `+`, `/`, `=`, `_` and `-`. Compared exactly; the host's sink keys its unique index on it.
 */
final readonly class ProviderMessageId
{
    /** Accepted form (the same as the 1.9 inbound pipeline). */
    public const PATTERN = '/^[A-Za-z0-9+\/=_-]{1,512}\z/';

    /**
     * @param string $value provider id
     *
     * @throws InvalidArgumentException when the id breaks {@see PATTERN}
     */
    public function __construct(public string $value)
    {
        if (preg_match(self::PATTERN, $value) !== 1) {
            throw new InvalidArgumentException('A provider message id must be 1 to 512 characters among letters, digits, "+", "/", "=", "_" and "-".');
        }
    }

    /**
     * True for the same id (exact comparison).
     */
    public function equals(self $other): bool
    {
        return $this->value === $other->value;
    }
}
