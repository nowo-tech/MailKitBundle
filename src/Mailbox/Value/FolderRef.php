<?php

declare(strict_types=1);

namespace Nowo\MailKitBundle\Mailbox\Value;

use InvalidArgumentException;

use function in_array;
use function preg_match;

/**
 * A followed folder (2.5.0): a well-known folder name (`inbox`, `sentitems`, …) or a provider folder id (Graph
 * mail-folder id), 1 to 255 characters among letters, digits, `+`, `/`, `=`, `_` and `-`. No quote, dot, space or
 * percent sign, so the key is safe in a Graph path segment and in an OData key literal.
 */
final readonly class FolderRef
{
    /** Accepted form. */
    public const PATTERN = '/^[A-Za-z0-9+\/=_-]{1,255}\z/';

    /** Graph well-known folder names MailKit recognises (lower-case). */
    public const WELL_KNOWN = ['inbox', 'sentitems', 'drafts', 'deleteditems', 'junkemail', 'archive', 'outbox', 'clutter', 'conversationhistory', 'scheduled'];

    /**
     * @param string $key well-known name or provider folder id
     *
     * @throws InvalidArgumentException when the key breaks {@see PATTERN}
     */
    public function __construct(public string $key)
    {
        if (preg_match(self::PATTERN, $key) !== 1) {
            throw new InvalidArgumentException('A folder key must be 1 to 255 characters among letters, digits, "+", "/", "=", "_" and "-".');
        }
    }

    /**
     * The inbox.
     */
    public static function inbox(): self
    {
        return new self('inbox');
    }

    /**
     * Sent Items.
     */
    public static function sentItems(): self
    {
        return new self('sentitems');
    }

    /**
     * True for a well-known folder name ({@see WELL_KNOWN}).
     */
    public function isWellKnown(): bool
    {
        return in_array($this->key, self::WELL_KNOWN, true);
    }

    /**
     * True for the same key (exact comparison).
     */
    public function equals(self $other): bool
    {
        return $this->key === $other->key;
    }
}
