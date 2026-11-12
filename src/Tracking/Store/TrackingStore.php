<?php

declare(strict_types=1);

namespace Nowo\MailKitBundle\Tracking\Store;

use DateTimeImmutable;
use Doctrine\DBAL\ArrayParameterType;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\ParameterType;
use Doctrine\DBAL\Platforms\AbstractMySQLPlatform;
use Doctrine\DBAL\Platforms\AbstractPlatform;
use Doctrine\DBAL\Schema\Schema;
use Doctrine\Persistence\ManagerRegistry;
use Nowo\MailKitBundle\Tracking\EmailTrackingPersonalDataInterface;
use Nowo\MailKitBundle\Tracking\EmailTrackingReaderInterface;
use Nowo\MailKitBundle\Tracking\OpenStats;
use Nowo\MailKitBundle\Tracking\TrackingIds;
use Psr\Clock\ClockInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\Clock\NativeClock;
use Throwable;

use function array_chunk;
use function array_keys;
use function array_values;
use function assert;
use function count;
use function is_numeric;
use function is_string;

use const DATE_ATOM;

/**
 * Bundle-owned table of the open tracking (1.10), `<prefix>mail_kit_tracking_send`, through DBAL on the configured
 * connection (resolved per call; never the host's entity manager, never a flush). One row per tracked send: send id,
 * template id, host recipient id and tenant (exact, binary collation on MySQL / MariaDB), the log id, when it was sent,
 * and the open counters (human and machine apart), the truncated address and user-agent family of the last open. No
 * e-mail address, subject or body.
 *
 * An open is one indexed `UPDATE … WHERE send_id = ?` (no read, no ORM hydration). The send row is written at delivery,
 * often inside the host's transaction: then it runs in a savepoint, so a failure (missing table, lost connection)
 * never aborts the host transaction (PostgreSQL) — the e-mail simply goes out untracked.
 */
final class TrackingStore implements EmailTrackingReaderInterface, EmailTrackingPersonalDataInterface
{
    private const SAVEPOINT = 'nowo_mail_kit_tracking';

    private const COLUMNS = 'send_id, log_id, template_id, recipient_id, tenant, sent_at, open_count, first_open_at, last_open_at, machine_open_count, machine_first_open_at, machine_last_open_at, last_open_ip, last_open_ua';

    /**
     * @param string|null $connectionName DBAL connection (null = default)
     * @param string $tablePrefix `doctrine.table_prefix`
     * @param int $maxOpensPerSend opens (human + machine) counted per send at most (`tracking.max_opens_per_send`)
     */
    public function __construct(
        private readonly ManagerRegistry $doctrine,
        private readonly ?string $connectionName = null,
        private readonly string $tablePrefix = '',
        private readonly ?ClockInterface $clock = null,
        private readonly ?LoggerInterface $logger = null,
        private readonly int $maxOpensPerSend = 1000,
    ) {
    }

    /**
     * Table of the tracked sends.
     */
    public function table(): string
    {
        return $this->tablePrefix . 'mail_kit_tracking_send';
    }

    /**
     * Stores a new send; false when it could not be stored (the e-mail then goes out untracked).
     */
    public function createSend(string $sendId, ?string $templateId, ?string $recipientId, ?string $tenant, ?string $logId = null): bool
    {
        return $this->guarded('create', fn (Connection $connection): int => (int) $connection->insert($this->table(), [
            'send_id'            => $sendId,
            'log_id'             => $logId,
            'template_id'        => $templateId,
            'recipient_id'       => $recipientId,
            'tenant'             => $tenant,
            'sent_at'            => $this->now(),
            'open_count'         => 0,
            'machine_open_count' => 0,
        ])) === 1;
    }

    /**
     * Links a send to its sent-mail log row (best effort).
     */
    public function attachLog(string $sendId, string $logId): void
    {
        $this->guarded('attach', fn (Connection $connection): int => (int) $connection->executeStatement('UPDATE ' . $this->table() . ' SET log_id = ? WHERE send_id = ?', [$logId, $sendId]));
    }

