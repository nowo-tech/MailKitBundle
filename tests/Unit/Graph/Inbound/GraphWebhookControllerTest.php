<?php

declare(strict_types=1);

namespace Nowo\MailKitBundle\Tests\Unit\Graph\Inbound;

use Nowo\MailKitBundle\Graph\Inbound\Message\FetchGraphMessage;
use Nowo\MailKitBundle\Graph\Inbound\Message\RenewGraphSubscriptions;
use Nowo\MailKitBundle\Graph\Inbound\Message\SweepGraphMailbox;
use Nowo\MailKitBundle\Graph\Inbound\Store\GraphInboundStore;
use Nowo\MailKitBundle\Graph\Inbound\Webhook\GraphWebhookController;
use Nowo\MailKitBundle\Graph\Inbound\Webhook\GraphWebhookRouteLoader;
use Nowo\MailKitBundle\Tests\Support\InboundStoreTrait;
use Nowo\MailKitBundle\Tests\Support\RecordingBus;
use Nowo\MailKitBundle\Tests\Support\RecordingLogger;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\RateLimiter\RateLimiterFactory;
use Symfony\Component\RateLimiter\Storage\InMemoryStorage;

use function is_string;
use function json_encode;
use function print_r;
use function str_repeat;

final class GraphWebhookControllerTest extends TestCase
{
    use InboundStoreTrait;

    private GraphInboundStore $store;

    private RecordingBus $bus;

    private RecordingLogger $logger;

    private string $secret;

    protected function setUp(): void
    {
        $this->store  = $this->inboundStore();
        $this->bus    = new RecordingBus();
        $this->logger = new RecordingLogger();
        $this->secret = 'client-state-REDACTED-TEST-a';
        $this->store->saveSubscription('sub-a', 'acme', $this->secret, 2_000_000_000);
        $this->store->saveSubscription('sub-b', 'globex', 'client-state-REDACTED-TEST-b', 2_000_000_000);
    }

    private function controller(int $limit = 1000, int $maxBody = 4096, int $maxNotifications = 5): GraphWebhookController
    {
        return new GraphWebhookController(
            $this->store,
            $this->bus,
            new RateLimiterFactory(['id' => 'test', 'policy' => 'sliding_window', 'limit' => $limit, 'interval' => '1 minute'], new InMemoryStorage()),
            $maxBody,
            $maxNotifications,
            $this->logger,
        );
    }

    /**
     * @param array<mixed>|string $payload
     * @param array<string, string> $server
     */
    private function post(array|string $payload, string $type = 'application/json', array $server = []): Request
    {
        return Request::create('/hooks/mail-kit/v1/graph', 'POST', server: ['CONTENT_TYPE' => $type] + $server, content: is_string($payload) ? $payload : (string) json_encode($payload));
    }

    /**
     * @param array<string, mixed> $extra
     *
     * @return array<string, mixed>
     */
    private function notification(string $subscription, string $clientState, array $extra = []): array
    {
        return ['subscriptionId' => $subscription, 'clientState' => $clientState, 'changeType' => 'created', 'resource' => 'Users/x/Messages/y', 'resourceData' => ['id' => 'AAMkMsg1=']] + $extra;
    }

    private function assertPlain(Response $response, int $status): void
    {
        self::assertSame($status, $response->getStatusCode());
        self::assertSame('text/plain; charset=utf-8', $response->headers->get('Content-Type'));
        self::assertSame('nosniff', $response->headers->get('X-Content-Type-Options'));
        self::assertStringContainsString("default-src 'none'", (string) $response->headers->get('Content-Security-Policy'));
    }

    public function testValidationTokenIsEchoedOnlyWhenSafe(): void
    {
        $controller = $this->controller();
        $response   = $controller(Request::create('/hooks?validationToken=' . rawurlencode('Validation: Token 123 <x>'), 'POST', server: ['CONTENT_TYPE' => 'text/plain']));
        $this->assertPlain($response, 200);
        self::assertSame('Validation: Token 123 <x>', $response->getContent());

        foreach (['validationToken=' . str_repeat('a', 1025), 'validationToken=%0Aevil', 'validationToken[]=a', 'validationToken='] as $query) {
            $response = $controller(Request::create('/hooks?' . $query, 'POST'));
            $this->assertPlain($response, 400);
            self::assertSame('', $response->getContent());
        }
        self::assertSame([], $this->bus->messages);
    }

