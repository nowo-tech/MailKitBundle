<?php

declare(strict_types=1);

namespace Nowo\MailKitBundle\Tests\Integration\Graph;

use Nowo\MailKitBundle\Graph\Inbound\ConfiguredInboundProfiles;
use Nowo\MailKitBundle\Graph\Inbound\GraphDeltaSweeper;
use Nowo\MailKitBundle\Graph\Inbound\GraphInboundProcessor;
use Nowo\MailKitBundle\Graph\Inbound\InboundAction;
use Nowo\MailKitBundle\Graph\Inbound\Store\GraphInboundStore;
use Nowo\MailKitBundle\Graph\Inbound\Webhook\GraphWebhookController;
use Nowo\MailKitBundle\Tests\Integration\Kernel\KernelTestCase;
use Nowo\MailKitBundle\Tests\Integration\Kernel\MailKitTestKernel;
use Nowo\MailKitBundle\Tests\Support\GraphApiMock;
use Nowo\MailKitBundle\Tests\Support\GraphInboundFixtures;
use Nowo\MailKitBundle\Tests\Support\HostGraphMailboxProvider;
use Nowo\MailKitBundle\Tests\Support\RecordingInboundHandler;
use Nowo\MailKitBundle\Tests\Support\RequestTenantSenderProvider;
use Nowo\MailKitBundle\Tests\Support\StaticDsnProvider;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Component\Console\Tester\ApplicationTester;
use Symfony\Component\DependencyInjection\Loader\Configurator\ContainerConfigurator;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\HttpKernel;
use Symfony\Component\Routing\Loader\Configurator\RoutingConfigurator;

use function gc_collect_cycles;
use function json_encode;
use function memory_get_usage;
use function preg_replace;
use function Symfony\Component\DependencyInjection\Loader\Configurator\service;

/**
 * 1.9 Graph inbound in a booted kernel: off by default; a strict DDD host (no Instance Settings, host mailbox and
 * handler ports, Messenger handling the fetch synchronously) serving 600 webhook requests in one kept-alive kernel
 * without the services resetter (FrankenPHP worker mode, kernel reset false) for two organizations in alternation;
 * the ORM schema listener; lint:container.
 */
final class GraphInboundKernelTest extends KernelTestCase
{
    private const SECRETS = ['acme' => 'acme-state', 'globex' => 'globex-state'];

    public function testOffByDefault(): void
    {
        $kernel    = $this->bootKernel();
        $container = $this->container($kernel);
        foreach ([GraphInboundProcessor::class, GraphWebhookController::class, GraphInboundStore::class, GraphDeltaSweeper::class] as $id) {
            self::assertFalse($container->has($id), $id);
        }
        self::assertSame('/hooks/mail-kit/v1/graph', $container->getParameter('nowo_mail_kit.graph.webhook_path'));
    }