    /**
     * Forgets a send whose delivery failed (best effort).
     */
    public function discard(string $sendId): void
    {
        $this->guarded('discard', fn (Connection $connection): int => (int) $connection->executeStatement('DELETE FROM ' . $this->table() . ' WHERE send_id = ?', [$sendId]));
    }

    /**
     * Counts one open (one indexed UPDATE); true when the send exists and is below the open cap.
     *
     * @param string|null $ip truncated address
     * @param string|null $family user-agent family
     * @param int $at UNIX seconds
     */
    public function recordOpen(string $sendId, bool $machine, ?string $ip, ?string $family, int $at): bool
    {
        $prefix = $machine ? 'machine_' : '';
        $sql    = 'UPDATE ' . $this->table() . ' SET ' . $prefix . 'open_count = ' . $prefix . 'open_count + 1, '
            . $prefix . 'first_open_at = COALESCE(' . $prefix . 'first_open_at, ?), ' . $prefix . 'last_open_at = ?, '
            . 'last_open_ip = ?, last_open_ua = ? WHERE send_id = ? AND open_count + machine_open_count < ?';

        return (int) $this->connection()->executeStatement($sql, [$at, $at, $ip, $family, $sendId, $this->maxOpensPerSend], [0 => ParameterType::INTEGER, 1 => ParameterType::INTEGER, 5 => ParameterType::INTEGER]) === 1;
    }

    /**
     * Statistics of one send, or null.
     */
    public function find(string $sendId): ?OpenStats
    {
        return $this->findMany([$sendId])[$sendId] ?? null;
    }

    /**
     * Statistics of several sends, keyed by send id (malformed ids are ignored).
     *
     * @param list<string> $sendIds
     *
     * @return array<string, OpenStats>
     */
    public function findMany(array $sendIds): array
    {
        $ids = [];
        foreach ($sendIds as $id) {
            if (TrackingIds::isSendId($id)) {
                $ids[$id] = true;
            }
        }
        $stats = [];
        foreach (array_chunk(array_keys($ids), 500) as $chunk) {
            $rows = $this->connection()->fetchAllAssociative('SELECT ' . self::COLUMNS . ' FROM ' . $this->table() . ' WHERE send_id IN (?)', [$chunk], [ArrayParameterType::STRING]);
            foreach ($rows as $row) {
                $stats[(string) $row['send_id']] = $this->hydrate($row);
            }
        }

        return $stats;
    }

    /**
     * Deletes the recipient's send rows (exact match); returns how many.
     */
    public function eraseRecipient(string $recipientId): int
    {
        $recipientId = (string) TrackingIds::recipient($recipientId);
        $connection  = $this->connection();
        $ids         = [];
        foreach ($connection->fetchAllAssociative('SELECT send_id, recipient_id FROM ' . $this->table() . ' WHERE recipient_id = ?', [$recipientId]) as $row) {
            // Exact match re-checked in PHP (case / accent-insensitive collations, audit LQ).
            if ($row['recipient_id'] === $recipientId) {
                $ids[] = (string) $row['send_id'];
            }
        }
        $deleted = 0;
        foreach (array_chunk($ids, 500) as $chunk) {
            $deleted += (int) $connection->executeStatement('DELETE FROM ' . $this->table() . ' WHERE send_id IN (?)', [$chunk], [ArrayParameterType::STRING]);
        }

        return $deleted;
    }

    /**
     * The recipient's send rows, oldest first.
     *
     * @return list<array{send_id: string, template: string|null, tenant: string|null, sent_at: string, opens: int, first_open_at: string|null, last_open_at: string|null, machine_opens: int, machine_first_open_at: string|null, machine_last_open_at: string|null, last_open_ip: string|null, last_open_ua: string|null}>
     */
    public function exportRecipient(string $recipientId): array
    {
        $recipientId = (string) TrackingIds::recipient($recipientId);
        $rows        = [];
        foreach ($this->connection()->fetchAllAssociative('SELECT ' . self::COLUMNS . ' FROM ' . $this->table() . ' WHERE recipient_id = ? ORDER BY sent_at, send_id', [$recipientId]) as $row) {
            // Exact match re-checked in PHP (case / accent-insensitive collations, audit LQ).
            if ($row['recipient_id'] === $recipientId) {
                $rows[] = $this->exportRow($this->hydrate($row));
            }
        }

        return $rows;
    }

