<?php

declare(strict_types=1);

namespace Nowo\MailKitBundle\Tests\Integration\Mailbox;

use Closure;
use LogicException;
use Nowo\MailKitBundle\Mailbox\Engine\MailboxSyncEngine;
use Nowo\MailKitBundle\Mailbox\Event\MailboxGrantFailedEvent;
use Nowo\MailKitBundle\Mailbox\Infrastructure\Messenger\SyncMailboxFolder;
use Nowo\MailKitBundle\Mailbox\Infrastructure\Store\MailboxStore;
use Nowo\MailKitBundle\Mailbox\Infrastructure\Webhook\MailboxGraphWebhookController;
use Nowo\MailKitBundle\Mailbox\Service\MailboxAttachmentReaderInterface;
use Nowo\MailKitBundle\Mailbox\Service\MailboxSenderInterface;
use Nowo\MailKitBundle\Mailbox\Service\MailboxSyncInterface;
use Nowo\MailKitBundle\Mailbox\Value\GrantFailureReason;
use Nowo\MailKitBundle\Mailbox\Value\MailboxId;
use Nowo\MailKitBundle\Mailbox\Value\MailboxStatus;
use Nowo\MailKitBundle\Mailbox\Value\MessageOrigin;
use Nowo\MailKitBundle\Mailbox\Value\OutgoingKind;
use Nowo\MailKitBundle\Mailbox\Value\OutgoingMailboxMail;
use Nowo\MailKitBundle\Sender\MailAddress;
use Nowo\MailKitBundle\Tests\Integration\Kernel\KernelTestCase;
use Nowo\MailKitBundle\Tests\Integration\Kernel\MailKitTestKernel;
use Nowo\MailKitBundle\Tests\Support\MailboxGmailFake;
use Nowo\MailKitBundle\Tests\Support\MailboxGraphFake;
use Nowo\MailKitBundle\Tests\Support\MemoryMailboxPorts;
use Nowo\MailKitBundle\Tests\Support\RequestTenantSenderProvider;
use Nowo\MailKitBundle\Tests\Support\StaticDsnProvider;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Component\Console\Tester\ApplicationTester;
use Symfony\Component\DependencyInjection\Loader\Configurator\ContainerConfigurator;
use Symfony\Component\EventDispatcher\EventDispatcherInterface;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\HttpKernel;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Routing\Loader\Configurator\RoutingConfigurator;

use function array_slice;
use function count;
use function gc_collect_cycles;
use function json_encode;
use function memory_get_usage;
use function preg_replace;
use function Symfony\Component\DependencyInjection\Loader\Configurator\service;

/**
 * 2.5.0 delegated mailboxes in a booted kernel: off by default; a strict DDD host (no Instance Settings, no ORM,
 * in-memory ports, Messenger handling synchronously) syncing two people's mailboxes through 400 webhook requests in one
 * kept-alive kernel without the services resetter (FrankenPHP worker mode, kernel reset false) with no state bleed;
 * the ORM schema listener; lint:container; the build errors.
 */
final class MailboxKernelTest extends KernelTestCase
{
    private const STATES = ['mbx-ana' => 'ana-state-REDACTED-TEST', 'mbx-bob' => 'bob-state-REDACTED-TEST'];

    public function testOffByDefault(): void
    {
        $container = $this->container($this->bootKernel());
        foreach ([MailboxSyncEngine::class, MailboxStore::class, MailboxGraphWebhookController::class, MailboxSyncInterface::class] as $id) {
            self::assertFalse($container->has($id), $id);
        }
    }

