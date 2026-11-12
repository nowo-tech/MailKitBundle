<?php

declare(strict_types=1);

namespace Nowo\MailKitBundle\Tests\Unit\Graph\Inbound;

use Nowo\MailKitBundle\Graph\GraphApiClient;
use Nowo\MailKitBundle\Graph\GraphException;
use Nowo\MailKitBundle\Graph\GraphTokenProvider;
use Nowo\MailKitBundle\Graph\Inbound\GraphDeltaSweeper;
use Nowo\MailKitBundle\Graph\Inbound\Message\FetchGraphMessage;
use Nowo\MailKitBundle\Graph\Inbound\Store\GraphInboundStore;
use Nowo\MailKitBundle\Tests\Support\GraphApiMock;
use Nowo\MailKitBundle\Tests\Support\HostGraphMailboxProvider;
use Nowo\MailKitBundle\Tests\Support\InboundStoreTrait;
use Nowo\MailKitBundle\Tests\Support\RecordingBus;
use Nowo\MailKitBundle\Tests\Support\RecordingLogger;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Cache\Adapter\ArrayAdapter;
use Symfony\Component\Clock\MockClock;
use Symfony\Component\HttpClient\Response\MockResponse;

use function rawurldecode;
use function str_contains;

final class GraphDeltaSweeperTest extends TestCase
{
    use InboundStoreTrait;

    private const BASE = 'https://graph.microsoft.com/v1.0/users/projects%40example.com/mailFolders/inbox/messages/delta';

    private GraphApiMock $mock;

    private RecordingBus $bus;

    private GraphInboundStore $store;

    private RecordingLogger $logger;

    protected function setUp(): void
    {
        $this->mock   = new GraphApiMock();
        $this->bus    = new RecordingBus();
        $this->store  = $this->inboundStore();
        $this->logger = new RecordingLogger();
    }

    private function sweeper(): GraphDeltaSweeper
    {
        $client = $this->mock->client();

        return new GraphDeltaSweeper(new HostGraphMailboxProvider(), new GraphApiClient($client, new GraphTokenProvider($client, new ArrayAdapter())), $this->store, $this->bus, 'P2D', 25, 'inbox', new MockClock('2026-11-12 10:00:00'), $this->logger);
    }

    /**
     * @param array<string, array<mixed>> $pages URL fragment => answer
     */
    private function pages(array $pages): void
    {
        $this->mock->override = static function (string $method, string $url) use ($pages): ?MockResponse {
            foreach ($pages as $fragment => $answer) {
                if (str_contains($url, $fragment)) {
                    return GraphApiMock::json($answer);
                }
            }

            return null;
        };
    }

    public function testInitialSweepPagesAndStoresTheDeltaLink(): void
    {
        $this->pages([
            'changeType=created' => ['value' => [['id' => 'm1'], ['id' => 'm2', '@removed' => ['reason' => 'deleted']], ['id' => 'bad id'], 'garbage'], '@odata.nextLink' => self::BASE . '?$skiptoken=page2'],
            'skiptoken=page2'    => ['value' => [['id' => 'm3']], '@odata.deltaLink' => self::BASE . '?$deltatoken=d1'],
        ]);
        self::assertSame(['pages' => 2, 'dispatched' => 2, 'complete' => true], $this->sweeper()->sweep('acme'));
        self::assertEquals([new FetchGraphMessage('acme', 'm1'), new FetchGraphMessage('acme', 'm3')], $this->bus->messages);
        self::assertSame(self::BASE . '?$deltatoken=d1', $this->store->deltaLink('acme'));

        $first = $this->mock->matching('changeType=created')[0];
        self::assertStringContainsString('$filter=receivedDateTime%20ge%202026-11-10T10%3A00%3A00Z', $first['url']);
        self::assertSame('odata.maxpagesize=25, IdType="ImmutableId"', $first['headers']['prefer']);

        // The next sweep follows the stored delta link.
        $this->pages(['deltatoken=d1' => ['value' => [], '@odata.deltaLink' => self::BASE . '?$deltatoken=d2']]);
        self::assertSame(['pages' => 1, 'dispatched' => 0, 'complete' => true], $this->sweeper()->sweep('acme'));
        self::assertSame(self::BASE . '?$deltatoken=d2', $this->store->deltaLink('acme'));
        self::assertTrue($this->logger->has('delta sweep finished'));
    }

    public function testPageLimitResumesAndNoLinkStops(): void
    {
        $this->pages(['changeType=created' => ['value' => [['id' => 'm1']], '@odata.nextLink' => self::BASE . '?$skiptoken=p2']]);
        self::assertSame(['pages' => 1, 'dispatched' => 1, 'complete' => false], $this->sweeper()->sweep('acme', 1));
        self::assertSame(self::BASE . '?$skiptoken=p2', $this->store->deltaLink('acme'));

        $this->pages(['skiptoken=p2' => ['value' => []]]);
        self::assertSame(['pages' => 1, 'dispatched' => 0, 'complete' => false], $this->sweeper()->sweep('acme'));
    }

    public function testLinksOutsideGraphAreNeverFollowed(): void
    {
        foreach ([
            ['@odata.nextLink', 'https://evil.example/v1.0/steal'],
            ['@odata.deltaLink', 'https://graph.microsoft.com.evil.example/v1.0/x'],
            ['@odata.nextLink', 'https://graph.microsoft.com/v1.0/users/support%40example.org/mailFolders/inbox/messages/delta?$skiptoken=x'],
            ['@odata.deltaLink', 'https://graph.microsoft.com/v1.0/users/projects%40example.com.evil/messages/delta'],
        ] as [$key, $link]) {
            $this->store->deleteDeltaLink('acme');
            $this->pages(['changeType=created' => ['value' => [], $key => $link]]);
            try {
                $this->sweeper()->sweep('acme');
                self::fail('Untrusted link followed.');
            } catch (GraphException $e) {
                self::assertSame(GraphException::REASON_UNTRUSTED_URL, $e->reason);
            }
            self::assertNull($this->store->deltaLink('acme'));
            self::assertCount(0, $this->mock->matching('evil.example'));
            self::assertCount(0, $this->mock->matching('support%40example.org'));
        }

        // A stored link that is not Graph (tampered table) is refused too.
        $this->store->saveDeltaLink('acme', 'http://169.254.169.254/latest');
        $this->expectException(GraphException::class);
        $this->sweeper()->sweep('acme');
    }

    public function testExpiredDeltaTokenRestartsAndOtherErrorsPropagate(): void
    {
        $this->store->saveDeltaLink('acme', self::BASE . '?$deltatoken=old');
        $this->mock->override = static fn (string $method, string $url): ?MockResponse => str_contains(rawurldecode($url), 'deltatoken=old') ? GraphApiMock::json(['error' => ['code' => 'SyncStateNotFound']], 410) : null;
        self::assertSame(['pages' => 0, 'dispatched' => 0, 'complete' => false], $this->sweeper()->sweep('acme'));
        self::assertNull($this->store->deltaLink('acme'));
        self::assertTrue($this->logger->has('delta token expired'));

        $this->mock->override = static fn (): MockResponse => GraphApiMock::json(['error' => ['code' => 'ErrorAccessDenied']], 403);
        $this->expectException(GraphException::class);
        $this->sweeper()->sweep('acme');
    }
}
