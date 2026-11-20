<?php

declare(strict_types=1);

namespace Nowo\MailKitBundle\Tests\Integration\Kernel;

use Nowo\MailKitBundle\Tests\Support\DeprecationRecorder;
use Nowo\MailKitBundle\Tests\Support\StaticSenderIdentityProvider;
use Nowo\MailKitBundle\Tests\Support\StaticSenderProvider;
use Nowo\MailKitBundle\Tests\Unit\DependencyInjection\MapEntitiesDeprecationTest;
use Symfony\Component\DependencyInjection\Loader\Configurator\ContainerConfigurator;

use function array_filter;
use function array_values;
use function str_contains;

/**
 * 1.14 (2.0 deprecation layer) in a real kernel: the notices fire once, when the container is built, and a standard
 * host (default configuration, built-in sender) builds without any MailKit notice.
 */
final class DeprecationNoticesKernelTest extends KernelTestCase
{
    public function testTheDefaultConfigurationBuildsWithoutAMailKitNotice(): void
    {
        self::assertSame([], $this->mailKitNotices(fn (): MailKitTestKernel => $this->bootKernel()));
    }

    public function testANewPortHostSenderBuildsWithoutANotice(): void
    {
        self::assertSame([], $this->mailKitNotices(fn (): MailKitTestKernel => $this->bootKernel(['mailer' => ['sender_provider' => StaticSenderIdentityProvider::class]], extra: static function (ContainerConfigurator $container): void {
            $container->services()->set(StaticSenderIdentityProvider::class);
        })));
    }

    public function testTheLogOffWithMapEntitiesUnsetWarnsOnceAtBuildTime(): void
    {
        self::assertSame([MapEntitiesDeprecationTest::NOTICE], $this->mailKitNotices(fn (): MailKitTestKernel => $this->bootKernel(['log' => ['enabled' => false]])));
        self::assertSame([], $this->mailKitNotices(fn (): MailKitTestKernel => $this->bootKernel(['log' => ['enabled' => false, 'map_entities' => true]])));
    }

    public function testADeprecatedPortHostSenderWarnsOnceAtBuildTime(): void
    {
        $notices = $this->mailKitNotices(fn (): MailKitTestKernel => $this->bootKernel(['mailer' => ['sender_provider' => StaticSenderProvider::class]], extra: static function (ContainerConfigurator $container): void {
            $container->services()->set(StaticSenderProvider::class);
        }));

        self::assertCount(1, $notices);
        self::assertStringContainsString('The sender service "' . StaticSenderProvider::class . '"', $notices[0]);
    }

    /**
     * @param callable(): mixed $boot
     *
     * @return list<string>
     */
    private function mailKitNotices(callable $boot): array
    {
        return array_values(array_filter(DeprecationRecorder::record($boot), static fn (string $message): bool => str_contains($message, 'nowo-tech/mail-kit-bundle')));
    }
}
