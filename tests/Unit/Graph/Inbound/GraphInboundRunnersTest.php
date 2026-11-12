<?php

declare(strict_types=1);

namespace Nowo\MailKitBundle\Tests\Unit\Graph\Inbound;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Tools\Event\GenerateSchemaEventArgs;
use Doctrine\Persistence\ManagerRegistry;
use Nowo\MailKitBundle\Graph\GraphApiClient;
use Nowo\MailKitBundle\Graph\GraphException;
use Nowo\MailKitBundle\Graph\GraphTokenProvider;
use Nowo\MailKitBundle\Graph\Inbound\Command\GraphSchemaCommand;
use Nowo\MailKitBundle\Graph\Inbound\Command\GraphSubscriptionsCommand;
use Nowo\MailKitBundle\Graph\Inbound\Command\GraphSweepCommand;
use Nowo\MailKitBundle\Graph\Inbound\ConfiguredInboundProfiles;
use Nowo\MailKitBundle\Graph\Inbound\GraphDeltaSweeper;
use Nowo\MailKitBundle\Graph\Inbound\GraphInboundProcessor;
use Nowo\MailKitBundle\Graph\Inbound\GraphMessageNormalizer;
use Nowo\MailKitBundle\Graph\Inbound\GraphSubscriptionManager;
use Nowo\MailKitBundle\Graph\Inbound\InboundHtmlSanitizer;
use Nowo\MailKitBundle\Graph\Inbound\Message\FetchGraphMessage;
use Nowo\MailKitBundle\Graph\Inbound\Message\GraphInboundMessageHandler;
use Nowo\MailKitBundle\Graph\Inbound\Message\RenewGraphSubscriptions;
use Nowo\MailKitBundle\Graph\Inbound\Message\SweepGraphMailbox;
use Nowo\MailKitBundle\Graph\Inbound\Store\GraphInboundSchemaListener;
use Nowo\MailKitBundle\Graph\Inbound\Store\GraphInboundStore;
use Nowo\MailKitBundle\Tests\Support\GraphApiMock;
use Nowo\MailKitBundle\Tests\Support\GraphInboundFixtures;
use Nowo\MailKitBundle\Tests\Support\HostGraphMailboxProvider;
use Nowo\MailKitBundle\Tests\Support\InboundStoreTrait;
use Nowo\MailKitBundle\Tests\Support\RecordingBus;
use Nowo\MailKitBundle\Tests\Support\RecordingInboundHandler;
use Nowo\MailKitBundle\Tests\Support\RecordingLogger;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use Symfony\Component\Cache\Adapter\ArrayAdapter;
use Symfony\Component\Clock\MockClock;
use Symfony\Component\Console\Tester\CommandTester;
use Symfony\Component\HttpClient\Response\MockResponse;

use function str_contains;

/**
 * Messenger handler, console commands and the ORM schema listener of the inbound pipeline.
 */
final class GraphInboundRunnersTest extends TestCase
{
    use InboundStoreTrait;

    private GraphApiMock $mock;

    private GraphInboundStore $store;

    private RecordingBus $bus;

    private RecordingInboundHandler $host;

    private RecordingLogger $logger;

    private MockClock $clock;

    protected function setUp(): void
    {
        $this->mock   = new GraphApiMock();
        $this->clock  = new MockClock('2026-11-12 10:00:00');
        $this->store  = $this->inboundStore('', $this->clock);
        $this->bus    = new RecordingBus();
        $this->host   = new RecordingInboundHandler();
        $this->logger = new RecordingLogger();
    }

    private function handler(?string $url = 'https://app.example.com/hooks/mail-kit/v1/graph'): GraphInboundMessageHandler
    {
        $client    = $this->mock->client();
        $api       = new GraphApiClient($client, new GraphTokenProvider($client, new ArrayAdapter()));
        $mailboxes = new HostGraphMailboxProvider();

        return new GraphInboundMessageHandler(
            new GraphInboundProcessor($mailboxes, $api, $this->store, $this->host, new GraphMessageNormalizer(new InboundHtmlSanitizer())),
            new GraphDeltaSweeper($mailboxes, $api, $this->store, $this->bus, 'P1D', 10, 'inbox', $this->clock),
            new GraphSubscriptionManager($mailboxes, $api, $this->store, $url, 4320, 1440, 'inbox', $this->clock),
            new ConfiguredInboundProfiles(['acme', 'globex'], $mailboxes),
            $this->store,
            5,
            30,
            $this->clock,
            $this->logger,
        );
    }

