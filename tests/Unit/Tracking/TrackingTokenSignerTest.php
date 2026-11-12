<?php

declare(strict_types=1);

namespace Nowo\MailKitBundle\Tests\Unit\Tracking;

use InvalidArgumentException;
use Nowo\MailKitBundle\Tracking\TrackingTokenSigner;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Clock\MockClock;

use function base64_decode;
use function base64_encode;
use function chr;
use function ord;
use function print_r;
use function rtrim;
use function str_contains;
use function str_repeat;
use function strlen;
use function strtr;
use function substr;

final class TrackingTokenSignerTest extends TestCase
{
    private const SECRET = 'test-tracking-secret-REDACTED-0000000000';

    public function testRoundTripAndOpaqueness(): void
    {
        $signer = new TrackingTokenSigner(self::SECRET, clock: new MockClock('2026-11-12 10:00:00'));
        $id     = TrackingTokenSigner::newSendId();
        self::assertMatchesRegularExpression('/^[0-9a-f]{32}$/', $id);
        self::assertNotSame($id, TrackingTokenSigner::newSendId());

        $token = $signer->issue($id);
        self::assertSame(TrackingTokenSigner::TOKEN_LENGTH, strlen($token));
        self::assertMatchesRegularExpression('/^[A-Za-z0-9_-]{48}$/', $token);
        self::assertSame($id, $signer->verify($token));
        self::assertFalse(str_contains($token, $id), 'The token is not the send id in clear hex.');
    }

    public function testForgedTamperedAndMalformedTokensAreRefused(): void
    {
        $signer = new TrackingTokenSigner(self::SECRET);
        $token  = $signer->issue(TrackingTokenSigner::newSendId());
        $raw    = (string) base64_decode(strtr($token, '-_', '+/'), true);

        // Every single bit flip of the payload or the MAC is refused.
        for ($i = 0; $i < strlen($raw); ++$i) {
            $flipped = substr($raw, 0, $i) . chr(ord($raw[$i]) ^ 0x01) . substr($raw, $i + 1);
            self::assertNull($signer->verify($this->encode($flipped)), 'byte ' . $i);
        }
        // Another send id with the MAC of this one.
        self::assertNull($signer->verify($this->encode(str_repeat("\x11", 16) . substr($raw, 16))));
        // A token of another secret.
        self::assertNull($signer->verify((new TrackingTokenSigner(str_repeat('x', 32)))->issue(TrackingTokenSigner::newSendId())));
        foreach (['', 'abc', str_repeat('A', 47), str_repeat('A', 49), str_repeat('A', 47) . '=', str_repeat('A', 47) . '/', str_repeat('A', 48)] as $bad) {
            self::assertNull($signer->verify($bad), $bad);
        }
    }

    public function testExpiryClockSkewAndRotation(): void
    {
        $clock  = new MockClock('2026-11-12 10:00:00');
        $old    = new TrackingTokenSigner('old-secret-REDACTED-00000000000000000', ttl: 3600, clock: $clock);
        $id     = TrackingTokenSigner::newSendId();
        $token  = $old->issue($id);
        $signer = new TrackingTokenSigner(self::SECRET, ['old-secret-REDACTED-00000000000000000'], 3600, $clock);

        self::assertSame($id, $signer->verify($token), 'A previous secret still verifies.');
        $clock->modify('+3600 seconds');
        self::assertSame($id, $signer->verify($token));
        $clock->modify('+1 second');
        self::assertNull($signer->verify($token), 'Expired.');

        $future = new TrackingTokenSigner(self::SECRET, clock: new MockClock('2026-11-12 10:10:00'));
        $later  = $future->issue($id);
        self::assertNull((new TrackingTokenSigner(self::SECRET, clock: new MockClock('2026-11-12 10:00:00')))->verify($later), 'Issued 10 minutes in the future.');
        self::assertSame($id, (new TrackingTokenSigner(self::SECRET, clock: new MockClock('2026-11-12 10:05:00')))->verify($later), '5 minutes of skew are accepted.');
    }

    public function testSecretsAreValidatedAndHidden(): void
    {
        $signer = TrackingTokenSigner::fromKernelSecret('short');
        self::assertSame(['ttl' => 7_776_000, 'keys' => '***'], $signer->__debugInfo());
        self::assertFalse(str_contains(print_r($signer, true), 'short'));
        $id = TrackingTokenSigner::newSendId();
        self::assertSame($id, TrackingTokenSigner::fromKernelSecret('short')->verify($signer->issue($id)));
        self::assertNull((new TrackingTokenSigner('short-REDACTED-padded-to-32-bytes-0000'))->verify($signer->issue($id)), 'The derived key is not the raw kernel secret.');

        try {
            TrackingTokenSigner::fromKernelSecret('');
            self::fail('Empty kernel secret.');
        } catch (InvalidArgumentException $exception) {
            self::assertStringContainsString('kernel.secret is empty', $exception->getMessage());
        }
        try {
            new TrackingTokenSigner(self::SECRET, ['too-short']);
            self::fail('Short previous secret.');
        } catch (InvalidArgumentException $exception) {
            self::assertStringContainsString('at least 32 bytes', $exception->getMessage());
        }
        $this->expectException(InvalidArgumentException::class);
        $signer->issue('not-a-send-id');
    }

    private function encode(string $raw): string
    {
        return rtrim(strtr(base64_encode($raw), '+/', '-_'), '=');
    }
}
