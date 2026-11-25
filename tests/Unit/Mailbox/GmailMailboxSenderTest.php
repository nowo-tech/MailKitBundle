<?php

declare(strict_types=1);

namespace Nowo\MailKitBundle\Tests\Unit\Mailbox;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\DriverManager;
use Doctrine\Persistence\ManagerRegistry;
use InvalidArgumentException;
use Nowo\MailKitBundle\Mailbox\Engine\MailboxSender;
use Nowo\MailKitBundle\Mailbox\Engine\MailboxSendOptions;
use Nowo\MailKitBundle\Mailbox\Engine\MailboxSyncEngine;
use Nowo\MailKitBundle\Mailbox\Engine\MailboxSyncOptions;
use Nowo\MailKitBundle\Mailbox\Engine\SendState;
use Nowo\MailKitBundle\Mailbox\Event\MailboxGrantFailedEvent;
use Nowo\MailKitBundle\Mailbox\Exception\MailboxGrantFailure;
use Nowo\MailKitBundle\Mailbox\Exception\MailboxProviderFailure;
use Nowo\MailKitBundle\Mailbox\Exception\MailboxSendFailed;
use Nowo\MailKitBundle\Mailbox\Exception\MailboxSendInProgress;
use Nowo\MailKitBundle\Mailbox\Exception\MailboxSendOutcomeUnknown;
use Nowo\MailKitBundle\Mailbox\Exception\MailboxTokenUnavailable;
use Nowo\MailKitBundle\Mailbox\Gmail\GmailApiClient;
use Nowo\MailKitBundle\Mailbox\Gmail\GmailMailboxGateway;
use Nowo\MailKitBundle\Mailbox\Infrastructure\Store\MailboxStore;
use Nowo\MailKitBundle\Mailbox\Value\GrantFailureReason;
use Nowo\MailKitBundle\Mailbox\Value\MailboxAccessToken;
use Nowo\MailKitBundle\Mailbox\Value\MailboxId;
use Nowo\MailKitBundle\Mailbox\Value\MailboxStatus;
use Nowo\MailKitBundle\Mailbox\Value\OutgoingAttachment;
use Nowo\MailKitBundle\Mailbox\Value\OutgoingKind;
use Nowo\MailKitBundle\Mailbox\Value\OutgoingMailboxMail;
use Nowo\MailKitBundle\Mailbox\Value\ProviderCall;
use Nowo\MailKitBundle\Mailbox\Value\ProviderMessageId;
use Nowo\MailKitBundle\Sender\MailAddress;
use Nowo\MailKitBundle\Tests\Support\MailboxGmailFake;
use Nowo\MailKitBundle\Tests\Support\MemoryMailboxPorts;
use Nowo\MailKitBundle\Tests\Support\RecordingBus;
use Nowo\MailKitBundle\Tests\Support\RecordingEventDispatcher;
use Nowo\MailKitBundle\Tests\Support\RecordingLogger;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Clock\MockClock;
use Symfony\Component\HttpClient\Response\MockResponse;

use function array_map;

/**
 * Phase B2: sending from a send-only Gmail mailbox on SQLite with the fake Google endpoints. One submit per key, decided
 * by the answer alone: 4xx sent nothing (released or failed), anything else unknown and never resent; replies refused
 * before the claim; the account check before every send; a stale `sending` row settled unknown. Fake tokens only.
 */
final class GmailMailboxSenderTest extends TestCase
{
    private const KEY = 'beacon-gmail-0001';

    private Connection $connection;

    private MailboxStore $store;

    private MailboxGmailFake $google;

    private MemoryMailboxPorts $ports;

    private RecordingBus $bus;

    private RecordingEventDispatcher $events;

    private RecordingLogger $log;

    private MockClock $clock;

    private MailboxSyncEngine $engine;

    private MailboxSender $sender;

    private MailboxId $ana;