    public function testFetchSweepAndRenew(): void
    {
        $this->mock->inbox['projects@example.com']['m1'] = GraphInboundFixtures::message('m1');
        $handler                                         = $this->handler();
        $handler->fetch(new FetchGraphMessage('acme', 'm1'));
        self::assertCount(1, $this->host->messages);

        $this->mock->override = static fn (string $method, string $url): ?MockResponse => str_contains($url, '/delta') ? GraphApiMock::json(['value' => [['id' => 'x1']], '@odata.deltaLink' => 'https://graph.microsoft.com/v1.0/users/x/messages/delta?$deltatoken=1']) : null;
        $this->store->claim('acme', 'old@x', 900);
        $this->clock->modify('+31 days');
        $handler->sweep(new SweepGraphMailbox());
        self::assertCount(2, $this->bus->messages, 'Both profiles swept.');
        self::assertTrue($this->store->claim('acme', 'old@x', 900), 'Purged after the retention.');
        $handler->sweep(new SweepGraphMailbox('globex'));
        self::assertCount(3, $this->bus->messages);

        $this->mock->override = null;
        $handler->renew(new RenewGraphSubscriptions());
        self::assertNotNull($this->store->subscription('acme'));
        self::assertNotNull($this->store->subscription('globex'));

        // One failing profile does not stop the other; the failure is rethrown afterwards.
        $this->store->deleteSubscription($this->store->subscription('acme')['id'] ?? '');
        $this->store->deleteSubscription($this->store->subscription('globex')['id'] ?? '');
        $calls                = 0;
        $this->mock->override = static function (string $method, string $url) use (&$calls): ?MockResponse {
            if (str_contains($url, '/subscriptions') && ++$calls === 1) {
                return GraphApiMock::json(['error' => ['code' => 'ErrorAccessDenied']], 403);
            }

            return null;
        };
        try {
            $handler->renew(new RenewGraphSubscriptions());
            self::fail('The failure must be rethrown.');
        } catch (GraphException $e) {
            self::assertSame(403, $e->status);
        }
        self::assertNull($this->store->subscription('acme'));
        self::assertNotNull($this->store->subscription('globex'));
        self::assertTrue($this->logger->has('subscription not renewed'));
    }

    public function testCommands(): void
    {
        $handler       = $this->handler();
        $mailboxes     = new HostGraphMailboxProvider();
        $client        = $this->mock->client();
        $subscriptions = new GraphSubscriptionManager($mailboxes, new GraphApiClient($client, new GraphTokenProvider($client, new ArrayAdapter())), $this->store, 'https://app.example.com/h', 4320, 1440, 'inbox', $this->clock);

        $command = new CommandTester(new GraphSubscriptionsCommand($subscriptions, new ConfiguredInboundProfiles(['acme', 'globex'], $mailboxes)));
        self::assertSame(0, $command->execute([]));
        self::assertSame("acme: created\nglobex: created\n", $command->getDisplay());
        self::assertSame(0, $command->execute(['--profile' => 'acme']));
        self::assertSame("acme: current\n", $command->getDisplay());
        self::assertSame(0, $command->execute(['--remove' => true, '--profile' => 'acme']));
        self::assertSame("acme: removed\n", $command->getDisplay());
        self::assertSame(0, $command->execute(['--remove' => true, '--profile' => 'acme']));
        self::assertSame("acme: none\n", $command->getDisplay());
        self::assertSame(1, $command->execute(['--profile' => 'initech']));
        self::assertStringContainsString('initech: Unknown organization.', $command->getDisplay());

        $sweep                = new CommandTester(new GraphSweepCommand($handler));
        $this->mock->override = static fn (string $method, string $url): ?MockResponse => str_contains($url, 'delta') ? GraphApiMock::json(['value' => [], '@odata.deltaLink' => 'https://graph.microsoft.com/v1.0/x/delta?$deltatoken=1']) : null;
        self::assertSame(0, $sweep->execute(['--profile' => 'acme']));
        self::assertSame("Sweep done.\n", $sweep->getDisplay());
        self::assertSame(1, $sweep->execute(['--profile' => 'initech']));
        self::assertStringContainsString('Unknown organization.', $sweep->getDisplay());
        $this->inboundConnection?->executeStatement('DROP TABLE mail_kit_graph_inbound');
        self::assertSame(1, $sweep->execute([]));
        self::assertStringContainsString('Sweep failed: ', $sweep->getDisplay());
        self::assertStringNotContainsString('mail_kit_graph_inbound', $sweep->getDisplay());

        $schema = new CommandTester(new GraphSchemaCommand($this->store));
        self::assertSame(0, $schema->execute([]));
        self::assertStringContainsString('CREATE TABLE mail_kit_graph_inbound', $schema->getDisplay());
        self::assertSame(0, $schema->execute(['--force' => true]));
        self::assertSame("Created the inbound tables.\n", $schema->getDisplay());
        self::assertSame(0, $schema->execute([]));
        self::assertSame("The inbound tables exist.\n", $schema->getDisplay());
    }

    public function testSchemaListenerOnlyForItsEntityManager(): void
    {
        $mine     = $this->createStub(EntityManagerInterface::class);
        $other    = $this->createStub(EntityManagerInterface::class);
        $registry = $this->createStub(ManagerRegistry::class);
        $registry->method('getManager')->willReturn($mine);
        $listener = new GraphInboundSchemaListener($this->store, $registry);

        $schema = new Schema();
        $listener->postGenerateSchema(new GenerateSchemaEventArgs($other, $schema));
        self::assertFalse($schema->hasTable('mail_kit_graph_inbound'));
        $listener->postGenerateSchema(new GenerateSchemaEventArgs($mine, $schema));
        self::assertTrue($schema->hasTable('mail_kit_graph_inbound'));
        self::assertTrue($schema->hasTable('mail_kit_graph_subscription'));
        self::assertTrue($schema->hasTable('mail_kit_graph_delta'));
    }

    public function testHostFailureStopsTheFetch(): void
    {
        $this->mock->inbox['projects@example.com']['m1'] = GraphInboundFixtures::message('m1');
        $this->host->fail                                = true;
        $this->expectException(RuntimeException::class);
        $this->handler()->fetch(new FetchGraphMessage('acme', 'm1'));
    }
}
