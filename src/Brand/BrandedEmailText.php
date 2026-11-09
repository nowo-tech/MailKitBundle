<?php

declare(strict_types=1);

namespace Nowo\MailKitBundle\Brand;

use function is_string;
use function mb_check_encoding;
use function mb_strlen;
use function preg_match;
use function preg_replace;
use function trim;

/**
 * Text rules of the branded e-mail layout (spec 220 S7): the brand name printed in the HTML and plain-text parts.
 *
 * The same rules as the brand contract's text fields: valid UTF-8, no control characters (line breaks included), no
 * bidi embedding / override / isolate characters, no line or paragraph separators, not blank, at most
 * {@see self::MAX_LENGTH} characters. HTML escaping is Twig's job (the layout is auto-escaped); {@see self::plain()}
 * is what the plain-text part prints, where no escaping exists.
 */
final class BrandedEmailText
{
    /** Longest configured fallback name (same cap as the InstanceSettings `brand.name` field). */
    public const MAX_LENGTH = 80;

    private const UNSAFE_PATTERN    = '/[\p{Cc}\x{202A}-\x{202E}\x{2066}-\x{2069}\p{Zl}\p{Zp}]/u';
    private const INVISIBLE_PATTERN = '/[\s\p{Z}\p{Cf}]+/u';

    /**
     * Whether `$value` is a usable fallback name (see the class description).
     */
    public static function isSafeName(mixed $value): bool
    {
        if (!is_string($value) || !mb_check_encoding($value, 'UTF-8')) {
            return false;
        }
        $value = trim($value);
        if ($value === '' || mb_strlen($value, 'UTF-8') > self::MAX_LENGTH || preg_match(self::UNSAFE_PATTERN, $value) === 1) {
            return false;
        }

        return preg_replace(self::INVISIBLE_PATTERN, '', $value) !== '';
    }

    /**
     * `$value` for the plain-text part: invalid UTF-8 gives an empty string; control, bidi and separator characters are
     * removed; surrounding whitespace is trimmed. Never adds markup and never decodes entities.
     */
    public static function plain(mixed $value): string
    {
        if (!is_string($value) || !mb_check_encoding($value, 'UTF-8')) {
            return '';
        }

        return trim((string) preg_replace(self::UNSAFE_PATTERN, '', $value));
    }
}