    protected function setUp(): void
    {
        $this->connection = DriverManager::getConnection(['driver' => 'pdo_sqlite', 'memory' => true]);
        $registry         = $this->createStub(ManagerRegistry::class);
        $registry->method('getConnection')->willReturn($this->connection);
        $this->clock = new MockClock('2026-11-25 12:00:00 UTC');
        $this->log   = new RecordingLogger();
        $this->store = new MailboxStore($registry, null, '', $this->clock, $this->log);
        $this->store->execute($this->store->missingTablesSql());
        $this->google = new MailboxGmailFake();
        $this->ports  = (new MemoryMailboxPorts())->add(MemoryMailboxPorts::gmailSettings('mbx-ana'));
        $this->bus    = new RecordingBus();
        $this->events = new RecordingEventDispatcher();
        $this->engine = new MailboxSyncEngine(
            $this->store,
            $this->ports,
            $this->ports,
            $this->ports,
            $this->ports,
            $this->ports,
            ['gmail' => new GmailMailboxGateway(new GmailApiClient($this->google->client(), 5.0, $this->log))],
            $this->bus,
            new MailboxSyncOptions(notificationUrl: 'https://app.example.com/hooks/mail-kit/v1/mailbox/graph'),
            $this->events,
            $this->clock,
            $this->log,
        );
        $this->sender = new MailboxSender($this->engine, $this->store, $this->bus, new MailboxSendOptions(staleClaimSeconds: 120), $this->clock, $this->log);
        $this->ana    = new MailboxId('mbx-ana');
        self::assertSame(MailboxStatus::Active, $this->engine->start($this->ana)->status);
        $this->google->requests = [];
        $this->ports->calls     = [];
    }

    public function testANewMessageIsSentOnceWithoutASentCopy(): void
    {
        $mail = $this->mail(attachments: [OutgoingAttachment::fromString('hello', 'note.txt', 'text/plain')]);
        $sent = $this->sender->send($this->ana, $mail);
        self::assertSame('18c0000000000001', $sent->id->value);
        self::assertMatchesRegularExpression('/^[0-9a-f]{32}\.mailkit@example\.com\z/', $sent->internetMessageId);
        self::assertSame('provider:gmail:18c0000000000001', $sent->threadKey->value);
        self::assertFalse($sent->replayed);
        self::assertCount(1, $this->google->sent);
        self::assertStringContainsString('Message-ID: <' . $sent->internetMessageId . '>', $this->google->sent[0]);
        self::assertStringContainsString('filename=note.txt', $this->google->sent[0]);
        self::assertSame([], $this->bus->messages, 'no sent copy: there is no inbox');
        self::assertSame(['account', 'send'], array_map(static fn (ProviderCall $c): string => $c->operation, $this->ports->calls));

        // The same key again: the stored ids, nothing sent.
        $again = $this->sender->send($this->ana, $mail);
        self::assertTrue($again->replayed);
        self::assertSame([$sent->id->value, $sent->internetMessageId, 'provider:gmail:18c0000000000001'], [$again->id->value, $again->internetMessageId, $again->threadKey->value]);
        self::assertCount(1, $this->google->sent);
    }

    public function testRepliesAreRefusedBeforeTheClaim(): void
    {
        try {
            $this->sender->send($this->ana, new OutgoingMailboxMail(OutgoingKind::Forward, new ProviderMessageId('orig'), [new MailAddress('pedro@example.org')], [], [], null, 'Hi', null, [], self::KEY));
            self::fail('Send-only.');
        } catch (InvalidArgumentException $refused) {
            self::assertStringContainsString('send-only', $refused->getMessage());
        }
        self::assertNull($this->store->sendRow($this->ana, self::KEY));
        self::assertSame([], $this->google->requests);
    }

    public function testA401IsRetriedOnceWithAFreshToken(): void
    {
        $submits                = 0;
        $this->google->override = static function (string $method, string $url) use (&$submits): ?MockResponse {
            return $url === GmailApiClient::SEND_URL && ++$submits === 1 ? MailboxGmailFake::json([], 401) : null;
        };
        self::assertFalse($this->sender->send($this->ana, $this->mail())->replayed);
        self::assertCount(1, $this->ports->rejectedTokens);
        $sends = $this->google->to(GmailApiClient::SEND_URL);
        self::assertCount(2, $sends);
        self::assertNotSame($sends[0]['authorization'], $sends[1]['authorization']);
        self::assertCount(1, $this->google->sent);
    }

    public function testASecond401PausesTheMailboxAndReleasesTheKey(): void
    {
        $this->google->override = static fn (string $method, string $url): ?MockResponse => $url === GmailApiClient::SEND_URL ? MailboxGmailFake::json([], 401) : null;

        try {
            $this->sender->send($this->ana, $this->mail());
            self::fail('Revoked.');
        } catch (MailboxGrantFailure $failure) {
            self::assertSame(GrantFailureReason::Revoked, $failure->reason);
        }
        self::assertSame(SendState::Released, $this->store->sendRow($this->ana, self::KEY)['status'] ?? null);
        self::assertSame(GrantFailureReason::Revoked, $this->engine->status($this->ana)->reason);
        self::assertCount(1, $this->events->events);
        self::assertInstanceOf(MailboxGrantFailedEvent::class, $this->events->events[0]);

        // After the host reconnects, the same key sends.
        $this->google->override = null;
        self::assertSame(MailboxStatus::Active, $this->engine->resume($this->ana)->status);
        self::assertFalse($this->sender->send($this->ana, $this->mail())->replayed);
        self::assertCount(1, $this->google->sent);
    }

