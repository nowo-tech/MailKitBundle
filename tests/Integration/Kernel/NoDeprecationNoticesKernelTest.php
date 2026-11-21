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
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Loader\Configurator\ContainerConfigurator;

use function array_filter;
use function array_values;
use function in_array;
use function str_contains;

/**
 * 2.0 in a real kernel: the 1.14 build-time notices are gone with what they announced. No configuration raises a
 * MailKit deprecation, and `log.map_entities` follows `log.enabled` when it is not set.
 */
final class NoDeprecationNoticesKernelTest extends KernelTestCase
{
    public function testTheDefaultConfigurationBuildsWithoutAMailKitNotice(): void
    {
        self::assertSame([], $this->mailKitNotices(fn (): MailKitTestKernel => $this->bootKernel()));
    }

    public function testAHostSenderBuildsWithoutANotice(): void
    {
        self::assertSame([], $this->mailKitNotices(fn (): MailKitTestKernel => $this->bootKernel(['mailer' => ['sender_provider' => StaticSenderIdentityProvider::class]], extra: static function (ContainerConfigurator $container): void {
            $container->services()->set(StaticSenderIdentityProvider::class);
        })));
    }

    /**
     * @return iterable<string, array{0: array<string, mixed>, 1: bool}>
     */
    public static function logConfigurations(): iterable
    {
        yield 'log on' => [[], true];
        yield 'log on, map_entities true' => [['map_entities' => true], true];
        yield 'log off, map_entities unset' => [['enabled' => false], false];
        yield 'log off, map_entities null' => [['enabled' => false, 'map_entities' => null], false];
        yield 'log off, map_entities true' => [['enabled' => false, 'map_entities' => true], true];
        yield 'log off, map_entities false' => [['enabled' => false, 'map_entities' => false], false];
    }

    /**
     * @param array<string, mixed> $log
     */
    #[DataProvider('logConfigurations')]
    public function testMapEntitiesFollowsTheLogWhenUnsetAndNoNoticeFires(array $log, bool $mapped): void
    {
        $kernel  = null;
        $notices = $this->mailKitNotices(function () use (&$kernel, $log): MailKitTestKernel {
            return $kernel = $this->bootKernel(['log' => $log]);
        });

        self::assertSame([], $notices);
        self::assertInstanceOf(MailKitTestKernel::class, $kernel);
        $manager = $this->service($kernel, ManagerRegistry::class)->getManager();
        self::assertInstanceOf(EntityManagerInterface::class, $manager);
        $tables = $this->connection($kernel)->createSchemaManager()->listTableNames();
        self::assertSame($mapped, $manager->getMetadataFactory()->isTransient(SentMail::class) === false && in_array('mail_kit_sent_mail', $tables, true));
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
