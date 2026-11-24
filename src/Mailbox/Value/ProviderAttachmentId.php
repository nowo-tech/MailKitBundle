<?php

declare(strict_types=1);

namespace Nowo\MailKitBundle\Mailbox\Value;

use InvalidArgumentException;

use function preg_match;

/**
 * A provider attachment id (2.5.0), 1 to 512 characters among letters, digits, `+`, `/`, `=`, `_` and `-`.
 */
final readonly class ProviderAttachmentId
{
    /** Accepted form. */
    public const PATTERN = ProviderMessageId::PATTERN;

    /**
     * @param string $value provider id
     *
     * @throws InvalidArgumentException when the id breaks {@see PATTERN}
     */
    public function __construct(public string $value)
    {
        if (preg_match(self::PATTERN, $value) !== 1) {
            throw new InvalidArgumentException('A provider attachment id must be 1 to 512 characters among letters, digits, "+", "/", "=", "_" and "-".');
        }
    }
}
