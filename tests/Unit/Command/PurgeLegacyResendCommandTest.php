<?php

declare(strict_types=1);

namespace Nowo\MailKitBundle\Tests\Unit\Command;

use DateTimeImmutable;
use Nowo\InstanceSettingsBundle\Entity\SettingValue;
use Nowo\MailKitBundle\Command\PurgeLegacyResendCommand;
use Nowo\MailKitBundle\Settings\LegacyResendSettings;
use Nowo\MailKitBundle\Tests\Support\FakeSettings;
use Nowo\MailKitBundle\Tests\Support\MemorySettingValueRepository;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;

/**
 * 1.3: the 1.2 `resend` rows are deleted only once copied into the mailer section.
 */
final class PurgeLegacyResendCommandTest extends TestCase
{
    public function testNothingToPurge(): void
    {
        [$tester, $repository] = $this->tester(new FakeSettings(), [$this->row('mailer', 'from', 'a@example.com')]);

        self::assertSame(Command::SUCCESS, $tester->execute([]));
        self::assertStringContainsString('nothing to purge', $tester->getDisplay());
        self::assertCount(1, $repository->rows);
    }

    public function testRefusedBeforeTheSeed(): void
    {
        [$tester, $repository] = $this->tester(new FakeSettings(), $this->legacyRows());

        self::assertSame(Command::FAILURE, $tester->execute([]));
        self::assertStringContainsString('mailer.provider is not stored yet', $tester->getDisplay());
        self::assertCount(3, $repository->rows);
    }

    public function testRefusedWhenAValueWasNotCopiedUnlessForced(): void
    {
        $settings              = new FakeSettings(['mailer.provider' => 'resend', 'mailer.resend_from' => 'other@example.com'], ['mailer.resend_api_key' => 're_REDACTED-OTHER']);
        [$tester, $repository] = $this->tester($settings, $this->legacyRows());

        self::assertSame(Command::FAILURE, $tester->execute([]));
        $display = $tester->getDisplay();
        self::assertStringContainsString('resend.api_key (mailer.resend_api_key holds another value)', $display);
        self::assertStringContainsString('resend.from (mailer.resend_from holds another value)', $display);
        self::assertStringNotContainsString('re_REDACTED', $display);
        self::assertCount(3, $repository->rows);

        self::assertSame(Command::SUCCESS, $tester->execute(['--force' => true]));
        self::assertSame([], $repository->rows);
    }

    public function testDeletesCopiedRowsOnce(): void
    {
        $settings              = new FakeSettings(['mailer.provider' => 'dsn', 'mailer.resend_from' => 'legacy@example.com'], ['mailer.resend_api_key' => 're_REDACTED-LEGACY']);
        [$tester, $repository] = $this->tester($settings, [...$this->legacyRows(), $this->row('mailer', 'from', 'a@example.com')]);

        self::assertSame(Command::SUCCESS, $tester->execute(['--dry-run' => true]));
        self::assertStringContainsString('resend.api_key', $tester->getDisplay());
        self::assertStringContainsString('3 legacy row(s) would be deleted', $tester->getDisplay());
        self::assertCount(4, $repository->rows);

        self::assertSame(Command::SUCCESS, $tester->execute([]));
        self::assertStringContainsString('Deleted 3 legacy row(s).', $tester->getDisplay());
        self::assertStringNotContainsString('re_REDACTED', $tester->getDisplay());
        self::assertCount(1, $repository->rows, 'other sections are kept');

        self::assertSame(Command::SUCCESS, $tester->execute([]));
        self::assertStringContainsString('nothing to purge', $tester->getDisplay());
    }

    public function testRowsWithoutValuesNeedNoCopy(): void
    {
        [$tester, $repository] = $this->tester(new FakeSettings(['mailer.provider' => 'dsn']), [$this->row('resend', 'enabled', false)]);

        self::assertSame(Command::SUCCESS, $tester->execute([]));
        self::assertSame([], $repository->rows);
    }

    /**
     * @param list<SettingValue> $rows
     *
     * @return array{CommandTester, MemorySettingValueRepository}
     */
    private function tester(FakeSettings $settings, array $rows): array
    {
        $repository = new MemorySettingValueRepository($rows);

        return [new CommandTester(new PurgeLegacyResendCommand($repository, $settings, new LegacyResendSettings($repository))), $repository];
    }

    /**
     * @return list<SettingValue>
     */
    private function legacyRows(): array
    {
        $now = new DateTimeImmutable();

        return [
            $this->row('resend', 'enabled', true),
            (new SettingValue('resend', 'api_key', $now))->storeSecret('re_REDACTED-LEGACY', $now),
            $this->row('resend', 'from', 'legacy@example.com'),
        ];
    }

    private function row(string $section, string $name, bool|string $value): SettingValue
    {
        $now = new DateTimeImmutable();

        return (new SettingValue($section, $name, $now))->storeValue($value, $now);
    }
}
