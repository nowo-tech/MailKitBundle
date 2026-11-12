<?php

declare(strict_types=1);

namespace Nowo\MailKitBundle\Tests\Unit\Graph\Inbound;

use Nowo\MailKitBundle\Graph\Inbound\ConfiguredInboundProfiles;
use Nowo\MailKitBundle\Graph\Inbound\Message\FetchGraphMessage;
use Nowo\MailKitBundle\Graph\Inbound\Message\RenewGraphSubscriptions;
use Nowo\MailKitBundle\Graph\Inbound\Message\SweepGraphMailbox;
use Nowo\MailKitBundle\Graph\Inbound\Store\GraphInboundStore;
use Nowo\MailKitBundle\Graph\Inbound\Webhook\GraphWebhookController;
use Nowo\MailKitBundle\Graph\Inbound\Webhook\GraphWebhookRouteLoader;
use Nowo\MailKitBundle\Tests\Support\ChunkedStream;
use Nowo\MailKitBundle\Tests\Support\HostGraphMailboxProvider;
use Nowo\MailKitBundle\Tests\Support\InboundStoreTrait;
use Nowo\MailKitBundle\Tests\Support\RecordingBus;
use Nowo\MailKitBundle\Tests\Support\RecordingLogger;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\RateLimiter\RateLimiterFactory;
use Symfony\Component\RateLimiter\Storage\InMemoryStorage;

