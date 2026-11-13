<?php

declare(strict_types=1);

namespace Nowo\MailKitBundle\Doctrine;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Platforms\AbstractMySQLPlatform;
use Doctrine\DBAL\Platforms\AbstractPlatform;
use Doctrine\DBAL\Platforms\MariaDBPlatform;
use Doctrine\DBAL\Platforms\MySQL80Platform;
use Psr\Log\LoggerInterface;

use function is_array;
use function is_callable;
use function is_string;
use function preg_match;
use function version_compare;

/**
 * The binary NO PAD collation that makes MySQL / MariaDB compare identifiers byte for byte (REQ-SEC-006 items 3 and 8,
 * since 1.11.0). Ported from the same class in nowo-tech/http-log-bundle 1.20.1.
 *
 * The default MySQL / MariaDB collations fold case and accents (`Bob` = `bob` = `bób`), and the PAD SPACE ones, the old
 * `utf8mb4_bin` included, ignore trailing spaces (`bob ` = `bob`). MariaDB gets `utf8mb4_nopad_bin`, MySQL 8.0.17+
 * `utf8mb4_0900_bin`; older MySQL servers keep `utf8mb4_bin` (the only binary utf8mb4 collation they have) and a
 * warning is logged. PostgreSQL and SQLite compare exactly already and get nothing (they reject MySQL collation names).
 *
 * Stateless (REQ-RUNTIME-002): everything is read from the connection on each call, nothing is memoised, so a
 * FrankenPHP worker with `kernel reset false` never serves a collation decided for another connection.
 *
 * @internal
 */
final class ExactCollation
{
    /** MySQL 8.0.17+ binary NO PAD collation. */
    public const MYSQL_NOPAD = 'utf8mb4_0900_bin';

    /** MariaDB binary NO PAD collation. */
    public const MARIADB_NOPAD = 'utf8mb4_nopad_bin';

    /** Fallback for MySQL < 8.0.17 (PAD SPACE: `bob ` = `bob`). */
    public const MYSQL_PAD_FALLBACK = 'utf8mb4_bin';

    /** First MySQL release with {@see self::MYSQL_NOPAD}. */
    public const MYSQL_NOPAD_SINCE = '8.0.17';

    /**
     * The exact collation for that platform and server version, null when the platform compares exactly by default.
     * A MySQL 8 platform with an unknown version gets {@see self::MYSQL_NOPAD}; a version without a patch number (`8.0`)
     * is read as `8.0.0` and keeps the fallback.
     *
     * @return non-empty-string|null
     */
    public static function forPlatform(AbstractPlatform $platform, ?string $serverVersion = null): ?string
    {
        return match (true) {
            $platform instanceof MariaDBPlatform       => self::MARIADB_NOPAD,
            $platform instanceof MySQL80Platform       => self::supportsNoPad($serverVersion) ? self::MYSQL_NOPAD : self::MYSQL_PAD_FALLBACK,
            $platform instanceof AbstractMySQLPlatform => self::MYSQL_PAD_FALLBACK,
            default                                    => null,
        };
    }

    /**
     * The exact collation for the connection's platform and server version; logs a warning when only the PAD SPACE
     * fallback is available (MySQL < 8.0.17, or `doctrine.dbal.server_version` without its patch number).
     *
     * @return non-empty-string|null
     */
    public static function forConnection(Connection $connection, ?LoggerInterface $logger = null): ?string
    {
        $platform  = $connection->getDatabasePlatform();
        $version   = $platform instanceof MySQL80Platform ? self::serverVersion($connection) : self::configuredVersion($connection);
        $collation = self::forPlatform($platform, $version);
        if ($collation === self::MYSQL_PAD_FALLBACK) {
            $logger?->warning('NowoMailKitBundle: the identifier columns get the PAD SPACE collation utf8mb4_bin (MySQL server version "{version}"), so trailing spaces are ignored in comparisons. Use MySQL 8.0.17+ and configure the full doctrine.dbal.server_version (e.g. 8.4.3) to get utf8mb4_0900_bin.', ['version' => $version !== null && $version !== '' ? $version : 'unknown']);
        }

        return $collation;
    }

    /**
     * The `platformOptions` to add to an identifier column's options, empty when the platform compares exactly.
     *
     * @param AbstractPlatform|Connection|null $source the schema's platform, or the connection the schema is built for
     *
     * @return array{platformOptions?: array{charset: string, collation: non-empty-string}}
     */
    public static function columnOptions(AbstractPlatform|Connection|null $source, ?LoggerInterface $logger = null): array
    {
        $collation = $source instanceof Connection ? self::forConnection($source, $logger) : ($source instanceof AbstractPlatform ? self::forPlatform($source) : null);

        return $collation === null ? [] : ['platformOptions' => ['charset' => 'utf8mb4', 'collation' => $collation]];
    }

    /**
     * The server version configured on the connection (`doctrine.dbal.server_version` / `?serverVersion=`, also on a
     * primary / replica connection), if any.
     */
    private static function configuredVersion(Connection $connection): ?string
    {
        $params  = $connection->getParams();
        $primary = $params['primary'] ?? null;
        $version = $params['serverVersion'] ?? (is_array($primary) ? ($primary['serverVersion'] ?? null) : null);

        return is_string($version) && $version !== '' ? $version : null;
    }

    /** The configured server version, else the one the server reports (doctrine/dbal 4), else '' (unknown). */
    private static function serverVersion(Connection $connection): string
    {
        // @phpstan-ignore function.alreadyNarrowedType (doctrine/dbal 3 keeps getServerVersion() private)
        return self::configuredVersion($connection) ?? (is_callable([$connection, 'getServerVersion']) ? $connection->getServerVersion() : '');
    }

    private static function supportsNoPad(?string $serverVersion): bool
    {
        if ($serverVersion === null || preg_match('/^(\d+)(?:\.(\d+))?(?:\.(\d+))?/', $serverVersion, $match) !== 1) {
            return true;
        }

        $version = $match[1] . '.' . (($match[2] ?? '') !== '' ? $match[2] : '0') . '.' . (($match[3] ?? '') !== '' ? $match[3] : '0');

        return version_compare($version, self::MYSQL_NOPAD_SINCE, '>=');
    }
}
