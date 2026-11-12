<?php

declare(strict_types=1);

namespace Nowo\MailKitBundle\Tests\Integration\Graph;

use Nowo\MailKitBundle\Graph\ConfiguredGraphMailboxProvider;
use Nowo\MailKitBundle\Graph\GraphApiClient;
use Nowo\MailKitBundle\Graph\GraphMailboxProviderInterface;
use Nowo\MailKitBundle\Graph\GraphTokenProvider;
use Nowo\MailKitBundle\Graph\Send\GraphMailSender;
use Nowo\MailKitBundle\Graph\Send\GraphOutgoingMail;
use Nowo\MailKitBundle\Graph\Send\GraphRecipient;
use Nowo\MailKitBundle\Graph\Transport\GraphTransport;
use Nowo\MailKitBundle\Graph\Transport\GraphTransportFactory;
use Nowo\MailKitBundle\Tests\Integration\Kernel\KernelTestCase;
use Nowo\MailKitBundle\Tests\Support\GraphApiMock;
use Nowo\MailKitBundle\Tests\Support\GraphSendController;
use Nowo\MailKitBundle\Tests\Support\HostGraphMailboxProvider;
use Nowo\MailKitBundle\Tests\Support\RequestTenantSenderProvider;
use Nowo\MailKitBundle\Tests\Support\StaticDsnProvider;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Component\Console\Tester\ApplicationTester;
use Symfony\Component\DependencyInjection\Loader\Configurator\ContainerConfigurator;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\HttpKernel;
use Symfony\Component\Mailer\Transport;
use Symfony\Component\Mime\Email;
use Symfony\Component\Routing\Loader\Configurator\RoutingConfigurator;

use function explode;
use function gc_collect_cycles;
use function memory_get_usage;
use function preg_replace;
use function Symfony\Component\DependencyInjection\Loader\Configurator\service;

/**
 * 1.8 Microsoft Graph sending in a booted kernel: off by default; standard host (profiles in the bundle config, the
 * microsoft+graph mailer transport); strict DDD host (no Instance Settings, no ORM, host mailbox port); FrankenPHP
 * worker mode (one kept-alive kernel, no services resetter, two organizations alternating); lint:container.
 */
final class GraphKernelTest extends KernelTestCase
{
    /**
     * Fixture profile; the secret marker is built at runtime (never a literal credential in the repository).
     *
     * @return array<string, array<string, string>>
     */
    private function profiles(): array
    {
        return ['acme' => [
            'tenant_id'     => GraphApiMock::TENANT_A,
            'client_id'     => GraphApiMock::CLIENT_A,
            'client_secret' => GraphApiMock::secret(),
            'mailbox'       => 'projects@example.com',
        ]];
    }

    public function testOffByDefault(): void
    {
        $container = $this->container($this->bootKernel());
        foreach ([GraphMailSender::class, GraphMailboxProviderInterface::class, GraphApiClient::class, GraphTokenProvider::class, GraphTransportFactory::class] as $id) {
            self::assertFalse($container->has($id), $id);
        }
    }

    public function testStandardHostSendsThroughTheMailerTransport(): void
    {
        $kernel    = $this->bootKernel(['graph' => ['enabled' => true, 'default_profile' => 'acme', 'profiles' => $this->profiles(), 'http_client' => 'test.graph_http_client']], extra: self::mockClient(...));
        $container = $this->container($kernel);
        self::assertInstanceOf(ConfiguredGraphMailboxProvider::class, $container->get(GraphMailboxProviderInterface::class));

        $factory = $container->get('mailer.transport_factory');
        self::assertInstanceOf(Transport::class, $factory);
        $transport = $factory->fromString('microsoft+graph://default');
        self::assertInstanceOf(GraphTransport::class, $transport);
        $sent = $transport->send((new Email())->from('app@example.com')->to('client@example.net')->subject('Hi')->text('Hi'));
        self::assertNotNull($sent);

        $mock = $this->service($kernel, GraphApiMock::class);
        self::assertCount(1, $mock->matching('/users/projects%40example.com/messages/', 'POST'));
        self::assertSame(1, $mock->tokensIssued);

        // The VO API of the same kernel.
        $result = $this->service($kernel, GraphMailSender::class)->send(new GraphOutgoingMail([new GraphRecipient('client@example.net')], 'Hi', 'Hi'));
        self::assertSame('acme', $result->profile);
        self::assertSame(1, $mock->tokensIssued, 'The token is cached in cache.app.');
    }

