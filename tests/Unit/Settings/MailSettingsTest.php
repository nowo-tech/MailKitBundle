<?php

declare(strict_types=1);

namespace Nowo\MailKitBundle\Tests\Unit\Settings;

use DateTimeImmutable;
use Nowo\InstanceSettingsBundle\Entity\SettingValue;
use Nowo\MailKitBundle\Dsn\DsnSource;
use Nowo\MailKitBundle\Settings\LegacyResendSettings;
use Nowo\MailKitBundle\Settings\MailSettings;
use Nowo\MailKitBundle\Tests\Support\FakeSettings;
use Nowo\MailKitBundle\Tests\Support\MemorySettingValueRepository;
use PHPUnit\Framework\TestCase;

final class MailSettingsTest extends TestCase
{
    public function testMailerOnly(): void
    {
        $settings = new MailSettings(new FakeSettings(
            ['mailer.from' => 'noreply@example.com', 'mailer.test_recipient' => 'qa@example.com', 'mailer.provider' => 'resend'],
            ['mailer.dsn' => 'smtp://mail.example.com', 'mailer.resend_api_key' => 're_REDACTED-TEST'],
        ));

        self::assertSame('dsn', $settings->provider(), 'no Resend provider without resend.enabled');
        self::assertSame(DsnSource::Settings, $settings->dsn()?->source);
        self::assertFalse($settings->resendActive());
        self::assertFalse($settings->legacyResendPending());
        self::assertNull($settings->resendApiKey());
        self::assertNull($settings->resendDsn());
        self::assertSame('noreply@example.com', $settings->from()?->getAddress());
        self::assertSame('noreply@example.com', $settings->resendFrom()?->getAddress());
        self::assertSame('qa@example.com', $settings->testRecipient());
        self::assertNull($settings->replyTo());
        self::assertSame([], $settings->missingFields());
    }

    public function testResendSelected(): void
    {
        $settings = new MailSettings(new FakeSettings(
            ['mailer.provider' => 'resend', 'mailer.from' => 'noreply@example.com', 'mailer.from_name' => 'Acme', 'mailer.resend_from' => 'mail@example.com', 'mailer.reply_to' => 'not an address'],
            ['mailer.dsn' => 'smtp://mail.example.com', 'mailer.resend_api_key' => 're_REDACTED/TEST'],
        ), true);

        self::assertSame('resend', $settings->provider());
        self::assertTrue($settings->resendActive());
        $dsn = $settings->dsn();
        self::assertNotNull($dsn);
        self::assertSame('resend+api://re_REDACTED%2FTEST@default', $dsn->dsn);
        self::assertSame(DsnSource::Resend, $dsn->source);
        self::assertSame('"Acme" <mail@example.com>', $settings->from()?->toString());
        self::assertNull($settings->replyTo());
        self::assertSame([], $settings->missingFields());
    }

    public function testDsnSelectedKeepsTheResendValues(): void
    {
        $settings = new MailSettings(new FakeSettings(
            ['mailer.provider' => 'dsn', 'mailer.from' => 'noreply@example.com'],
            ['mailer.resend_api_key' => 're_REDACTED-TEST'],
        ), true, new LegacyResendSettings(new MemorySettingValueRepository([$this->row('enabled', true)])));

        self::assertSame('dsn', $settings->provider());
        self::assertFalse($settings->resendActive());
        self::assertFalse($settings->legacyResendPending(), 'mailer.provider is stored: the 1.2 rows are ignored');
        self::assertSame('re_REDACTED-TEST', $settings->resendApiKey());
        self::assertNull($settings->dsn());
        self::assertSame(['mailer.dsn'], $settings->missingFields());
        self::assertSame('noreply@example.com', $settings->from()?->getAddress());
    }

    public function testResendSelectedWithoutAKey(): void
    {
        $settings = new MailSettings(new FakeSettings(['mailer.provider' => 'resend'], ['mailer.dsn' => 'smtp://mail.example.com']), true);

        self::assertFalse($settings->resendActive());
        self::assertSame(DsnSource::Settings, $settings->dsn()?->source, 'no key: the stored DSN keeps mail flowing');
        self::assertSame(['mailer.resend_api_key'], $settings->missingFields());
    }

    public function testThe12RowsApplyUntilTheProviderIsStored(): void
    {
        $now        = new DateTimeImmutable();
        $repository = new MemorySettingValueRepository([
            $this->row('enabled', true),
            (new SettingValue('resend', 'api_key', $now))->storeSecret('re_REDACTED-LEGACY', $now),
            $this->row('from', 'legacy@example.com'),
        ]);
        $fake     = new FakeSettings(['mailer.from' => 'noreply@example.com'], ['mailer.dsn' => 'smtp://mail.example.com']);
        $settings = new MailSettings($fake, true, new LegacyResendSettings($repository));

        self::assertTrue($settings->legacyResendPending());
        self::assertSame('resend', $settings->provider());
        self::assertTrue($settings->resendActive());
        self::assertSame('resend+api://re_REDACTED-LEGACY@default', $settings->dsn()?->dsn);
        self::assertSame('legacy@example.com', $settings->from()?->getAddress());

        $fake->values['mailer.provider'] = 'resend';
        self::assertFalse($settings->legacyResendPending());
        self::assertNull($settings->resendApiKey(), 'migrated: only the mailer rows count');

        $off = new MailSettings(new FakeSettings(), true, new LegacyResendSettings(new MemorySettingValueRepository([$this->row('enabled', false)])));
        self::assertTrue($off->legacyResendPending());
        self::assertSame('dsn', $off->provider());
    }

    public function testNothingStored(): void
    {
        $settings = new MailSettings(new FakeSettings(), true, new LegacyResendSettings(new MemorySettingValueRepository()));

        self::assertSame('dsn', $settings->provider());
        self::assertFalse($settings->legacyResendPending());
        self::assertNull($settings->dsn());
        self::assertFalse($settings->resendActive());
        self::assertNull($settings->from());
        self::assertNull($settings->resendFrom());
        self::assertNull($settings->testRecipient());
        self::assertSame(['mailer.dsn'], $settings->missingFields());
    }

    private function row(string $name, bool|string $value): SettingValue
    {
        $now = new DateTimeImmutable();

        return (new SettingValue('resend', $name, $now))->storeValue($value, $now);
    }
}
