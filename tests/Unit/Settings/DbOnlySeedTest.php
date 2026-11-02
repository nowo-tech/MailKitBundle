<?php

declare(strict_types=1);

namespace Nowo\MailKitBundle\Tests\Unit\Settings;

use Nowo\InstanceSettingsBundle\Section\SectionActionContext;
use Nowo\InstanceSettingsBundle\Section\SettingFallback;
use Nowo\MailKitBundle\Dsn\DsnHostGuard;
use Nowo\MailKitBundle\Dsn\MailerDsnValidator;
use Nowo\MailKitBundle\Dsn\SettingsMailerDsnProvider;
use Nowo\MailKitBundle\Readiness\OutboundMailReadiness;
use Nowo\MailKitBundle\Settings\MailerSettingsSection;
use Nowo\MailKitBundle\Settings\MailSettings;
use Nowo\MailKitBundle\Settings\ResendSettingsSection;
use Nowo\MailKitBundle\Tests\Support\FakeSettings;
use Nowo\MailKitBundle\Tests\Support\StaticDsnProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;

use function bin2hex;
use function iterator_to_array;
use function random_bytes;

/**
 * 1.2 DB-only mode: bootstrap fallbacks of the sections, missing-field report, section notices.
 */
final class DbOnlySeedTest extends TestCase
{
    public function testBootstrapFallbacks(): void
    {
        $dsn = 'smtp://user:' . bin2hex(random_bytes(8)) . '@smtp.example.com:587';
        $key = 're_' . bin2hex(random_bytes(12));

        $mailer = iterator_to_array((new MailerSettingsSection(bootstrapDsn: $dsn))->getFallbacks(), false);
        self::assertEquals([SettingFallback::secret('dsn', $dsn)], $mailer);
        $resend = iterator_to_array((new ResendSettingsSection(bootstrapApiKey: $key))->getFallbacks(), false);
        self::assertEquals([SettingFallback::secret('api_key', $key)], $resend);

        $empty = iterator_to_array((new MailerSettingsSection())->getFallbacks(), false);
        self::assertNull($empty[0]->value, 'no bootstrap value: nothing to seed');
    }

    public function testMissingFields(): void
    {
        self::assertSame(['mailer.dsn'], $this->settings([], [])->missingFields());
        self::assertSame([], $this->settings([], ['mailer.dsn' => 'smtp://smtp.example.com'])->missingFields());
        self::assertSame(['resend.api_key'], $this->settings(['resend.enabled' => true], ['mailer.dsn' => 'smtp://smtp.example.com'])->missingFields());
        self::assertSame([], $this->settings(['resend.enabled' => true], ['resend.api_key' => 're_' . bin2hex(random_bytes(6))])->missingFields());

        $none = $this->readiness($this->settings(['resend.enabled' => true], []));
        self::assertSame(['resend.api_key', 'mailer.dsn'], $none->status()->missingFields);
        self::assertSame(['api_key'], $none->missingFieldsOf('resend'));
        self::assertSame(['dsn'], $none->missingFieldsOf('mailer'));
        self::assertSame(['resend.api_key'], $this->readiness($this->settings(['resend.enabled' => true], []), true)->missingFields(), 'MAILER_DSN counts as ready');

        $stored = $this->readiness($this->settings(['resend.enabled' => true], ['mailer.dsn' => 'smtp://8.8.8.8']));
        self::assertTrue($stored->status()->ready);
        self::assertSame(['resend.api_key'], $stored->status()->missingFields);

        $custom = new OutboundMailReadiness(new StaticDsnProvider(), new MailerDsnValidator(['smtp'], new DsnHostGuard(resolveDns: false)));
        self::assertSame([], $custom->missingFields(), 'custom DSN provider: no settings report');
    }

    public function testNotices(): void
    {
        $context   = new SectionActionContext('mailer', new Request());
        $readiness = $this->readiness($this->settings(['resend.enabled' => true], []));
        $lazy      = static fn (): OutboundMailReadiness => $readiness;

        self::assertSame([], iterator_to_array((new MailerSettingsSection())->getNotices($context), false));
        $notices = iterator_to_array((new MailerSettingsSection(readiness: $lazy))->getNotices($context), false);
        self::assertCount(1, $notices);
        self::assertSame('settings.notice_missing', $notices[0]->getMessage());
        self::assertSame(['%fields%' => 'dsn'], $notices[0]->getParameters());
        self::assertSame('NowoMailKitBundle', $notices[0]->getDomain());

        $resend = iterator_to_array((new ResendSettingsSection(readiness: $lazy))->getNotices($context), false);
        self::assertSame(['%fields%' => 'api_key'], $resend[0]->getParameters());
        self::assertSame([], iterator_to_array((new ResendSettingsSection())->getNotices($context), false));

        $ready = $this->readiness($this->settings([], ['mailer.dsn' => 'smtp://8.8.8.8']));
        self::assertSame([], iterator_to_array((new MailerSettingsSection(readiness: static fn (): OutboundMailReadiness => $ready))->getNotices($context), false));
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