    public function testDddHostSyncsTwoMailboxesInOneWorker(): void
    {
        $kernel = $this->bootMailboxes(['orm', 'instance_settings']);
        $ports  = $this->service($kernel, MemoryMailboxPorts::class);
        $graph  = $this->service($kernel, MailboxGraphFake::class);
        $ports->add(MemoryMailboxPorts::settings('mbx-ana', 'ana@example.com'));
        $ports->add(MemoryMailboxPorts::settings('mbx-bob', 'ana@example.com'));
        $store = $this->service($kernel, MailboxStore::class);
        $store->execute($store->missingTablesSql());
        foreach (['mail_kit_mailbox_send', 'mail_kit_mailbox_subscription', 'mail_kit_mailbox_cursor', 'mail_kit_mailbox'] as $table) {
            $this->connection($kernel)->executeStatement('DELETE FROM ' . $table);
        }

        $sync = $this->service($kernel, MailboxSyncInterface::class);
        foreach (self::STATES as $id => $state) {
            self::assertSame(MailboxStatus::Active, $sync->start(new MailboxId($id))->status);
            $store->saveSubscription('sub-' . $id, new MailboxId($id), 'inbox', $state, 2_000_000_000);
        }

        $http = $kernel->getContainer()->get('http_kernel');
        self::assertInstanceOf(HttpKernel::class, $http);
        $before = 0;
        for ($i = 0; $i < 400; ++$i) {
            $mailbox         = $i % 2 === 0 ? 'mbx-ana' : 'mbx-bob';
            $other           = $mailbox === 'mbx-ana' ? 'mbx-bob' : 'mbx-ana';
            $id              = 'msg' . $i;
            $graph->messages = [$id => MailboxGraphFake::message($id)];
            $graph->requests = [];

            $request = Request::create('/hooks/mail-kit/v1/mailbox/graph', 'POST', server: ['CONTENT_TYPE' => 'application/json'], content: (string) json_encode(['value' => [
                ['subscriptionId' => 'sub-' . $mailbox, 'clientState' => self::STATES[$mailbox], 'changeType' => 'created', 'resourceData' => ['id' => $id]],
                // Another person's subscription with this person's client state is refused.
                ['subscriptionId' => 'sub-' . $other, 'clientState' => self::STATES[$mailbox], 'changeType' => 'created', 'resourceData' => ['id' => 'forged']],
            ]]));
            $response = $http->handle($request);
            self::assertSame(202, $response->getStatusCode(), (string) $response->getContent());
            $http->terminate($request, $response);

            $stored = $ports->stored[$mailbox . "\n" . $id] ?? null;
            self::assertNotNull($stored, $mailbox . ' ' . $id);
            self::assertSame($mailbox, $stored->mailbox->value);
            self::assertCount(0, $graph->matching('/messages/forged'));
            self::assertStringStartsWith('Bearer fake-' . $mailbox . '-', $graph->matching('/messages/' . $id)[0]['authorization'] ?? '');
            if ($i === 99) {
                gc_collect_cycles();
                $before = memory_get_usage();
            }
        }
        gc_collect_cycles();
        self::assertLessThan(2 * 1024 * 1024, memory_get_usage() - $before, 'Memory must stay flat over 300 requests.');
        self::assertCount(400, $ports->stored);
        self::assertGreaterThan(200, $ports->issued['mbx-ana'], 'a token per message, never cached across messages');
        self::assertNotSame([], $ports->calls);
    }

