<?php

declare(strict_types=1);

namespace Nowo\MailKitBundle\Tests\Unit\DependencyInjection;

use LogicException;
use Nowo\MailKitBundle\DependencyInjection\Compiler\TemplatePlaceholderPass;
use Nowo\MailKitBundle\DependencyInjection\Configuration;
use Nowo\MailKitBundle\DependencyInjection\NowoMailKitExtension;
use Nowo\MailKitBundle\Integration\UserKit\EmailTemplatesPersonalDataEraser;
use Nowo\MailKitBundle\Integration\UserKit\EmailTemplatesPersonalDataExportProvider;
use Nowo\MailKitBundle\Template\Engine\PlaceholderResolver;
use Nowo\MailKitBundle\Template\Engine\TemplateLibrary;
use Nowo\MailKitBundle\Template\Infrastructure\Command\TemplateSchemaCommand;
use Nowo\MailKitBundle\Template\Infrastructure\Store\TemplateSchemaListener;
use Nowo\MailKitBundle\Template\Infrastructure\Store\TemplateStore;
use Nowo\MailKitBundle\Template\Placeholder\PlaceholderContext;
use Nowo\MailKitBundle\Template\Placeholder\PlaceholderProviderInterface;
use Nowo\MailKitBundle\Template\Port\DenyAllTemplatePermission;
use Nowo\MailKitBundle\Template\Port\TemplateOwnerResolverInterface;
use Nowo\MailKitBundle\Template\Port\TemplatePermissionInterface;
use Nowo\MailKitBundle\Template\Service\TemplateLibraryInterface;
use Nowo\MailKitBundle\Template\Service\TemplatePersonalDataInterface;
use Nowo\MailKitBundle\Template\Service\TemplateRendererInterface;
use Nowo\MailKitBundle\Tests\Support\ContactPlaceholderProvider;
use Nowo\MailKitBundle\Tests\Support\MePlaceholderProvider;
use PHPUnit\Framework\TestCase;
use stdClass;
use Symfony\Component\Config\Definition\Exception\InvalidConfigurationException;
use Symfony\Component\Config\Definition\Processor;
use Symfony\Component\DependencyInjection\Argument\ServiceLocatorArgument;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Definition;
use Symfony\Component\DependencyInjection\Exception\LogicException as DiLogicException;
use Symfony\Component\DependencyInjection\Extension\Extension;
use Symfony\Component\DependencyInjection\Reference;

use function array_filter;
use function array_keys;
use function str_contains;

/**
 * 2.7.0 `templates` configuration: off by default with nothing registered, the ports, the UserKit bridge and its
 * owner resolver, the build errors, and the closed placeholder registry pass.
 */
final class TemplateConfigurationTest extends TestCase
{
    public function testDefaults(): void
    {
        /** @var array{templates: array<string, mixed>} $config */
        $config = (new Processor())->processConfiguration(new Configuration(), [[]]);
        self::assertSame([
            'enabled'          => false,
            'permission'       => null,
            'owner_resolver'   => null,
            'connection'       => null,
            'max_body_bytes'   => 262_144,
            'max_output_bytes' => 1_048_576,
            'max_placeholders' => 200,
            'max_per_scope'    => 500,
        ], $config['templates']);
    }

    public function testTheBodyLimitMustFitTheOutputLimit(): void
    {
        $this->expectException(InvalidConfigurationException::class);
        $this->expectExceptionMessage('templates.max_body_bytes must not exceed templates.max_output_bytes');
        (new Processor())->processConfiguration(new Configuration(), [['templates' => ['max_body_bytes' => 4096, 'max_output_bytes' => 2048]]]);
    }

    public function testDisabledRegistersNothing(): void
    {
        $container = $this->container(['doctrine']);
        (new NowoMailKitExtension())->load([[]], $container);
        $ids = array_filter([...array_keys($container->getDefinitions()), ...array_keys($container->getAliases())], static fn (string $id): bool => str_contains($id, '\\Template\\') || str_contains($id, 'EmailTemplates'));
        self::assertSame([], $ids);
        self::assertSame([PlaceholderProviderInterface::TAG => [[]]], $container->getAutoconfiguredInstanceof()[PlaceholderProviderInterface::class]->getTags());
    }