    public function testDddHostServesManyNotificationsInOneWorker(): void
    {
        $kernel    = $this->bootInbound();
        $container = $this->container($kernel);
        $store     = $container->get(GraphInboundStore::class);
        self::assertInstanceOf(GraphInboundStore::class, $store);
        // The ORM schema listener created the inbound tables with the log tables.
        self::assertSame([], $store->missingTablesSql());
        foreach (self::SECRETS as $profile => $secret) {
            $store->saveSubscription('sub-' . $profile, $profile, $secret . '-REDACTED-TEST', 2_000_000_000);
        }

        $http = $kernel->getContainer()->get('http_kernel');
        self::assertInstanceOf(HttpKernel::class, $http);
        $mock   = $this->service($kernel, GraphApiMock::class);
        $host   = $this->service($kernel, RecordingInboundHandler::class);
        $before = 0;

        for ($i = 0; $i < 600; ++$i) {
            $profile        = $i % 2 === 0 ? 'acme' : 'globex';
            $mailbox        = $profile === 'acme' ? 'projects@example.com' : 'support@example.org';
            $id             = 'msg' . $i;
            $mock->requests = [];
            $mock->inbox    = [$mailbox => [$id => GraphInboundFixtures::message($id, [
                'internetMessageId' => '<m' . $i . '@client.example.net>',
                'toRecipients'      => [['emailAddress' => ['address' => ($profile === 'acme' ? 'projects' : 'support') . '+t-' . $i . '@' . ($profile === 'acme' ? 'example.com' : 'example.org')]]],
            ])]];
            $host->messages = [];
            $host->bytes    = [];

            $request = Request::create('/hooks/mail-kit/v1/graph', 'POST', server: ['CONTENT_TYPE' => 'application/json'], content: (string) json_encode(['value' => [
                ['subscriptionId' => 'sub-' . $profile, 'clientState' => self::SECRETS[$profile] . '-REDACTED-TEST', 'changeType' => 'created', 'resourceData' => ['id' => $id]],
                // A forged notification for the other organization is refused.
                ['subscriptionId' => 'sub-' . ($profile === 'acme' ? 'globex' : 'acme'), 'clientState' => self::SECRETS[$profile] . '-REDACTED-TEST', 'changeType' => 'created', 'resourceData' => ['id' => 'forged']],
            ]]));
            $response = $http->handle($request);
            self::assertSame(202, $response->getStatusCode(), (string) $response->getContent());
            $http->terminate($request, $response);

            // Exactly this organization's message, read from its own mailbox with its own token, tagged for its task.
            self::assertCount(1, $host->messages);
            $message = $host->messages[0];
            self::assertSame($profile, $message->profile);
            self::assertSame(['t-' . $i], $message->plusTags);
            self::assertSame('m' . $i . '@client.example.net', $message->internetMessageId);
            $reads = $mock->matching('/messages/' . $id . '?$select=', 'GET');
            self::assertCount(1, $reads);
            self::assertStringContainsString('/users/' . ($profile === 'acme' ? 'projects%40example.com' : 'support%40example.org') . '/', $reads[0]['url']);
            self::assertStringStartsWith('Bearer tok-' . ($profile === 'acme' ? GraphApiMock::TENANT_A : GraphApiMock::TENANT_B) . '-', $reads[0]['headers']['authorization']);
            self::assertCount(0, $mock->matching('/messages/forged'));

            if ($i === 99) {
                gc_collect_cycles();
                $before = memory_get_usage();
            }
        }
        gc_collect_cycles();
        self::assertLessThan(2 * 1024 * 1024, memory_get_usage() - $before, 'Memory must stay flat over 500 requests.');
        self::assertSame(2, $mock->tokensIssued, 'One cached token per tenant.');

        // Replaying a notification never processes a message twice.
        $mock->inbox = ['projects@example.com' => ['msg0' => GraphInboundFixtures::message('msg0', ['internetMessageId' => '<m0@client.example.net>'])]];
        $processor   = $this->service($kernel, GraphInboundProcessor::class);
        self::assertNull($processor->process('acme', 'msg0'));

        $connection = $this->connection($kernel);
        self::assertSame(600, (int) $connection->fetchOne('SELECT COUNT(*) FROM mail_kit_graph_inbound WHERE status = ?', [InboundAction::Route->value]));
    }

    public function testLintContainer(): void
    {
        $kernel      = $this->bootInbound(true, false);
        $application = new Application($kernel);
        $application->setAutoExit(false);
        $tester = new ApplicationTester($application);
        $tester->run(['command' => 'lint:container']);
        self::assertSame(0, $tester->getStatusCode(), (string) preg_replace('/\s+/', ' ', $tester->getDisplay()));
        $tester->run(['command' => 'nowo:mail-kit:graph:schema']);
        self::assertSame("The inbound tables exist.\n", $tester->getDisplay());
    }

    private function bootInbound(bool $debug = false, bool $hostProfiles = true): MailKitTestKernel
    {
        return $this->bootKernel(
            [
                'mailer' => ['dsn_provider' => StaticDsnProvider::class, 'sender_provider' => RequestTenantSenderProvider::class],
                'log'    => ['enabled' => false],
                'graph'  => [
                    'enabled'          => true,
                    'mailbox_provider' => HostGraphMailboxProvider::class,
                    'http_client'      => 'test.graph_http_client',
                    'token_cache'      => 'cache.graph_tokens',
                    'draft_cleanup'    => ['bus' => 'messenger.default_bus'],
                    'inbound'          => [
                        'enabled' => true,
                        'handler' => RecordingInboundHandler::class,
                    ] + ($hostProfiles ? ['profiles_provider' => 'test.inbound_profiles'] : ['profiles' => ['acme', 'globex']]) + [
                        'webhook' => ['notification_url' => 'https://app.example.com/hooks/mail-kit/v1/graph', 'rate_limit' => 100_000],
                    ],
                ],
            ],
            without: ['instance_settings'],
            routes: static function (RoutingConfigurator $routes): void {
                $routes->import('.', 'nowo_mail_kit_graph_webhook');
            },
            debug: $debug,
            extra: static function (ContainerConfigurator $container): void {
                $services = $container->services();
                $services->set(GraphApiMock::class)->public();
                $services->set('test.graph_http_client', MockHttpClient::class)->factory([service(GraphApiMock::class), 'client']);
                $services->set(StaticDsnProvider::class)->args(['memory://default']);
                $services->set(RequestTenantSenderProvider::class)->args([service('request_stack')]);
                $services->set(HostGraphMailboxProvider::class);
                $services->set(RecordingInboundHandler::class)->public();
                $services->set('test.inbound_profiles', ConfiguredInboundProfiles::class)->args([['acme', 'globex'], service(HostGraphMailboxProvider::class)]);
                $container->extension('framework', ['cache' => ['pools' => ['cache.graph_tokens' => ['adapter' => 'cache.adapter.array']]]]);
            },
        );
    }
}