    public function testLintContainerSchemaAndCommandsWithTheOrm(): void
    {
        $kernel = $this->bootMailboxes([], true);
        $store  = $this->service($kernel, MailboxStore::class);
        // The ORM schema listener created the mailbox tables with the host's schema.
        self::assertSame([], $store->missingTablesSql());
        self::assertInstanceOf(MailboxAttachmentReaderInterface::class, $this->container($kernel)->get(MailboxAttachmentReaderInterface::class));

        $application = new Application($kernel);
        $application->setAutoExit(false);
        $tester = new ApplicationTester($application);
        $tester->run(['command' => 'lint:container']);
        self::assertSame(0, $tester->getStatusCode(), (string) preg_replace('/\s+/', ' ', $tester->getDisplay()));
        $tester->run(['command' => 'nowo:mail-kit:mailbox:schema']);
        self::assertSame("The mailbox tables exist.\n", $tester->getDisplay());
        $tester->run(['command' => 'nowo:mail-kit:mailbox:status', 'mailbox' => 'mbx-none']);
        self::assertSame("Status: unknown\n", $tester->getDisplay());
        $tester->run(['command' => 'nowo:mail-kit:mailbox:sweep']);
        self::assertSame("0 folder sweep(s) enqueued.\n", $tester->getDisplay());
        $tester->run(['command' => 'nowo:mail-kit:mailbox:subscriptions']);
        self::assertSame(0, $tester->getStatusCode());
        $tester->run(['command' => 'nowo:mail-kit:mailbox:purge']);
        self::assertSame("0 send row(s) deleted.\n", $tester->getDisplay());
        self::assertInstanceOf(MailboxSenderInterface::class, $this->container($kernel)->get(MailboxSenderInterface::class));
    }

    public function testDddHostSendsOnceAndGetsTheSentCopyInOneWorker(): void
    {
        $kernel = $this->bootMailboxes(['orm', 'instance_settings']);
        $ports  = $this->service($kernel, MemoryMailboxPorts::class);
        $graph  = $this->service($kernel, MailboxGraphFake::class);
        $ports->add(MemoryMailboxPorts::settings('mbx-ana', 'ana@example.com'));
        $store = $this->service($kernel, MailboxStore::class);
        $store->execute($store->missingTablesSql());
        foreach (['mail_kit_mailbox_send', 'mail_kit_mailbox_subscription', 'mail_kit_mailbox_cursor', 'mail_kit_mailbox'] as $table) {
            $this->connection($kernel)->executeStatement('DELETE FROM ' . $table);
        }
        $this->service($kernel, MailboxSyncInterface::class)->start(new MailboxId('mbx-ana'));
        $sender = $this->service($kernel, MailboxSenderInterface::class);
        for ($i = 0; $i < 20; ++$i) {
            $mail = new OutgoingMailboxMail(OutgoingKind::New, null, [new MailAddress('pedro@example.org')], [], [], 'Plan ' . $i, 'Hello', null, [], 'kernel-send-key-' . $i);
            $sent = $sender->send(new MailboxId('mbx-ana'), $mail);
            self::assertFalse($sent->replayed);
            self::assertTrue($sender->send(new MailboxId('mbx-ana'), $mail)->replayed);
            // The synchronous bus ran DeliverSentCopy at once (its DelayStamp is ignored there).
            self::assertSame(MessageOrigin::SentCopy, ($ports->stored['mbx-ana' . "\n" . $sent->id->value] ?? null)?->origin);
        }
        self::assertCount(20, $graph->delivered);
    }

    public function testAGrantPauseSurvivesTheDoctrineTransactionMiddleware(): void
    {
        $kernel = $this->bootMailboxes(['instance_settings'], false, static function (ContainerConfigurator $container): void {
            $container->extension('framework', ['messenger' => [
                'default_bus' => 'messenger.bus.default',
                'buses'       => ['messenger.bus.default' => ['middleware' => ['doctrine_transaction']]],
            ]]);
        });
        $ports = $this->service($kernel, MemoryMailboxPorts::class);
        $ports->add(MemoryMailboxPorts::settings('mbx-ana', 'ana@example.com'));
        $events     = [];
        $dispatcher = $this->container($kernel)->get('event_dispatcher');
        self::assertInstanceOf(EventDispatcherInterface::class, $dispatcher);
        $dispatcher->addListener(MailboxGrantFailedEvent::class, static function (MailboxGrantFailedEvent $event) use (&$events): void {
            $events[] = $event;
        });
        $sync = $this->service($kernel, MailboxSyncInterface::class);
        self::assertSame(MailboxStatus::Active, $sync->start(new MailboxId('mbx-ana'))->status);

        // The grant dies: two sweeps run through the bus inside the middleware's transaction.
        $ports->revoked['mbx-ana'] = GrantFailureReason::Revoked;
        $bus                       = $this->container($kernel)->get('messenger.default_bus');
        self::assertInstanceOf(MessageBusInterface::class, $bus);
        $bus->dispatch(new SyncMailboxFolder('mbx-ana', 'inbox'));
        $bus->dispatch(new SyncMailboxFolder('mbx-ana', 'inbox'));

        self::assertSame(MailboxStatus::PausedGrant, $sync->status(new MailboxId('mbx-ana'))->status);
        self::assertSame('paused_grant', $this->connection($kernel)->fetchOne("SELECT status FROM mail_kit_mailbox WHERE mailbox_id = 'mbx-ana'"));
        self::assertCount(1, $events);
    }

