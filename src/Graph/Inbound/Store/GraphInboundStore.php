<?php

declare(strict_types=1);

namespace Nowo\MailKitBundle\Graph\Inbound\Store;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use Doctrine\DBAL\Platforms\AbstractPlatform;
use Doctrine\DBAL\Schema\Schema;
use Doctrine\Persistence\ManagerRegistry;
use Nowo\MailKitBundle\Doctrine\ExactCollation;
use Nowo\MailKitBundle\Graph\Inbound\InboundAction;
use Psr\Clock\ClockInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\Clock\NativeClock;
use Throwable;

use function assert;
use function hash;
use function hash_equals;
use function is_numeric;
use function is_string;

/**
 * Bundle-owned tables of the Graph inbound pipeline (1.9), through DBAL on the configured connection (resolved per
 * call; never the host's entity manager, never a flush):
 *
 * - `<prefix>mail_kit_graph_subscription`: change-notification subscriptions (id, profile, **SHA-256 of the client
 *   state** — the secret itself is never stored —, expiry);
 * - `<prefix>mail_kit_graph_delta`: the delta / next link of each profile's sweep;
 * - `<prefix>mail_kit_graph_inbound`: idempotency records, keyed by SHA-256(profile, internetMessageId) — no address,
 *   subject or id in clear.
 *
 * Times are UNIX seconds (BIGINT). A claim inside an open host transaction runs in a savepoint, so a duplicate key
 * never aborts the host's transaction (PostgreSQL).
 */
final class GraphInboundStore
{
    /** Claim being processed. */
    public const STATUS_PROCESSING = 'processing';

    private const SAVEPOINT = 'nowo_mail_kit_graph_claim';

    /**
     * @param string|null $connectionName DBAL connection (null = default)
     * @param string $tablePrefix `doctrine.table_prefix`
     */
    public function __construct(
        private readonly ManagerRegistry $doctrine,
        private readonly ?string $connectionName = null,
        private readonly string $tablePrefix = '',
        private readonly ?ClockInterface $clock = null,
        private readonly ?LoggerInterface $logger = null,
    ) {
    }

    /**
     * Table of the subscriptions.
     */
    public function subscriptionTable(): string
    {
        return $this->tablePrefix . 'mail_kit_graph_subscription';
    }

    /**
     * Table of the delta links.
     */
    public function deltaTable(): string
    {
        return $this->tablePrefix . 'mail_kit_graph_delta';
    }

    /**
     * Table of the idempotency records.
     */
    public function inboundTable(): string
    {
        return $this->tablePrefix . 'mail_kit_graph_inbound';
    }

    /**
     * Stores a subscription (the client state is hashed) and drops the profile's other subscriptions.
     */
    public function saveSubscription(string $subscriptionId, string $profile, string $clientState, int $expiresAt): void
    {
        $connection = $this->connection();
        $connection->executeStatement('DELETE FROM ' . $this->subscriptionTable() . ' WHERE profile = ?', [$profile]);
        $connection->insert($this->subscriptionTable(), [
            'subscription_id'   => $subscriptionId,
            'profile'           => $profile,
            'client_state_hash' => hash('sha256', $clientState),
            'expires_at'        => $expiresAt,
            'created_at'        => $this->now(),
        ]);
    }

    /**
     * The profile's subscription, or null.
     *
     * @return array{id: string, expires_at: int}|null
     */
    public function subscription(string $profile): ?array
    {
        $row = $this->connection()->fetchAssociative('SELECT subscription_id, expires_at FROM ' . $this->subscriptionTable() . ' WHERE profile = ?', [$profile]);
        if ($row === false || !is_string($row['subscription_id'] ?? null) || !is_numeric($row['expires_at'] ?? null)) {
            return null;
        }

        return ['id' => $row['subscription_id'], 'expires_at' => (int) $row['expires_at']];
    }

