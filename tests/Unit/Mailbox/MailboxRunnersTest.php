<?php

declare(strict_types=1);

namespace Nowo\MailKitBundle\Tests\Unit\Mailbox;

use DateTimeImmutable;
use Doctrine\DBAL\Schema\Schema;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Tools\Event\GenerateSchemaEventArgs;
use Doctrine\Persistence\ManagerRegistry;
use InvalidArgumentException;
use Nowo\MailKitBundle\Mailbox\Engine\MailboxAttachmentReader;
use Nowo\MailKitBundle\Mailbox\Engine\MailboxSession;
use Nowo\MailKitBundle\Mailbox\Event\MailboxGrantFailedEvent;
use Nowo\MailKitBundle\Mailbox\Exception\MailboxGrantFailure;
use Nowo\MailKitBundle\Mailbox\Exception\MailboxNotActive;
use Nowo\MailKitBundle\Mailbox\Exception\MailboxProviderFailure;
use Nowo\MailKitBundle\Mailbox\Exception\MailboxTokenUnavailable;
use Nowo\MailKitBundle\Mailbox\Infrastructure\Command\MailboxSchemaCommand;
use Nowo\MailKitBundle\Mailbox\Infrastructure\Command\MailboxStatusCommand;
use Nowo\MailKitBundle\Mailbox\Infrastructure\Command\MailboxSubscriptionsCommand;
use Nowo\MailKitBundle\Mailbox\Infrastructure\Command\MailboxSweepCommand;
use Nowo\MailKitBundle\Mailbox\Infrastructure\Messenger\FetchMailboxMessage;
use Nowo\MailKitBundle\Mailbox\Infrastructure\Messenger\MailboxMessageHandler;
use Nowo\MailKitBundle\Mailbox\Infrastructure\Messenger\RenewMailboxSubscriptions;
use Nowo\MailKitBundle\Mailbox\Infrastructure\Messenger\SyncMailboxFolder;
use Nowo\MailKitBundle\Mailbox\Infrastructure\Store\MailboxSchemaListener;
use Nowo\MailKitBundle\Mailbox\Infrastructure\Webhook\MailboxWebhookRouteLoader;
use Nowo\MailKitBundle\Mailbox\Port\MailboxCallObserverInterface;
use Nowo\MailKitBundle\Mailbox\Value\GrantFailureReason;
use Nowo\MailKitBundle\Mailbox\Value\MailboxAccessToken;
use Nowo\MailKitBundle\Mailbox\Value\MailboxId;
use Nowo\MailKitBundle\Mailbox\Value\MailboxProvider;
use Nowo\MailKitBundle\Mailbox\Value\MailboxStatus;
use Nowo\MailKitBundle\Mailbox\Value\ProviderAttachmentId;
use Nowo\MailKitBundle\Mailbox\Value\ProviderCall;
use Nowo\MailKitBundle\Mailbox\Value\ProviderMessageId;
use Nowo\MailKitBundle\Tests\Support\MailboxGraphFake;
use Nowo\MailKitBundle\Tests\Support\MailboxHarness;
use Nowo\MailKitBundle\Tests\Support\MemoryMailboxPorts;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use Symfony\Component\Console\Tester\CommandTester;
use Symfony\Component\HttpClient\Response\MockResponse;
use Symfony\Component\Messenger\Exception\UnrecoverableMessageHandlingException;
use Symfony\Component\Messenger\Stamp\DelayStamp;
use Throwable;

use function str_repeat;
use function stream_get_contents;

/**
 * 2.5.0: the attachment reader, the Messenger handler's retry decisions, the per-operation session, the commands and
 * the route loader.
 */
final class MailboxRunnersTest extends TestCase
{
    use MailboxHarness;

    private MailboxId $ana;

    protected function setUp(): void
    {
        $this->harness();
        $this->ana = new MailboxId('mbx-ana');
        $this->ports->add(MemoryMailboxPorts::settings());
    }

