<?php

declare(strict_types=1);

namespace Nowo\MailKitBundle\Log;

use function mb_substr;
use function preg_replace;

/**
 * Removes credentials from transport error messages before they are stored or shown: `user:password@` in URLs and
 * DSNs, Resend-style API keys (`re_…`) and `Bearer …` tokens. Capped at {@see self::MAX} characters.
 */
final class ErrorScrubber
{
    /** Maximum stored length. */
    public const MAX = 2000;

    /**
     * Scrubbed, capped message.
     */
    public static function scrub(string $message): string
    {
        $message = (string) preg_replace('#([a-z][a-z0-9+.\-]*://)[^\s/@]+@#i', '$1***@', $message);
        $message = (string) preg_replace('/\bre_[A-Za-z0-9_]{6,}/', 're_***', $message);
        $message = (string) preg_replace('/\bBearer\s+[A-Za-z0-9._~+\/=\-]+/i', 'Bearer ***', $message);

        return mb_substr($message, 0, self::MAX);
    }
}
