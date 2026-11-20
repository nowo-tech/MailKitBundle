<?php

declare(strict_types=1);

namespace Nowo\MailKitBundle\Tests\Unit\DependencyInjection;

use Nowo\MailKitBundle\DependencyInjection\NowoMailKitExtension;
use Nowo\MailKitBundle\Tests\Support\DeprecationRecorder;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Extension\Extension;

/**
 * 1.14 (2.0 deprecation layer): `log.map_entities` defaults to true in 1.x and to `log.enabled` in 2.0. The build warns
 * exactly where the two differ — the option unset, the log off, DoctrineBundle present — and never once it is set.
 */
final class MapEntitiesDeprecationTest extends TestCase
{
    public const NOTICE = 'Since nowo-tech/mail-kit-bundle 1.14: Not setting "nowo_mail_kit.log.map_entities" while "nowo_mail_kit.log.enabled" is false is deprecated: it keeps the log entities (SentMail, SentMailBody) mapped in your entity manager. In 2.0 the option defaults to "log.enabled" (false here), so they are no longer mapped and your schema tool proposes dropping their tables. Set "nowo_mail_kit.log.map_entities: true" to keep the mapping, or "nowo_mail_kit.log.map_entities: false" to opt in to the 2.0 behaviour now (see UPGRADING.md "To 1.14.0").';

    public function testUnsetWithTheLogOffAndDoctrineWarnsOnce(): void
    {
        self::assertSame([self::NOTICE], $this->load([['log' => ['enabled' => false]]], true));
    }

    public function testUnsetWithTheLogOffSplitOverSeveralFilesWarnsOnce(): void
    {
        self::assertSame([self::NOTICE], $this->load([['log' => ['enabled' => false]], ['log' => ['retention_days' => 7]], []], true));
    }

    /**
     * @return iterable<string, array{list<array<string, mixed>>, bool}>
     */
    public static function silentCases(): iterable
    {
        yield 'default configuration (log on: 2.0 keeps the mapping)' => [[[]], true];
        yield 'log on, map_entities unset' => [[['log' => ['enabled' => true]]], true];
        yield 'log off, map_entities true' => [[['log' => ['enabled' => false, 'map_entities' => true]]], true];
        yield 'log off, map_entities false (2.0 behaviour now)' => [[['log' => ['enabled' => false, 'map_entities' => false]]], true];
        yield 'log off, map_entities set in another file' => [[['log' => ['enabled' => false]], ['log' => ['map_entities' => true]]], true];
        yield 'log off without DoctrineBundle (nothing is mapped either way)' => [[['log' => ['enabled' => false]]], false];
    }

    /**
     * @param list<array<string, mixed>> $configs
     */
    #[DataProvider('silentCases')]
    public function testNoNotice(array $configs, bool $doctrine): void
    {
        self::assertSame([], $this->load($configs, $doctrine));
    }

    /**
     * @param list<array<string, mixed>> $configs
     *
     * @return list<string>
     */
    private function load(array $configs, bool $doctrine): array
    {
        $container = new ContainerBuilder();
        $container->setParameter('kernel.bundles', ['NowoInstanceSettingsBundle' => 'x', 'SecurityBundle' => 'y']);
        if ($doctrine) {
            $container->registerExtension(new class extends Extension {
                public function load(array $configs, ContainerBuilder $container): void
                {
                }

                public function getAlias(): string
                {
                    return 'doctrine';
                }
            });
        }

        return DeprecationRecorder::record(static fn () => (new NowoMailKitExtension())->load($configs, $container));
    }
}
