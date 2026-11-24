<?php

declare(strict_types=1);

namespace Nowo\MailKitBundle\Tests\Unit\Mailbox;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\DriverManager;
use Doctrine\Persistence\ManagerRegistry;
use InvalidArgumentException;
use Nowo\MailKitBundle\Mailbox\Engine\SendState;
use Nowo\MailKitBundle\Mailbox\Infrastructure\Command\MailboxPurgeCommand;
use Nowo\MailKitBundle\Mailbox\Infrastructure\Store\MailboxStore;
use Nowo\MailKitBundle\Mailbox\Value\MailboxId;
use Nowo\MailKitBundle\Mailbox\Value\MailboxProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Clock\MockClock;
use Symfony\Component\Console\Tester\CommandTester;

/**
 * 2.5.0 part 2: the send rows on SQLite: the claim (caught duplicate), transitions held by the claim token, the stale
 * takeover with one winner, the purge of settled rows only, the GDPR delete and the purge command.
 */
final class MailboxSendStoreTest extends TestCase
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
        $this->store->execute($this->store->missingTablesSql());
        $this->id = new MailboxId('mbx-1');
        $this->store->insertMailbox($this->id, MailboxProvider::Graph);
    }

    public function testClaimTransitionsAndTakeover(): void
    {
        self::assertSame('app_mail_kit_mailbox_send', $this->store->sendTable());
        self::assertNull($this->store->sendRow($this->id, 'key-0000000000001'));
        self::assertTrue($this->store->claimSend($this->id, 'key-0000000000001', 'hash-1', 'token-a'));
        self::assertFalse($this->store->claimSend($this->id, 'key-0000000000001', 'hash-2', 'token-b'));
        self::assertTrue($this->store->claimSend(new MailboxId('mbx-2'), 'key-0000000000001', 'hash-1', 'token-c'), 'keys are per mailbox');

        $row = $this->store->sendRow($this->id, 'key-0000000000001');
        self::assertNotNull($row);
        self::assertSame(SendState::Drafting, $row['status']);
        self::assertSame('token-a', $row['token']);
        self::assertSame('hash-1', $row['payload_hash']);
        self::assertNull($row['message_id']);
        self::assertSame('', $row['internet_message_id']);
        self::assertSame(1, $row['attempts']);

        // Heartbeats in the same second still count (row_version changes every time).
        self::assertTrue($this->store->updateSend($this->id, 'key-0000000000001', 'token-a', SendState::Drafting, SendState::Drafting));
        self::assertTrue($this->store->updateSend($this->id, 'key-0000000000001', 'token-a', SendState::Drafting, SendState::Drafting, ['provider_message_id' => 'DRAFT-1', 'internet_message_id' => 'x@y', 'thread_id' => 'conv-1']));
        self::assertFalse($this->store->updateSend($this->id, 'key-0000000000001', 'token-b', SendState::Drafting, SendState::Sending), 'another token');
        self::assertFalse($this->store->updateSend($this->id, 'key-0000000000001', 'token-a', SendState::Sending, SendState::Sent), 'another state');
        self::assertTrue($this->store->updateSend($this->id, 'key-0000000000001', 'token-a', SendState::Drafting, SendState::Sending));

        // Takeover: only once stale, only one winner.
        $stale = $this->clock->now()->getTimestamp() - 120;
        self::assertFalse($this->store->takeOverSend($this->id, 'key-0000000000001', 'token-a', SendState::Sending, 'token-x', SendState::Sending, $stale));
        $this->clock->modify('+121 seconds');
        $stale = $this->clock->now()->getTimestamp() - 120;
        self::assertTrue($this->store->takeOverSend($this->id, 'key-0000000000001', 'token-a', SendState::Sending, 'token-x', SendState::Sending, $stale));
        self::assertFalse($this->store->takeOverSend($this->id, 'key-0000000000001', 'token-a', SendState::Sending, 'token-y', SendState::Sending, $stale));
        $row = $this->store->sendRow($this->id, 'key-0000000000001');
        self::assertSame(['token-x', 2, 'DRAFT-1', 'x@y', 'conv-1'], [$row['token'] ?? null, $row['attempts'] ?? null, $row['message_id'] ?? null, $row['internet_message_id'] ?? null, $row['thread_id'] ?? null]);

        // A released row is taken over at once.
        self::assertTrue($this->store->updateSend($this->id, 'key-0000000000001', 'token-x', SendState::Sending, SendState::Released));
        self::assertTrue($this->store->takeOverSend($this->id, 'key-0000000000001', 'token-x', SendState::Released, 'token-z', SendState::Drafting, null));

        $this->connection->executeStatement("UPDATE app_mail_kit_mailbox_send SET status = 'bogus'");
        self::assertSame(SendState::Unknown, $this->store->sendRow($this->id, 'key-0000000000001')['status'] ?? null);

        $this->expectException(InvalidArgumentException::class);
        $this->store->updateSend($this->id, 'key-0000000000001', 'token-z', SendState::Drafting, SendState::Sent, ['claim_token' => 'mine']);
    }

    public function testPurgeDeletesSettledRowsOnlyAndDisconnectEverything(): void
    {
        foreach (SendState::cases() as $i => $state) {
            $key = 'key-00000000000' . $i . 'a';
            $this->store->claimSend($this->id, $key, 'h', 't');
            $this->store->updateSend($this->id, $key, 't', SendState::Drafting, $state);
        }
        $this->clock->modify('+8 days');
        $this->store->claimSend($this->id, 'key-fresh-sent-01', 'h', 't');
        $this->store->updateSend($this->id, 'key-fresh-sent-01', 't', SendState::Drafting, SendState::Sent);

        $command = new CommandTester(new MailboxPurgeCommand($this->store, 'P7D', $this->clock));
        self::assertSame(0, $command->execute([]));
        self::assertStringContainsString('4 send row(s) deleted.', $command->getDisplay());
        self::assertSame(['drafting', 'sending', 'sent'], $this->connection->fetchFirstColumn('SELECT status FROM app_mail_kit_mailbox_send ORDER BY status'));
        self::assertSame(0, $this->store->purgeSends($this->clock->now()->getTimestamp() - 86_400, 1));

        self::assertSame(4, $this->store->deleteMailbox($this->id));
        self::assertSame(0, (int) $this->connection->fetchOne('SELECT COUNT(*) FROM app_mail_kit_mailbox_send'));
    }

    public function testPurgeRunsInBatches(): void
    {
        for ($i = 0; $i < 5; ++$i) {
            $this->store->claimSend($this->id, 'batch-key-0000' . $i, 'h', 't');
            $this->store->updateSend($this->id, 'batch-key-0000' . $i, 't', SendState::Drafting, SendState::Sent);
        }
        $this->clock->modify('+1 day');
        self::assertSame(5, $this->store->purgeSends($this->clock->now()->getTimestamp(), 2));
        self::assertSame(0, (int) $this->connection->fetchOne('SELECT COUNT(*) FROM app_mail_kit_mailbox_send'));
        $this->clock->modify('+1 second');
        self::assertSame(0, (new CommandTester(new MailboxPurgeCommand($this->store)))->execute([]));
    }
}
