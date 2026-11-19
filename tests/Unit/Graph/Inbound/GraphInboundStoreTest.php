<?php

declare(strict_types=1);

namespace Nowo\MailKitBundle\Tests\Unit\Graph\Inbound;

use ArrayObject;
use Closure;
use Doctrine\DBAL\Configuration;
use Doctrine\DBAL\DriverManager;
use Doctrine\DBAL\Exception\TableNotFoundException;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use Doctrine\DBAL\Schema\Schema;
use Doctrine\Persistence\ManagerRegistry;
use Nowo\MailKitBundle\Concurrency\StorageConflicts;
use Nowo\MailKitBundle\Graph\Inbound\InboundAction;
use Nowo\MailKitBundle\Graph\Inbound\Store\GraphInboundStore;
use Nowo\MailKitBundle\Tests\Support\InboundStoreTrait;
use Nowo\MailKitBundle\Tests\Support\StatementHookMiddleware;
use Nowo\MailKitBundle\Tests\Support\StorageFailures;
use Nowo\TransactionContract\TransientStorageFailure;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Clock\MockClock;
use Throwable;

use function hash;
use function is_string;
use function str_starts_with;

final class GraphInboundStoreTest extends TestCase
{
    use InboundStoreTrait;

    public function testSchema(): void
    {
        $store = $this->inboundStore('app_');
        self::assertSame('app_mail_kit_graph_subscription', $store->subscriptionTable());
        self::assertSame([], $store->missingTablesSql());
        $schema = new Schema();
        $store->defineSchema($schema);
        $store->defineSchema($schema);
        self::assertTrue($schema->hasTable('app_mail_kit_graph_delta'));
        self::assertTrue($schema->hasTable('app_mail_kit_graph_inbound'));
    }

    public function testSubscriptionsKeepOnlyAHashOfTheClientState(): void
    {
        $store  = $this->inboundStore();
        $secret = 'client-state-REDACTED-TEST';
        $store->saveSubscription('sub-1', 'acme', $secret, 1_900_000_000);
        $store->saveSubscription('sub-2', 'acme', $secret, 2_000_000_000);
        self::assertSame(['id' => 'sub-2', 'expires_at' => 2_000_000_000], $store->subscription('acme'));
        self::assertNull($store->subscription('globex'));

        $stored = $this->inboundConnection?->fetchOne('SELECT client_state_hash FROM mail_kit_graph_subscription');
        self::assertSame(hash('sha256', $secret), $stored);

        self::assertSame('acme', $store->verifyNotification('sub-2', $secret));
        self::assertNull($store->verifyNotification('sub-2', 'wrong'));
        self::assertNull($store->verifyNotification('sub-1', $secret));
        self::assertNull($store->verifyNotification('unknown', $secret));

        // An expired subscription no longer authenticates notifications.
        $store->updateSubscriptionExpiry('sub-2', 3000);
        self::assertSame(3000, $store->subscription('acme')['expires_at'] ?? null);
        self::assertNull($store->verifyNotification('sub-2', $secret));
        $store->deleteSubscription('sub-2');
        self::assertNull($store->subscription('acme'));
    }

    public function testDeltaLinks(): void
    {
        $store = $this->inboundStore();
        self::assertNull($store->deltaLink('acme'));
        $store->saveDeltaLink('acme', 'https://graph.microsoft.com/v1.0/a');
        $store->saveDeltaLink('acme', 'https://graph.microsoft.com/v1.0/b');
        self::assertSame('https://graph.microsoft.com/v1.0/b', $store->deltaLink('acme'));
        $store->deleteDeltaLink('acme');
        self::assertNull($store->deltaLink('acme'));
    }

    public function testClaimsAreIdempotentStaleClaimsAreTakenOverAndSavepointsProtectHostTransactions(): void
    {
        $clock = new MockClock('2026-11-12 10:00:00');
        $store = $this->inboundStore('', $clock);
        self::assertTrue($store->claim('acme', 'id@x', 900));
        self::assertFalse($store->claim('acme', 'id@x', 900));
        self::assertTrue($store->claim('globex', 'id@x', 900), 'Per profile.');
        self::assertTrue($store->claim('acme', 'ID@x', 900), 'Ids compare exactly.');

        $clock->modify('+901 seconds');
        self::assertTrue($store->claim('acme', 'id@x', 900), 'A stale processing claim is taken over.');
        $store->complete('acme', 'id@x', InboundAction::Route);
        $clock->modify('+2000 seconds');
        self::assertFalse($store->claim('acme', 'id@x', 900), 'A completed message is never processed again.');
        self::assertSame('route', $this->inboundConnection?->fetchOne('SELECT status FROM mail_kit_graph_inbound WHERE message_key = ?', [GraphInboundStore::key('acme', 'id@x')]));

        // Inside a host transaction: the duplicate rolls back to a savepoint, the host transaction goes on.
        $connection = $this->inboundConnection;
        $connection->beginTransaction();
        self::assertTrue($store->claim('acme', 'tx@x', 900));
        self::assertFalse($store->claim('acme', 'tx@x', 900));
        $connection->commit();
        self::assertFalse($store->claim('acme', 'tx@x', 900));

        $store->release('acme', 'tx@x');
        self::assertTrue($store->claim('acme', 'tx@x', 900));

        $clock->modify('+100 days');
        self::assertSame(0, $store->purge($clock->now()->getTimestamp() - 200 * 86_400));
        self::assertSame(4, $store->purge($clock->now()->getTimestamp()));

        // 1.13 (REQ-RUNTIME-003 rule 8): a failing release is no longer swallowed.
        $connection->executeStatement('DROP TABLE mail_kit_graph_inbound');
        try {
            $store->release('acme', 'tx@x');
            self::fail('A failing release is thrown.');
        } catch (TableNotFoundException) {
        }
        self::assertNotSame([], $store->missingTablesSql());
    }