    public function testAScopeRefusalPausesAndARateLimitReleasesForALaterRetry(): void
    {
        $this->google->override = static fn (string $method, string $url): ?MockResponse => $url === GmailApiClient::SEND_URL ? MailboxGmailFake::error(403, 'insufficientPermissions', 'PERMISSION_DENIED') : null;

        try {
            $this->sender->send($this->ana, $this->mail());
            self::fail('Scope.');
        } catch (MailboxGrantFailure $failure) {
            self::assertSame(GrantFailureReason::ScopeMissing, $failure->reason);
        }
        self::assertSame(SendState::Released, $this->store->sendRow($this->ana, self::KEY)['status'] ?? null);
        $this->engine->resume($this->ana);

        $this->google->override = static fn (string $method, string $url): ?MockResponse => $url === GmailApiClient::SEND_URL ? MailboxGmailFake::error(429, 'rateLimitExceeded', 'RESOURCE_EXHAUSTED') : null;

        try {
            $this->sender->send($this->ana, $this->mail());
            self::fail('Rate limit.');
        } catch (MailboxProviderFailure $failure) {
            self::assertTrue($failure->isRetryable());
            self::assertSame(429, $failure->status);
        }
        self::assertSame(SendState::Released, $this->store->sendRow($this->ana, self::KEY)['status'] ?? null);
        self::assertSame(MailboxStatus::Active, $this->engine->status($this->ana)->status);

        $this->google->override = null;
        self::assertFalse($this->sender->send($this->ana, $this->mail())->replayed);
        self::assertCount(1, $this->google->sent);
    }

    public function testADefiniteRefusalFailsTheKey(): void
    {
        $this->google->override = static fn (string $method, string $url): ?MockResponse => $url === GmailApiClient::SEND_URL ? MailboxGmailFake::error(400, 'invalidArgument', 'INVALID_ARGUMENT') : null;
        foreach ([1, 2] as $try) {
            try {
                $this->sender->send($this->ana, $this->mail());
                self::fail('Refused.');
            } catch (MailboxSendFailed $failed) {
                self::assertSame('refused:invalidArgument', $failed->reason);
            }
        }
        self::assertCount(1, $this->google->to(GmailApiClient::SEND_URL));
        self::assertSame(SendState::Failed, $this->store->sendRow($this->ana, self::KEY)['status'] ?? null);
    }

    public function testUnconfirmedOutcomesAreUnknownAndNeverResent(): void
    {
        $answers = [
            'unconfirmed-key-0001' => MailboxGmailFake::json([], 503),
            'unconfirmed-key-0002' => new MockResponse('', ['error' => 'connection reset']),
            'unconfirmed-key-0003' => MailboxGmailFake::json(['labelIds' => ['SENT']]),
        ];
        foreach ($answers as $key => $answer) {
            $this->google->override = static fn (string $method, string $url): ?MockResponse => $url === GmailApiClient::SEND_URL ? $answer : null;
            foreach ([1, 2] as $try) {
                try {
                    $this->sender->send($this->ana, $this->mail(key: $key));
                    self::fail('Unknown.');
                } catch (MailboxSendOutcomeUnknown) {
                }
            }
            self::assertSame([SendState::Unknown, 'submit_unconfirmed'], [$this->store->sendRow($this->ana, $key)['status'] ?? null, $this->store->sendRow($this->ana, $key)['reason'] ?? null]);
        }
        self::assertCount(3, $this->google->to(GmailApiClient::SEND_URL));
        self::assertTrue($this->log->has('it is never sent again'));
    }

    public function testFailuresBeforeTheSubmitSendNothing(): void
    {
        // Another account behind the token: paused, nothing sent.
        $this->google->userinfo = ['sub' => MailboxGmailFake::SUB, 'email' => 'pedro@example.com', 'email_verified' => true];

        try {
            $this->sender->send($this->ana, $this->mail());
            self::fail('Mismatch.');
        } catch (MailboxGrantFailure $failure) {
            self::assertSame(GrantFailureReason::AccountMismatch, $failure->reason);
        }
        self::assertSame([], $this->google->to(GmailApiClient::SEND_URL));
        $this->google->userinfo = ['sub' => MailboxGmailFake::SUB, 'email' => 'ana@example.com', 'email_verified' => true];
        $this->engine->resume($this->ana);

        // A message Gmail cannot take (an inline part without "@" in its Content-ID): failed before the submit.
        try {
            $this->sender->send($this->ana, $this->mail(key: 'bad-cid-key-00001', attachments: [OutgoingAttachment::fromString('x', 'x.png', 'image/png', true, 'logo')]));
            self::fail('Refused.');
        } catch (MailboxSendFailed) {
        }
        self::assertSame(SendState::Failed, $this->store->sendRow($this->ana, 'bad-cid-key-00001')['status'] ?? null);
        self::assertSame([], $this->google->to(GmailApiClient::SEND_URL));
    }

