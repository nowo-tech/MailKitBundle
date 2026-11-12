<?php

declare(strict_types=1);

namespace Nowo\MailKitBundle\Tracking;

use InvalidArgumentException;

use function mb_check_encoding;
use function mb_strlen;
use function preg_match;
use function sprintf;
use function trim;

/**
 * Validation of the identifiers the tracking stores (1.10): the host recipient id, the tenant key, the template id and
 * the opaque send id. Identifiers compare exactly (binary): they are never trimmed or case-folded, so a value with
 * surrounding white space, control characters, invalid UTF-8 or more than the column length is refused instead of being
 * silently normalised.
 *
 * Plain PHP (no Doctrine, Symfony or HTTP types).
 */
final class TrackingIds
{
    /** Longest recipient id (host user id). */
    public const RECIPIENT_MAX = 128;

    /** Longest tenant key. */
    public const TENANT_MAX = 64;

    /** Longest template id (same as the log tag). */
    public const TEMPLATE_MAX = 64;

    /** Opaque send id: 32 lower-case hexadecimal characters (128 random bits). */
    public const SEND_ID_PATTERN = '/^[0-9a-f]{32}\z/';

    /**
     * A recipient id (null stays null).
     *
     * @throws InvalidArgumentException when the value is not acceptable
     */
    public static function recipient(?string $value): ?string
    {
        return self::check($value, self::RECIPIENT_MAX, 'recipient id');
    }

    /**
     * A tenant key (null stays null).
     *
     * @throws InvalidArgumentException when the value is not acceptable
     */
    public static function tenant(?string $value): ?string
    {
        return self::check($value, self::TENANT_MAX, 'tenant');
    }

    /**
     * A template id (null stays null).
     *
     * @throws InvalidArgumentException when the value is not acceptable
     */
    public static function template(?string $value): ?string
    {
        return self::check($value, self::TEMPLATE_MAX, 'template id');
    }

    /**
     * True for a well-formed send id.
     */
    public static function isSendId(string $value): bool
    {
        return preg_match(self::SEND_ID_PATTERN, $value) === 1;
    }

    private static function check(?string $value, int $max, string $what): ?string
    {
        if ($value === null) {
            return null;
        }
        if ($value === '' || trim($value) !== $value) {
            throw new InvalidArgumentException(sprintf('A tracking %s must be non-empty and must not start or end with white space.', $what));
        }
        if (!mb_check_encoding($value, 'UTF-8') || preg_match('/[\x00-\x1F\x7F]/', $value) === 1) {
            throw new InvalidArgumentException(sprintf('A tracking %s must be valid UTF-8 without control characters.', $what));
        }
        if (mb_strlen($value) > $max) {
            throw new InvalidArgumentException(sprintf('A tracking %s must not be longer than %d characters.', $what, $max));
        }

        return $value;
    }
}
