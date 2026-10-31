<?php

declare(strict_types=1);

namespace Nowo\MailKitBundle\Tests\Unit\EventListener;

use Nowo\InstanceSettingsBundle\Event\SettingsChangedEvent;
use Nowo\MailKitBundle\Dsn\DsnHostGuard;
use Nowo\MailKitBundle\Dsn\MailerDsnValidator;
use Nowo\MailKitBundle\EventListener\SettingsChangedListener;
use Nowo\MailKitBundle\Mailer\RuntimeTransport;
use Nowo\MailKitBundle\Readiness\OutboundMailReadiness;
use Nowo\MailKitBundle\Sender\SenderPolicy;
use Nowo\MailKitBundle\Settings\MailSettings;
use Nowo\MailKitBundle\Tests\Support\FakeSettings;
use Nowo\MailKitBundle\Tests\Support\MemoryMailStore;
use Nowo\MailKitBundle\Tests\Support\MemoryTransport;
use Nowo\MailKitBundle\Tests\Support\StaticDsnProvider;
use PHPUnit\Framework\TestCase;
use Psr\Log\AbstractLogger;
use Stringable;
use Symfony\Component\Mailer\Transport;

use function json_encode;

final class SettingsChangedListenerTest extends TestCase
{
    public function testAuditsOnlyTheMailSectionsWithoutValues(): void
    {
        $logger = new class extends AbstractLogger {
            /** @var list<array{string, array<mixed>}> */
            public array $records = [];

            public function log($level, string|Stringable $message, array $context = []): void
            {
                $this->records[] = [(string) $message, $context];
            }
        };
        $provider  = new StaticDsnProvider('smtp://user:REDACTED-TEST@smtp.example.com:587');
        $transport = new RuntimeTransport(new MemoryTransport('f', new MemoryMailStore()), $provider, new MailerDsnValidator(hostGuard: new DsnHostGuard(resolveDns: false)), new Transport([]), new SenderPolicy(new MailSettings(new FakeSettings())));
        $listener  = new SettingsChangedListener($transport, new OutboundMailReadiness($provider, $transport), $logger);

        $listener(new SettingsChangedEvent('site', ['name']));
        self::assertSame([], $logger->records);

        $listener(new SettingsChangedEvent('mailer', ['dsn', 'from'], ['dsn'], SettingsChangedEvent::ORIGIN_FORM));
        self::assertCount(1, $logger->records);
        [$message, $context] = $logger->records[0];
        self::assertSame('Mail settings changed.', $message);
        self::assertSame('dsn,from', $context['fields']);
        self::assertSame('smtp://smtp.example.com:587', $context['transport']);
        self::assertStringNotContainsString('REDACTED-TEST', (string) json_encode($context));
    }
}
