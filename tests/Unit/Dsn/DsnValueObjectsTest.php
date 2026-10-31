<?php

declare(strict_types=1);

namespace Nowo\MailKitBundle\Tests\Unit\Dsn;

use Nowo\MailKitBundle\Dsn\DsnSource;
use Nowo\MailKitBundle\Dsn\MailerDsnAudit;
use Nowo\MailKitBundle\Dsn\RuntimeDsn;
use Nowo\MailKitBundle\Dsn\SettingsMailerDsnProvider;
use Nowo\MailKitBundle\Settings\MailSettings;
use Nowo\MailKitBundle\Tests\Support\FakeSettings;
use PHPUnit\Framework\TestCase;

final class DsnValueObjectsTest extends TestCase
{
    public function testAuditNeverReturnsCredentials(): void
    {
        self::assertSame(['scheme' => 'smtp', 'host' => 'mail.example.com', 'port' => 587], MailerDsnAudit::redact('SMTP://user:REDACTED-TEST@Mail.Example.com:587?verify_peer=0'));
        self::assertSame(['scheme' => null, 'host' => null, 'port' => null], MailerDsnAudit::redact('http://:80'));
        self::assertSame(['scheme' => null, 'host' => null, 'port' => null], MailerDsnAudit::redact(null));
        self::assertSame('smtp://mail.example.com:587', MailerDsnAudit::label('smtp://u:p@mail.example.com:587'));
        self::assertSame('resend+api://default', MailerDsnAudit::label('resend+api://key@default'));
        self::assertSame('null', MailerDsnAudit::label('null:'));
        self::assertSame('unknown', MailerDsnAudit::label('mail.example.com'));
        self::assertSame('unknown', MailerDsnAudit::scheme(''));
        self::assertSame('smtps', MailerDsnAudit::scheme('smtps://h'));
    }

    public function testRuntimeDsnAndProvider(): void
    {
        $dsn = new RuntimeDsn('smtp://u:REDACTED-TEST@mail.example.com');
        self::assertSame(DsnSource::Custom, $dsn->source);
        self::assertSame('smtp://mail.example.com', $dsn->label());

        $provider = new SettingsMailerDsnProvider(new MailSettings(new FakeSettings(secrets: ['mailer.dsn' => 'smtp://mail.example.com'])));
        self::assertSame(DsnSource::Settings, $provider->getDsn()?->source);
    }
}
