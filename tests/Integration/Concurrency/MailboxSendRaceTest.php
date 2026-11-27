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
use Nowo\MailKitBundle\Graph\GraphApiClient;
use Nowo\MailKitBundle\Graph\Inbound\GraphMessageNormalizer;
use Nowo\MailKitBundle\Mailbox\Engine\MailboxSender;
use Nowo\MailKitBundle\Mailbox\Engine\MailboxSendOptions;
use Nowo\MailKitBundle\Mailbox\Engine\MailboxSyncEngine;
use Nowo\MailKitBundle\Mailbox\Engine\SendState;
use Nowo\MailKitBundle\Mailbox\Exception\MailboxSendInProgress;
use Nowo\MailKitBundle\Mailbox\Exception\MailboxSendOutcomeUnknown;
use Nowo\MailKitBundle\Mailbox\Gmail\GmailApiClient;
use Nowo\MailKitBundle\Mailbox\Gmail\GmailMailboxGateway;
use Nowo\MailKitBundle\Mailbox\Graph\GraphMailboxGateway;
use Nowo\MailKitBundle\Mailbox\Html\MailHtmlSanitizer;
use Nowo\MailKitBundle\Mailbox\Html\RemoteImages;
use Nowo\MailKitBundle\Mailbox\Infrastructure\Store\MailboxStore;
use Nowo\MailKitBundle\Mailbox\Value\MailboxId;
use Nowo\MailKitBundle\Mailbox\Value\MailboxProvider;
use Nowo\MailKitBundle\Mailbox\Value\OutgoingAttachment;
use Nowo\MailKitBundle\Mailbox\Value\OutgoingKind;
use Nowo\MailKitBundle\Mailbox\Value\OutgoingMailboxMail;
use Nowo\MailKitBundle\Sender\MailAddress;
use Nowo\MailKitBundle\Tests\Support\MailboxGmailFake;
use Nowo\MailKitBundle\Tests\Support\MailboxGraphFake;
use Nowo\MailKitBundle\Tests\Support\MemoryMailboxPorts;
use Nowo\MailKitBundle\Tests\Support\RecordingBus;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Clock\MockClock;
use Symfony\Component\HttpClient\Response\MockResponse;
use Throwable;

use function getenv;
use function is_string;
use function str_contains;
use function str_ends_with;
use function str_starts_with;

/**
 * 2.5.0 part 2 REQ-RUNTIME-003 races of the send idempotency (SC-001: one send per idempotency key) on two real
 * connections of the same MySQL 8.4 / MariaDB 11 / PostgreSQL database (MAIL_KIT_TEST_DATABASE_URL; skipped on
 * SQLite), with one injected MockClock so the stale delays are deterministic: one claim per key, heartbeats in the same
 * second (MySQL / MariaDB count changed rows only, audit ZAT-R4), one winner per takeover (also against an open
 * transaction), and two workers sending the same key, including an ambiguous submit reconciled by the other worker:
 * one delivery.
 */
#[Group('database')]
final class MailboxSendRaceTest extends TestCase
{
    private const PREFIX = 'race_send_';

    private const KEY = 'race-send-key-0001';

    private Connection $a;

    private Connection $b;

    private MailboxStore $storeA;

    private MailboxStore $storeB;

