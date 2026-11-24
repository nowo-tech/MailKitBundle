<?php

declare(strict_types=1);

namespace Nowo\MailKitBundle\Tests\Unit\Graph;

use Nowo\MailKitBundle\Graph\GraphApiClient;
use Nowo\MailKitBundle\Graph\GraphCredentialInterface;
use Nowo\MailKitBundle\Graph\GraphException;
use Nowo\MailKitBundle\Graph\GraphRequestOptionsInterface;
use Nowo\MailKitBundle\Graph\GuardedGraphRequestOptions;
use Nowo\MailKitBundle\Tests\Support\GraphApiMock;
use Nowo\MailKitBundle\Tests\Support\RecordingLogger;
use Nowo\OutboundUrlGuardBundle\Dns\HostnameDnsLookupInterface;
use Nowo\OutboundUrlGuardBundle\Guard\OutboundUrlGuard;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

use function array_shift;

use const DNS_A;

/**
 * 2.5.0 Graph client with a delegated credential: the credential's token in the header, one 401 report and retry,
 * every call reported (status 0 for a transport error), no app-only tokens needed, and the OutboundUrlGuard host pin.
 */
final class GraphCredentialClientTest extends TestCase
{
    public function testADelegatedCredentialIsAskedReportedAndRetriedOnce(): void
    {
        $credential = new class implements GraphCredentialInterface {
            public int $tokens = 0;

            public int $rejected = 0;

            /** @var list<array{string, int, int}> */
            public array $calls = [];

            public function token(): string
            {
                return 'fake-delegated-' . ++$this->tokens;
            }

            public function rejected(): void
            {
                ++$this->rejected;
            }

            public function logContext(): array
            {
                return ['mailbox' => 'mbx-1'];
            }

            public function called(string $operation, int $status, int $durationMs, int $bytes, ?int $retryAfter, int $attempt): void
            {
                $this->calls[] = [$operation, $status, $attempt];
            }
        };
        $headers = [];
        $answers = [new MockResponse('{}', ['http_code' => 401]), new MockResponse('{"id":"x"}', ['http_code' => 200]), new MockResponse('', ['error' => 'boom'])];
        $client  = new MockHttpClient(static function (string $method, string $url, array $options) use (&$headers, &$answers): MockResponse {
            $headers[] = $options['normalized_headers']['authorization'][0] ?? '';

            return array_shift($answers) ?? new MockResponse('', ['http_code' => 500]);
        });
        $log = new RecordingLogger();
        $api = new GraphApiClient($client, null, logger: $log);

        self::assertSame(['id' => 'x'], $api->call($credential, 'GET', '/me', [], [200], 'account'));
        self::assertSame(['Authorization: Bearer fake-delegated-1', 'Authorization: Bearer fake-delegated-2'], $headers);
        self::assertSame(1, $credential->rejected);
        self::assertSame([['account', 401, 1], ['account', 200, 2]], $credential->calls);

        try {
            $api->call($credential, 'GET', '/me', [], [200], 'account');
            self::fail('Expected a transport failure.');
        } catch (GraphException $failure) {
            self::assertSame(GraphException::REASON_TRANSPORT, $failure->reason);
        }
        self::assertContains(['account', 0, 1], $credential->calls);
        self::assertSame('mbx-1', $log->records[0]['context']['mailbox'] ?? null);

        $this->expectException(GraphException::class);
        $api->call(GraphApiMock::mailboxA(), 'GET', '/users/x', [], [200], 'read message');
    }

    public function testRequestOptionsPinTheHost(): void
    {
        $seen   = [];
        $client = new MockHttpClient(static function (string $method, string $url, array $options) use (&$seen): MockResponse {
            $seen[] = $options['resolve'] ?? [];

            return new MockResponse('{}', ['http_code' => 200]);
        });
        $options = new class implements GraphRequestOptionsInterface {
            public function options(string $url): array
            {
                return ['resolve' => ['graph.microsoft.com' => '203.0.113.10']];
            }
        };
        $credential = $this->createStub(GraphCredentialInterface::class);
        $credential->method('token')->willReturn('fake');
        $credential->method('logContext')->willReturn([]);
        (new GraphApiClient($client, null, requestOptions: $options))->call($credential, 'GET', '/me', [], [200], 'account');
        self::assertSame([['graph.microsoft.com' => '203.0.113.10']], $seen);
    }

    public function testTheGuardBridgeRefusesAPrivateAnswer(): void
    {
        $dns = new class implements HostnameDnsLookupInterface {
            public string $ip = '93.184.216.34';

            public function dnsGetRecord(string $hostname, int $type): array
            {
                return $type === DNS_A ? [['ip' => $this->ip]] : [];
            }

            public function hostByNameL(string $hostname): array|false
            {
                return false;
            }
        };
        $bridge = new GuardedGraphRequestOptions(new OutboundUrlGuard(false, true, $dns));
        self::assertSame('93.184.216.34', $bridge->options('https://graph.microsoft.com/v1.0/me')['resolve']['graph.microsoft.com'] ?? null);

        $dns->ip = '10.0.0.5';
        try {
            $bridge->options('https://graph.microsoft.com/v1.0/me');
            self::fail('A private address must be refused.');
        } catch (GraphException $failure) {
            self::assertSame(GraphException::REASON_UNTRUSTED_URL, $failure->reason);
        }
    }
}
