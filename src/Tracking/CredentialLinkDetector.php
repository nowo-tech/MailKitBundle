<?php

declare(strict_types=1);

namespace Nowo\MailKitBundle\Tracking;

use Nowo\MailKitBundle\Mailer\MailHeaders;

use function array_map;
use function explode;
use function implode;
use function in_array;
use function is_array;
use function is_string;
use function parse_url;
use function preg_match;
use function preg_quote;
use function rawurldecode;
use function rawurlencode;
use function str_contains;
use function str_starts_with;
use function strlen;
use function strpos;
use function strtolower;
use function substr;
use function trim;
use function urldecode;
use function urlencode;

/**
 * Links that are never rewritten (1.10): credential-bearing URLs. Built-in rules always apply (they cannot be turned
 * off); `tracking.links.credential_query_parameters` and `credential_path_segments` add to them.
 *
 * A link is credential-bearing when:
 *
 * - it does not parse, or carries user info (`https://user:pass@…`);
 * - a query or fragment parameter is named like a credential ({@see self::QUERY_NAMES}, case-insensitive, `[]`
 *   ignored), contains one of {@see self::QUERY_PARTS} (`reset_token`, `X-Amz-Signature`…) or starts with `x-amz-` /
 *   `x-goog-` (pre-signed URLs);
 * - a path segment starts with one of {@see self::PATH_WORDS} (optionally plural) followed by `-`, `_`, `.` or the end
 *   of the segment (`/reset-password`, `/login/…`, `/invitations/…/accept`, `/verify-email`) or contains one of
 *   {@see self::QUERY_PARTS};
 * - it contains one of the values the message declared for redaction ({@see MailHeaders::redact()}),
 *   raw or URL-encoded.
 *
 * Tokens embedded in a path without such a word are only caught by the redaction values and the allow-list
 * ({@see TrackableLinkFilterInterface}): keep `tracking.links.allowed_path_prefixes` tight.
 */
final readonly class CredentialLinkDetector
{
    /** Query / fragment parameter names that carry a credential. */
    public const QUERY_NAMES = [
        'token', 'access_token', 'refresh_token', 'id_token', 'auth', 'authorization', 'code', 'otp', 'totp', 'pin',
        'key', 'api_key', 'apikey', 'secret', 'password', 'pass', 'pwd', 'passcode', 'signature', 'sig', 'hash',
        '_hash', '_expiration', 'expires', 'nonce', 'ticket', 'session', 'sessionid', 'sid', 'jwt', 'magic', 'login',
        'credential', 'credentials', 'state', 'verify', 'verification', 'confirm', 'confirmation', 'reset', 'invite',
        'invitation', 'k', 't',
    ];

    /** Substrings of a parameter name or path segment that mark a credential. */
    public const QUERY_PARTS = ['token', 'secret', 'password', 'passwd', 'signature', 'otp', 'apikey', 'api_key', 'credential', 'nonce'];

    /** Leading words of a path segment that mark a credential-bearing route. */
    public const PATH_WORDS = [
        'reset', 'password', 'magic', 'login', 'signin', 'sign-in', 'logon', 'verify', 'verification', 'confirm',
        'activate', 'activation', 'invitation', 'invite', 'accept', 'unlock', 'otp', '2fa', 'mfa', 'token', 'auth',
        'oauth', 'sso', 'saml', 'impersonate', 'one-time', 'onetime', 'email-change', 'change-email', 'recover',
        'recovery', 'passwordless',
    ];

    /** @var list<string> */
    private array $names;

    private string $pathPattern;

    /**
     * @param list<string> $extraQueryNames `tracking.links.credential_query_parameters`
     * @param list<string> $extraPathWords `tracking.links.credential_path_segments`
     */
    public function __construct(array $extraQueryNames = [], array $extraPathWords = [])
    {
        $names = self::QUERY_NAMES;
        foreach ($extraQueryNames as $name) {
            $names[] = strtolower($name);
        }
        $words = self::PATH_WORDS;
        foreach ($extraPathWords as $word) {
            $words[] = strtolower($word);
        }
        $this->names       = $names;
        $this->pathPattern = '/^(?:' . implode('|', array_map(static fn (string $w): string => preg_quote($w, '/'), $words)) . ')s?(?:[-_.]|\z)/';
    }

    /**
     * True when the link must never be rewritten.
     *
     * @param list<string> $secrets values the message declared for redaction
     */
    public function isCredential(string $url, array $secrets = []): bool
    {
        $parts = parse_url($url);
        if (!is_array($parts) || isset($parts['user']) || isset($parts['pass'])) {
            return true;
        }
        foreach ($secrets as $secret) {
            if (strlen($secret) >= 4 && (str_contains($url, $secret) || str_contains($url, rawurlencode($secret)) || str_contains($url, urlencode($secret)))) {
                return true;
            }
        }
        foreach (['query', 'fragment'] as $key) {
            if (isset($parts[$key]) && $this->credentialParameters($parts[$key])) {
                return true;
            }
        }
        $path = $parts['path'] ?? '';

        return is_string($path) && $this->credentialPath($path);
    }

    private function credentialParameters(string $query): bool
    {
        foreach (explode('&', str_contains($query, ';') ? implode('&', explode(';', $query)) : $query) as $pair) {
            $position = strpos($pair, '=');
            $name     = strtolower(trim(urldecode($position === false ? $pair : substr($pair, 0, $position))));
            if (str_contains($name, '[')) {
                $name = substr($name, 0, (int) strpos($name, '['));
            }
            if ($name === '') {
                continue;
            }
            if (in_array($name, $this->names, true) || str_starts_with($name, 'x-amz-') || str_starts_with($name, 'x-goog-') || $this->containsPart($name)) {
                return true;
            }
        }

        return false;
    }

    private function credentialPath(string $path): bool
    {
        foreach (explode('/', $path) as $segment) {
            $segment = strtolower(rawurldecode($segment));
            if ($segment === '') {
                continue;
            }
            if (preg_match($this->pathPattern, $segment) === 1 || $this->containsPart($segment)) {
                return true;
            }
        }

        return false;
    }

    private function containsPart(string $value): bool
    {
        foreach (self::QUERY_PARTS as $part) {
            if (str_contains($value, $part)) {
                return true;
            }
        }

        return false;
    }
}
