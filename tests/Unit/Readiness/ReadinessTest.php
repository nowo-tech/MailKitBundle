<?php

declare(strict_types=1);

namespace Nowo\MailKitBundle\Tests\Unit\Readiness;

use Nowo\MailKitBundle\Dsn\DsnHostGuard;
use Nowo\MailKitBundle\Dsn\DsnSource;
use Nowo\MailKitBundle\Dsn\MailerDsnValidator;
use Nowo\MailKitBundle\Integration\AuthKit\MailKitOutboundMailReadyChecker;
use Nowo\MailKitBundle\Readiness\OutboundMailReadiness;
use Nowo\MailKitBundle\Tests\Support\StaticDsnProvider;
use Nowo\MailKitBundle\Twig\MailKitTwigExtension;
use Nowo\OutboundUrlGuardBundle\Dns\HostnameDnsLookup;
use PHPUnit\Framework\TestCase;

final class ReadinessTest extends TestCase
{
    public function testReadinessFollowsTheDsn(): void
    {
        $provider  = new StaticDsnProvider();
        $readiness = $this->readiness($provider);
        self::assertFalse($readiness->isOutboundMailReady());
        self::assertSame(DsnSource::Fallback, $readiness->status()->source);
        self::assertTrue($this->readiness($provider, true)->isOutboundMailReady());

        $provider->dsn = 'smtp://8.8.8.8';
        self::assertTrue($readiness->isOutboundMailReady());
        self::assertSame('smtp://8.8.8.8', $readiness->status()->label);

        $provider->dsn = 'smtp://127.0.0.1';
        $status        = $readiness->status();
        self::assertFalse($status->ready);
        self::assertSame(DsnHostGuard::HOST_NOT_ALLOWED, $status->error);

        $checker = new MailKitOutboundMailReadyChecker($readiness);
        self::assertFalse($checker->isReady());
        $twig = new MailKitTwigExtension($readiness);
        self::assertCount(2, $twig->getFunctions());
        self::assertFalse($twig->status()->ready);
    }

    /**
     * CA L1: readiness runs on anonymous pages (AuthKit login): it never resolves DNS, even with resolve_dns on.
     */
    public function testReadinessNeverResolvesDns(): void
    {
        $dns = $this->createMock(HostnameDnsLookup::class);
        $dns->expects(self::never())->method('dnsGetRecord');
        $dns->expects(self::never())->method('hostByNameL');
        $readiness = new OutboundMailReadiness(new StaticDsnProvider('smtp://smtp.example.com:587'), new MailerDsnValidator(['smtp'], new DsnHostGuard(dns: $dns)));

        self::assertTrue($readiness->isOutboundMailReady());
    }

    private function readiness(StaticDsnProvider $provider, bool $fallback = false): OutboundMailReadiness
    {
        return new OutboundMailReadiness($provider, new MailerDsnValidator(['smtp'], new DsnHostGuard(resolveDns: false)), $fallback);
    }
}
