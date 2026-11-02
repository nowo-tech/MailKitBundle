<?php

declare(strict_types=1);

namespace Nowo\MailKitBundle\Tests\Unit\Settings;

use DateTimeImmutable;
use Nowo\InstanceSettingsBundle\Entity\SettingValue;
use Nowo\InstanceSettingsBundle\Section\SectionActionContext;
use Nowo\InstanceSettingsBundle\Section\SettingFallback;
use Nowo\MailKitBundle\Dsn\DsnHostGuard;
use Nowo\MailKitBundle\Dsn\MailerDsnValidator;
use Nowo\MailKitBundle\Dsn\SettingsMailerDsnProvider;
use Nowo\MailKitBundle\Readiness\OutboundMailReadiness;
use Nowo\MailKitBundle\Settings\LegacyResendSettings;
use Nowo\MailKitBundle\Settings\MailerSettingsSection;
use Nowo\MailKitBundle\Settings\MailSettings;
use Nowo\MailKitBundle\Tests\Support\FakeSettings;
use Nowo\MailKitBundle\Tests\Support\MemorySettingValueRepository;
use Nowo\MailKitBundle\Tests\Support\StaticDsnProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Translation\TranslatableMessage;

use function array_map;
use function bin2hex;
use function iterator_to_array;
use function random_bytes;

/**
 * DB-only mode (1.2) and the 1.3 migration of the `resend` rows: fallbacks of the mailer section, missing-field
 * report of the selected provider, section notices.
 */
final class DbOnlySeedTest extends TestCase
{
    public function testBootstrapFallbacks(): void
    {
        $dsn = 'smtp://user:' . bin2hex(random_bytes(8)) . '@smtp.example.com:587';
        $key = 're_' . bin2hex(random_bytes(12));

        $mailer = iterator_to_array((new MailerSettingsSection(bootstrapDsn: $dsn))->getFallbacks(), false);
        self::assertSame([['dsn', $dsn, true]], $this->plain($mailer));
        $resend = iterator_to_array((new MailerSettingsSection(resendEnabled: true, bootstrapResendApiKey: $key, legacy: new LegacyResendSettings(new MemorySettingValueRepository())))->getFallbacks(), false);
        self::assertSame([['dsn', null, true], ['resend_api_key', $key, true], ['resend_from', null, false]], $this->plain($resend), 'no 1.2 rows: the provider is not seeded');

        $empty = iterator_to_array((new MailerSettingsSection())->getFallbacks(), false);
        self::assertNull($empty[0]->value(), 'no bootstrap value: nothing to seed');
    }

    public function testThe12RowsAreSeededIntoTheMailerSection(): void
    {
        $now    = new DateTimeImmutable();
        $legacy = new LegacyResendSettings(new MemorySettingValueRepository([
            (new SettingValue('resend', 'enabled', $now))->storeValue(true, $now),
            (new SettingValue('resend', 'api_key', $now))->storeSecret('re_REDACTED-LEGACY', $now),
            (new SettingValue('resend', 'from', $now))->storeValue('mail@example.com', $now),
        ]));
        $fallbacks = iterator_to_array((new MailerSettingsSection(resendEnabled: true, bootstrapResendApiKey: 're_REDACTED-BOOTSTRAP', legacy: $legacy))->getFallbacks(), false);
        self::assertSame([['dsn', null, true], ['provider', 'resend', false], ['resend_api_key', 're_REDACTED-LEGACY', true], ['resend_from', 'mail@example.com', false]], $this->plain($fallbacks));

        $off       = new LegacyResendSettings(new MemorySettingValueRepository([(new SettingValue('resend', 'enabled', $now))->storeValue(false, $now)]));
        $fallbacks = iterator_to_array((new MailerSettingsSection(resendEnabled: true, bootstrapResendApiKey: 're_REDACTED-BOOTSTRAP', legacy: $off))->getFallbacks(), false);
        self::assertSame([['dsn', null, true], ['provider', 'dsn', false], ['resend_api_key', 're_REDACTED-BOOTSTRAP', true], ['resend_from', null, false]], $this->plain($fallbacks));
    }