    /**
     * The profile of a notification when its subscription is known and unexpired and its client state matches (constant-time
     * comparison of SHA-256 digests), else null.
     */
    public function verifyNotification(string $subscriptionId, string $clientState): ?string
    {
        $row      = $this->connection()->fetchAssociative('SELECT profile, client_state_hash, expires_at FROM ' . $this->subscriptionTable() . ' WHERE subscription_id = ?', [$subscriptionId]);
        $expected = $row !== false && is_string($row['client_state_hash'] ?? null) ? $row['client_state_hash'] : hash('sha256', 'nowo-mail-kit-unknown-subscription');
        $valid    = hash_equals($expected, hash('sha256', $clientState));
        // An expired subscription no longer authenticates notifications (audit NQ-I2).
        $current = $row !== false && is_numeric($row['expires_at'] ?? null) && (int) $row['expires_at'] > $this->now();

        return $valid && $current && is_string($row['profile'] ?? null) ? $row['profile'] : null;
    }

    /**
     * New expiry of a renewed subscription.
     */
    public function updateSubscriptionExpiry(string $subscriptionId, int $expiresAt): void
    {
        $this->connection()->executeStatement('UPDATE ' . $this->subscriptionTable() . ' SET expires_at = ? WHERE subscription_id = ?', [$expiresAt, $subscriptionId]);
    }

    /**
     * Forgets a subscription.
     */
    public function deleteSubscription(string $subscriptionId): void
    {
        $this->connection()->executeStatement('DELETE FROM ' . $this->subscriptionTable() . ' WHERE subscription_id = ?', [$subscriptionId]);
    }

    /**
     * The stored delta (or next) link of a profile, or null.
     */
    public function deltaLink(string $profile): ?string
    {
        $link = $this->connection()->fetchOne('SELECT delta_link FROM ' . $this->deltaTable() . ' WHERE profile = ?', [$profile]);

        return is_string($link) && $link !== '' ? $link : null;
    }

    /**
     * Stores the delta (or next) link of a profile.
     */
    public function saveDeltaLink(string $profile, string $link): void
    {
        $connection = $this->connection();
        $exists     = $connection->fetchOne('SELECT 1 FROM ' . $this->deltaTable() . ' WHERE profile = ?', [$profile]);
        if ($exists !== false) {
            $connection->executeStatement('UPDATE ' . $this->deltaTable() . ' SET delta_link = ?, updated_at = ? WHERE profile = ?', [$link, $this->now(), $profile]);

            return;
        }
        $connection->insert($this->deltaTable(), ['profile' => $profile, 'delta_link' => $link, 'updated_at' => $this->now()]);
    }

    /**
     * Forgets the delta link (the next sweep starts again from the initial window).
     */
    public function deleteDeltaLink(string $profile): void
    {
        $this->connection()->executeStatement('DELETE FROM ' . $this->deltaTable() . ' WHERE profile = ?', [$profile]);
    }

    /**
     * Claims a message for processing: true for the first claim, or when a previous claim is still `processing` and
     * older than `$staleAfter` seconds (a crashed worker); false for a processed or in-flight duplicate.
     */
    public function claim(string $profile, string $internetMessageId, int $staleAfter): bool
    {
        $connection = $this->connection();
        $key        = self::key($profile, $internetMessageId);
        $now        = $this->now();
        $nested     = $connection->isTransactionActive();
        if ($nested) {
            $connection->createSavepoint(self::SAVEPOINT);
        }

        try {
            $connection->insert($this->inboundTable(), [
                'message_key'  => $key,
                'profile'      => $profile,
                'status'       => self::STATUS_PROCESSING,
                'claimed_at'   => $now,
                'completed_at' => null,
            ]);
            if ($nested) {
                $connection->releaseSavepoint(self::SAVEPOINT);
            }

            return true;
        } catch (UniqueConstraintViolationException) {
            if ($nested) {
                $connection->rollbackSavepoint(self::SAVEPOINT);
            }
        }

        return $connection->executeStatement(
            'UPDATE ' . $this->inboundTable() . ' SET claimed_at = ? WHERE message_key = ? AND status = ? AND claimed_at < ?',
            [$now, $key, self::STATUS_PROCESSING, $now - $staleAfter],
        ) === 1;
    }

    /**
     * Records the host's decision for a claimed message.
     */
    public function complete(string $profile, string $internetMessageId, InboundAction $action): void
    {
        $this->connection()->executeStatement(
            'UPDATE ' . $this->inboundTable() . ' SET status = ?, completed_at = ? WHERE message_key = ?',
            [$action->value, $this->now(), self::key($profile, $internetMessageId)],
        );
    }