    public function testDddHostSendsFromTwoGmailMailboxesInOneWorkerWithoutStateBleed(): void
    {
        // Phase B2: send-only Gmail, no Graph, kept-alive kernel without the services resetter (FrankenPHP worker mode).
        $kernel = $this->bootKernel(
            [
                'mailer'    => ['dsn_provider' => StaticDsnProvider::class, 'sender_provider' => RequestTenantSenderProvider::class],
                'log'       => ['enabled' => false],
                'mailboxes' => [
                    'enabled'        => true,
                    'token_provider' => MemoryMailboxPorts::class,
                    'directory'      => MemoryMailboxPorts::class,
                    'sink'           => MemoryMailboxPorts::class,
                    'http_client'    => 'test.gmail_http_client',
                    'gmail'          => ['enabled' => true],
                ],
            ],
            without: ['orm', 'instance_settings'],
            extra: static function (ContainerConfigurator $container): void {
                $services = $container->services();
                $services->set(MailboxGmailFake::class)->public();
                $services->set('test.gmail_http_client', MockHttpClient::class)->factory([service(MailboxGmailFake::class), 'client']);
                $services->set(MemoryMailboxPorts::class)->public();
                $services->set(StaticDsnProvider::class)->args(['memory://default']);
                $services->set(RequestTenantSenderProvider::class)->args([service('request_stack')]);
            },
        );
        self::assertFalse($this->container($kernel)->has(MailboxGraphWebhookController::class));
        $ports  = $this->service($kernel, MemoryMailboxPorts::class);
        $google = $this->service($kernel, MailboxGmailFake::class);
        $ports->add(MemoryMailboxPorts::gmailSettings('mbx-ana', 'ana@example.com'));
        $ports->add(MemoryMailboxPorts::gmailSettings('mbx-bob', 'ana@example.com'));
        $store = $this->service($kernel, MailboxStore::class);
        $store->execute($store->missingTablesSql());
        foreach (['mail_kit_mailbox_send', 'mail_kit_mailbox_subscription', 'mail_kit_mailbox_cursor', 'mail_kit_mailbox'] as $table) {
            $this->connection($kernel)->executeStatement('DELETE FROM ' . $table);
        }
        $sync = $this->service($kernel, MailboxSyncInterface::class);
        self::assertTrue($sync->start(new MailboxId('mbx-ana'))->isSendOnly());
        self::assertTrue($sync->start(new MailboxId('mbx-bob'))->isSendOnly());
        $sender = $this->service($kernel, MailboxSenderInterface::class);
        for ($i = 0; $i < 50; ++$i) {
            $mailbox = $i % 2 === 0 ? 'mbx-ana' : 'mbx-bob';
            $before  = count($google->requests);
            $mail    = new OutgoingMailboxMail(OutgoingKind::New, null, [new MailAddress('pedro@example.org')], [], [], 'Plan ' . $i, 'Hello', null, [], 'gmail-kernel-key-' . $i);
            self::assertFalse($sender->send(new MailboxId($mailbox), $mail)->replayed);
            self::assertTrue($sender->send(new MailboxId($mailbox), $mail)->replayed);
            foreach (array_slice($google->requests, $before) as $request) {
                self::assertStringStartsWith('Bearer fake-' . $mailbox . '-', $request['authorization'], 'one mailbox\'s token never serves the other');
            }
        }
        self::assertCount(50, $google->sent);
        self::assertSame([], $ports->stored, 'no sent copy: there is no inbox');
    }

