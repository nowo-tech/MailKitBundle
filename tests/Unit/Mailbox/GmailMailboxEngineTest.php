<?php

declare(strict_types=1);

namespace Nowo\MailKitBundle\Tests\Unit\Mailbox;

use Doctrine\DBAL\DriverManager;
use Doctrine\Persistence\ManagerRegistry;
use InvalidArgumentException;
use Nowo\MailKitBundle\Mailbox\Engine\MailboxAttachmentReader;
use Nowo\MailKitBundle\Mailbox\Engine\MailboxSyncEngine;
use Nowo\MailKitBundle\Mailbox\Engine\MailboxSyncOptions;
use Nowo\MailKitBundle\Mailbox\Exception\MailboxProviderFailure;
use Nowo\MailKitBundle\Mailbox\Gmail\GmailApiClient;
use Nowo\MailKitBundle\Mailbox\Gmail\GmailMailboxGateway;
use Nowo\MailKitBundle\Mailbox\Infrastructure\Command\MailboxStatusCommand;
use Nowo\MailKitBundle\Mailbox\Infrastructure\Store\MailboxStore;
use Nowo\MailKitBundle\Mailbox\Value\FolderRef;
use Nowo\MailKitBundle\Mailbox\Value\GrantFailureReason;
use Nowo\MailKitBundle\Mailbox\Value\MailboxId;
use Nowo\MailKitBundle\Mailbox\Value\MailboxProvider;
use Nowo\MailKitBundle\Mailbox\Value\MailboxSettings;
use Nowo\MailKitBundle\Mailbox\Value\MailboxStatus;
use Nowo\MailKitBundle\Mailbox\Value\MailboxSyncStatus;
use Nowo\MailKitBundle\Mailbox\Value\ProviderAttachmentId;
use Nowo\MailKitBundle\Mailbox\Value\ProviderMessageId;
use Nowo\MailKitBundle\Tests\Support\MailboxGmailFake;
use Nowo\MailKitBundle\Tests\Support\MemoryMailboxPorts;
use Nowo\MailKitBundle\Tests\Support\RecordingBus;
use Nowo\MailKitBundle\Tests\Support\RecordingEventDispatcher;
use Nowo\MailKitBundle\Tests\Support\RecordingLogger;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Clock\MockClock;
use Symfony\Component\Console\Tester\CommandTester;

/**
 * Phase B2: a send-only Gmail mailbox in the engine. Start / resume only check and bind the account; no cursor,
 * subscription, sweep, renewal, fetch, sent copy or attachment download ever runs; the status names the provider and
 * says it is send-only. Fake tokens only.
 */
final class GmailMailboxEngineTest extends TestCase
{
    private MailboxStore $store;

    private MailboxGmailFake $google;

    private MemoryMailboxPorts $ports;

    private RecordingBus $bus;

    private MailboxSyncEngine $engine;

    private MailboxId $ana;

    protected function setUp(): void
    {
        $connection = DriverManager::getConnection(['driver' => 'pdo_sqlite', 'memory' => true]);
        $registry   = $this->createStub(ManagerRegistry::class);
        $registry->method('getConnection')->willReturn($connection);
        $clock       = new MockClock('2026-11-25 12:00:00 UTC');
        $log         = new RecordingLogger();
        $this->store = new MailboxStore($registry, null, '', $clock, $log);
        $this->store->execute($this->store->missingTablesSql());
        $this->google = new MailboxGmailFake();
        $this->ports  = (new MemoryMailboxPorts())->add(MemoryMailboxPorts::gmailSettings('mbx-ana'));
        $this->bus    = new RecordingBus();
        $this->engine = new MailboxSyncEngine(
            $this->store,
            $this->ports,
            $this->ports,
            $this->ports,
            $this->ports,
            $this->ports,
            ['gmail' => new GmailMailboxGateway(new GmailApiClient($this->google->client(), 5.0, $log))],
            $this->bus,
            new MailboxSyncOptions(notificationUrl: 'https://app.example.com/hooks/mail-kit/v1/mailbox/graph'),
            new RecordingEventDispatcher(),
            $clock,
            $log,
        );
        $this->ana = new MailboxId('mbx-ana');
    }

