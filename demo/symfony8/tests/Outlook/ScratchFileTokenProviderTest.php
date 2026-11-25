<?php

declare(strict_types=1);

namespace App\Tests\Outlook;

use App\Outlook\MicrosoftIdentityClient;
use App\Outlook\ScratchFileTokenProvider;
use App\Outlook\TokenFile;
use LogicException;
use Nowo\MailKitBundle\Mailbox\Exception\MailboxGrantFailure;
use Nowo\MailKitBundle\Mailbox\Exception\MailboxTokenUnavailable;
use Nowo\MailKitBundle\Mailbox\Value\GrantFailureReason;
use Nowo\MailKitBundle\Mailbox\Value\MailboxId;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

final class ScratchFileTokenProviderTest extends TestCase
{
    private const CLIENT_ID = '00000000-0000-0000-0000-000000000001';

    private string $dir;

    private string $path;

    protected function setUp(): void
    {
        $this->dir  = sys_get_temp_dir() . '/outlook-provider-' . bin2hex(random_bytes(4));
        $this->path = $this->dir . '/token.json';
        mkdir($this->dir, 0o700);
    }

    protected function tearDown(): void
    {
        @unlink($this->path);
        @rmdir($this->dir);
    }

    public function testAValidTokenIsReturnedWithoutCallingMicrosoft(): void
    {
        $this->token('valid.token', time() + 3600);
        $http = new MockHttpClient(static fn () => throw new LogicException('No call expected'));

        $token = $this->provider($http)->accessToken(new MailboxId('outlook-test'));

        self::assertSame('valid.token', $token->reveal());
        self::assertSame(0, $http->getRequestsCount());
    }

    public function testAnExpiredTokenIsRefreshedAndWrittenBackToTheSameFile(): void
    {
        $this->token('old.token', time() - 10);
        $body = null;
        $http = new MockHttpClient(static function (string $method, string $url, array $options) use (&$body): MockResponse {
            self::assertSame('https://login.microsoftonline.com/consumers/oauth2/v2.0/token', $url);
            $body = $options['body'];

            return new MockResponse('{"access_token":"new.token","refresh_token":"refresh-2","expires_in":3600,"scope":"Mail.Send Mail.ReadWrite"}');
        });

        $token = $this->provider($http)->accessToken(new MailboxId('outlook-test'));

        self::assertSame('new.token', $token->reveal());
        self::assertIsString($body);
        self::assertStringContainsString('grant_type=refresh_token', $body);
        self::assertStringContainsString('refresh_token=refresh-1', $body);
        $stored = (new TokenFile($this->path, []))->read();
        self::assertSame('new.token', $stored['access_token']);
        self::assertSame('refresh-2', $stored['refresh_token']);
        self::assertGreaterThan(time(), $stored['expires_at']);
    }

    public function testARejectedFingerprintForcesARefresh(): void
    {
        $this->token('valid.token', time() + 3600);
        $http     = new MockHttpClient([new MockResponse('{"access_token":"fresh.token","expires_in":3600}')]);
        $provider = $this->provider($http);
        $mailbox  = new MailboxId('outlook-test');

        $provider->rejected($mailbox, $provider->accessToken($mailbox)->fingerprint());

        self::assertSame('fresh.token', $provider->accessToken($mailbox)->reveal());
        self::assertSame('refresh-1', (new TokenFile($this->path, []))->read()['refresh_token'], 'A refresh without a new refresh token keeps the old one.');
    }

    public function testAnInvalidGrantIsAGrantFailureWithoutTheTokenInTheMessage(): void
    {
        $this->token('old.token', time() - 10);
        $http = new MockHttpClient([new MockResponse('{"error":"invalid_grant","error_codes":[70000]}', ['http_code' => 400])]);

        try {
            $this->provider($http)->accessToken(new MailboxId('outlook-test'));
            self::fail('No grant failure');
        } catch (MailboxGrantFailure $failure) {
            self::assertSame(GrantFailureReason::Revoked, $failure->reason);
            self::assertStringNotContainsString('refresh-1', $failure->getMessage());
        }
    }

    public function testAServerErrorIsTransient(): void
    {
        $this->token('old.token', time() - 10);
        $http = new MockHttpClient([new MockResponse('oops', ['http_code' => 503])]);

        $this->expectException(MailboxTokenUnavailable::class);

        $this->provider($http)->accessToken(new MailboxId('outlook-test'));
    }

    public function testAPathInsideTheRepositoryIsRefusedAsUnavailable(): void
    {
        $this->token('valid.token', time() + 3600);
        $provider = new ScratchFileTokenProvider(new MicrosoftIdentityClient(new MockHttpClient(), self::CLIENT_ID), $this->path, [$this->dir]);

        $this->expectException(MailboxTokenUnavailable::class);
        $this->expectExceptionMessage('inside the repository tree');

        $provider->accessToken(new MailboxId('outlook-test'));
    }

    private function provider(MockHttpClient $http): ScratchFileTokenProvider
    {
        return new ScratchFileTokenProvider(new MicrosoftIdentityClient($http, self::CLIENT_ID), $this->path, ['/nonexistent-root']);
    }

    private function token(string $access, int $expiresAt): void
    {
        (new TokenFile($this->path, []))->write(['access_token' => $access, 'refresh_token' => 'refresh-1', 'expires_at' => $expiresAt, 'scope' => null]);
    }
}