    public function testDddHostWithItsOwnMailboxPortAndManyRequestsInOneWorker(): void
    {
        $kernel = $this->bootKernel(
            [
                'mailer' => ['dsn_provider' => StaticDsnProvider::class, 'sender_provider' => RequestTenantSenderProvider::class],
                'log'    => ['enabled' => false],
                'graph'  => ['enabled' => true, 'mailbox_provider' => HostGraphMailboxProvider::class, 'http_client' => 'test.graph_http_client', 'token_cache' => 'cache.graph_tokens'],
            ],
            without: ['instance_settings', 'doctrine'],
            routes: static function (RoutingConfigurator $routes): void {
                $routes->add('graph_send', '/graph/{tenant}/{task}')->controller(GraphSendController::class);
            },
            extra: static function (ContainerConfigurator $container): void {
                self::mockClient($container);
                $services = $container->services();
                $services->set(StaticDsnProvider::class)->args(['memory://default']);
                $services->set(RequestTenantSenderProvider::class)->args([service('request_stack')]);
                $services->set(HostGraphMailboxProvider::class);
                $services->set(GraphSendController::class)->args([service(GraphMailSender::class)])->public()->tag('controller.service_arguments');
                $container->extension('framework', ['cache' => ['pools' => ['cache.graph_tokens' => ['adapter' => 'cache.adapter.array']]]]);
            },
        );
        $container = $this->container($kernel);
        self::assertFalse($container->has('doctrine'));
        self::assertInstanceOf(HostGraphMailboxProvider::class, $container->get(GraphMailboxProviderInterface::class));

        $http = $kernel->getContainer()->get('http_kernel');
        self::assertInstanceOf(HttpKernel::class, $http);
        $mock   = $this->service($kernel, GraphApiMock::class);
        $before = 0;

        // FrankenPHP worker mode, kernel reset false: the same services serve every request, no services resetter.
        for ($i = 0; $i < 600; ++$i) {
            $tenant         = $i % 2 === 0 ? 'acme' : 'globex';
            $mock->requests = [];
            $request        = Request::create('/graph/' . $tenant . '/' . $i);
            $response       = $http->handle($request);
            self::assertSame(200, $response->getStatusCode(), (string) $response->getContent());
            $http->terminate($request, $response);

            [$profile, $graphId] = explode('|', (string) $response->getContent());
            self::assertSame($tenant, $profile);
            self::assertStringStartsWith('draft-' . ($tenant === 'acme' ? 'projects@example.com' : 'support@example.org'), $graphId);

            // The draft went to this organization's mailbox with this organization's token and Reply-To.
            $drafts = $mock->matching($tenant === 'acme' ? '/users/projects%40example.com/messages' : '/users/support%40example.org/messages', 'POST');
            self::assertCount(2, $drafts);
            $draft = $drafts[0];
            self::assertStringStartsWith('Bearer tok-' . ($tenant === 'acme' ? GraphApiMock::TENANT_A : GraphApiMock::TENANT_B) . '-', $draft['headers']['authorization']);
            self::assertStringContainsString('Reply-To: ' . ($tenant === 'acme' ? 'projects' : 'support') . '+t-' . $i . '@', $mock->draftMime());

            if ($i === 99) {
                gc_collect_cycles();
                $before = memory_get_usage();
            }
        }
        gc_collect_cycles();
        self::assertLessThan(2 * 1024 * 1024, memory_get_usage() - $before, 'Memory must stay flat over 500 requests.');
        self::assertSame(2, $mock->tokensIssued, 'One cached token per tenant, never shared.');

        $unknown = $http->handle(Request::create('/graph/initech/1'));
        self::assertSame(500, $unknown->getStatusCode());
        self::assertStringNotContainsString('REDACTED-TEST', (string) $unknown->getContent());
    }

    public function testLintContainer(): void
    {
        $kernel      = $this->bootKernel(['graph' => ['enabled' => true, 'default_profile' => 'acme', 'profiles' => $this->profiles()]], debug: true, extra: self::mockClient(...));
        $application = new Application($kernel);
        $application->setAutoExit(false);
        $tester = new ApplicationTester($application);
        $tester->run(['command' => 'lint:container']);
        self::assertSame(0, $tester->getStatusCode(), (string) preg_replace('/\s+/', ' ', $tester->getDisplay()));
    }

    private static function mockClient(ContainerConfigurator $container): void
    {
        $services = $container->services();
        $services->set(GraphApiMock::class)->public();
        $services->set('test.graph_http_client', MockHttpClient::class)->factory([service(GraphApiMock::class), 'client']);
    }
}