    public function testMissingFields(): void
    {
        self::assertSame(['mailer.dsn'], $this->settings([], [])->missingFields());
        self::assertSame([], $this->settings([], ['mailer.dsn' => 'smtp://smtp.example.com'])->missingFields());
        self::assertSame(['mailer.resend_api_key'], $this->settings(['mailer.provider' => 'resend'], ['mailer.dsn' => 'smtp://smtp.example.com'])->missingFields());
        self::assertSame([], $this->settings(['mailer.provider' => 'resend'], ['mailer.resend_api_key' => 're_' . bin2hex(random_bytes(6))])->missingFields());

        $none = $this->readiness($this->settings(['mailer.provider' => 'resend'], []));
        self::assertSame(['mailer.resend_api_key'], $none->status()->missingFields);
        self::assertSame(['resend_api_key'], $none->missingFieldsOf('mailer'));
        self::assertSame([], $none->missingFieldsOf('resend'));
        self::assertSame(['mailer.resend_api_key'], $this->readiness($this->settings(['mailer.provider' => 'resend'], []), true)->missingFields(), 'MAILER_DSN counts as ready');
        self::assertSame([], $this->readiness($this->settings([], []), true)->missingFields(), 'MAILER_DSN counts as ready');

        $stored = $this->readiness($this->settings(['mailer.provider' => 'resend'], ['mailer.dsn' => 'smtp://8.8.8.8']));
        self::assertTrue($stored->status()->ready);
        self::assertSame(['mailer.resend_api_key'], $stored->status()->missingFields);

        $custom = new OutboundMailReadiness(new StaticDsnProvider(), new MailerDsnValidator(['smtp'], new DsnHostGuard(resolveDns: false)));
        self::assertSame([], $custom->missingFields(), 'custom DSN provider: no settings report');
    }

    public function testNotices(): void
    {
        $context   = new SectionActionContext('mailer', new Request());
        $readiness = $this->readiness($this->settings(['mailer.provider' => 'resend'], []));
        $lazy      = static fn (): OutboundMailReadiness => $readiness;

        self::assertSame([], iterator_to_array((new MailerSettingsSection())->getNotices($context), false));
        $notices = iterator_to_array((new MailerSettingsSection(readiness: $lazy))->getNotices($context), false);
        self::assertCount(1, $notices);
        self::assertSame('settings.notice_missing', $notices[0]->getMessage());
        self::assertSame(['%fields%' => 'resend_api_key'], $notices[0]->getParameters());
        self::assertSame('NowoMailKitBundle', $notices[0]->getDomain());

        $ready = $this->readiness($this->settings([], ['mailer.dsn' => 'smtp://8.8.8.8']));
        self::assertSame([], iterator_to_array((new MailerSettingsSection(readiness: static fn (): OutboundMailReadiness => $ready))->getNotices($context), false));

        $now     = new DateTimeImmutable();
        $pending = new MailSettings(new FakeSettings(), true, new LegacyResendSettings(new MemorySettingValueRepository([(new SettingValue('resend', 'enabled', $now))->storeValue(false, $now)])));
        $notices = iterator_to_array((new MailerSettingsSection(resendEnabled: true, settings: static fn (): MailSettings => $pending))->getNotices($context), false);
        self::assertSame(['settings.mailer.notice_legacy_resend'], array_map(static fn (TranslatableMessage $notice): string => $notice->getMessage(), $notices));
        $migrated = $this->settings(['mailer.provider' => 'dsn'], []);
        self::assertSame([], iterator_to_array((new MailerSettingsSection(resendEnabled: true, settings: static fn (): MailSettings => $migrated))->getNotices($context), false));
    }

    /**
     * @param list<SettingFallback> $fallbacks
     *
     * @return list<array{string, bool|float|int|string|null, bool}>
     */
    private function plain(array $fallbacks): array
    {
        return array_map(static fn (SettingFallback $f): array => [$f->field, $f->value(), $f->secret], $fallbacks);
    }

    /**
     * @param array<string, bool|string> $values
     * @param array<string, string> $secrets
     */
    private function settings(array $values, array $secrets): MailSettings
    {
        return new MailSettings(new FakeSettings($values, $secrets), true);
    }

    private function readiness(MailSettings $settings, bool $fallbackReady = false): OutboundMailReadiness
    {
        return new OutboundMailReadiness(new SettingsMailerDsnProvider($settings), new MailerDsnValidator(['smtp'], new DsnHostGuard(resolveDns: false)), $fallbackReady, $settings);
    }
}
