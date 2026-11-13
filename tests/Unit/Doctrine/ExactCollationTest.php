<?php

declare(strict_types=1);

namespace Nowo\MailKitBundle\Tests\Unit\Doctrine;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\DriverManager;
use Doctrine\DBAL\Platforms\MariaDBPlatform;
use Doctrine\DBAL\Platforms\MySQL80Platform;
use Doctrine\DBAL\Platforms\MySQLPlatform;
use Doctrine\DBAL\Platforms\PostgreSQLPlatform;
use Doctrine\DBAL\Platforms\SQLitePlatform;
use Doctrine\DBAL\Schema\Schema;
use Doctrine\Persistence\ManagerRegistry;
use Nowo\MailKitBundle\Doctrine\ExactCollation;
use Nowo\MailKitBundle\Graph\Inbound\Store\GraphInboundStore;
use Nowo\MailKitBundle\Tracking\Store\TrackingStore;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Psr\Log\AbstractLogger;
use Stringable;

use function implode;
use function preg_match_all;
use function sprintf;
use function strtr;

/**
 * REQ-SEC-006 (1.11.0): the identifier and key columns of the tracking table and of the Graph inbound tables get a
 * binary NO PAD collation on MySQL / MariaDB (`utf8mb4_0900_bin` on MySQL 8.0.17+ and 9.x, `utf8mb4_nopad_bin` on
 * MariaDB, `utf8mb4_bin` plus a logged warning on MySQL 5.7), instead of the PAD SPACE `utf8mb4_bin` the tracking
 * table used before and of the host default the inbound tables took.
 */
final class ExactCollationTest extends TestCase
{
    /** Tracking columns that must compare byte for byte. */
    private const TRACKING_COLUMNS = ['send_id', 'recipient_id', 'tenant'];

    #[Test]
    public function thePlatformAndTheServerVersionChooseTheCollation(): void
    {
        self::assertSame('utf8mb4_nopad_bin', ExactCollation::forPlatform(new MariaDBPlatform()));
        self::assertSame('utf8mb4_0900_bin', ExactCollation::forPlatform(new MySQL80Platform()));
        self::assertSame('utf8mb4_0900_bin', ExactCollation::forPlatform(new MySQL80Platform(), '8.0.17'));
        self::assertSame('utf8mb4_0900_bin', ExactCollation::forPlatform(new MySQL80Platform(), '8.4.3'));
        self::assertSame('utf8mb4_0900_bin', ExactCollation::forPlatform(new MySQL80Platform(), '9.7.0'), 'MySQL 9.x is newer than 8.0.17');
        self::assertSame('utf8mb4_0900_bin', ExactCollation::forPlatform(new MySQL80Platform(), '10.0.0'), 'a two-digit major is not MariaDB here');
        self::assertSame('utf8mb4_0900_bin', ExactCollation::forPlatform(new MySQL80Platform(), 'not-a-version'));
        self::assertSame('utf8mb4_bin', ExactCollation::forPlatform(new MySQL80Platform(), '8.0.16'));
        self::assertSame('utf8mb4_bin', ExactCollation::forPlatform(new MySQL80Platform(), '8.0'), 'no patch number: 8.0.0');
        self::assertSame('utf8mb4_bin', ExactCollation::forPlatform(new MySQL80Platform(), '8'));
        self::assertSame('utf8mb4_bin', ExactCollation::forPlatform(new MySQLPlatform(), '5.7.44'));
        self::assertNull(ExactCollation::forPlatform(new PostgreSQLPlatform()));
        self::assertNull(ExactCollation::forPlatform(new SQLitePlatform()));
    }