    public function testTheReaderStreamsFromAnActiveMailboxOnly(): void
    {
        $reader                         = new MailboxAttachmentReader($this->engine, 50);
        $this->graph->attachments['m1'] = [['@odata.type' => '#microsoft.graph.fileAttachment', 'id' => 'a1', 'name' => 'notes.txt', '_bytes' => 'plain text']];

        try {
            $reader->open($this->ana, new ProviderMessageId('m1'), new ProviderAttachmentId('a1'), 10);
            self::fail('Not started yet.');
        } catch (MailboxNotActive $notActive) {
            self::assertSame(MailboxStatus::Unknown, $notActive->status);
        }
        $this->engine->start($this->ana);
        $file = $reader->open($this->ana, new ProviderMessageId('m1'), new ProviderAttachmentId('a1'), 1000);
        self::assertSame(['plain text', 'text/plain', 'notes.txt'], [stream_get_contents($file->stream), $file->contentType, $file->filename]);

        // The configured ceiling wins over the caller's limit.
        $this->graph->attachments['m1'][0]['_bytes'] = str_repeat('x', 60);
        $this->graph->attachments['m1'][0]['size']   = 60;
        try {
            $reader->open($this->ana, new ProviderMessageId('m1'), new ProviderAttachmentId('a1'), 1000);
            self::fail('Over the ceiling.');
        } catch (MailboxProviderFailure $failure) {
            self::assertSame(MailboxProviderFailure::TOO_LARGE, $failure->reason);
        }

        // A 403 on one attachment never pauses the mailbox (audit ZAT-I4).
        $this->graph->override = static fn (): MockResponse => MailboxGraphFake::json(['error' => ['code' => 'ErrorAccessDenied']], 403);
        try {
            $reader->open($this->ana, new ProviderMessageId('m1'), new ProviderAttachmentId('a1'), 10);
            self::fail('Expected a refusal.');
        } catch (MailboxProviderFailure $failure) {
            self::assertSame(MailboxProviderFailure::REFUSED, $failure->reason);
        }
        self::assertSame(MailboxStatus::Active, $this->engine->status($this->ana)->status);

        $this->graph->override = static fn (): MockResponse => MailboxGraphFake::json(['error' => ['code' => 'InvalidAuthenticationToken']], 401);
        try {
            $reader->open($this->ana, new ProviderMessageId('m1'), new ProviderAttachmentId('a1'), 10);
            self::fail('Expected the grant failure.');
        } catch (MailboxGrantFailure $failure) {
            self::assertSame(GrantFailureReason::Revoked, $failure->reason);
        }
        self::assertCount(1, $this->events->of(MailboxGrantFailedEvent::class));
        self::assertSame(MailboxStatus::PausedGrant, $this->engine->status($this->ana)->status);

        $this->expectException(InvalidArgumentException::class);
        $reader->open($this->ana, new ProviderMessageId('m1'), new ProviderAttachmentId('a1'), 0);
    }

    public function testHandlerRetryDecisions(): void
    {
        $this->engine->start($this->ana);
        $this->bus->messages = [];

        // Malformed ids are dropped.
        $this->handler->sync(new SyncMailboxFolder('bad id', 'inbox'));
        $this->handler->fetch(new FetchMailboxMessage('mbx-ana', 'inbox', 'bad id'));
        $this->handler->fetch(new FetchMailboxMessage('mbx-ana', 'inbox', 'm1', 'bogus'));
        $this->handler->renew(new RenewMailboxSubscriptions('bad id'));
        self::assertTrue($this->log->has('malformed'));

        // Retry-After: a delayed copy of the message (at most one hour), up to the bounded number of attempts.
        foreach ([['99999', 3_600_000], ['5', 5000]] as [$retryAfter, $delay]) {
            $this->bus->messages   = [];
            $this->bus->stamps     = [];
            $this->graph->override = static fn (): MockResponse => MailboxGraphFake::json(['error' => ['code' => 'x']], 429, ['retry-after' => $retryAfter]);
            $this->handler->fetch(new FetchMailboxMessage('mbx-ana', 'inbox', 'm1', 'sync', 3));
            self::assertEquals([new FetchMailboxMessage('mbx-ana', 'inbox', 'm1', 'sync', 4)], $this->bus->messages);
            $stamp = $this->firstStamp();
            self::assertInstanceOf(DelayStamp::class, $stamp);
            self::assertSame($delay, $stamp->getDelay());
        }
        $this->bus->messages = [];
        $this->handler->fetch(new FetchMailboxMessage('mbx-ana', 'inbox', 'm1', 'sync', -5));
        self::assertEquals([new FetchMailboxMessage('mbx-ana', 'inbox', 'm1', 'sync', 1)], $this->bus->messages, 'a negative attempt is clamped');
        $this->bus->messages = [];
        self::assertInstanceOf(MailboxProviderFailure::class, $this->failure(fn () => $this->handler->fetch(new FetchMailboxMessage('mbx-ana', 'inbox', 'm1', 'sync', MailboxMessageHandler::MAX_DELAYED_ATTEMPTS - 1))));
        self::assertSame([], $this->bus->messages, 'the last attempt goes to the transport retry strategy');

        // A sweep acknowledges a transient failure: the next scheduled sweep covers the folder.
        self::assertNull($this->failure(fn () => $this->handler->sync(new SyncMailboxFolder('mbx-ana', 'inbox'))));
        self::assertTrue($this->log->has('Mailbox sweep acknowledged after a transient failure'));

        $this->graph->override = static fn (): MockResponse => MailboxGraphFake::json(['error' => ['code' => 'x']], 500);
        self::assertInstanceOf(MailboxProviderFailure::class, $this->failure(fn () => $this->handler->fetch(new FetchMailboxMessage('mbx-ana', 'inbox', 'm1'))));
        $this->graph->override = static fn (): MockResponse => MailboxGraphFake::json(['error' => ['code' => 'x']], 400);
        $refused               = $this->failure(fn () => $this->handler->fetch(new FetchMailboxMessage('mbx-ana', 'inbox', 'm2')));
        self::assertInstanceOf(UnrecoverableMessageHandlingException::class, $refused);
        $this->graph->override = null;

        $this->ports->tokenOverride = static fn (): never => throw new MailboxTokenUnavailable();
        self::assertNull($this->failure(fn () => $this->handler->sync(new SyncMailboxFolder('mbx-ana', 'inbox'))));
        self::assertInstanceOf(MailboxTokenUnavailable::class, $this->failure(fn () => $this->handler->renew(new RenewMailboxSubscriptions('mbx-ana'))));
        $this->ports->tokenOverride = static fn (): never => throw new MailboxTokenUnavailable(retryAfter: 30);
        $this->bus->messages        = [];
        $this->bus->stamps          = [];
        self::assertNull($this->failure(fn () => $this->handler->renew(new RenewMailboxSubscriptions('mbx-ana'))));
        self::assertEquals([new RenewMailboxSubscriptions('mbx-ana', null, null, 1)], $this->bus->messages);
        $stamp = $this->firstStamp();
        self::assertInstanceOf(DelayStamp::class, $stamp);
        self::assertSame(30_000, $stamp->getDelay());

        // A grant failure is acknowledged (the pause is recorded): nothing is thrown.
        $this->ports->tokenOverride      = null;
        $this->ports->revoked['mbx-ana'] = GrantFailureReason::Revoked;
        self::assertNull($this->failure(fn () => $this->handler->fetch(new FetchMailboxMessage('mbx-ana', 'inbox', 'm1'))));
        self::assertSame(MailboxStatus::PausedGrant, $this->engine->status($this->ana)->status);
    }

