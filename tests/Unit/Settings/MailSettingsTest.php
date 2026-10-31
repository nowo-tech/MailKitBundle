<?php

declare(strict_types=1);

namespace Nowo\MailKitBundle\Tests\Unit\Settings;

use Nowo\MailKitBundle\Dsn\DsnSource;
use Nowo\MailKitBundle\Settings\MailSettings;
use Nowo\MailKitBundle\Tests\Support\FakeSettings;
use PHPUnit\Framework\TestCase;

final class MailSettingsTest extends TestCase
{
    public function testMailerOnly(): void
    {
        $settings = new MailSettings(new FakeSettings(
            ['mailer.from' => 'noreply@example.com', 'mailer.test_recipient' => 'qa@example.com', 'resend.enabled' => true],
            ['mailer.dsn' => 'smtp://mail.example.com', 'resend.api_key' => 're_REDACTED-TEST'],
        ));

        self::assertSame(DsnSource::Settings, $settings->dsn()?->source);
        self::assertFalse($settings->resendActive());
        self::assertNull($settings->resendApiKey());
        self::assertNull($settings->resendDsn());
        self::assertSame('noreply@example.com', $settings->from()?->getAddress());
        self::assertSame('noreply@example.com', $settings->resendFrom()?->getAddress());
        self::assertSame('qa@example.com', $settings->testRecipient());
        self::assertNull($settings->replyTo());
    }

    public function testResendActive(): void
    {
        $settings = new MailSettings(new FakeSettings(
            ['mailer.from' => 'noreply@example.com', 'mailer.from_name' => 'Acme', 'resend.enabled' => true, 'resend.from' => 'mail@example.com', 'mailer.reply_to' => 'not an address'],
            ['mailer.dsn' => 'smtp://mail.example.com', 'resend.api_key' => 're_REDACTED/TEST'],
        ), true);

        self::assertTrue($settings->resendActive());
        $dsn = $settings->dsn();
        self::assertNotNull($dsn);
        self::assertSame('resend+api://re_REDACTED%2FTEST@default', $dsn->dsn);
        self::assertSame(DsnSource::Resend, $dsn->source);
        self::assertSame('"Acme" <mail@example.com>', $settings->from()?->toString());
        self::assertNull($settings->replyTo());
    }

    public function testNothingStored(): void
    {
        $settings = new MailSettings(new FakeSettings(), true);

        self::assertNull($settings->dsn());
        self::assertFalse($settings->resendActive());
        self::assertNull($settings->from());
        self::assertNull($settings->resendFrom());
        self::assertNull($settings->testRecipient());
    }
}