    public function testTheTokenPortFailingOnTheRetryReleasesTheKey(): void
    {
        $this->google->override     = static fn (string $method, string $url): ?MockResponse => $url === GmailApiClient::SEND_URL ? MailboxGmailFake::json([], 401) : null;
        $issued                     = 0;
        $this->ports->tokenOverride = static function () use (&$issued): ?MailboxAccessToken {
            if (++$issued === 2) {
                throw new MailboxTokenUnavailable('Host token endpoint down (fixture).');
            }

            return null;
        };

        try {
            $this->sender->send($this->ana, $this->mail());
            self::fail('Unavailable.');
        } catch (MailboxTokenUnavailable) {
        }
        self::assertSame(SendState::Released, $this->store->sendRow($this->ana, self::KEY)['status'] ?? null);
        self::assertCount(1, $this->google->to(GmailApiClient::SEND_URL));
        self::assertSame(MailboxStatus::Active, $this->engine->status($this->ana)->status);
    }

    public function testASlowRefreshPastTheSubmitWindowSendsNothing(): void
    {
        $clock                  = $this->clock;
        $this->google->override = static function (string $method, string $url) use ($clock): ?MockResponse {
            if ($url !== GmailApiClient::SEND_URL) {
                return null;
            }
            $clock->modify('+61 seconds');

            return MailboxGmailFake::json([], 401);
        };

        try {
            $this->sender->send($this->ana, $this->mail());
            self::fail('Window.');
        } catch (MailboxProviderFailure $failure) {
            self::assertTrue($failure->isRetryable());
            self::assertStringContainsString('nothing was sent', $failure->getMessage());
        }
        self::assertSame(SendState::Released, $this->store->sendRow($this->ana, self::KEY)['status'] ?? null);
        self::assertCount(1, $this->google->to(GmailApiClient::SEND_URL));
    }

    public function testAHolderThatLostTheKeySubmitsNothingAndAnswersTheRowsOutcome(): void
    {
        // The key changed hands between the first (401) submit and the retry: the heartbeat fails, nothing is resubmitted.
        $connection             = $this->connection;
        $this->google->override = static function (string $method, string $url) use ($connection): ?MockResponse {
            if ($url !== GmailApiClient::SEND_URL) {
                return null;
            }
            $connection->executeStatement("UPDATE mail_kit_mailbox_send SET claim_token = 'another-worker'");

            return MailboxGmailFake::json([], 401);
        };

        try {
            $this->sender->send($this->ana, $this->mail());
            self::fail('Lost.');
        } catch (MailboxSendInProgress $pending) {
            self::assertTrue($pending->reconciling);
        }
        self::assertCount(1, $this->google->to(GmailApiClient::SEND_URL));

        // Lost while the message went out: the new holder recorded the send, so this call answers a replay.
        $this->google->override = static function (string $method, string $url) use ($connection): ?MockResponse {
            if ($url === GmailApiClient::SEND_URL) {
                $connection->executeStatement("UPDATE mail_kit_mailbox_send SET claim_token = 'another-worker', status = 'sent', provider_message_id = '18cfeed', thread_id = '18cfeed', sent_at = 1 WHERE idempotency_key = 'lost-key-00000001'");
            }

            return null;
        };
        $replay = $this->sender->send($this->ana, $this->mail(key: 'lost-key-00000001'));
        self::assertTrue($replay->replayed);
        self::assertSame(['18cfeed', 'provider:gmail:18cfeed'], [$replay->id->value, $replay->threadKey->value]);

        // Lost before recording a failure, an unknown outcome or a release: the holder's state is answered.
        $answers = ['lost-key-00000002' => MailboxGmailFake::error(400, 'invalidArgument'), 'lost-key-00000003' => MailboxGmailFake::json([], 503), 'lost-key-00000004' => MailboxGmailFake::error(429, 'rateLimitExceeded')];
        foreach ($answers as $key => $answer) {
            $this->google->override = static function (string $method, string $url) use ($connection, $key, $answer): ?MockResponse {
                if ($url !== GmailApiClient::SEND_URL) {
                    return null;
                }
                $connection->executeStatement("UPDATE mail_kit_mailbox_send SET claim_token = 'another-worker' WHERE idempotency_key = ?", [$key]);

                return $answer;
            };

            try {
                $this->sender->send($this->ana, $this->mail(key: $key));
                self::fail('Lost.');
            } catch (MailboxSendInProgress) {
            }
        }
    }

