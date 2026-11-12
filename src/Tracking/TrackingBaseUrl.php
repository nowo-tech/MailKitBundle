<?php

declare(strict_types=1);

namespace Nowo\MailKitBundle\Tracking;

use InvalidArgumentException;

use function is_string;
use function parse_url;
use function preg_match;
use function rtrim;

use const PHP_URL_HOST;

/**
 * Rules of `nowo_mail_kit.tracking.public_base_url`: an absolute http(s) URL with a host and no user info, query or
 * fragment. A literal value is checked when the container is built; an env placeholder (`'%env(DEFAULT_URI)%'`) is
 * accepted then and checked with the same rules when the tracker first uses it. Stateless.
 */
abstract class TrackingBaseUrl
{
    /**
     * Whether the value holds a Symfony env placeholder (`env_<16 hex>_<NAME>_<hash>`, what `%env(NAME)%` becomes while
     * the container is built).
     */
    public static function isPlaceholder(mixed $value): bool
    {
        return is_string($value) && preg_match('/env_[0-9a-f]{16}_\w+/', $value) === 1;
    }

    public static function isValid(mixed $value): bool
    {
        if (!is_string($value) || preg_match('#^https?://[^/\s?\#@]+(/[^\s?\#]*)?\z#i', $value) !== 1) {
            return false;
        }

        return is_string(parse_url($value, PHP_URL_HOST));
    }

    /**
     * The base without a trailing slash.
     *
     * @throws InvalidArgumentException when the value is not an absolute http(s) URL without user info, query or fragment
     */
    public static function normalize(string $value): string
    {
        if (!self::isValid($value)) {
            throw new InvalidArgumentException('nowo_mail_kit.tracking.public_base_url must be an absolute http(s) URL without user info, query or fragment; no tracking pixel or link is generated.');
        }

        return rtrim($value, '/');
    }
}