    public function testTheSessionAsksOnceAndIgnoresObserverFailures(): void
    {
        $observer = new class implements MailboxCallObserverInterface {
            public function called(ProviderCall $call): void
            {
                throw new RuntimeException('metering is down');
            }
        };
        $session = new MailboxSession($this->ana, MailboxProvider::Graph, 'ana@example.com', null, $this->ports, $observer, $this->log);
        self::assertSame($session->token(), $session->token());
        self::assertSame(1, $this->ports->issued['mbx-ana']);
        $session->rejected();
        $session->rejected();
        self::assertCount(1, $this->ports->rejectedTokens);
        self::assertSame('fake-mbx-ana-2', $session->token()->reveal());
        $session->called('delta', 200, 1, 1, null, 1);
        self::assertTrue($this->log->has('Mailbox call observer failed; ignored.'));

        $this->ports->tokenOverride = static fn (MailboxId $id): MailboxAccessToken => new MailboxAccessToken('host-token', new DateTimeImmutable());
        self::assertSame('host-token', (new MailboxSession($this->ana, MailboxProvider::Graph, null, null, $this->ports, $this->ports))->token()->reveal());
    }

    public function testCommands(): void
    {
        $schema = new CommandTester(new MailboxSchemaCommand($this->mailboxStore));
        $schema->execute([]);
        self::assertSame("The mailbox tables exist.\n", $schema->getDisplay());
        $this->mailboxStore->execute(['DROP TABLE mail_kit_mailbox']);
        $schema->execute([]);
        self::assertStringContainsString('CREATE TABLE mail_kit_mailbox', $schema->getDisplay());
        $schema->execute(['--force' => true]);
        self::assertSame("Created the mailbox tables.\n", $schema->getDisplay());

        $this->engine->start($this->ana);
        $this->bus->messages = [];
        $sweep               = new CommandTester(new MailboxSweepCommand($this->engine));
        self::assertSame(0, $sweep->execute([]));
        self::assertSame("1 folder sweep(s) enqueued.\n", $sweep->getDisplay());
        self::assertSame(0, $sweep->execute(['--mailbox' => 'mbx-ana']));
        self::assertSame("Sweep enqueued.\n", $sweep->getDisplay());
        self::assertSame(2, $sweep->execute(['--mailbox' => 'bad id']));

        $renew = new CommandTester(new MailboxSubscriptionsCommand($this->engine));
        self::assertSame(0, $renew->execute([]));
        self::assertSame("Subscriptions checked.\n", $renew->getDisplay());
        self::assertSame(0, $renew->execute(['--mailbox' => 'mbx-ana']));
        self::assertSame(2, $renew->execute(['--mailbox' => 'bad id']));
        $this->graph->override = static fn (): MockResponse => MailboxGraphFake::json(['error' => ['code' => 'x']], 500);
        self::assertSame(1, $renew->execute(['--mailbox' => 'mbx-ana']));
        self::assertSame(1, $renew->execute([]));
        self::assertStringContainsString('1 mailbox(es) failed', $renew->getDisplay());
        $this->graph->override = null;

        $status = new CommandTester(new MailboxStatusCommand($this->engine));
        self::assertSame(0, $status->execute(['mailbox' => 'mbx-ana']));
        self::assertStringContainsString('Status: active', $status->getDisplay());
        self::assertStringContainsString('inbox: last complete sweep never, subscription until ', $status->getDisplay());
        self::assertStringNotContainsString('ana@example.com', $status->getDisplay());
        $this->engine->grantFailed($this->ana, MailboxProvider::Graph, GrantFailureReason::Revoked);
        $status->execute(['mailbox' => 'mbx-ana']);
        self::assertStringContainsString('Status: paused_grant (revoked)', $status->getDisplay());
        $this->mailboxConnection->executeStatement('DELETE FROM mail_kit_mailbox_subscription');
        $this->mailboxStore->advanceCursor($this->ana, 'inbox', 'x', 0, null, 1, true);
        $status->execute(['mailbox' => 'mbx-ana']);
        self::assertStringContainsString('subscription none', $status->getDisplay());
        $this->mailboxStore->markFolderError($this->ana, 'inbox', 'refused');
        $status->execute(['mailbox' => 'mbx-ana']);
        self::assertStringContainsString(', sync error refused since ', $status->getDisplay());
        self::assertSame(2, $status->execute(['mailbox' => 'bad id']));
    }