    public function testNotificationsAreVerifiedAndOnlyEnqueued(): void
    {
        $response = ($this->controller(1000, 8192, 20))($this->post(['value' => [
            $this->notification('sub-a', $this->secret),
            $this->notification('sub-a', $this->secret),
            $this->notification('sub-a', $this->secret, ['resourceData' => ['id' => 'bad id!']]),
            $this->notification('sub-a', $this->secret, ['changeType' => 'updated']),
            $this->notification('sub-a', 'wrong-state'),
            $this->notification('sub-b', $this->secret),
            $this->notification('unknown', $this->secret),
            $this->notification('bad id!', $this->secret),
            $this->notification('sub-a', str_repeat('a', 256)),
            'garbage',
        ]]));
        $this->assertPlain($response, 202);
        self::assertEquals([new FetchGraphMessage('acme', 'AAMkMsg1=')], $this->bus->messages);
        self::assertStringNotContainsString('REDACTED-TEST', print_r($this->logger->records, true));
        self::assertTrue($this->logger->has('notifications received'));
    }

    public function testLifecycleEvents(): void
    {
        ($this->controller())($this->post(['value' => [
            ['subscriptionId' => 'sub-a', 'clientState' => $this->secret, 'lifecycleEvent' => 'missed'],
            ['subscriptionId' => 'sub-a', 'clientState' => $this->secret, 'lifecycleEvent' => 'reauthorizationRequired'],
            ['subscriptionId' => 'sub-a', 'clientState' => $this->secret, 'lifecycleEvent' => 'subscriptionRemoved'],
        ]]));
        self::assertEquals([new SweepGraphMailbox('acme'), new RenewGraphSubscriptions('acme'), new RenewGraphSubscriptions('acme')], $this->bus->messages);
    }

    public function testRejections(): void
    {
        $controller = $this->controller();
        $this->assertPlain($controller($this->post(['value' => [$this->notification('sub-a', 'wrong')]])), 403);
        $this->assertPlain($controller($this->post(['value' => []])), 400);
        $this->assertPlain($controller($this->post(['other' => 1])), 400);
        $this->assertPlain($controller($this->post('{not json')), 400);
        $this->assertPlain($controller($this->post('"scalar"')), 400);
        $this->assertPlain($controller($this->post(['value' => [1, 2, 3, 4, 5, 6]])), 413);
        $this->assertPlain($controller($this->post(['value' => [$this->notification('sub-a', $this->secret)]], 'text/plain')), 415);
        $this->assertPlain($controller($this->post(['value' => [$this->notification('sub-a', $this->secret)]], 'application/json', ['HTTP_CONTENT_LENGTH' => '999999'])), 413);
        $this->assertPlain($controller($this->post(str_repeat(' ', 5000))), 413);
        self::assertSame([], $this->bus->messages);

        $limited = $this->controller(10);
        for ($i = 0; $i < 10; ++$i) {
            $limited($this->post(['value' => [$this->notification('sub-a', 'wrong')]]));
        }
        $this->assertPlain($limited($this->post(['value' => [$this->notification('sub-a', $this->secret)]])), 429);
    }

    public function testRouteLoader(): void
    {
        $loader = new GraphWebhookRouteLoader(true, '/hooks/mail-kit/v1/graph');
        self::assertTrue($loader->supports('.', GraphWebhookRouteLoader::TYPE));
        self::assertFalse($loader->supports('.', 'other'));
        $route = $loader->load('.')->get(GraphWebhookRouteLoader::ROUTE);
        self::assertNotNull($route);
        self::assertSame('/hooks/mail-kit/v1/graph', $route->getPath());
        self::assertSame(['POST'], $route->getMethods());
        self::assertSame(GraphWebhookController::class, $route->getDefault('_controller'));
        self::assertTrue($route->getDefault('_stateless'));

        self::assertCount(0, (new GraphWebhookRouteLoader(false, '/x'))->load('.'));
        $this->expectException(RuntimeException::class);
        $loader->load('.');
    }
}
