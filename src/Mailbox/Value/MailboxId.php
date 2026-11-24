<?php

declare(strict_types=1);

namespace Nowo\MailKitBundle\Mailbox\Value;

use InvalidArgumentException;

use function preg_match;

/**
 * The host's opaque id of one delegated mailbox (2.5.0): `[A-Za-z0-9_.:-]{1,128}`, compared exactly. MailKit never
 * derives it from a provider answer or a webhook payload; it is what the host passed to the services or what MailKit
 * stored for a subscription.
 */
final readonly class MailboxId
{
    /** Accepted form. */
    public const PATTERN = '/^[A-Za-z0-9_.:-]{1,128}\z/';

    /**
     * @param string $value the host's id
     *
     * @throws InvalidArgumentException when the id breaks {@see PATTERN}
     */
    public function __construct(public string $value)
    {
        if (preg_match(self::PATTERN, $value) !== 1) {
            throw new InvalidArgumentException('A mailbox id must be 1 to 128 characters among letters, digits, "_", ".", ":" and "-".');
        }
    }

    /**
     * True for the same id (exact comparison).
     */
    public function equals(self $other): bool
    {
        return $this->value === $other->value;
    }

    /**
     * The id.
     */
    public function __toString(): string
    {
        return $this->value;
    }
}