    public function testSchemaListenerOnlyForItsEntityManager(): void
    {
        $mine = $this->createStub(EntityManagerInterface::class);
        $mine->method('getConnection')->willReturn($this->mailboxConnection);
        $other    = $this->createStub(EntityManagerInterface::class);
        $registry = $this->createStub(ManagerRegistry::class);
        $registry->method('getManager')->willReturn($mine);
        $listener = new MailboxSchemaListener($this->mailboxStore, $registry);

        $schema = new Schema();
        $listener->postGenerateSchema(new GenerateSchemaEventArgs($other, $schema));
        self::assertFalse($schema->hasTable('mail_kit_mailbox'));
        $listener->postGenerateSchema(new GenerateSchemaEventArgs($mine, $schema));
        self::assertTrue($schema->hasTable('mail_kit_mailbox'));
        self::assertTrue($schema->hasTable('mail_kit_mailbox_subscription'));
    }

    public function testRenewOfAnInactiveOrMismatchedMailboxAndALostTransition(): void
    {
        $this->engine->renew($this->ana);
        self::assertSame([], $this->graph->requests);

        $this->engine->start($this->ana);
        $this->graph->account = ['id' => 'other-account', 'mail' => 'ana@example.com'];
        $this->engine->renew($this->ana);
        self::assertSame(GrantFailureReason::AccountMismatch, $this->engine->status($this->ana)->reason);

        // Only the winner of the transition dispatches the event.
        $this->engine->grantFailed($this->ana, MailboxProvider::Graph, GrantFailureReason::Revoked);
        self::assertCount(1, $this->events->of(MailboxGrantFailedEvent::class));
    }

    public function testRouteLoader(): void
    {
        $loader = new MailboxWebhookRouteLoader(true, '/hooks/mail-kit/v1/mailbox/graph');
        self::assertTrue($loader->supports('.', MailboxWebhookRouteLoader::TYPE));
        self::assertFalse($loader->supports('.', 'other'));
        $route = $loader->load('.')->get(MailboxWebhookRouteLoader::ROUTE);
        self::assertNotNull($route);
        self::assertSame(['POST'], $route->getMethods());
        self::assertTrue($route->getDefault('_stateless'));
        self::assertCount(0, (new MailboxWebhookRouteLoader(false, '/x'))->load('.'));

        $this->expectException(RuntimeException::class);
        $loader->load('.');
    }

    private function firstStamp(): mixed
    {
        $stamps = $this->bus->stamps;

        return $stamps[0][0] ?? null;
    }

    /**
     * @param callable(): void $call
     */
    private function failure(callable $call): ?Throwable
    {
        try {
            $call();
        } catch (Throwable $failure) {
            return $failure;
        }

        return null;
    }
}
