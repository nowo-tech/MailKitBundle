<?php

declare(strict_types=1);

namespace Nowo\MailKitBundle\Tests\Support;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\DriverManager;
use Doctrine\Persistence\ManagerRegistry;
use Nowo\MailKitBundle\Graph\GraphApiClient;
use Nowo\MailKitBundle\Graph\Inbound\GraphMessageNormalizer;
use Nowo\MailKitBundle\Mailbox\Engine\MailboxSyncEngine;
use Nowo\MailKitBundle\Mailbox\Engine\MailboxSyncOptions;
use Nowo\MailKitBundle\Mailbox\Graph\GraphMailboxGateway;
use Nowo\MailKitBundle\Mailbox\Html\MailHtmlSanitizer;
use Nowo\MailKitBundle\Mailbox\Html\RemoteImages;
use Nowo\MailKitBundle\Mailbox\Infrastructure\Messenger\FetchMailboxMessage;
use Nowo\MailKitBundle\Mailbox\Infrastructure\Messenger\MailboxMessageHandler;
use Nowo\MailKitBundle\Mailbox\Infrastructure\Messenger\RenewMailboxSubscriptions;
use Nowo\MailKitBundle\Mailbox\Infrastructure\Messenger\SyncMailboxFolder;
use Nowo\MailKitBundle\Mailbox\Infrastructure\Store\MailboxStore;
use Symfony\Component\Clock\MockClock;
use Throwable;

use function array_shift;

/**
 * Builds the delegated-mailbox engine on SQLite in memory with the fake Graph, the in-memory host ports, a recording
 * bus and a recording event dispatcher; drain() runs the queued messages through the Messenger handler.
 */
trait MailboxHarness
{
    private Connection $mailboxConnection;

    private MailboxStore $mailboxStore;

    private MailboxGraphFake $graph;

    private MemoryMailboxPorts $ports;

    private RecordingBus $bus;

    private MockClock $clock;

    private RecordingLogger $log;

    private RecordingEventDispatcher $events;

    private MailboxSyncEngine $engine;

    private MailboxMessageHandler $handler;

    private GraphMailboxGateway $gateway;

    private function harness(?MailboxSyncOptions $options = null, string $prefix = ''): void
    {
        $this->mailboxConnection = DriverManager::getConnection(['driver' => 'pdo_sqlite', 'memory' => true]);
        $registry                = $this->createStub(ManagerRegistry::class);
        $registry->method('getConnection')->willReturn($this->mailboxConnection);
        $this->clock        = new MockClock('2026-11-23 12:00:00 UTC');
        $this->log          = new RecordingLogger();
        $this->mailboxStore = new MailboxStore($registry, null, $prefix, $this->clock, $this->log);
        $this->mailboxStore->execute($this->mailboxStore->missingTablesSql());
        $this->graph   = new MailboxGraphFake();
        $this->ports   = new MemoryMailboxPorts();
        $this->bus     = new RecordingBus();
        $this->gateway = new GraphMailboxGateway(
            new GraphApiClient($this->graph->client(), null, GraphApiClient::DEFAULT_UPLOAD_HOSTS, 5.0, $this->log),
            new GraphMessageNormalizer(new MailHtmlSanitizer(RemoteImages::Blocked)),
        );
        $this->events = new RecordingEventDispatcher();
        $this->engine = new MailboxSyncEngine(
            $this->mailboxStore,
            $this->ports,
            $this->ports,
            $this->ports,
            $this->ports,
            $this->ports,
            ['graph' => $this->gateway],
            $this->bus,
            $options ?? new MailboxSyncOptions(notificationUrl: 'https://app.example.com/hooks/mail-kit/v1/mailbox/graph'),
            $this->events,
            $this->clock,
            $this->log,
        );
        $this->handler = new MailboxMessageHandler($this->engine, $this->bus, $this->log);
    }

    /**
     * Runs queued messages through the handler (at most `$max`); returns the failures by message class.
     *
     * @return list<Throwable>
     */
    private function drain(int $max = 200): array
    {
        $failures = [];
        for ($i = 0; $i < $max && $this->bus->messages !== []; ++$i) {
            $message = array_shift($this->bus->messages);
            array_shift($this->bus->stamps);

            try {
                match (true) {
                    $message instanceof SyncMailboxFolder         => $this->handler->sync($message),
                    $message instanceof FetchMailboxMessage       => $this->handler->fetch($message),
                    $message instanceof RenewMailboxSubscriptions => $this->handler->renew($message),
                    default                                       => null,
                };
            } catch (Throwable $failure) {
                $failures[] = $failure;
            }
        }

        return $failures;
    }
}