    public function testEnabledDefaultsAndHostPorts(): void
    {
        $default = $this->container(['doctrine']);
        (new NowoMailKitExtension())->load([['templates' => ['enabled' => true]]], $default);
        self::assertSame(DenyAllTemplatePermission::class, (string) $default->getAlias(TemplatePermissionInterface::class));
        self::assertSame(TemplateLibrary::class, (string) $default->getAlias(TemplateLibraryInterface::class));
        self::assertTrue($default->getAlias(TemplateLibraryInterface::class)->isPublic());
        self::assertTrue($default->getAlias(TemplateRendererInterface::class)->isPublic());
        self::assertTrue($default->getAlias(TemplatePersonalDataInterface::class)->isPublic());
        self::assertSame([['command' => TemplateSchemaCommand::NAME]], $default->getDefinition(TemplateSchemaCommand::class)->getTag('console.command'));
        self::assertSame([['event' => 'postGenerateSchema']], $default->getDefinition(TemplateSchemaListener::class)->getTag('doctrine.event_listener'));
        self::assertFalse($default->hasDefinition(EmailTemplatesPersonalDataEraser::class), 'personal_data is opt-in');
        self::assertSame([200], [$default->getDefinition(PlaceholderResolver::class)->getArgument(1)]);
        self::assertNull($default->getDefinition(TemplateStore::class)->getArgument(1));

        $custom = $this->container(['doctrine']);
        (new NowoMailKitExtension())->load([[
            'templates'     => ['enabled' => true, 'permission' => 'app.template_permission', 'owner_resolver' => 'app.template_owners', 'connection' => 'mail'],
            'personal_data' => ['enabled' => true],
        ]], $custom);
        self::assertSame('app.template_permission', (string) $custom->getAlias(TemplatePermissionInterface::class));
        self::assertSame('app.template_owners', (string) $custom->getAlias(TemplateOwnerResolverInterface::class));
        self::assertSame('mail', $custom->getDefinition(TemplateStore::class)->getArgument(1));
        self::assertSame([['priority' => EmailTemplatesPersonalDataEraser::PRIORITY]], $custom->getDefinition(EmailTemplatesPersonalDataEraser::class)->getTag('nowo_user_kit.personal_data.eraser'));
        self::assertSame([['priority' => EmailTemplatesPersonalDataExportProvider::PRIORITY]], $custom->getDefinition(EmailTemplatesPersonalDataExportProvider::class)->getTag('nowo_user_kit.personal_data.export_provider'));
    }

    public function testTheGdprBridgeNeedsTheOwnerResolver(): void
    {
        $this->expectException(InvalidConfigurationException::class);
        $this->expectExceptionMessage('nowo_mail_kit.templates.owner_resolver is required');
        (new NowoMailKitExtension())->load([['templates' => ['enabled' => true], 'personal_data' => ['enabled' => true]]], $this->container(['doctrine']));
    }

    public function testTemplatesNeedDoctrine(): void
    {
        $this->expectException(LogicException::class);
        $this->expectExceptionMessage('nowo_mail_kit.templates requires doctrine/doctrine-bundle');
        (new NowoMailKitExtension())->load([['templates' => ['enabled' => true], 'log' => ['enabled' => false]]], $this->container([]));
    }

    public function testThePassBuildsAClosedRegistry(): void
    {
        $container = $this->passContainer(['app.me' => MePlaceholderProvider::class, 'app.contact' => ContactPlaceholderProvider::class]);
        (new TemplatePlaceholderPass())->process($container);
        $locator = $container->getDefinition(PlaceholderResolver::class)->getArgument(0);
        self::assertInstanceOf(Reference::class, $locator);
        $services = $container->getDefinition((string) $locator)->getArgument(0);
        self::assertIsArray($services);
        self::assertSame(['me', 'contact'], array_keys($services));

        // Without the feature the pass does nothing.
        $off = new ContainerBuilder();
        (new TemplatePlaceholderPass())->process($off);
        self::assertFalse($off->hasDefinition(PlaceholderResolver::class));
    }

    public function testThePassRefusesDuplicatesInvalidNamesAndForeignClasses(): void
    {
        $invalid = new class implements PlaceholderProviderInterface {
            public static function placeholderNamespace(): string
            {
                return 'Bad-Name';
            }

            public function definitions(): array
            {
                return [];
            }

            public function resolve(array $names, PlaceholderContext $context): array
            {
                return [];
            }
        };
        foreach ([
            'provided twice' => ['a' => MePlaceholderProvider::class, 'b' => MePlaceholderProvider::class],
            'must match'     => ['a' => $invalid::class],
            'must implement' => ['a' => stdClass::class],
        ] as $message => $services) {
            try {
                (new TemplatePlaceholderPass())->process($this->passContainer($services));
                self::fail($message);
            } catch (DiLogicException $e) {
                self::assertStringContainsString($message, $e->getMessage());
            }
        }
    }

    /**
     * @param array<string, class-string> $services
     */
    private function passContainer(array $services): ContainerBuilder
    {
        $container = new ContainerBuilder();
        $container->setDefinition(PlaceholderResolver::class, new Definition(PlaceholderResolver::class, [new ServiceLocatorArgument([]), 10]));
        foreach ($services as $id => $class) {
            $container->setDefinition($id, (new Definition($class))->addTag(PlaceholderProviderInterface::TAG));
        }

        return $container;
    }

    /**
     * @param list<string> $extensions
     */
    private function container(array $extensions): ContainerBuilder
    {
        $container = new ContainerBuilder();
        $container->setParameter('kernel.bundles', ['NowoInstanceSettingsBundle' => 'x', 'SecurityBundle' => 'y']);
        foreach ($extensions as $alias) {
            $container->registerExtension(new class($alias) extends Extension {
                public function __construct(private readonly string $alias)
                {
                }

                public function load(array $configs, ContainerBuilder $container): void
                {
                }

                public function getAlias(): string
                {
                    return $this->alias;
                }
            });
        }

        return $container;
    }
}