    /**
     * @return array{send_id: string, template: string|null, tenant: string|null, sent_at: string, opens: int, first_open_at: string|null, last_open_at: string|null, machine_opens: int, machine_first_open_at: string|null, machine_last_open_at: string|null, last_open_ip: string|null, last_open_ua: string|null}
     */
    private function exportRow(OpenStats $stats): array
    {
        return [
            'send_id'               => $stats->sendId,
            'template'              => $stats->templateId,
            'tenant'                => $stats->tenant,
            'sent_at'               => $stats->sentAt->format(DATE_ATOM),
            'opens'                 => $stats->opens,
            'first_open_at'         => $stats->firstOpenAt?->format(DATE_ATOM),
            'last_open_at'          => $stats->lastOpenAt?->format(DATE_ATOM),
            'machine_opens'         => $stats->machineOpens,
            'machine_first_open_at' => $stats->machineFirstOpenAt?->format(DATE_ATOM),
            'machine_last_open_at'  => $stats->machineLastOpenAt?->format(DATE_ATOM),
            'last_open_ip'          => $stats->lastOpenIp,
            'last_open_ua'          => $stats->lastOpenFamily,
        ];
    }

    /**
     * Deletes the sends prepared before `$before` (UNIX seconds) in batches; returns the count.
     */
    public function purge(int $before, int $batch = 1000): int
    {
        $connection = $this->connection();
        $deleted    = 0;
        do {
            $ids = [];
            foreach ($connection->fetchFirstColumn('SELECT send_id FROM ' . $this->table() . ' WHERE sent_at < ? ORDER BY sent_at LIMIT ' . $batch, [$before]) as $id) {
                $ids[] = (string) $id;
            }
            if ($ids !== []) {
                $deleted += (int) $connection->executeStatement('DELETE FROM ' . $this->table() . ' WHERE send_id IN (?)', [$ids], [ArrayParameterType::STRING]);
            }
        } while (count($ids) === $batch);

        return $deleted;
    }

    /**
     * Adds the table to a schema (ORM `postGenerateSchema`, migrations, the schema command). Identifier columns get a
     * binary collation on MySQL / MariaDB (exact comparison; PostgreSQL and SQLite compare exactly by default).
     */
    public function defineSchema(Schema $schema, ?AbstractPlatform $platform = null): void
    {
        if ($schema->hasTable($this->table())) {
            return;
        }
        $exact = ($platform ?? $this->connection()->getDatabasePlatform()) instanceof AbstractMySQLPlatform ? ['platformOptions' => ['collation' => 'utf8mb4_bin']] : [];
        $table = $schema->createTable($this->table());
        $table->addColumn('send_id', 'string', ['length' => 32] + $exact);
        $table->addColumn('log_id', 'string', ['length' => 64, 'notnull' => false]);
        $table->addColumn('template_id', 'string', ['length' => TrackingIds::TEMPLATE_MAX, 'notnull' => false]);
        $table->addColumn('recipient_id', 'string', ['length' => TrackingIds::RECIPIENT_MAX, 'notnull' => false] + $exact);
        $table->addColumn('tenant', 'string', ['length' => TrackingIds::TENANT_MAX, 'notnull' => false] + $exact);
        $table->addColumn('sent_at', 'bigint');
        $table->addColumn('open_count', 'integer', ['default' => 0]);
        $table->addColumn('first_open_at', 'bigint', ['notnull' => false]);
        $table->addColumn('last_open_at', 'bigint', ['notnull' => false]);
        $table->addColumn('machine_open_count', 'integer', ['default' => 0]);
        $table->addColumn('machine_first_open_at', 'bigint', ['notnull' => false]);
        $table->addColumn('machine_last_open_at', 'bigint', ['notnull' => false]);
        $table->addColumn('last_open_ip', 'string', ['length' => 45, 'notnull' => false]);
        $table->addColumn('last_open_ua', 'string', ['length' => 16, 'notnull' => false]);
        $table->setPrimaryKey(['send_id']);
        $table->addIndex(['sent_at'], $this->tablePrefix . 'idx_mail_kit_tracking_sent');
        $table->addIndex(['recipient_id'], $this->tablePrefix . 'idx_mail_kit_tracking_recipient');
    }