use function fopen;
use function in_array;
use function is_string;
use function json_encode;
use function print_r;
use function rawurlencode;
use function str_repeat;
use function stream_context_create;
use function stream_get_wrappers;
use function stream_wrapper_register;
use function stream_wrapper_unregister;

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
        $this->store->saveSubscription('sub-old', 'old', $this->secret, 2_000_000_000);
        $this->store->saveSubscription('sub-expired', 'globex-2', $this->secret, 1000);
    }

    /**
     * @param list<string> $allowedIps
     */
    private function controller(int $verified = 1000, int $client = 1000, int $maxBody = 65_536, int $maxNotifications = 20, array $allowedIps = []): GraphWebhookController
    {
        return new GraphWebhookController(
            $this->store,
            $this->bus,
            new RateLimiterFactory(['id' => 'verified', 'policy' => 'sliding_window', 'limit' => $verified, 'interval' => '1 minute'], new InMemoryStorage()),
            new RateLimiterFactory(['id' => 'client', 'policy' => 'sliding_window', 'limit' => $client, 'interval' => '1 minute'], new InMemoryStorage()),
            new ConfiguredInboundProfiles(['acme', 'globex', 'globex-2'], new HostGraphMailboxProvider()),
            $maxBody,
            $maxNotifications,
            $allowedIps,
            $this->logger,
        );
    }

    /**
     * @param array<mixed>|string $payload
     * @param array<string, string> $server
     */
    private function post(array|string $payload, string $type = 'application/json', array $server = []): Request
    {
        return Request::create('/hooks/mail-kit/v1/graph', 'POST', server: $server + ['CONTENT_TYPE' => $type, 'REMOTE_ADDR' => '198.51.100.7'], content: is_string($payload) ? $payload : (string) json_encode($payload));
    }

    /**
     * @param array<string, mixed> $extra
     *
     * @return array<string, mixed>
     */
    private function notification(string $subscription, string $clientState, array $extra = []): array
    {
        return $extra + ['subscriptionId' => $subscription, 'clientState' => $clientState, 'changeType' => 'created', 'resource' => 'Users/x/Messages/y', 'resourceData' => ['id' => 'AAMkMsg1=']];
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

    public function testNotificationsAreVerifiedOnceAndOnlyEnqueued(): void
    {
        $response = ($this->controller())($this->post(['value' => [
            $this->notification('sub-a', $this->secret),
            $this->notification('sub-a', $this->secret),
            $this->notification('sub-a', $this->secret, ['resourceData' => ['id' => 'bad id!']]),
            $this->notification('sub-a', $this->secret, ['changeType' => 'updated']),
            $this->notification('sub-a', 'wrong-state'),
            $this->notification('sub-b', $this->secret),
            $this->notification('bad id!', $this->secret),
            'garbage',
        ]]));
        $this->assertPlain($response, 202);
        self::assertEquals([new FetchGraphMessage('acme', 'AAMkMsg1=')], $this->bus->messages);
        self::assertStringNotContainsString('REDACTED-TEST', print_r($this->logger->records, true));
        self::assertTrue($this->logger->has('notifications received'));
        self::assertTrue($this->logger->has('notifications rejected'));
    }

    public function testExpiredSubscriptionsOffboardedProfilesAndTooManyRejections(): void
    {
        $controller = $this->controller();
        // Expired subscription, profile no longer read (offboarded): refused.
        $this->assertPlain($controller($this->post(['value' => [$this->notification('sub-expired', $this->secret)]])), 403);
        $this->assertPlain($controller($this->post(['value' => [$this->notification('sub-old', $this->secret)]])), 403);

        // After five distinct rejected subscriptions no further one is looked up: only forged items → 403.
        $items = [];
        for ($i = 0; $i < 5; ++$i) {
            $items[] = $this->notification('unknown-' . $i, $this->secret);
        }
        $items[] = $this->notification('sub-a', $this->secret);
        $this->assertPlain($controller($this->post(['value' => $items])), 403);
        self::assertSame([], $this->bus->messages);

        // Valid items are never refused for stale ones next to them (≥ 5 stale items in a mixed batch).
        $items = [$this->notification('sub-a', $this->secret)];
        for ($i = 0; $i < 6; ++$i) {
            $items[] = $this->notification('stale-' . $i, $this->secret);
        }
        $items[] = $this->notification('sub-a', $this->secret, ['resourceData' => ['id' => 'AAMkMsg2=']]);
        $this->assertPlain($controller($this->post(['value' => $items])), 202);
        self::assertEquals([new FetchGraphMessage('acme', 'AAMkMsg1='), new FetchGraphMessage('acme', 'AAMkMsg2=')], $this->bus->messages);
    }

    public function testLifecycleEvents(): void
    {
        ($this->controller())($this->post(['value' => [
            ['subscriptionId' => 'sub-a', 'clientState' => $this->secret, 'lifecycleEvent' => 'missed'],
            ['subscriptionId' => 'sub-a', 'clientState' => $this->secret, 'lifecycleEvent' => 'reauthorizationRequired'],
            ['subscriptionId' => 'sub-a', 'clientState' => $this->secret, 'lifecycleEvent' => 'subscriptionRemoved'],
            ['subscriptionId' => 'sub-a', 'clientState' => $this->secret, 'lifecycleEvent' => 'somethingNew'],
        ]]));
        self::assertEquals([
            new SweepGraphMailbox('acme'),
            new RenewGraphSubscriptions('acme', RenewGraphSubscriptions::REAUTHORIZE, 'sub-a'),
            new RenewGraphSubscriptions('acme', RenewGraphSubscriptions::REMOVED, 'sub-a'),
        ], $this->bus->messages);
        self::assertTrue($this->logger->has('lifecycle event ignored'));
    }

    public function testRejections(): void
    {
        $controller = $this->controller(1000, 1000, 4096, 5);
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
    }

    /**
     * @return resource
     */
    private function chunked(string $content): mixed
    {
        $stream = fopen('mk-chunked://body', 'r', false, stream_context_create(['mk-chunked' => ['content' => $content]]));
        self::assertIsResource($stream);

        return $stream;
    }

    public function testBodiesAreReadToTheCapWhateverTheChunkSize(): void
    {
        if (!in_array('mk-chunked', stream_get_wrappers(), true)) {
            stream_wrapper_register('mk-chunked', ChunkedStream::class);
        }

        try {
            // A valid batch larger than one 8 KiB read (padding inside the JSON).
            $request = new Request(server: ['REQUEST_METHOD' => 'POST', 'CONTENT_TYPE' => 'application/json', 'REMOTE_ADDR' => '198.51.100.7'], content: $this->chunked((string) json_encode(['value' => [$this->notification('sub-a', $this->secret, ['padding' => str_repeat('p', 20_000)])]])));
            $this->assertPlain(($this->controller())($request), 202);
            self::assertCount(1, $this->bus->messages);

            // Over the cap across several reads: 413, not a truncated parse.
            $request = new Request(server: ['REQUEST_METHOD' => 'POST', 'CONTENT_TYPE' => 'application/json', 'REMOTE_ADDR' => '198.51.100.7'], content: $this->chunked(str_repeat(' ', 70_000)));
            $this->assertPlain(($this->controller())($request), 413);
        } finally {
            stream_wrapper_unregister('mk-chunked');
        }
    }

    public function testRateLimitsAreSplitAndSourcesCanBeRestricted(): void
    {
        // Forged traffic from one client exhausts only that client's bucket.
        $controller = $this->controller(1000, 3);
        for ($i = 0; $i < 3; ++$i) {
            $this->assertPlain($controller($this->post(['value' => [$this->notification('sub-a', 'wrong')]])), 403);
        }
        // An exhausted client bucket never blocks a verifiable notification (Graph shares Microsoft's addresses).
        $this->assertPlain($controller($this->post(['value' => [$this->notification('sub-a', $this->secret)]])), 202);
        $this->assertPlain($controller($this->post(['value' => [$this->notification('sub-a', 'wrong'), $this->notification('sub-a', $this->secret)]])), 429);
        $this->assertPlain($controller(Request::create('/hooks?validationToken=x', 'POST', server: ['REMOTE_ADDR' => '198.51.100.7'])), 429);
        $this->assertPlain($controller($this->post('{bad')), 429);
        $other = $this->post(['value' => [$this->notification('sub-a', $this->secret)]], 'application/json', ['REMOTE_ADDR' => '203.0.113.5']);
        $this->assertPlain($controller($other), 202);
        $v6 = $this->post(['value' => [$this->notification('sub-a', 'wrong')]], 'application/json', ['REMOTE_ADDR' => '2001:db8::1']);
        $this->assertPlain($controller($v6), 403);

        // Verified requests use their own bucket.
        $verified = $this->controller(1, 1000);
        $this->assertPlain($verified($this->post(['value' => [$this->notification('sub-a', $this->secret)]])), 202);
        $this->assertPlain($verified($this->post(['value' => [$this->notification('sub-a', $this->secret)]])), 429);

        // Allow-list.
        $restricted = $this->controller(1000, 1000, 65_536, 20, ['203.0.113.0/24']);
        $this->assertPlain($restricted($this->post(['value' => [$this->notification('sub-a', $this->secret)]])), 403);
        $this->assertPlain($restricted($other), 202);
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