    private MockClock $clock;

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
        foreach (['mail_kit_mailbox_send', 'mail_kit_mailbox_subscription', 'mail_kit_mailbox_cursor', 'mail_kit_mailbox'] as $table) {
            $this->a->executeStatement('DROP TABLE IF EXISTS ' . self::PREFIX . $table);
        }
        $this->clock  = new MockClock('2026-11-24 12:00:00 UTC');
        $this->storeA = $this->store($this->a);
        $this->storeB = $this->store($this->b);
        $this->storeA->execute($this->storeA->missingTablesSql());
        $this->id = new MailboxId('mbx-ana');
        $this->storeA->insertMailbox($this->id, MailboxProvider::Graph);
    }

    protected function tearDown(): void
    {
        if (isset($this->a)) {
            $this->a->close();
            $this->b->close();
        }
    }

    public function testOneClaimPerKeyAndHeartbeatsInTheSameSecondCount(): void
    {
        self::assertTrue($this->storeA->claimSend($this->id, self::KEY, 'hash', 'token-a'));
        self::assertFalse($this->storeB->claimSend($this->id, self::KEY, 'hash', 'token-b'));
        self::assertSame('token-a', $this->storeB->sendRow($this->id, self::KEY)['token'] ?? null);

        // Audit ZAT-R4: three heartbeats in one second all count; another token never does.
        for ($i = 0; $i < 3; ++$i) {
            self::assertTrue($this->storeA->updateSend($this->id, self::KEY, 'token-a', SendState::Drafting, SendState::Drafting));
        }
        self::assertFalse($this->storeB->updateSend($this->id, self::KEY, 'token-b', SendState::Drafting, SendState::Sending));
        self::assertTrue($this->storeA->updateSend($this->id, self::KEY, 'token-a', SendState::Drafting, SendState::Sending, ['provider_message_id' => 'DRAFT-1']));
    }

    public function testOneWinnerPerTakeoverEvenAgainstAnOpenTransaction(): void
    {
        $this->storeA->claimSend($this->id, self::KEY, 'hash', 'token-0');
        $this->storeA->updateSend($this->id, self::KEY, 'token-0', SendState::Drafting, SendState::Sending, ['provider_message_id' => 'DRAFT-1']);
        $this->clock->modify('+121 seconds');
        $stale = $this->clock->now()->getTimestamp() - 120;

        $this->a->beginTransaction();
        self::assertTrue($this->storeA->takeOverSend($this->id, self::KEY, 'token-0', SendState::Sending, 'token-a', SendState::Sending, $stale));
        $failure = $this->expectFailure(fn (): bool => $this->storeB->takeOverSend($this->id, self::KEY, 'token-0', SendState::Sending, 'token-b', SendState::Sending, $stale));
        self::assertTrue(StorageConflicts::isConflict($failure), $failure::class);
        $this->a->commit();
        self::assertFalse($this->storeB->takeOverSend($this->id, self::KEY, 'token-0', SendState::Sending, 'token-b', SendState::Sending, $stale));
        self::assertSame(['token-a', 2], [$this->storeB->sendRow($this->id, self::KEY)['token'] ?? null, $this->storeB->sendRow($this->id, self::KEY)['attempts'] ?? null]);
    }

    public function testTwoWorkersSendingOneKeyDeliverOnce(): void
    {
        $graph = new MailboxGraphFake();
        $ports = (new MemoryMailboxPorts())->add(MemoryMailboxPorts::settings('mbx-ana', 'ana@example.com', ['inbox']));
        $a     = $this->sender($this->storeA, $graph, $ports);
        $b     = $this->sender($this->storeB, $graph, $ports);
        $mail  = new OutgoingMailboxMail(OutgoingKind::New, null, [new MailAddress('pedro@example.org')], [], [], 'Plan', 'Hello', null, [], self::KEY);

        // B runs while A is inside its submit: B sends nothing.
        $inner           = null;
        $graph->override = static function (string $method, string $path) use (&$inner, $b, $mail): ?MockResponse {
            if ($method === 'POST' && str_contains($path, '/send') && !$inner instanceof Throwable) {
                try {
                    $b->send(new MailboxId('mbx-ana'), $mail);
                } catch (Throwable $failure) {
                    $inner = $failure;
                }
            }

            return null;
        };
        self::assertFalse($a->send($this->id, $mail)->replayed);
        self::assertInstanceOf(MailboxSendInProgress::class, $inner);
        self::assertTrue($b->send($this->id, $mail)->replayed);
        self::assertSame(['DRAFT-1'], $graph->delivered);

        // An ambiguous submit by A is reconciled by B once stale: the same draft, one delivery.
        $second          = new OutgoingMailboxMail(OutgoingKind::New, null, [new MailAddress('pedro@example.org')], [], [], 'Plan 2', 'Hello', null, [], 'race-send-key-0002');
        $first           = true;
        $graph->override = static function (string $method, string $path) use (&$first): ?MockResponse {
            if ($first && $method === 'POST' && str_contains($path, '/DRAFT-2/send')) {
                $first = false;

                return MailboxGraphFake::json([], 504);
            }

            return null;
        };

        try {
            $a->send($this->id, $second);
            self::fail('Pending.');
        } catch (MailboxSendInProgress $pending) {
            self::assertTrue($pending->reconciling);
        }
        $graph->override = null;
        $this->clock->modify('+121 seconds');
        self::assertTrue($b->reconcile($this->id, 'race-send-key-0002'));
        self::assertTrue($a->send($this->id, $second)->replayed);
        self::assertSame(['DRAFT-1', 'DRAFT-2'], $graph->delivered);
    }

    public function testAStalledHolderNeverDeletesTheDraftAnotherWorkerSent(): void
    {
        // Audit ZAV-F1: A's submit is refused (4xx); A stalls before deciding; B takes the stale key over, finds the
        // draft still a draft and sends it. A's late "still a draft" view must neither delete it nor mark it failed.
        $graph           = new MailboxGraphFake();
        $ports           = (new MemoryMailboxPorts())->add(MemoryMailboxPorts::settings('mbx-ana', 'ana@example.com', ['inbox']));
        $a               = $this->sender($this->storeA, $graph, $ports);
        $b               = $this->sender($this->storeB, $graph, $ports);
        $mail            = new OutgoingMailboxMail(OutgoingKind::New, null, [new MailAddress('pedro@example.org')], [], [], 'Plan', 'Hello', null, [], self::KEY);
        $clock           = $this->clock;
        $submits         = 0;
        $stalled         = false;
        $graph->override = static function (string $method, string $path) use (&$submits, &$stalled, $b, $clock): ?MockResponse {
            if ($method === 'POST' && str_ends_with($path, '/send') && ++$submits === 1) {
                return MailboxGraphFake::json(['error' => ['code' => 'ErrorInvalidRecipients']], 400);
            }
            if ($method === 'GET' && str_contains($path, 'isDraft') && !$stalled) {
                $stalled = true;
                $clock->modify('+121 seconds');
                self::assertTrue($b->reconcile(new MailboxId('mbx-ana'), self::KEY));

                return MailboxGraphFake::json(['id' => 'DRAFT-1', 'isDraft' => true]);
            }

            return null;
        };

        self::assertTrue($a->send($this->id, $mail)->replayed);
        self::assertSame(['DRAFT-1'], $graph->delivered);
        self::assertSame([], $graph->deleted);
        self::assertSame(SendState::Sent, $this->storeA->sendRow($this->id, self::KEY)['status'] ?? null);
    }

    public function testAHolderThatLostTheKeyWhileDraftingAnswersTheNewHoldersSend(): void
    {
        // Audit ZAV-F2: A stalls on an attachment; B takes the stale `drafting` key over and sends; A's attachment then
        // fails. A must answer B's outcome (a replay), not a failure of its own, and nothing is sent twice.
        $graph           = new MailboxGraphFake();
        $ports           = (new MemoryMailboxPorts())->add(MemoryMailboxPorts::settings('mbx-ana', 'ana@example.com', ['inbox']));
        $a               = $this->sender($this->storeA, $graph, $ports);
        $b               = $this->sender($this->storeB, $graph, $ports);
        $mail            = new OutgoingMailboxMail(OutgoingKind::New, null, [new MailAddress('pedro@example.org')], [], [], 'Plan', 'Hello', null, [OutgoingAttachment::fromString('a', 'a.txt')], self::KEY);
        $clock           = $this->clock;
        $stalled         = false;
        $graph->override = static function (string $method, string $path) use (&$stalled, $b, $mail, $clock): ?MockResponse {
            if ($method === 'POST' && str_ends_with($path, '/DRAFT-1/attachments') && !$stalled) {
                $stalled = true;
                $clock->modify('+121 seconds');
                self::assertFalse($b->send(new MailboxId('mbx-ana'), $mail)->replayed);

                return MailboxGraphFake::json([], 503);
            }

            return null;
        };

        self::assertTrue($a->send($this->id, $mail)->replayed);
        self::assertSame(['DRAFT-2'], $graph->delivered);
        self::assertSame(SendState::Sent, $this->storeA->sendRow($this->id, self::KEY)['status'] ?? null);
    }

    public function testTwoWorkersSendingOneGmailKeyDeliverOnce(): void
    {
        // Phase B2: send-only Gmail has no draft to reconcile; the claim alone keeps one delivery per key.
        $this->storeA->insertMailbox(new MailboxId('mbx-gmail'), MailboxProvider::Gmail);
        $google = new MailboxGmailFake();
        $ports  = (new MemoryMailboxPorts())->add(MemoryMailboxPorts::gmailSettings('mbx-gmail'));
        $a      = $this->gmailSender($this->storeA, $google, $ports);
        $b      = $this->gmailSender($this->storeB, $google, $ports);
        $id     = new MailboxId('mbx-gmail');
        $mail   = new OutgoingMailboxMail(OutgoingKind::New, null, [new MailAddress('pedro@example.org')], [], [], 'Plan', 'Hello', null, [], self::KEY);

        // B runs while A is inside its submit: B sends nothing and later replays A's ids.
        $inner            = null;
        $google->override = static function (string $method, string $url) use (&$inner, $b, $mail, $id): ?MockResponse {
            if ($url === GmailApiClient::SEND_URL && !$inner instanceof Throwable) {
                try {
                    $b->send($id, $mail);
                } catch (Throwable $failure) {
                    $inner = $failure;
                }
            }

            return null;
        };
        $sent = $a->send($id, $mail);
        self::assertInstanceOf(MailboxSendInProgress::class, $inner);
        self::assertSame($sent->id->value, $b->send($id, $mail)->id->value);
        self::assertCount(1, $google->sent);

        // A hangs inside its submit past the stale delay: B settles the key unknown (never resent); A's late answer
        // finds the key settled and reports it unknown too. One delivery.
        $second           = new OutgoingMailboxMail(OutgoingKind::New, null, [new MailAddress('pedro@example.org')], [], [], 'Plan 2', 'Hello', null, [], 'race-send-key-0002');
        $clock            = $this->clock;
        $settled          = null;
        $google->override = static function (string $method, string $url) use (&$settled, $b, $second, $id, $clock): ?MockResponse {
            if ($url === GmailApiClient::SEND_URL && !$settled instanceof Throwable) {
                $clock->modify('+121 seconds');

                try {
                    $b->send($id, $second);
                } catch (Throwable $failure) {
                    $settled = $failure;
                }
            }

            return null;
        };

        try {
            $a->send($id, $second);
            self::fail('Settled by B.');
        } catch (MailboxSendOutcomeUnknown) {
        }
        self::assertInstanceOf(MailboxSendOutcomeUnknown::class, $settled);
        self::assertSame(SendState::Unknown, $this->storeB->sendRow($id, 'race-send-key-0002')['status'] ?? null);
        self::assertCount(2, $google->sent);
    }

    private function gmailSender(MailboxStore $store, MailboxGmailFake $google, MemoryMailboxPorts $ports): MailboxSender
    {
        $bus    = new RecordingBus();
        $engine = new MailboxSyncEngine($store, $ports, $ports, $ports, $ports, $ports, ['gmail' => new GmailMailboxGateway(new GmailApiClient($google->client()))], $bus, clock: $this->clock);

        return new MailboxSender($engine, $store, $bus, new MailboxSendOptions(staleClaimSeconds: 120), $this->clock);
    }

    private function sender(MailboxStore $store, MailboxGraphFake $graph, MemoryMailboxPorts $ports): MailboxSender
    {
        $bus    = new RecordingBus();
        $engine = new MailboxSyncEngine(
            $store,
            $ports,
            $ports,
            $ports,
            $ports,
            $ports,
            ['graph' => new GraphMailboxGateway(new GraphApiClient($graph->client(), null), new GraphMessageNormalizer(new MailHtmlSanitizer(RemoteImages::Blocked)))],
            $bus,
            clock: $this->clock,
        );

        return new MailboxSender($engine, $store, $bus, new MailboxSendOptions(staleClaimSeconds: 120), $this->clock);
    }

    private function store(Connection $connection): MailboxStore
    {
        $registry = $this->createStub(ManagerRegistry::class);
        $registry->method('getConnection')->willReturn($connection);

        return new MailboxStore($registry, null, self::PREFIX, $this->clock);
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
}
