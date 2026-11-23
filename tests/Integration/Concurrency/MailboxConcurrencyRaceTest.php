<?php

declare(strict_types=1);

namespace Nowo\MailKitBundle\Tests\Integration\Concurrency;

use Closure;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\DriverManager;
use Doctrine\DBAL\Platforms\PostgreSQLPlatform;
use Doctrine\DBAL\Tools\DsnParser;
use Doctrine\Persistence\ManagerRegistry;
use Nowo\MailKitBundle\Concurrency\StorageConflicts;
use Nowo\MailKitBundle\Mailbox\Infrastructure\Store\MailboxStore;
use Nowo\MailKitBundle\Mailbox\Value\GrantFailureReason;
use Nowo\MailKitBundle\Mailbox\Value\MailboxId;
use Nowo\MailKitBundle\Mailbox\Value\MailboxProvider;
use Nowo\MailKitBundle\Mailbox\Value\MailboxStatus;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use Throwable;

use function getenv;
use function is_string;
use function str_starts_with;

/**
 * 2.5.0 REQ-RUNTIME-003 races of the delegated-mailbox store (SC-001): two real connections on the same MySQL 8.4 /
 * MariaDB 11 / PostgreSQL database (MAIL_KIT_TEST_DATABASE_URL; skipped on SQLite). One sweeper per folder (lease and
 * rerun), no cursor lost update after a lease takeover, one winner per grant-failure transition, first rows inserted
 * by two workers, all also inside a host transaction (savepoints keep it usable). Lock waits are capped at one second.
 */
#[Group('database')]
final class MailboxConcurrencyRaceTest extends TestCase
{
    private const PREFIX = 'race_';

    private Connection $a;

    private Connection $b;

    private MailboxStore $storeA;

    private MailboxStore $storeB;

    private MailboxId $id;

    protected function setUp(): void
    {
        $url = getenv('MAIL_KIT_TEST_DATABASE_URL');
        if (!is_string($url) || $url === '' || str_starts_with($url, 'sqlite')) {
            self::markTestSkipped('Needs MAIL_KIT_TEST_DATABASE_URL on MySQL, MariaDB or PostgreSQL.');
        }
        $params  = (new DsnParser(['mysql' => 'pdo_mysql', 'mariadb' => 'pdo_mysql', 'postgresql' => 'pdo_pgsql', 'postgres' => 'pdo_pgsql', 'pgsql' => 'pdo_pgsql']))->parse($url);
        $this->a = DriverManager::getConnection($params);
        $this->b = DriverManager::getConnection($params);
        foreach ([$this->a, $this->b] as $connection) {
            $connection->executeStatement($this->isPostgres() ? "SET lock_timeout = '1s'" : 'SET SESSION innodb_lock_wait_timeout = 1');
        }
        foreach (['mail_kit_mailbox_subscription', 'mail_kit_mailbox_cursor', 'mail_kit_mailbox'] as $table) {
            $this->a->executeStatement('DROP TABLE IF EXISTS ' . self::PREFIX . $table);
        }
        $this->storeA = $this->store($this->a);
        $this->storeB = $this->store($this->b);
        $this->storeA->execute($this->storeA->missingTablesSql());
        $this->id = new MailboxId('mbx-race');
        $this->storeA->insertMailbox($this->id, MailboxProvider::Graph);
    }

    protected function tearDown(): void
    {
        if (isset($this->a)) {
            $this->a->close();
            $this->b->close();
        }
    }

    public function testOneSweeperPerFolderAndARerunIsNeverLost(): void
    {
        $this->storeA->ensureCursor($this->id, 'inbox');
        $this->storeB->ensureCursor($this->id, 'inbox');
        self::assertSame(1, $this->rows('mail_kit_mailbox_cursor'));

        self::assertTrue($this->storeA->acquireLease($this->id, 'inbox', 'worker-a', 120));
        self::assertFalse($this->storeB->acquireLease($this->id, 'inbox', 'worker-b', 120));
        $this->storeB->requestRerun($this->id, 'inbox');
        self::assertTrue($this->storeA->releaseLease($this->id, 'inbox', 'worker-a'), 'the holder sees the rerun');
        self::assertTrue($this->storeB->acquireLease($this->id, 'inbox', 'worker-b', 120));
        self::assertFalse($this->storeB->releaseLease($this->id, 'inbox', 'worker-b'));
    }

