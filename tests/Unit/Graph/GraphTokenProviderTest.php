<?php

declare(strict_types=1);

namespace Nowo\MailKitBundle\Tests\Unit\Graph;

use Nowo\MailKitBundle\Graph\GraphException;
use Nowo\MailKitBundle\Graph\GraphMailbox;
use Nowo\MailKitBundle\Graph\GraphSecret;
use Nowo\MailKitBundle\Graph\GraphTokenProvider;
use Nowo\MailKitBundle\Tests\Support\GraphApiMock;
use Nowo\MailKitBundle\Tests\Support\RecordingLogger;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Cache\Adapter\ArrayAdapter;
use Symfony\Component\Clock\MockClock;
use Symfony\Component\HttpClient\Exception\TransportException;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

use function json_encode;
use function parse_str;
use function print_r;
use function str_repeat;

final class GraphTokenProviderTest extends TestCase
{
    public function testClientCredentialsFlowAndCache(): void
    {
        $mock     = new GraphApiMock();
        $clock    = new MockClock('2026-11-12 10:00:00');
        $cache    = new ArrayAdapter();
        $provider = new GraphTokenProvider($mock->client(), $cache, 5.0, $clock);
        $mailbox  = GraphApiMock::mailboxA();

        self::assertSame('tok-' . GraphApiMock::TENANT_A . '-1', $provider->token($mailbox));
        self::assertSame('tok-' . GraphApiMock::TENANT_A . '-1', $provider->token($mailbox));
        self::assertSame(1, $mock->tokensIssued);

        $request = $mock->requests[0];
        self::assertSame('POST', $request['method']);
        self::assertSame('https://login.microsoftonline.com/' . GraphApiMock::TENANT_A . '/oauth2/v2.0/token', $request['url']);
        parse_str($request['body'], $form);
        self::assertSame([
            'client_id'     => GraphApiMock::CLIENT_A,
            'client_secret' => GraphApiMock::secret(),
            'scope'         => 'https://graph.microsoft.com/.default',
            'grant_type'    => 'client_credentials',
        ], $form);

        // The cache key never holds the tenant, client id or secret in clear.
        $key = $provider->key($mailbox);
        self::assertStringStartsWith('nowo_mail_kit.graph_token.', $key);
        self::assertStringNotContainsString(GraphApiMock::TENANT_A, $key);
        self::assertNotSame($key, $provider->key(GraphApiMock::mailboxA('rotated')));
        self::assertStringNotContainsString(GraphApiMock::secret(), print_r($cache->getValues(), true));

        // A second tenant gets its own token.
        self::assertSame('tok-' . GraphApiMock::TENANT_B . '-2', $provider->token(GraphApiMock::mailboxB()));

        // Expired (the stored expiry is checked even when the pool still returns the item).
        $clock->modify('+3301 seconds');
        self::assertSame('tok-' . GraphApiMock::TENANT_A . '-3', $provider->token($mailbox));

        $provider->forget($mailbox);
        self::assertSame('tok-' . GraphApiMock::TENANT_A . '-4', $provider->token($mailbox));
    }

    public function testCorruptCacheEntryIsReplaced(): void
    {
        $mock     = new GraphApiMock();
        $cache    = new ArrayAdapter();
        $provider = new GraphTokenProvider($mock->client(), $cache);
        $mailbox  = GraphApiMock::mailboxA();
        $item     = $cache->getItem($provider->key($mailbox));
        $cache->save($item->set('garbage'));

        self::assertSame('tok-' . GraphApiMock::TENANT_A . '-1', $provider->token($mailbox));
    }

    public function testRefusedCredentialsNeverLeakTheSecret(): void
    {
        $logger   = new RecordingLogger();
        $provider = new GraphTokenProvider((new GraphApiMock())->client(), new ArrayAdapter(), 5.0, null, $logger);
        $wrong    = 'wrong-value-REDACTED';
        $mailbox  = new GraphMailbox('acme', GraphApiMock::TENANT_A, GraphApiMock::CLIENT_A, new GraphSecret($wrong), 'projects@example.com');

        try {
            $provider->token($mailbox);
            self::fail('Refused credentials must throw.');
        } catch (GraphException $e) {
            self::assertSame(GraphException::REASON_AUTHENTICATION, $e->reason);
            self::assertSame(401, $e->status);
            self::assertSame('invalid_client', $e->graphCode);
            self::assertStringNotContainsString($wrong, $e->getMessage());
            self::assertStringNotContainsString('AADSTS', $e->getMessage());
        }
        self::assertStringNotContainsString($wrong, print_r($logger->records, true));
        self::assertTrue($logger->has('Microsoft Graph token request refused.'));
    }

    public function testTransportFailureAndUnusableAnswers(): void
    {
        $logger   = new RecordingLogger();
        $provider = new GraphTokenProvider(new MockHttpClient(static fn (): never => throw new TransportException('Could not resolve host login.microsoftonline.com')), new ArrayAdapter(), 5.0, null, $logger);

        try {
            $provider->token(GraphApiMock::mailboxA());
            self::fail('Network failure must throw.');
        } catch (GraphException $e) {
            self::assertSame(GraphException::REASON_TRANSPORT, $e->reason);
            self::assertSame('Microsoft Graph token request failed: network error or timeout.', $e->getMessage());
        }
        self::assertTrue($logger->has('Microsoft Graph token request failed.'));

        $answers = [
            ['token_type' => 'Bearer', 'expires_in' => 3599],
            ['token_type' => 'Bearer', 'expires_in' => 3599, 'access_token' => ''],
            ['token_type' => 'Bearer', 'expires_in' => 3599, 'access_token' => str_repeat('a', 16_385)],
            ['token_type' => 'MAC', 'expires_in' => 3599, 'access_token' => 'x'],
            ['token_type' => 'Bearer', 'access_token' => 'x'],
        ];
        foreach ($answers as $answer) {
            $provider = new GraphTokenProvider(new MockHttpClient(new MockResponse((string) json_encode($answer))), new ArrayAdapter());

            try {
                $provider->token(GraphApiMock::mailboxA());
                self::fail('Unusable token answer accepted.');
            } catch (GraphException $e) {
                self::assertSame(GraphException::REASON_AUTHENTICATION, $e->reason);
            }
        }

        $provider = new GraphTokenProvider(new MockHttpClient(new MockResponse('not json', ['http_code' => 500])), new ArrayAdapter());
        try {
            $provider->token(GraphApiMock::mailboxA());
            self::fail('A 500 must throw.');
        } catch (GraphException $e) {
            self::assertSame(GraphException::REASON_TRANSPORT, $e->reason);
        }

        // Lifetimes are clamped: a token without token_type is a bearer token; a short lifetime is cached 30 s.
        $cache    = new ArrayAdapter();
        $provider = new GraphTokenProvider(new MockHttpClient(new MockResponse((string) json_encode(['expires_in' => '10', 'access_token' => 'short']))), $cache);
        self::assertSame('short', $provider->token(GraphApiMock::mailboxA()));
    }
}
