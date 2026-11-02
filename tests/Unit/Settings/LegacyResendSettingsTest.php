<?php

declare(strict_types=1);

namespace Nowo\MailKitBundle\Tests\Unit\Settings;

use DateTimeImmutable;
use Nowo\InstanceSettingsBundle\Entity\SettingValue;
use Nowo\MailKitBundle\Settings\LegacyResendSettings;
use Nowo\MailKitBundle\Tests\Support\MemorySettingValueRepository;
use PHPUnit\Framework\TestCase;

/**
 * 1.3: the rows of the 1.2 `resend` section, read straight from the repository, once per request.
 */
final class LegacyResendSettingsTest extends TestCase
{
    public function testNoRowIsNull(): void
    {
        $repository = new MemorySettingValueRepository([$this->row('mailer', 'from', 'a@example.com')]);
        $legacy     = new LegacyResendSettings($repository);

        self::assertNull($legacy->values());
        self::assertNull($legacy->values());
        self::assertSame(1, $repository->queries, 'memoized');
        $legacy->reset();
        self::assertNull($legacy->values());
        self::assertSame(2, $repository->queries);
    }

    public function testRowsAreRead(): void
    {
        $now    = new DateTimeImmutable();
        $legacy = new LegacyResendSettings(new MemorySettingValueRepository([
            $this->row('resend', 'enabled', true),
            (new SettingValue('resend', 'api_key', $now))->storeSecret(' re_REDACTED-TEST ', $now),
            $this->row('resend', 'from', ' mail@example.com '),
            $this->row('resend', 'unknown', 'x'),
        ]));

        self::assertSame(['enabled' => true, 'api_key' => 're_REDACTED-TEST', 'from' => 'mail@example.com'], $legacy->values());
    }

    public function testEmptyAndOffValues(): void
    {
        $now    = new DateTimeImmutable();
        $legacy = new LegacyResendSettings(new MemorySettingValueRepository([
            $this->row('resend', 'enabled', false),
            (new SettingValue('resend', 'api_key', $now))->storeSecret('  ', $now),
            $this->row('resend', 'from', 42),
        ]));

        self::assertSame(['enabled' => false, 'api_key' => null, 'from' => null], $legacy->values());
        self::assertTrue((new LegacyResendSettings(new MemorySettingValueRepository([$this->row('resend', 'enabled', '1')])))->values()['enabled'] ?? false);
    }

    private function row(string $section, string $name, bool|int|string $value): SettingValue
    {
        $now = new DateTimeImmutable();

        return (new SettingValue($section, $name, $now))->storeValue($value, $now);
    }
}