    /**
     * CREATE statements of the table when it is missing on the connection (schema manager probe: no failing query,
     * safe inside a PostgreSQL transaction).
     *
     * @return list<string>
     */
    public function missingTablesSql(): array
    {
        $connection = $this->connection();
        if ($connection->createSchemaManager()->tablesExist([$this->table()])) {
            return [];
        }
        $schema = new Schema();
        $this->defineSchema($schema, $connection->getDatabasePlatform());

        return array_values($connection->getDatabasePlatform()->getCreateTableSQL($schema->getTable($this->table())));
    }

    /**
     * Runs SQL statements on the store connection (the schema command).
     *
     * @param list<string> $statements
     */
    public function execute(array $statements): void
    {
        $connection = $this->connection();
        foreach ($statements as $statement) {
            $connection->executeStatement($statement);
        }
    }

    /**
     * The DBAL connection the store writes to (resolved per call).
     */
    public function connection(): Connection
    {
        $connection = $this->doctrine->getConnection($this->connectionName);
        assert($connection instanceof Connection);

        return $connection;
    }

    /**
     * Runs a write in a savepoint when the host has a transaction open; a failure is logged and returns 0.
     *
     * @param callable(Connection): int $write
     */
    private function guarded(string $operation, callable $write): int
    {
        $connection = null;
        $nested     = false;
        try {
            $connection = $this->connection();
            $nested     = $connection->isTransactionActive();
            if ($nested) {
                $connection->createSavepoint(self::SAVEPOINT);
            }
            $result = $write($connection);
            if ($nested) {
                $connection->releaseSavepoint(self::SAVEPOINT);
            }

            return $result;
        } catch (Throwable $exception) {
            if ($nested && $connection instanceof Connection) {
                try {
                    $connection->rollbackSavepoint(self::SAVEPOINT);
                } catch (Throwable) {
                    // The host transaction reports its own state.
                }
            }
            $this->failed($operation, $exception);

            return 0;
        }
    }

    private function failed(string $operation, Throwable $exception): void
    {
        $this->logger?->warning('Mail tracking store write failed; the e-mail is sent untracked.', [
            'bundle'    => 'NowoMailKitBundle',
            'operation' => $operation,
            'error'     => $exception::class,
        ]);
    }

    /**
     * @param array<string, mixed> $row
     */
    private function hydrate(array $row): OpenStats
    {
        return new OpenStats(
            (string) $row['send_id'],
            $this->string($row['log_id'] ?? null),
            $this->string($row['template_id'] ?? null),
            $this->string($row['recipient_id'] ?? null),
            $this->string($row['tenant'] ?? null),
            $this->date($row['sent_at'] ?? null) ?? new DateTimeImmutable('@0'),
            (int) ($row['open_count'] ?? 0),
            $this->date($row['first_open_at'] ?? null),
            $this->date($row['last_open_at'] ?? null),
            (int) ($row['machine_open_count'] ?? 0),
            $this->date($row['machine_first_open_at'] ?? null),
            $this->date($row['machine_last_open_at'] ?? null),
            $this->string($row['last_open_ip'] ?? null),
            $this->string($row['last_open_ua'] ?? null),
        );
    }

    private function string(mixed $value): ?string
    {
        return is_string($value) && $value !== '' ? $value : null;
    }

    private function date(mixed $value): ?DateTimeImmutable
    {
        return is_numeric($value) ? new DateTimeImmutable('@' . (int) $value) : null;
    }

    private function now(): int
    {
        return ($this->clock ?? new NativeClock())->now()->getTimestamp();
    }
}
