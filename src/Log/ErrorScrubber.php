<?php

declare(strict_types=1);

namespace Nowo\MailKitBundle\Log;

use SensitiveParameter;

use function array_unique;
use function base64_encode;
use function mb_substr;
use function preg_replace;
use function rawurlencode;
use function str_replace;
use function strlen;
use function urlencode;

/**
 * Removes credentials from transport error messages before they are stored, shown or logged:
 *
 * - the credentials of the DSN in use, passed by the caller (user, password, API key — also URL-encoded / base64);
 * - `user:password@` in URLs and DSNs, `username "…"` echoed by SMTP authentication failures;
 * - provider key formats: Resend `re_…`, SendGrid `SG.….…`, Brevo `xkeysib-…`, Mailgun `key-…`, `Bearer …` tokens and
 *   UUID-shaped tokens after `token` / `key` / `username`.
 *
 * Capped at {@see self::MAX} characters.
 */
final class ErrorScrubber
{
    /** Maximum stored length. */
    public const MAX = 2000;

    /** Masking text. */
    public const MASK = '***';

    /** Secrets shorter than this are not replaced (would mask ordinary words). */
    private const SECRET_MIN = 4;

    /**
     * Scrubbed, capped message.
     *
     * @param string ...$secrets credentials of the DSN in use (masked wherever they appear)
     */
    public static function scrub(string $message, #[SensitiveParameter] string ...$secrets): string
    {
        foreach ($secrets as $secret) {
            if (strlen($secret) < self::SECRET_MIN) {
                continue;
            }
            $message = str_replace(array_unique([$secret, rawurlencode($secret), urlencode($secret), base64_encode($secret)]), self::MASK, $message);
        }

        $message = (string) preg_replace('#([a-z][a-z0-9+.\-]*://)[^\s/@]+@#i', '$1' . self::MASK . '@', $message);
        $message = (string) preg_replace('/(username\s+)("[^"]*"|\'[^\']*\'|\S+)/i', '$1"' . self::MASK . '"', $message);
        $message = (string) preg_replace('/\bre_[A-Za-z0-9_]{6,}/', 're_' . self::MASK, $message);
        $message = (string) preg_replace('/\bSG\.[\w-]{8,}\.[\w-]{8,}/', 'SG.' . self::MASK, $message);
        $message = (string) preg_replace('/\bxkeysib-[A-Za-z0-9-]{16,}/', 'xkeysib-' . self::MASK, $message);
        $message = (string) preg_replace('/\bkey-[a-f0-9]{24,}/i', 'key-' . self::MASK, $message);
        $message = (string) preg_replace('/\b((?:token|key|username)\W{0,3})[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}\b/i', '$1' . self::MASK, $message);
        $message = (string) preg_replace('/\bBearer\s+[A-Za-z0-9._~+\/=\-]+/i', 'Bearer ' . self::MASK, $message);

        return mb_substr($message, 0, self::MAX);
    }
}
