<?php

declare(strict_types=1);

namespace Nowo\MailKitBundle\Tests\Unit\Mailbox;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\DriverManager;
use Doctrine\DBAL\Schema\Schema;
use Doctrine\Persistence\ManagerRegistry;
use Nowo\MailKitBundle\Mailbox\Infrastructure\Store\MailboxStore;
use Nowo\MailKitBundle\Mailbox\Value\GrantFailureReason;
use Nowo\MailKitBundle\Mailbox\Value\MailboxId;
use Nowo\MailKitBundle\Mailbox\Value\MailboxProvider;
use Nowo\MailKitBundle\Mailbox\Value\MailboxStatus;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Clock\MockClock;
use Throwable;

use function hash;

/**
 * 2.5.0 mailbox store on SQLite: conditional transitions (one winner), lease and rerun, cursor compare-and-set,
 * caught duplicate first rows, hashed client states, the GDPR delete, and savepoints inside a host transaction.
 */
final class MailboxStoreTest extends TestCase
{
    private Connection $connection;

    private MockClock $clock;

    private MailboxStore $store;

    private MailboxId $id;

    protected function setUp(): void
    {
        $this->connection = DriverManager::getConnection(['driver' => 'pdo_sqlite', 'memory' => true]);
        $registry         = $this->createStub(ManagerRegistry::class);
        $registry->method('getConnection')->willReturn($this->connection);
        $this->clock = new MockClock('2026-11-23 12:00:00 UTC');
        $this->store = new MailboxStore($registry, null, 'app_', $this->clock);
        self::assertNotSame([], $this->store->missingTablesSql());
        $this->store->execute($this->store->missingTablesSql());
        self::assertSame([], $this->store->missingTablesSql());
        $this->id = new MailboxId('mbx-1');
    }

    public function testTransitionsHaveOneWinner(): void
    {
        self::assertNull($this->store->mailbox($this->id));
        self::assertTrue($this->store->insertMailbox($this->id, MailboxProvider::Graph));
        self::assertFalse($this->store->insertMailbox($this->id, MailboxProvider::Graph));
        self::assertSame(MailboxStatus::Active, $this->store->mailbox($this->id)['status'] ?? null);

        self::assertTrue($this->store->pauseGrant($this->id, GrantFailureReason::Revoked));
        self::assertFalse($this->store->pauseGrant($this->id, GrantFailureReason::ConsentMissing));
        self::assertSame(GrantFailureReason::Revoked, $this->store->mailbox($this->id)['reason'] ?? null);
        self::assertFalse($this->store->pauseByHost($this->id));
        self::assertTrue($this->store->activate($this->id));
        self::assertFalse($this->store->activate($this->id));
        self::assertNull($this->store->mailbox($this->id)['reason'] ?? null);
        self::assertTrue($this->store->pauseByHost($this->id));
        self::assertTrue($this->store->activate($this->id));

        $this->connection->executeStatement("UPDATE app_mail_kit_mailbox SET status = 'bogus', reason = 'bogus' WHERE mailbox_id = 'mbx-1'");
        self::assertSame(MailboxStatus::Unknown, $this->store->mailbox($this->id)['status'] ?? null);
    }

    public function testTheAccountIsBoundOnce(): void
    {
        $this->store->insertMailbox($this->id, MailboxProvider::Graph);
        self::assertFalse($this->store->isAccount($this->id, 'acc-1'));
        self::assertTrue($this->store->bindAccount($this->id, 'acc-1'));
        self::assertTrue($this->store->bindAccount($this->id, 'acc-1'));
        self::assertFalse($this->store->bindAccount($this->id, 'acc-2'));
        self::assertTrue($this->store->isAccount($this->id, 'acc-1'));
        self::assertSame(hash('sha256', 'acc-1'), $this->store->mailbox($this->id)['account_hash'] ?? null);
        self::assertFalse($this->store->bindAccount(new MailboxId('none'), 'acc-1'));
    }