    /** @return iterable<string, array{0: array<string, mixed>, 1: string|null, 2: bool}> */
    public static function connections(): iterable
    {
        yield 'MySQL 9.7' => [['driver' => 'pdo_mysql', 'serverVersion' => '9.7.0'], 'utf8mb4_0900_bin', false];
        yield 'MySQL 8.4' => [['driver' => 'pdo_mysql', 'serverVersion' => '8.4.3'], 'utf8mb4_0900_bin', false];
        yield 'MySQL 8.0 without patch' => [['driver' => 'pdo_mysql', 'serverVersion' => '8.0'], 'utf8mb4_bin', true];
        yield 'MySQL 5.7' => [['driver' => 'pdo_mysql', 'serverVersion' => '5.7.44'], 'utf8mb4_bin', true];
        yield 'MySQL 8.4 replica set' => [['driver' => 'pdo_mysql', 'primary' => ['serverVersion' => '8.4.3'], 'replica' => []], 'utf8mb4_0900_bin', false];
        yield 'MariaDB 11.4' => [['driver' => 'pdo_mysql', 'serverVersion' => '11.4.0-MariaDB'], 'utf8mb4_nopad_bin', false];
        yield 'PostgreSQL 17' => [['driver' => 'pdo_pgsql', 'serverVersion' => '17'], null, false];
        yield 'SQLite' => [['driver' => 'pdo_sqlite', 'memory' => true], null, false];
    }

    /** @param array<string, mixed> $params */
    #[Test]
    #[DataProvider('connections')]
    public function theConnectionChoosesTheCollationAndWarnsAboutTheFallback(array $params, ?string $expected, bool $warns): void
    {
        $logger     = $this->logger();
        $connection = DriverManager::getConnection($params);
        self::assertSame($expected, ExactCollation::forConnection($connection, $logger));
        self::assertCount($warns ? 1 : 0, $logger->records);
        if ($warns) {
            self::assertStringContainsString('PAD SPACE collation utf8mb4_bin', $logger->records[0]);
        }
    }

    #[Test]
    public function withoutAConfiguredVersionTheServerReportsIt(): void
    {
        $connection = $this->createMock(Connection::class);
        $connection->method('getDatabasePlatform')->willReturn(new MySQL80Platform());
        $connection->method('getParams')->willReturn([]);
        $connection->method('getServerVersion')->willReturn('8.0.16');
        $logger = $this->logger();

        self::assertSame('utf8mb4_bin', ExactCollation::forConnection($connection, $logger));
        self::assertStringContainsString('"8.0.16"', $logger->records[0]);
        self::assertSame('utf8mb4_bin', ExactCollation::forConnection($connection), 'no logger: no warning');
    }

    #[Test]
    public function theColumnOptionsCarryTheCharsetAndTheCollation(): void
    {
        self::assertSame(['platformOptions' => ['charset' => 'utf8mb4', 'collation' => 'utf8mb4_0900_bin']], ExactCollation::columnOptions(new MySQL80Platform()));
        self::assertSame([], ExactCollation::columnOptions(new SQLitePlatform()));
        self::assertSame([], ExactCollation::columnOptions(null));
    }

    /** @return iterable<string, array{0: string, 1: string|null, 2: bool}> */
    public static function schemas(): iterable
    {
        yield 'MySQL 9.7' => ['9.7.0', 'utf8mb4_0900_bin', false];
        yield 'MySQL 8.4' => ['8.4.3', 'utf8mb4_0900_bin', false];
        yield 'MariaDB 11.4' => ['11.4.0-MariaDB', 'utf8mb4_nopad_bin', false];
        yield 'MySQL 5.7' => ['5.7.44', 'utf8mb4_bin', true];
    }