    public function testARetryingHttpClientFailsTheBuild(): void
    {
        // Audit ZBU-L1: framework.http_client retry_failed would re-POST a send outside MailKit's claim.
        $this->expectException(LogicException::class);
        $this->expectExceptionMessage('mailboxes.http_client "http_client" retries failed requests');
        $this->bootKernel(
            [
                'mailer'    => ['dsn_provider' => StaticDsnProvider::class, 'sender_provider' => RequestTenantSenderProvider::class],
                'log'       => ['enabled' => false],
                'mailboxes' => ['enabled' => true, 'token_provider' => MemoryMailboxPorts::class, 'directory' => MemoryMailboxPorts::class, 'sink' => MemoryMailboxPorts::class, 'graph' => ['enabled' => true]],
            ],
            without: ['orm', 'instance_settings'],
            extra: static function (ContainerConfigurator $container): void {
                $container->extension('framework', ['http_client' => ['default_options' => ['retry_failed' => true]]]);
                $services = $container->services();
                $services->set(MemoryMailboxPorts::class)->public();
                $services->set(StaticDsnProvider::class)->args(['memory://default']);
                $services->set(RequestTenantSenderProvider::class)->args([service('request_stack')]);
            },
        );
    }

    public function testMailboxesNeedDoctrine(): void
    {
        $this->expectException(LogicException::class);
        $this->expectExceptionMessage('nowo_mail_kit.mailboxes requires doctrine/doctrine-bundle');
        $this->bootMailboxes(['doctrine', 'instance_settings']);
    }

    /**
     * @param list<string> $without
     */
    private function bootMailboxes(array $without, bool $debug = false, ?Closure $more = null): MailKitTestKernel
    {
        return $this->bootKernel(
            [
                'mailer'    => ['dsn_provider' => StaticDsnProvider::class, 'sender_provider' => RequestTenantSenderProvider::class],
                'log'       => ['enabled' => false],
                'mailboxes' => [
                    'enabled'        => true,
                    'token_provider' => MemoryMailboxPorts::class,
                    'directory'      => MemoryMailboxPorts::class,
                    'sink'           => MemoryMailboxPorts::class,
                    'http_client'    => 'test.mailbox_http_client',
                    'graph'          => [
                        'enabled' => true,
                        'webhook' => ['notification_url' => 'https://app.example.com/hooks/mail-kit/v1/mailbox/graph', 'rate_limit' => ['limit' => 100_000]],
                    ],
                ] + ($debug ? [] : ['exclusion_policy' => MemoryMailboxPorts::class, 'call_observer' => MemoryMailboxPorts::class, 'message_index' => MemoryMailboxPorts::class]),
            ],
            without: $without,
            routes: static function (RoutingConfigurator $routes): void {
                $routes->import('.', 'nowo_mail_kit_mailbox_webhook');
            },
            debug: $debug,
            extra: static function (ContainerConfigurator $container) use ($more): void {
                $services = $container->services();
                $services->set(MailboxGraphFake::class)->public();
                $services->set('test.mailbox_http_client', MockHttpClient::class)->factory([service(MailboxGraphFake::class), 'client']);
                $services->set(MemoryMailboxPorts::class)->public();
                $services->set(StaticDsnProvider::class)->args(['memory://default']);
                $services->set(RequestTenantSenderProvider::class)->args([service('request_stack')]);
                if ($more instanceof Closure) {
                    $more($container);
                }
            },
        );
    }
}
