<?php

declare(strict_types=1);

namespace Nowo\MailKitBundle\Dsn;

use function is_array;
use function is_int;
use function is_string;
use function parse_url;
use function sprintf;
use function strtolower;
use function trim;

/**
 * Redacted view of a mailer DSN for logs, audit trails and the admin UI: scheme, host and port only — never the
 * user, password, API key or query options.
 */
final class MailerDsnAudit
{
    /**
     * Scheme, host and port of the DSN (each null when absent or unparsable).
     *
     * @return array{scheme: string|null, host: string|null, port: int|null}
     */
    public static function redact(?string $dsn): array
    {
        $parts = parse_url(trim((string) $dsn));
        if (!is_array($parts)) {
            return ['scheme' => null, 'host' => null, 'port' => null];
        }

        $scheme = is_string($parts['scheme'] ?? null) && $parts['scheme'] !== '' ? strtolower($parts['scheme']) : null;
        $host   = is_string($parts['host'] ?? null) && $parts['host'] !== '' ? strtolower($parts['host']) : null;
        $port   = is_int($parts['port'] ?? null) ? $parts['port'] : null;

        return ['scheme' => $scheme, 'host' => $host, 'port' => $port];
    }

    /**
     * `scheme://host[:port]` (or the scheme alone, or "unknown"): safe to print and to log.
     */
    public static function label(?string $dsn): string
    {
        $parts = self::redact($dsn);
        if ($parts['scheme'] === null) {
            return 'unknown';
        }
        if ($parts['host'] === null) {
            return $parts['scheme'];
        }

        return sprintf('%s://%s%s', $parts['scheme'], $parts['host'], $parts['port'] === null ? '' : ':' . $parts['port']);
    }

    /**
     * Lowercase scheme (or "unknown"): the transport column of the sent-mail log.
     */
    public static function scheme(?string $dsn): string
    {
        return self::redact($dsn)['scheme'] ?? 'unknown';
    }
}
