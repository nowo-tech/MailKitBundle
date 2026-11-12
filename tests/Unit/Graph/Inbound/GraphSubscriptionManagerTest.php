<?php

declare(strict_types=1);

namespace Nowo\MailKitBundle\Tests\Unit\Graph\Inbound;

use DateTimeImmutable;
use Nowo\MailKitBundle\Graph\GraphApiClient;
use Nowo\MailKitBundle\Graph\GraphException;
use Nowo\MailKitBundle\Graph\GraphTokenProvider;
use Nowo\MailKitBundle\Graph\Inbound\GraphSubscriptionManager;
use Nowo\MailKitBundle\Graph\Inbound\Store\GraphInboundStore;
use Nowo\MailKitBundle\Tests\Support\GraphApiMock;
use Nowo\MailKitBundle\Tests\Support\HostGraphMailboxProvider;
use Nowo\MailKitBundle\Tests\Support\InboundStoreTrait;
use Nowo\MailKitBundle\Tests\Support\RecordingLogger;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Cache\Adapter\ArrayAdapter;
use Symfony\Component\Clock\MockClock;
use Symfony\Component\HttpClient\Response\MockResponse;

use function hash;
use function json_decode;
use function str_contains;
use function strlen;

final class GraphSubscriptionManagerTest extends TestCase
{
    use InboundStoreTrait;

    private const URL = 'https://app.example.com/hooks/mail-kit/v1/graph';

    private GraphApiMock $mock;

    private GraphInboundStore $store;

    private MockClock $clock;

    protected function setUp(): void
    {
        $this->mock  = new GraphApiMock();
        $this->clock = new MockClock('2026-11-12 10:00:00');
        $this->store = $this->inboundStore('', $this->clock);
    }

    private function manager(?string $url = self::URL): GraphSubscriptionManager
    {
        $client = $this->mock->client();

        return new GraphSubscriptionManager(new HostGraphMailboxProvider(), new GraphApiClient($client, new GraphTokenProvider($client, new ArrayAdapter())), $this->store, $url, 4320, 1440, 'inbox', $this->clock, new RecordingLogger());
    }

    public function testCreateRenewAndRecreate(): void
    {
        self::assertSame(GraphSubscriptionManager::DISABLED, $this->manager(null)->ensure('acme'));

        self::assertSame(GraphSubscriptionManager::CREATED, $this->manager()->ensure('acme'));
        $request = $this->mock->subscriptionRequests[0];
        self::assertSame('created', $request['changeType']);
        self::assertSame(self::URL, $request['notificationUrl']);
        self::assertSame(self::URL, $request['lifecycleNotificationUrl']);
        self::assertSame("users/projects@example.com/mailFolders('inbox')/messages", $request['resource']);
        self::assertSame('2026-11-15T10:00:00.000Z', $request['expirationDateTime']);
        self::assertSame(64, strlen($request['clientState']));
        $create = $this->mock->matching('/v1.0/subscriptions', 'POST')[0];
        self::assertSame('IdType="ImmutableId"', $create['headers']['prefer']);

        // Only the hash of the client state is stored, and it verifies notifications.
        $stored = $this->inboundConnection?->fetchOne('SELECT client_state_hash FROM mail_kit_graph_subscription');
        self::assertSame(hash('sha256', $request['clientState']), $stored);
        self::assertSame('acme', $this->store->verifyNotification('sub-1-0000', $request['clientState']));

        self::assertSame(GraphSubscriptionManager::CURRENT, $this->manager()->ensure('acme'));
        $this->clock->modify('+2 days +1 minute');
        self::assertSame(GraphSubscriptionManager::RENEWED, $this->manager()->ensure('acme'));
        $patch = json_decode($this->mock->matching('/subscriptions/sub-1-0000', 'PATCH')[0]['body'], true);
        self::assertSame(['expirationDateTime' => '2026-11-17T10:01:00.000Z'], $patch);
        self::assertSame((new DateTimeImmutable('2026-11-17T10:01:00Z'))->getTimestamp(), $this->store->subscription('acme')['expires_at'] ?? null);

        // Graph forgot it (404): a new one is created.
        $this->store->saveSubscription('gone-1', 'acme', 'x', 0);
        self::assertSame(GraphSubscriptionManager::CREATED, $this->manager()->ensure('acme'));
        self::assertSame('sub-2-0000', $this->store->subscription('acme')['id'] ?? null);
    }

    public function testFailures(): void
    {
        $this->store->saveSubscription('sub-9', 'acme', 'x', 0);
        $this->mock->override = static fn (string $method): ?MockResponse => $method === 'PATCH' ? GraphApiMock::json(['error' => ['code' => 'ErrorAccessDenied']], 403) : null;
        try {
            $this->manager()->ensure('acme');
            self::fail('403 must propagate.');
        } catch (GraphException $e) {
            self::assertSame(403, $e->status);
        }

        // Unparsable expiry: the requested one is kept.
        $this->mock->override = static fn (string $method): ?MockResponse => $method === 'PATCH' ? GraphApiMock::json(['expirationDateTime' => 'soon']) : null;
        self::assertSame(GraphSubscriptionManager::RENEWED, $this->manager()->ensure('acme'));
        self::assertSame($this->clock->now()->getTimestamp() + 4320 * 60, $this->store->subscription('acme')['expires_at'] ?? null);

        // No expiry in Graph's answer: the requested one is stored.
        $this->store->deleteSubscription('sub-9');
        $this->mock->override = static fn (string $method, string $url): ?MockResponse => str_contains($url, '/subscriptions') ? GraphApiMock::json(['id' => 'sub-77'], 201) : null;
        self::assertSame(GraphSubscriptionManager::CREATED, $this->manager()->ensure('acme'));
        self::assertSame($this->clock->now()->getTimestamp() + 4320 * 60, $this->store->subscription('acme')['expires_at'] ?? null);

        $this->store->deleteSubscription('sub-77');
        $this->mock->override = static fn (string $method, string $url): ?MockResponse => str_contains($url, '/subscriptions') ? GraphApiMock::json(['id' => '../evil'], 201) : null;
        $this->expectException(GraphException::class);
        $this->expectExceptionMessage('no usable id');
        $this->manager()->ensure('acme');
    }

    public function testRemove(): void
    {
        self::assertFalse($this->manager()->delete('acme'));
        $this->manager()->ensure('acme');
        self::assertTrue($this->manager()->delete('acme'));
        self::assertNull($this->store->subscription('acme'));
        self::assertCount(1, $this->mock->matching('/subscriptions/sub-1-0000', 'DELETE'));
    }
}
