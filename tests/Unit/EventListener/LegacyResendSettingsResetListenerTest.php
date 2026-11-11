<?php

declare(strict_types=1);

namespace Nowo\MailKitBundle\Tests\Unit\EventListener;

use DateTimeImmutable;
use Nowo\InstanceSettingsBundle\Entity\SettingValue;
use Nowo\MailKitBundle\EventListener\LegacyResendSettingsResetListener;
use Nowo\MailKitBundle\Settings\LegacyResendSettings;
use Nowo\MailKitBundle\Tests\Support\MemorySettingValueRepository;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\HttpKernelInterface;

/**
 * REQ-RUNTIME-002: the legacy `resend` memo is forgotten at every unit-of-work boundary, not only by kernel.reset.
 */
final class LegacyResendSettingsResetListenerTest extends TestCase
{
    public function testResetForgetsTheMemoDirectly(): void
    {
        $now        = new DateTimeImmutable();
        $repository = new MemorySettingValueRepository([(new SettingValue('resend', 'enabled', $now))->storeValue(true, $now)]);
        $legacy     = new LegacyResendSettings($repository);
        $legacy->values();
        $legacy->values();
        self::assertSame(1, $repository->queries);

        (new LegacyResendSettingsResetListener([$legacy]))->reset();

        $legacy->values();
        self::assertSame(2, $repository->queries);
    }

    public function testOnlyMainRequestsForgetTheMemo(): void
    {
        $now        = new DateTimeImmutable();
        $repository = new MemorySettingValueRepository([(new SettingValue('resend', 'enabled', $now))->storeValue(true, $now)]);
        $legacy     = new LegacyResendSettings($repository);
        $listener   = new LegacyResendSettingsResetListener([$legacy]);
        $kernel     = $this->createStub(HttpKernelInterface::class);
        $legacy->values();

        $listener->onRequest(new RequestEvent($kernel, Request::create('/'), HttpKernelInterface::SUB_REQUEST));
        $legacy->values();
        self::assertSame(1, $repository->queries, 'A sub-request keeps the memo of its main request.');

        $listener->onRequest(new RequestEvent($kernel, Request::create('/'), HttpKernelInterface::MAIN_REQUEST));
        $legacy->values();
        self::assertSame(2, $repository->queries);
    }

    public function testNothingBuiltMeansNothingToReset(): void
    {
        (new LegacyResendSettingsResetListener([]))->reset();

        $this->addToAssertionCount(1);
    }
}