    /**
     * Forgets a claim (processing failed: the message is processed again on the next delivery).
     */
    public function release(string $profile, string $internetMessageId): void
    {
        try {
            $this->connection()->executeStatement('DELETE FROM ' . $this->inboundTable() . ' WHERE message_key = ?', [self::key($profile, $internetMessageId)]);
        } catch (Throwable) {
            // The stale-claim timeout releases it.
        }
    }

    /**
     * Deletes idempotency records claimed before `$before` (UNIX seconds); returns the count.
     */
    public function purge(int $before): int
    {
        return (int) $this->connection()->executeStatement('DELETE FROM ' . $this->inboundTable() . ' WHERE claimed_at < ?', [$before]);
    }

    /**
     * Adds the three tables to a schema (ORM `postGenerateSchema`, migrations, the schema command). The key columns
     * (`subscription_id`, `profile`, `message_key`, `status`) get a binary **NO PAD** collation on MySQL / MariaDB
     * instead of the host's default one, which folds case and accents and ignores trailing spaces on primary keys
     * (REQ-SEC-006, 1.11.0); `client_state_hash` is never compared in SQL (it is read back and checked with
     * `hash_equals()`), so it keeps the table collation. PostgreSQL and SQLite compare exactly by default.
     *
     * @param AbstractPlatform|Connection|null $source the connection the schema is built for (the server version is read
     *                                                 from it), its platform alone, or null for the store's connection
     */
    public function defineSchema(Schema $schema, AbstractPlatform|Connection|null $source = null): void
    {
        $exact = ExactCollation::columnOptions($source ?? $this->connection(), $this->logger);
        if (!$schema->hasTable($this->subscriptionTable())) {
            $table = $schema->createTable($this->subscriptionTable());
            $table->addColumn('subscription_id', 'string', ['length' => 128] + $exact);
            $table->addColumn('profile', 'string', ['length' => 64] + $exact);
            $table->addColumn('client_state_hash', 'string', ['length' => 64]);
            $table->addColumn('expires_at', 'bigint');
            $table->addColumn('created_at', 'bigint');
            $table->setPrimaryKey(['subscription_id']);
            $table->addIndex(['profile'], $this->tablePrefix . 'idx_mail_kit_graph_sub_profile');
        }
        if (!$schema->hasTable($this->deltaTable())) {
            $table = $schema->createTable($this->deltaTable());
            $table->addColumn('profile', 'string', ['length' => 64] + $exact);
            $table->addColumn('delta_link', 'text');
            $table->addColumn('updated_at', 'bigint');
            $table->setPrimaryKey(['profile']);
        }
        if (!$schema->hasTable($this->inboundTable())) {
            $table = $schema->createTable($this->inboundTable());
            $table->addColumn('message_key', 'string', ['length' => 64] + $exact);
            $table->addColumn('profile', 'string', ['length' => 64] + $exact);
            $table->addColumn('status', 'string', ['length' => 16] + $exact);
            $table->addColumn('claimed_at', 'bigint');
            $table->addColumn('completed_at', 'bigint', ['notnull' => false]);
            $table->setPrimaryKey(['message_key']);
            $table->addIndex(['claimed_at'], $this->tablePrefix . 'idx_mail_kit_graph_inbound_claimed');
        }
    }

    /**
     * CREATE statements of the tables missing on the connection.
     *
     * @return list<string>
     */
    public function missingTablesSql(): array
    {
        $connection = $this->connection();
        $manager    = $connection->createSchemaManager();
        $schema     = new Schema();
        $this->defineSchema($schema, $connection);
        $sql = [];
        foreach ([$this->subscriptionTable(), $this->deltaTable(), $this->inboundTable()] as $name) {
            if (!$manager->tablesExist([$name])) {
                foreach ($connection->getDatabasePlatform()->getCreateTableSQL($schema->getTable($name)) as $statement) {
                    $sql[] = $statement;
                }
            }
        }

        return $sql;
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
     * Idempotency key: SHA-256 of the profile and the internetMessageId (case kept: ids compare exactly).
     */
    public static function key(string $profile, string $internetMessageId): string
    {
        return hash('sha256', $profile . "\n" . $internetMessageId);
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

    private function now(): int
    {
        return ($this->clock ?? new NativeClock())->now()->getTimestamp();
    }
}