    public function testAReleaseThatCannotBeWrittenStillReportsTheFailure(): void
    {
        $this->connection->executeStatement("CREATE TRIGGER no_release BEFORE UPDATE ON mail_kit_mailbox_send WHEN NEW.status = 'released' BEGIN SELECT RAISE(ABORT, 'disk full'); END");
        $this->google->override = static fn (string $method, string $url): ?MockResponse => $url === GmailApiClient::SEND_URL ? MailboxGmailFake::error(429, 'rateLimitExceeded') : null;

        try {
            $this->sender->send($this->ana, $this->mail());
            self::fail('Rate limit.');
        } catch (MailboxProviderFailure $failure) {
            self::assertSame(429, $failure->status);
        }
        self::assertTrue($this->log->has('the released claim could not be recorded'));
        self::assertSame(SendState::Sending, $this->store->sendRow($this->ana, self::KEY)['status'] ?? null);
    }

    public function testAStaleSendingRowIsSettledUnknownNeverResent(): void
    {
        $this->connection->executeStatement("INSERT INTO mail_kit_mailbox_send (mailbox_id, idempotency_key, payload_hash, status, claim_token, attempts, row_version, claimed_at, updated_at) VALUES ('mbx-ana', ?, ?, 'sending', 't0', 1, 0, ?, ?)", [self::KEY, $this->mail()->fingerprint(), $this->clock->now()->getTimestamp(), $this->clock->now()->getTimestamp()]);

        // Fresh: in flight.
        try {
            $this->sender->send($this->ana, $this->mail());
            self::fail('In flight.');
        } catch (MailboxSendInProgress $pending) {
            self::assertTrue($pending->reconciling);
        }
        self::assertFalse($this->sender->reconcile($this->ana, self::KEY));

        // Stale: one caller settles it unknown; nothing is sent.
        $this->clock->modify('+121 seconds');

        try {
            $this->sender->send($this->ana, $this->mail());
            self::fail('Unknown.');
        } catch (MailboxSendOutcomeUnknown) {
        }
        self::assertSame([SendState::Unknown, 'submit_interrupted'], [$this->store->sendRow($this->ana, self::KEY)['status'] ?? null, $this->store->sendRow($this->ana, self::KEY)['reason'] ?? null]);
        self::assertSame([], $this->google->to(GmailApiClient::SEND_URL));

        // The background reconcile settles another stale row the same way.
        $this->connection->executeStatement("INSERT INTO mail_kit_mailbox_send (mailbox_id, idempotency_key, payload_hash, status, claim_token, attempts, row_version, claimed_at, updated_at) VALUES ('mbx-ana', 'stale-key-0000001', 'h', 'sending', 't0', 1, 0, 0, 0)");
        self::assertTrue($this->sender->reconcile($this->ana, 'stale-key-0000001'));
        self::assertSame(SendState::Unknown, $this->store->sendRow($this->ana, 'stale-key-0000001')['status'] ?? null);

        // A settle whose unknown write meets another holder answers that holder's state.
        $this->connection->executeStatement("INSERT INTO mail_kit_mailbox_send (mailbox_id, idempotency_key, payload_hash, status, claim_token, attempts, row_version, claimed_at, updated_at) VALUES ('mbx-ana', 'stale-key-0000002', 'h', 'sending', 't0', 1, 0, 0, 0)");
        $this->connection->executeStatement("CREATE TRIGGER lose_unknown BEFORE UPDATE ON mail_kit_mailbox_send WHEN NEW.status = 'unknown' BEGIN SELECT RAISE(IGNORE); END");
        self::assertFalse($this->sender->reconcile($this->ana, 'stale-key-0000002'));
        self::assertSame([], $this->google->to(GmailApiClient::SEND_URL));
    }

    /**
     * @param list<OutgoingAttachment> $attachments
     */
    private function mail(string $key = self::KEY, array $attachments = []): OutgoingMailboxMail
    {
        return new OutgoingMailboxMail(OutgoingKind::New, null, [new MailAddress('pedro@example.org', 'Pedro')], [], [new MailAddress('boss@example.org')], 'Plan', 'Hello', '<p>Hello</p>', $attachments, $key);
    }
}
