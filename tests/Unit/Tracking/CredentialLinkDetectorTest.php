<?php

declare(strict_types=1);

namespace Nowo\MailKitBundle\Tests\Unit\Tracking;

use Nowo\MailKitBundle\Tracking\CredentialLinkDetector;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class CredentialLinkDetectorTest extends TestCase
{
    /**
     * @return iterable<string, array{0: string}>
     */
    public static function credentialLinks(): iterable
    {
        yield 'magic link token' => ['https://app.example.com/magic?token=abc'];
        yield 'reset path' => ['https://app.example.com/reset-password/abc123'];
        yield 'reset path plural' => ['https://app.example.com/resets/abc123'];
        yield 'login link' => ['https://app.example.com/login/check?user=a'];
        yield 'login link with dots' => ['https://app.example.com/login.php'];
        yield 'verify email' => ['https://app.example.com/verify-email/42'];
        yield 'invitation accept' => ['https://app.example.com/invitations/42/accept'];
        yield 'one-time code' => ['https://app.example.com/x?otp=123456'];
        yield 'code param' => ['https://app.example.com/x?Code=1'];
        yield 'short t param' => ['https://app.example.com/x?t=1'];
        yield 'array param' => ['https://app.example.com/x?token[]=1'];
        yield 'semicolon separator' => ['https://app.example.com/x?a=1;sig=2'];
        yield 'uri signer' => ['https://app.example.com/x?_expiration=1&_hash=abc'];
        yield 'reset_token' => ['https://app.example.com/x?reset_token=abc'];
        yield 'contains secret' => ['https://app.example.com/x?client_secret=abc'];
        yield 's3 presigned' => ['https://bucket.s3.amazonaws.com/f?X-Amz-Signature=abc&X-Amz-Credential=x'];
        yield 'gcs presigned' => ['https://storage.googleapis.com/f?x-goog-date=1'];
        yield 'fragment token' => ['https://app.example.com/cb#access_token=abc'];
        yield 'user info' => ['https://user:pass@app.example.com/'];
        yield 'user only' => ['https://user@app.example.com/'];
        yield 'oauth path' => ['https://app.example.com/oauth/authorize'];
        yield 'api token path' => ['https://app.example.com/settings/api-tokens'];
        yield '2fa path' => ['https://app.example.com/2fa'];
        yield 'encoded segment' => ['https://app.example.com/%6Cogin'];
        yield 'unparsable' => ['https:///nohost'];
        yield 'jsessionid path parameter' => ['https://app.example.com/home;jsessionid=ABC123'];
        yield 'token path parameter' => ['https://app.example.com/a;x=1;token=abc/b'];
    }

    /**
     * @return iterable<string, array{0: string}>
     */
    public static function trackableLinks(): iterable
    {
        yield 'issue' => ['https://app.example.com/issues/550e8400-e29b-41d4-a716-446655440000'];
        yield 'author is not auth' => ['https://app.example.com/authors/12'];
        yield 'codes-of-conduct page' => ['https://app.example.com/docs/codex'];
        yield 'utm' => ['https://app.example.com/p?utm_source=mail&utm_campaign=x&ref=1&=x&&'];
        yield 'unsubscribe' => ['https://app.example.com/account/notifications'];
        yield 'no path' => ['https://app.example.com'];
        yield 'empty segments' => ['https://app.example.com//a//'];
        yield 'harmless path parameter' => ['https://app.example.com/list;page=2'];
    }

    #[DataProvider('credentialLinks')]
    public function testCredentialBearingLinksAreNeverRewritten(string $url): void
    {
        self::assertTrue((new CredentialLinkDetector())->isCredential($url));
    }

    #[DataProvider('trackableLinks')]
    public function testOtherLinksMayBeRewritten(string $url): void
    {
        self::assertFalse((new CredentialLinkDetector())->isCredential($url));
    }

    public function testHostExtrasAndRedactedValues(): void
    {
        $detector = new CredentialLinkDetector(['Ticket_Ref'], ['Approve']);
        self::assertTrue($detector->isCredential('https://app.example.com/x?ticket_ref=1'));
        self::assertTrue($detector->isCredential('https://app.example.com/approve-expense/1'));
        self::assertFalse($detector->isCredential('https://app.example.com/approved'));

        $secret = 'abc/def+ghi';
        self::assertTrue($detector->isCredential('https://app.example.com/i/abc/def+ghi', [$secret]));
        self::assertTrue($detector->isCredential('https://app.example.com/i/abc%2Fdef%2Bghi', [$secret]));
        self::assertTrue($detector->isCredential('https://app.example.com/i?x=abc%2Fdef%2Bghi', ['xyz', $secret]));
        self::assertFalse($detector->isCredential('https://app.example.com/i/abcd', ['abc']), 'Values shorter than 4 characters are ignored.');
    }
}