    public function testStartBindsTheAccountAndSyncsNothing(): void
    {
        $status = $this->engine->start($this->ana);
        self::assertSame(MailboxStatus::Active, $status->status);
        self::assertSame(MailboxProvider::Gmail, $status->provider);
        self::assertTrue($status->isSendOnly());
        self::assertSame([], $status->folders);
        self::assertSame([GmailApiClient::USERINFO_URL], array_map(static fn (array $r): string => $r['url'], $this->google->requests));
        self::assertNotNull($this->store->mailbox($this->ana)['account_hash'] ?? null);
        self::assertSame([], $this->store->cursors($this->ana));
        self::assertSame([], $this->store->subscriptions($this->ana));
        self::assertSame([], $this->bus->messages);

        // Schedules, sweeps, fetches and sent copies skip it; nothing reaches Google.
        self::assertSame(0, $this->engine->sweepAll());
        self::assertSame(0, $this->engine->renewAll());
        $this->engine->sweep($this->ana);
        $this->engine->sweepFolder($this->ana, 'inbox');
        self::assertTrue($this->engine->deliverSentCopy($this->ana, new ProviderMessageId('18c0000000000001')));
        self::assertSame([], $this->bus->messages);
        self::assertCount(1, $this->google->requests);

        try {
            (new MailboxAttachmentReader($this->engine))->open($this->ana, new ProviderMessageId('m1'), new ProviderAttachmentId('a1'), 100);
            self::fail('No inbox.');
        } catch (MailboxProviderFailure $failure) {
            self::assertSame(MailboxProviderFailure::REFUSED, $failure->reason);
        }

        $command = new CommandTester(new MailboxStatusCommand($this->engine));
        self::assertSame(0, $command->execute(['mailbox' => 'mbx-ana']));
        self::assertStringContainsString('Provider: gmail (send only: no inbox, nothing is synced)', $command->getDisplay());
        self::assertStringNotContainsString('ana@example.com', $command->getDisplay());
        $command->execute(['mailbox' => 'mbx-unknown']);
        self::assertStringNotContainsString('Provider:', $command->getDisplay());

        // Resume after a pause checks the account again.
        $this->engine->pause($this->ana);
        self::assertSame(MailboxStatus::Active, $this->engine->resume($this->ana)->status);
        self::assertCount(2, $this->google->requests);
        self::assertSame(1, $this->engine->disconnect($this->ana)->rowsDeleted);
    }

    public function testStartPausesOnAnotherAccountOrAMissingScope(): void
    {
        $this->google->userinfo = ['sub' => MailboxGmailFake::SUB, 'email' => 'pedro@example.com', 'email_verified' => true];
        $status                 = $this->engine->start($this->ana);
        self::assertSame([MailboxStatus::PausedGrant, GrantFailureReason::AccountMismatch], [$status->status, $status->reason]);

        $this->ports->add(MemoryMailboxPorts::gmailSettings('mbx-scope'));
        $this->ports->gmailScopes = ['openid', 'email', 'https://www.googleapis.com/auth/gmail.readonly'];
        $status                   = $this->engine->start(new MailboxId('mbx-scope'));
        self::assertSame([MailboxStatus::PausedGrant, GrantFailureReason::ScopeMissing], [$status->status, $status->reason]);
    }

    public function testValueObjectsKnowSendOnlyProviders(): void
    {
        self::assertTrue(MailboxProvider::Gmail->isSendOnly());
        self::assertFalse(MailboxProvider::Graph->isSendOnly());
        self::assertFalse((new MailboxSyncStatus($this->ana, MailboxStatus::Unknown))->isSendOnly());
        self::assertFalse((new MailboxSyncStatus($this->ana, MailboxStatus::Active, provider: MailboxProvider::Graph))->isSendOnly());
        self::assertSame([], MemoryMailboxPorts::gmailSettings()->folders);

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('follows no folders');
        new MailboxSettings($this->ana, MailboxProvider::Gmail, 'ana@example.com', [FolderRef::inbox()]);
    }
}