    #[Test]
    #[DataProvider('schemas')]
    public function theTrackingTableGivesEveryIdentifierColumnTheCollation(string $serverVersion, string $collation, bool $warns): void
    {
        $logger     = $this->logger();
        $connection = DriverManager::getConnection(['driver' => 'pdo_mysql', 'serverVersion' => $serverVersion]);
        $sql        = $this->createTableSql($connection, new TrackingStore($this->registry($connection), null, '', null, $logger), 'mail_kit_tracking_send');

        foreach (self::TRACKING_COLUMNS as $column) {
            self::assertMatchesRegularExpression(sprintf('/\b%s VARCHAR\(\d+\) CHARACTER SET utf8mb4 [^,]*COLLATE `%s`/', $column, $collation), $sql, $column);
        }
        self::assertDoesNotMatchRegularExpression('/\btemplate_id VARCHAR\(\d+\)[^,]*COLLATE/', $sql, 'the other columns keep the table collation');
        self::assertDoesNotMatchRegularExpression('/\blast_open_ua VARCHAR\(\d+\)[^,]*COLLATE/', $sql);
        self::assertCount($warns ? 1 : 0, $logger->records);
    }

    #[Test]
    #[DataProvider('schemas')]
    public function theGraphInboundTablesGiveEveryKeyColumnTheCollation(string $serverVersion, string $collation, bool $warns): void
    {
        $logger     = $this->logger();
        $connection = DriverManager::getConnection(['driver' => 'pdo_mysql', 'serverVersion' => $serverVersion]);
        $store      = new GraphInboundStore($this->registry($connection), null, '', null, $logger);
        $sql        = $this->createTableSql($connection, $store, 'mail_kit_graph_subscription', 'mail_kit_graph_delta', 'mail_kit_graph_inbound');

        foreach (['subscription_id', 'message_key', 'status'] as $column) {
            self::assertMatchesRegularExpression(sprintf('/\b%s VARCHAR\(\d+\) CHARACTER SET utf8mb4 [^,]*COLLATE `%s`/', $column, $collation), $sql, $column);
        }
        self::assertSame(3, preg_match_all(sprintf('/\bprofile VARCHAR\(64\) CHARACTER SET utf8mb4 [^,]*COLLATE `%s`/', $collation), $sql), 'subscription, delta and inbound');
        self::assertDoesNotMatchRegularExpression('/\bclient_state_hash VARCHAR\(\d+\)[^,]*COLLATE/', $sql, 'never compared in SQL: hash_equals() in PHP');
        self::assertCount($warns ? 1 : 0, $logger->records, 'one warning per schema, not per table');
    }

    #[Test]
    public function sqliteAndPostgresGetNoCollation(): void
    {
        foreach ([['driver' => 'pdo_sqlite', 'memory' => true], ['driver' => 'pdo_pgsql', 'serverVersion' => '17']] as $params) {
            $connection = DriverManager::getConnection($params);
            $registry   = $this->registry($connection);
            self::assertStringNotContainsString('COLLATE', $this->createTableSql($connection, new TrackingStore($registry), 'mail_kit_tracking_send'));
            self::assertStringNotContainsString('COLLATE', $this->createTableSql($connection, new GraphInboundStore($registry), 'mail_kit_graph_inbound'));
        }
    }

    private function registry(Connection $connection): ManagerRegistry
    {
        $registry = $this->createStub(ManagerRegistry::class);
        $registry->method('getConnection')->willReturn($connection);

        return $registry;
    }

    private function createTableSql(Connection $connection, GraphInboundStore|TrackingStore $store, string ...$tables): string
    {
        $schema = new Schema();
        $store->defineSchema($schema);
        $sql = [];
        foreach ($tables as $table) {
            foreach ($connection->getDatabasePlatform()->getCreateTableSQL($schema->getTable($table)) as $statement) {
                $sql[] = $statement;
            }
        }

        return implode("\n", $sql);
    }

    /** @return AbstractLogger&object{records: list<string>} */
    private function logger(): AbstractLogger
    {
        return new class extends AbstractLogger {
            /** @var list<string> */
            public array $records = [];

            public function log(mixed $level, string|Stringable $message, array $context = []): void
            {
                $this->records[] = strtr((string) $message, ['{version}' => (string) ($context['version'] ?? '')]);
            }
        };
    }
}
