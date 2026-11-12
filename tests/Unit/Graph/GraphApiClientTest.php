<?php

declare(strict_types=1);

namespace Nowo\MailKitBundle\Tests\Unit\Graph;

use Nowo\MailKitBundle\Graph\GraphApiClient;
use Nowo\MailKitBundle\Graph\GraphException;
use Nowo\MailKitBundle\Graph\GraphTokenProvider;
use Nowo\MailKitBundle\Tests\Support\GraphApiMock;
use Nowo\MailKitBundle\Tests\Support\RecordingLogger;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Cache\Adapter\ArrayAdapter;
use Symfony\Component\HttpClient\Exception\TransportException;
use Symfony\Component\HttpClient\Response\MockResponse;

use function print_r;
use function str_contains;

final class GraphApiClientTest extends TestCase
{
    private GraphApiMock $mock;

    private RecordingLogger $logger;

    protected function setUp(): void
    {
        $this->mock   = new GraphApiMock();
        $this->logger = new RecordingLogger();
    }

    private function api(): GraphApiClient
    {
        $client = $this->mock->client();

        return new GraphApiClient($client, new GraphTokenProvider($client, new ArrayAdapter()), GraphApiClient::DEFAULT_UPLOAD_HOSTS, 5.0, $this->logger);
    }

    public function testRetriesOnceWithAFreshTokenAfter401(): void
    {
        $calls                = 0;
        $this->mock->override = static function (string $method, string $url) use (&$calls): ?MockResponse {
            if (str_contains($url, 'graph.microsoft.com') && ++$calls === 1) {
                return GraphApiMock::json(['error' => ['code' => 'InvalidAuthenticationToken']], 401);
            }

            return null;
        };
        $api = $this->api();
        self::assertSame(204, $this->deleteDraft($api));
        self::assertSame(2, $this->mock->tokensIssued);
        $graph = $this->mock->matching('graph.microsoft.com');
        self::assertCount(2, $graph);
        self::assertSame('Bearer tok-' . GraphApiMock::TENANT_A . '-2', $graph[1]['headers']['authorization']);
        self::assertSame('/users/projects%40example.com', GraphApiClient::userPath(GraphApiMock::mailboxA()));
    }

    private function deleteDraft(GraphApiClient $api): int
    {
        $api->call(GraphApiMock::mailboxA(), 'DELETE', '/users/projects%40example.com/messages/x', [], [204], 'delete draft');

        return 204;
    }

    public function testPersistent401AndErrorsAreMappedWithoutBodies(): void
    {
        $this->mock->override = static fn (string $method, string $url): ?MockResponse => str_contains($url, 'graph.microsoft.com')
            ? GraphApiMock::json(['error' => ['code' => 'ErrorAccessDenied', 'message' => 'Access to projects@example.com denied']], 403, ['request-id' => 'abc-123'])
            : null;

        try {
            $this->api()->call(GraphApiMock::mailboxA(), 'GET', '/users/x', [], [200], 'read');
            self::fail('403 must throw.');
        } catch (GraphException $e) {
            self::assertSame(403, $e->status);
            self::assertSame('ErrorAccessDenied', $e->graphCode);
            self::assertSame('abc-123', $e->requestId);
            self::assertStringNotContainsString('projects@example.com', $e->getMessage());
        }
        self::assertStringNotContainsString('projects@example.com', print_r($this->logger->records, true));
        self::assertStringNotContainsString('tok-', print_r($this->logger->records, true));

        $this->mock->override = static fn (string $method, string $url): ?MockResponse => str_contains($url, 'graph.microsoft.com')
            ? new MockResponse('{"error":', ['http_code' => 429, 'response_headers' => ['Retry-After' => '12', 'client-request-id' => 'c-1']])
            : null;
        try {
            $this->api()->call(GraphApiMock::mailboxA(), 'GET', '/users/x', [], [200], 'read');
            self::fail('429 must throw.');
        } catch (GraphException $e) {
            self::assertSame(GraphException::REASON_THROTTLED, $e->reason);
            self::assertSame(12, $e->retryAfter);
            self::assertSame('c-1', $e->requestId);
        }

        $this->mock->override = static fn (string $method, string $url): ?MockResponse => str_contains($url, 'graph.microsoft.com')
            ? new MockResponse('[1]', ['http_code' => 500])
            : null;
        try {
            $this->api()->call(GraphApiMock::mailboxA(), 'GET', '/users/x', [], [200], 'read');
            self::fail('500 must throw.');
        } catch (GraphException $e) {
            self::assertNull($e->graphCode);
            self::assertTrue($e->isRetryable());
        }

        $this->mock->override = static fn (string $method, string $url): ?MockResponse => str_contains($url, 'graph.microsoft.com')
            ? GraphApiMock::json(['ok' => true], 401)
            : null;
        try {
            $this->api()->call(GraphApiMock::mailboxA(), 'GET', '/users/x', [], [200], 'read');
            self::fail('A second 401 must throw.');
        } catch (GraphException $e) {
            self::assertSame(401, $e->status);
        }
    }