    public function testNoCursorLostUpdateAfterALeaseTakeover(): void
    {
        $this->storeA->ensureCursor($this->id, 'inbox');
        self::assertTrue($this->storeA->acquireLease($this->id, 'inbox', 'worker-a', 120));
        self::assertTrue($this->storeA->advanceCursor($this->id, 'inbox', 'worker-a', 0, 'page-1', 120, false));
        // A stalls; its lease expires and B takes the folder over.
        $this->a->executeStatement('UPDATE ' . self::PREFIX . 'mail_kit_mailbox_cursor SET lease_until = 0');
        self::assertTrue($this->storeB->acquireLease($this->id, 'inbox', 'worker-b', 120));
        self::assertTrue($this->storeB->advanceCursor($this->id, 'inbox', 'worker-b', 1, 'page-2-by-b', 120, false));
        self::assertFalse($this->storeA->advanceCursor($this->id, 'inbox', 'worker-a', 1, 'page-2-by-a', 120, true), 'A must not overwrite B');
        self::assertSame(['cursor' => 'page-2-by-b', 'version' => 2], $this->storeA->cursor($this->id, 'inbox'));
    }

    public function testOneWinnerPerGrantFailure(): void
    {
        // A pauses in an open transaction: B waits on the row and gets a retry-safe conflict; after A commits, B's
        // replay finds the mailbox paused and does not win (no second event).
        $this->a->beginTransaction();
        self::assertTrue($this->storeA->pauseGrant($this->id, GrantFailureReason::Revoked));
        $failure = $this->expectFailure(fn (): bool => $this->storeB->pauseGrant($this->id, GrantFailureReason::ConsentMissing));
        self::assertTrue(StorageConflicts::isConflict($failure), $failure::class);
        $this->a->commit();
        self::assertFalse($this->storeB->pauseGrant($this->id, GrantFailureReason::ConsentMissing));
        self::assertSame(GrantFailureReason::Revoked, $this->storeB->mailbox($this->id)['reason'] ?? null);

        self::assertTrue($this->storeA->activate($this->id));
        self::assertFalse($this->storeB->activate($this->id));
        self::assertSame(MailboxStatus::Active, $this->storeB->mailbox($this->id)['status'] ?? null);
    }

    public function testFirstRowsInsideAHostTransactionKeepItUsable(): void
    {
        $this->storeA->ensureCursor($this->id, 'inbox');
        $this->b->beginTransaction();
        $this->storeB->ensureCursor($this->id, 'inbox');
        self::assertFalse($this->storeB->insertMailbox($this->id, MailboxProvider::Graph));
        self::assertTrue($this->storeB->pauseByHost($this->id));
        self::assertSame(1, (int) $this->b->fetchOne('SELECT 1'));
        $this->b->commit();
        self::assertSame(MailboxStatus::PausedByHost, $this->storeA->mailbox($this->id)['status'] ?? null);

        // A lock held by A on the cursor row: B's conditional update times out in its savepoint; B's transaction lives.
        $this->a->beginTransaction();
        self::assertTrue($this->storeA->acquireLease($this->id, 'inbox', 'worker-a', 120));
        $this->b->beginTransaction();
        $failure = $this->expectFailure(fn (): bool => $this->storeB->acquireLease($this->id, 'inbox', 'worker-b', 120));
        self::assertTrue(StorageConflicts::isConflict($failure), $failure::class);
        self::assertSame(1, (int) $this->b->fetchOne('SELECT 1'));
        $this->b->commit();
        $this->a->commit();
    }

    private function store(Connection $connection): MailboxStore
    {
        $registry = $this->createStub(ManagerRegistry::class);
        $registry->method('getConnection')->willReturn($connection);

        return new MailboxStore($registry, null, self::PREFIX);
    }

    private function isPostgres(): bool
    {
        return $this->a->getDatabasePlatform() instanceof PostgreSQLPlatform;
    }

    /**
     * @param Closure(): mixed $write
     */
    private function expectFailure(Closure $write): Throwable
    {
        try {
            $write();
        } catch (Throwable $failure) {
            return $failure;
        }
        self::fail('The write was expected to meet the lock held by connection A.');
    }

    private function rows(string $table): int
    {
        return (int) $this->a->fetchOne('SELECT COUNT(*) FROM ' . self::PREFIX . $table);
    }
}