    public function testReleaseRetriesOneLockConflictOutsideATransaction(): void
    {
        $deletes = new ArrayObject();
        $budget  = new ArrayObject([1]);
        $store   = $this->hookedStore(static function (string $sql) use ($deletes, $budget): void {
            if (!str_starts_with($sql, 'DELETE FROM mail_kit_graph_inbound')) {
                return;
            }
            $deletes->append($sql);
            if ($budget[0] > 0) {
                --$budget[0];

                throw StorageFailures::deadlock();
            }
        });
        self::assertTrue($store->claim('acme', 'id@x', 900));
        $store->release('acme', 'id@x');
        self::assertCount(2, $deletes, 'One failed DELETE, one retried.');
        self::assertTrue($store->claim('acme', 'id@x', 900), 'Released on the retry.');

        // A second conflict is thrown.
        $budget[0] = 2;
        $this->assertTransientFailure(static fn () => $store->release('acme', 'id@x'));

        // Inside a transaction a conflict is thrown at once (the transaction must roll back).
        $budget[0] = 1;
        $deletes->exchangeArray([]);
        $store->connection()->beginTransaction();
        try {
            $this->assertTransientFailure(static fn () => $store->release('acme', 'id@x'));
            self::assertCount(1, $deletes);
        } finally {
            $store->connection()->rollBack();
        }
    }

    public function testAnyInsertFailureInsideATransactionRollsBackItsSavepoint(): void
    {
        // Audit UE-MK-1: a lock conflict on the INSERT (not only a duplicate key) must not leave the host transaction
        // aborted (PostgreSQL); the failure is rethrown after the rollback to the savepoint.
        /** @var ArrayObject<string, mixed> $state */
        $state = new ArrayObject(['prefix' => null, 'drop' => null, 'store' => null]);
        $store = $this->hookedStore(static function (string $sql) use ($state): void {
            if (is_string($state['prefix']) && str_starts_with($sql, $state['prefix'])) {
                if (is_string($state['drop']) && $state['store'] instanceof GraphInboundStore) {
                    // As after a MySQL deadlock: the server already dropped the savepoint.
                    $state['store']->connection()->executeStatement('RELEASE SAVEPOINT ' . $state['drop']);
                }

                throw StorageFailures::lockWaitTimeout();
            }
        });
        $state['store'] = $store;
        $connection     = $store->connection();

        foreach ([
            ['INSERT INTO mail_kit_graph_delta', static fn () => $store->saveDeltaLink('acme', 'https://graph.microsoft.com/v1.0/a'), null],
            ['INSERT INTO mail_kit_graph_inbound', static fn (): bool => $store->claim('acme', 'id@x', 900), null],
            ['INSERT INTO mail_kit_graph_inbound', static fn (): bool => $store->claim('acme', 'id@y', 900), 'nowo_mail_kit_graph_claim'],
        ] as [$prefix, $write, $drop]) {
            $connection->beginTransaction();
            $connection->insert('mail_kit_graph_delta', ['profile' => 'host-row', 'delta_link' => 'x', 'updated_at' => 1]);
            $state['prefix'] = $prefix;
            $state['drop']   = $drop;
            $this->assertTransientFailure($write);
            $state['prefix'] = null;
            self::assertTrue($connection->isTransactionActive());
            self::assertSame(1, (int) $connection->fetchOne('SELECT COUNT(*) FROM mail_kit_graph_delta WHERE profile = ?', ['host-row']), 'The host transaction goes on.');
            $connection->rollBack();
        }
        self::assertSame(0, (int) $connection->fetchOne('SELECT COUNT(*) FROM mail_kit_graph_inbound'));
    }