    public function testTransportErrorsHideTheUrl(): void
    {
        $this->mock->override = static fn (string $method, string $url): ?MockResponse => str_contains($url, 'graph.microsoft.com')
            ? throw new TransportException('Idle timeout reached for "' . $url . '".') : null;

        try {
            $this->api()->call(GraphApiMock::mailboxA(), 'GET', '/users/projects%40example.com', [], [200], 'read');
            self::fail('Transport failure must throw.');
        } catch (GraphException $e) {
            self::assertSame('Microsoft Graph read failed: network error or timeout.', $e->getMessage());
        }
        self::assertTrue($this->logger->has('Microsoft Graph call failed.'));
    }

    public function testOnlyRelativeGraphPathsAreCalled(): void
    {
        $this->expectException(GraphException::class);
        $this->expectExceptionMessage('outside the allowed hosts');
        $this->api()->call(GraphApiMock::mailboxA(), 'GET', 'https://evil.example/steal', [], [200], 'read');
    }

    public function testUploadUrlGuard(): void
    {
        $api = $this->api();
        $api->assertUploadUrl('https://outlook.office.com/upload/x?authtoken=a');
        $api->assertUploadUrl('https://OUTLOOK.office365.com:443/upload/x');
        foreach ([
            'http://outlook.office.com/upload/x',
            'https://evil.example/upload',
            'https://outlook.office.com.evil.example/x',
            'https://user:pw@outlook.office.com/x',
            'https://outlook.office.com:8443/x',
            'https://169.254.169.254/latest/meta-data',
            'not a url',
            '//outlook.office.com/x',
        ] as $url) {
            try {
                $api->assertUploadUrl($url);
                self::fail('Accepted ' . $url);
            } catch (GraphException $e) {
                self::assertSame(GraphException::REASON_UNTRUSTED_URL, $e->reason);
            }
        }
    }

    public function testUploadChunksCarryNoTokenAndCancelIsBestEffort(): void
    {
        $api = $this->api();
        self::assertSame(200, $api->uploadChunk(GraphApiMock::mailboxA(), 'https://outlook.office.com/upload/s', 'abc', 0, 6));
        self::assertSame(201, $api->uploadChunk(GraphApiMock::mailboxA(), 'https://outlook.office.com/upload/s', 'def', 3, 6));
        $puts = $this->mock->matching('outlook.office.com', 'PUT');
        self::assertSame('bytes 0-2/6', $puts[0]['headers']['content-range']);
        self::assertSame('bytes 3-5/6', $puts[1]['headers']['content-range']);
        self::assertArrayNotHasKey('authorization', $puts[1]['headers']);
        self::assertSame(0, $this->mock->tokensIssued);

        try {
            $api->uploadChunk(GraphApiMock::mailboxA(), 'https://outlook.office.com/upload/t', 'abc', 1, 6);
            self::fail('Bad range must throw.');
        } catch (GraphException $e) {
            self::assertSame('InvalidRange', $e->graphCode);
        }
        $this->mock->override = static fn (): MockResponse => new MockResponse('', ['http_code' => 410]);
        try {
            $api->uploadChunk(GraphApiMock::mailboxA(), 'https://outlook.office.com/upload/s', 'abc', 0, 6);
            self::fail('410 must throw.');
        } catch (GraphException $e) {
            self::assertNull($e->graphCode);
        }

        $this->mock->override = null;
        $api->cancelUpload(GraphApiMock::mailboxA(), 'https://outlook.office.com/upload/s');
        self::assertCount(1, $this->mock->matching('outlook.office.com', 'DELETE'));
        $api->cancelUpload(GraphApiMock::mailboxA(), 'https://evil.example/upload/s');
        $this->mock->override = static fn (): never => throw new TransportException('down');
        $api->cancelUpload(GraphApiMock::mailboxA(), 'https://outlook.office.com/upload/s');
        self::assertCount(0, $this->mock->matching('evil.example'));
    }
}
