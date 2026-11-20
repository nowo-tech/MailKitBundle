<?php

declare(strict_types=1);

namespace Nowo\MailKitBundle\Tests\Integration\Kernel;

use Doctrine\Bundle\DoctrineBundle\DependencyInjection\DoctrineExtension;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\Persistence\ManagerRegistry;
use Nowo\MailKitBundle\DependencyInjection\NowoMailKitExtension;
use Nowo\MailKitBundle\Entity\SentMail;
use Nowo\MailKitBundle\Tests\Support\DeprecationRecorder;
use Nowo\MailKitBundle\Tests\Support\StaticSenderIdentityProvider;
use Nowo\MailKitBundle\Tests\Support\StaticSenderProvider;
use Nowo\MailKitBundle\Tests\Unit\DependencyInjection\MapEntitiesDeprecationTest;
use Symfony\Component\DependencyInjection\ContainerBuilder;
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
     * DoctrineBundle 3.4 deprecates the ORM mapping `alias` option; the prepended mapping must not set it and must
     * still load.
     */
    public function testThePrependedDoctrineMappingSetsNoDeprecatedAlias(): void
    {
        $kernel  = null;
        $notices = DeprecationRecorder::record(function () use (&$kernel): void {
            $kernel = $this->bootKernel();
        });
        $aliasHits = array_values(array_filter($notices, static fn (string $message): bool => str_contains($message, 'alias option')));

        self::assertSame([], $aliasHits);
        self::assertInstanceOf(MailKitTestKernel::class, $kernel);
        $manager = $this->service($kernel, ManagerRegistry::class)->getManager();
        self::assertInstanceOf(EntityManagerInterface::class, $manager);
        self::assertTrue($manager->getMetadataFactory()->hasMetadataFor(SentMail::class));
        self::assertSame([], $this->prependedAliases());
    }

    /**
     * @return list<string> mapping names of the prepended Doctrine config that set an `alias`
     */
    private function prependedAliases(): array
    {
        $container = new ContainerBuilder();
        $container->registerExtension(new DoctrineExtension());
        (new NowoMailKitExtension())->prepend($container);
        $names = [];
        foreach ($container->getExtensionConfig('doctrine') as $config) {
            foreach ($config['orm']['mappings'] ?? [] as $name => $mapping) {
                if (isset($mapping['alias'])) {
                    $names[] = $name;
                }
            }
        }

        return $names;
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