    public function testCompleteRetriesOneLockConflictOutsideATransaction(): void
    {
        $budget = new ArrayObject([1]);
        $store  = $this->hookedStore(static function (string $sql) use ($budget): void {
            if (str_starts_with($sql, 'UPDATE mail_kit_graph_inbound SET status') && $budget[0] > 0) {
                --$budget[0];

                throw StorageFailures::deadlock();
            }
        });
        self::assertTrue($store->claim('acme', 'id@x', 900));
        $store->complete('acme', 'id@x', InboundAction::Discard);
        self::assertSame('discard', $store->connection()->fetchOne('SELECT status FROM mail_kit_graph_inbound'));

        $budget[0] = 2;
        $this->assertTransientFailure(static fn () => $store->complete('acme', 'id@x', InboundAction::Route));

        $budget[0] = 1;
        $store->connection()->beginTransaction();
        try {
            $this->assertTransientFailure(static fn () => $store->complete('acme', 'id@x', InboundAction::Route));
            self::assertSame(0, $budget[0], 'No retry inside a transaction.');
        } finally {
            $store->connection()->rollBack();
        }

        $store->connection()->executeStatement('DROP TABLE mail_kit_graph_inbound');
        try {
            $store->complete('acme', 'id@x', InboundAction::Route);
            self::fail('A non-transient failure is thrown at once.');
        } catch (TableNotFoundException) {
        }
    }

    /**
     * @param Closure(): mixed $call
     */
    private function assertTransientFailure(Closure $call): void
    {
        try {
            $call();
        } catch (Throwable $failure) {
            self::assertTrue(StorageConflicts::isTransient($failure));

            return;
        }
        self::fail('A transient failure was expected.');
    }

    public function testSaveSubscriptionIsOneTransaction(): void
    {
        $store = $this->inboundStore();
        $store->saveSubscription('sub-1', 'acme', 'state-a', 2_000_000_000);
        $store->saveSubscription('sub-2', 'globex', 'state-b', 2_000_000_000);

        // The new id is taken by another profile: the INSERT fails and the DELETE of acme's row is rolled back.
        try {
            $store->saveSubscription('sub-2', 'acme', 'state-c', 2_100_000_000);
            self::fail('Duplicate subscription id.');
        } catch (UniqueConstraintViolationException) {
        }
        self::assertSame(['id' => 'sub-1', 'expires_at' => 2_000_000_000], $store->subscription('acme'));
        self::assertSame(0, $store->connection()->getTransactionNestingLevel());

        // A lock conflict becomes the runner's TransientStorageFailure, after the rollback.
        $armed  = new ArrayObject([false]);
        $hooked = $this->hookedStore(static function (string $sql) use ($armed): void {
            if ($armed[0] === true && str_starts_with($sql, 'INSERT INTO mail_kit_graph_subscription')) {
                throw StorageFailures::lockWaitTimeout();
            }
        });
        $hooked->connection()->insert('mail_kit_graph_subscription', ['subscription_id' => 'sub-9', 'profile' => 'acme', 'client_state_hash' => 'h', 'expires_at' => 1, 'created_at' => 1]);
        $armed[0] = true;
        try {
            $hooked->saveSubscription('sub-10', 'acme', 'state', 2_000_000_000);
            self::fail('Lock conflict.');
        } catch (TransientStorageFailure $failure) {
            self::assertTrue(StorageConflicts::isTransient($failure->getPrevious() ?? $failure));
        }
        self::assertSame('sub-9', $hooked->subscription('acme')['id'] ?? null);
    }

    public function testDeltaLinkUpsertSurvivesAConcurrentInsert(): void
    {
        $store      = $this->inboundStore();
        $connection = $store->connection();
        // Make every UPDATE of the delta table touch no row (as on MySQL when the row is unchanged or a concurrent
        // sweep inserted it after our UPDATE): the INSERT then meets the duplicate key, which is caught.
        $connection->executeStatement('CREATE TRIGGER skip_delta_update BEFORE UPDATE ON mail_kit_graph_delta BEGIN SELECT RAISE(IGNORE); END');
        $store->saveDeltaLink('acme', 'https://graph.microsoft.com/v1.0/a');
        $store->saveDeltaLink('acme', 'https://graph.microsoft.com/v1.0/b');
        self::assertSame('https://graph.microsoft.com/v1.0/a', $store->deltaLink('acme'));

        // Inside a host transaction the duplicate key rolls back to a savepoint; the transaction goes on.
        $connection->beginTransaction();
        $store->saveDeltaLink('acme', 'https://graph.microsoft.com/v1.0/c');
        $store->saveDeltaLink('globex', 'https://graph.microsoft.com/v1.0/g');
        $connection->commit();
        self::assertSame('https://graph.microsoft.com/v1.0/g', $store->deltaLink('globex'));
        self::assertSame(1, (int) $connection->fetchOne('SELECT COUNT(*) FROM mail_kit_graph_delta WHERE profile = ?', ['acme']));
    }

    /**
     * @param Closure(string): void $hook
     */
    private function hookedStore(Closure $hook): GraphInboundStore
    {
        $connection = DriverManager::getConnection(['driver' => 'pdo_sqlite', 'memory' => true], (new Configuration())->setMiddlewares([new StatementHookMiddleware($hook)]));
        $registry   = $this->createStub(ManagerRegistry::class);
        $registry->method('getConnection')->willReturn($connection);
        $store = new GraphInboundStore($registry);
        $store->execute($store->missingTablesSql());

        return $store;
    }
}
