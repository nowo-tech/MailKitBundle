<?php

declare(strict_types=1);

namespace Nowo\MailKitBundle\Tests\Unit\Readiness;

use Nowo\MailKitBundle\Dsn\DsnHostGuard;
use Nowo\MailKitBundle\Dsn\DsnSource;
use Nowo\MailKitBundle\Dsn\MailerDsnValidator;
use Nowo\MailKitBundle\Integration\AuthKit\MailKitOutboundMailReadyChecker;
use Nowo\MailKitBundle\Mailer\RuntimeTransport;
use Nowo\MailKitBundle\Readiness\OutboundMailReadiness;
use Nowo\MailKitBundle\Sender\SenderPolicy;
use Nowo\MailKitBundle\Settings\MailSettings;
use Nowo\MailKitBundle\Tests\Support\FakeSettings;
use Nowo\MailKitBundle\Tests\Support\MemoryMailStore;
use Nowo\MailKitBundle\Tests\Support\MemoryTransport;
use Nowo\MailKitBundle\Tests\Support\StaticDsnProvider;
use Nowo\MailKitBundle\Twig\MailKitTwigExtension;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Mailer\Transport;

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

    private function readiness(StaticDsnProvider $provider, bool $fallback = false): OutboundMailReadiness
    {
        $store     = new MemoryMailStore();
        $transport = new RuntimeTransport(
            new MemoryTransport('fallback', $store),
            $provider,
            new MailerDsnValidator(['smtp'], new DsnHostGuard(resolveDns: false)),
            new Transport([]),
            new SenderPolicy(new MailSettings(new FakeSettings())),
        );

        return new OutboundMailReadiness($provider, $transport, $fallback);
    }
}