    public function testLeaseRerunAndCursorCompareAndSet(): void
    {
        $this->store->ensureCursor($this->id, 'inbox');
        $this->store->ensureCursor($this->id, 'inbox');
        self::assertSame(['cursor' => null, 'version' => 0], $this->store->cursor($this->id, 'inbox'));
        self::assertNull($this->store->cursor($this->id, 'archive'));

        self::assertTrue($this->store->acquireLease($this->id, 'inbox', 'owner-a', 120));
        self::assertFalse($this->store->acquireLease($this->id, 'inbox', 'owner-b', 120));
        $this->store->requestRerun($this->id, 'inbox');

        self::assertTrue($this->store->advanceCursor($this->id, 'inbox', 'owner-a', 0, 'link-1', 120, false));
        self::assertFalse($this->store->advanceCursor($this->id, 'inbox', 'owner-a', 0, 'link-x', 120, false), 'stale version');
        self::assertFalse($this->store->advanceCursor($this->id, 'inbox', 'owner-b', 1, 'link-x', 120, false), 'not the holder');
        self::assertTrue($this->store->advanceCursor($this->id, 'inbox', 'owner-a', 1, 'delta-1', 120, true));
        self::assertSame(['cursor' => 'delta-1', 'version' => 2], $this->store->cursor($this->id, 'inbox'));
        self::assertSame([['folder_key' => 'inbox', 'last_complete_at' => $this->clock->now()->getTimestamp()]], $this->store->cursors($this->id));

        self::assertTrue($this->store->releaseLease($this->id, 'inbox', 'owner-a'), 'a rerun was requested');
        self::assertTrue($this->store->acquireLease($this->id, 'inbox', 'owner-b', 120));
        self::assertFalse($this->store->releaseLease($this->id, 'inbox', 'owner-b'));
        self::assertFalse($this->store->releaseLease($this->id, 'inbox', 'owner-b'), 'not the holder any more');

        // An expired lease is taken over.
        self::assertTrue($this->store->acquireLease($this->id, 'inbox', 'owner-c', 60));
        $this->clock->sleep(61);
        self::assertTrue($this->store->acquireLease($this->id, 'inbox', 'owner-d', 60));

        $this->store->deleteCursor($this->id, 'inbox');
        self::assertSame([], $this->store->cursors($this->id));
    }

    public function testSubscriptionsAreVerifiedByHashedClientState(): void
    {
        $this->store->insertMailbox($this->id, MailboxProvider::Graph);
        $now = $this->clock->now()->getTimestamp();
        $this->store->saveSubscription('sub-1', $this->id, 'inbox', 'state-REDACTED-1', $now + 3600);
        $this->store->saveSubscription('sub-2', $this->id, 'inbox', 'state-REDACTED-2', $now + 3600);
        self::assertSame([['id' => 'sub-2', 'folder_key' => 'inbox', 'expires_at' => $now + 3600]], $this->store->subscriptions($this->id));
        self::assertSame(hash('sha256', 'state-REDACTED-2'), $this->connection->fetchOne('SELECT client_state_hash FROM app_mail_kit_mailbox_subscription'));

        $target = $this->store->verifyNotification('sub-2', 'state-REDACTED-2');
        self::assertNotNull($target);
        self::assertSame(['mbx-1', 'inbox', true], [$target['mailbox']->value, $target['folder'], $target['active']]);
        self::assertNull($this->store->verifyNotification('sub-2', 'wrong'));
        self::assertNull($this->store->verifyNotification('sub-1', 'state-REDACTED-1'));
        self::assertNull($this->store->verifyNotification('unknown', 'state-REDACTED-2'));

        $this->store->pauseByHost($this->id);
        self::assertFalse($this->store->verifyNotification('sub-2', 'state-REDACTED-2')['active'] ?? true);

        $this->store->updateSubscriptionExpiry('sub-2', $now - 1);
        self::assertNull($this->store->verifyNotification('sub-2', 'state-REDACTED-2'), 'expired');
        $this->store->deleteSubscription('sub-2');
        self::assertSame([], $this->store->subscriptions($this->id));
    }

    public function testDeleteMailboxRemovesEveryRow(): void
    {
        $this->store->insertMailbox($this->id, MailboxProvider::Graph);
        $this->store->ensureCursor($this->id, 'inbox');
        $this->store->saveSubscription('sub-1', $this->id, 'inbox', 'state', 2_000_000_000);
        $other = new MailboxId('mbx-2');
        $this->store->insertMailbox($other, MailboxProvider::Graph);

        self::assertSame(3, $this->store->deleteMailbox($this->id));
        self::assertNull($this->store->mailbox($this->id));
        self::assertNotNull($this->store->mailbox($other));
    }

    public function testWritesInsideAHostTransactionUseSavepoints(): void
    {
        $this->connection->beginTransaction();
        self::assertTrue($this->store->insertMailbox($this->id, MailboxProvider::Graph));
        self::assertFalse($this->store->insertMailbox($this->id, MailboxProvider::Graph), 'the duplicate key is caught in a savepoint');
        self::assertTrue($this->store->pauseByHost($this->id));
        $this->connection->commit();
        self::assertSame(MailboxStatus::PausedByHost, $this->store->mailbox($this->id)['status'] ?? null);

        $this->connection->beginTransaction();
        $this->store->execute(['DROP TABLE app_mail_kit_mailbox_cursor']);
        $failed = false;

        try {
            $this->store->ensureCursor($this->id, 'inbox');
        } catch (Throwable) {
            $failed = true;
        }
        self::assertTrue($failed, 'A failed insert is rethrown after the savepoint rollback.');
        self::assertTrue($this->store->pauseGrant(new MailboxId('none'), GrantFailureReason::Revoked) === false);
        $this->connection->rollBack();
    }

    public function testDefineSchemaSkipsExistingTables(): void
    {
        $schema = new Schema();
        $this->store->defineSchema($schema);
        $this->store->defineSchema($schema);
        self::assertTrue($schema->hasTable('app_mail_kit_mailbox_cursor'));
        self::assertSame('app_mail_kit_mailbox', $this->store->mailboxTable());
        self::assertSame($this->connection, $this->store->connection());
    }
}
