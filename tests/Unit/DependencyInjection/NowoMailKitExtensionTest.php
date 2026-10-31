<?php

declare(strict_types=1);

namespace Nowo\MailKitBundle\Tests\Unit\DependencyInjection;

use LogicException;
use Nowo\InstanceSettingsBundle\Settings\InstanceSettingsInterface;
use Nowo\MailKitBundle\DependencyInjection\Compiler\MailerIntegrationPass;
use Nowo\MailKitBundle\DependencyInjection\NowoMailKitExtension;
use Nowo\MailKitBundle\Dsn\MailerDsnProviderInterface;
use Nowo\MailKitBundle\NowoMailKitBundle;
use Nowo\MailKitBundle\Resend\ResendApiChecker;
use Nowo\MailKitBundle\Security\MailKitAccessCheckerInterface;
use PHPUnit\Framework\TestCase;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Definition;
use Symfony\Component\DependencyInjection\Extension\Extension;
use Symfony\Component\DependencyInjection\Reference;
use Symfony\Component\Mailer\Mailer;
use Symfony\Component\Mailer\Transport;
use Symfony\Component\Mailer\Transport\Transports;

final class NowoMailKitExtensionTest extends TestCase
{
    public function testPrependsTheProfilesAndTheAuthKitChecker(): void
    {
        $container = $this->container(['nowo_form_kit', 'nowo_auth_kit', 'doctrine']);
        (new NowoMailKitExtension())->prepend($container);

        self::assertSame(NowoMailKitExtension::formKitProfile(), $container->getExtensionConfig('nowo_form_kit')[0]['profiles'][NowoMailKitBundle::FORM_KIT_PROFILE]);
        self::assertSame(['outbound_mail_ready_checker' => NowoMailKitExtension::AUTH_KIT_CHECKER_ID], $container->getExtensionConfig('nowo_auth_kit')[0]);
        self::assertArrayHasKey('NowoMailKitBundle', $container->getExtensionConfig('doctrine')[0]['orm']['mappings']);

        $own = $this->container(['nowo_form_kit']);
        $own->prependExtensionConfig('nowo_form_kit', ['profiles' => [NowoMailKitBundle::FORM_KIT_PROFILE => ['translation_domain' => 'app']]]);
        (new NowoMailKitExtension())->prepend($own);
        self::assertCount(1, $own->getExtensionConfig('nowo_form_kit'));

        $none = $this->container([]);
        (new NowoMailKitExtension())->prepend($none);
        self::assertSame([], $none->getExtensionConfig('nowo_form_kit'));
    }

    public function testCustomServicesAndSwitches(): void
    {
        $container = $this->container([]);
        (new NowoMailKitExtension())->load([[
            'mailer'   => ['dsn_provider' => 'app.dsn_provider', 'decorate' => false],
            'security' => ['access_checker' => 'app.mail_checker'],
            'resend'   => ['enabled' => true],
            'web_ui'   => ['enabled' => true],
        ]], $container);

        self::assertSame('app.dsn_provider', (string) $container->getAlias(MailerDsnProviderInterface::class));
        self::assertSame('app.mail_checker', (string) $container->getAlias(MailKitAccessCheckerInterface::class));
        self::assertFalse($container->hasDefinition(MailerIntegrationPass::MAILER_ID));
        self::assertTrue($container->hasDefinition(ResendApiChecker::class));
        self::assertSame('nowo_mail_kit', (new NowoMailKitExtension())->getAlias());
    }

    public function testThePassDetectsTheMessageBus(): void
    {
        foreach ([[new Reference('messenger.default_bus'), true, true], [new Reference('messenger.default_bus'), false, false], [null, false, false]] as [$bus, $registered, $expected]) {
            $container = $this->container([]);
            $container->setDefinition(InstanceSettingsInterface::class, new Definition('stdClass'));
            $container->setDefinition('mailer.transports', new Definition(Transports::class));
            $container->setDefinition('mailer.transport_factory', new Definition(Transport::class));
            $container->setDefinition('mailer.mailer', new Definition(Mailer::class, [new Reference('mailer.transports'), $bus]));
            if ($registered) {
                $container->setDefinition('messenger.default_bus', new Definition('stdClass'));
            }
            $container->setDefinition(MailerIntegrationPass::MAILER_ID, new Definition('stdClass', ['$queues' => null]));
            (new MailerIntegrationPass())->process($container);
            self::assertSame($expected, $container->getDefinition(MailerIntegrationPass::MAILER_ID)->getArgument('$queues'));
        }

        $bare = $this->container([]);
        $bare->setDefinition(InstanceSettingsInterface::class, new Definition('stdClass'));
        $bare->setDefinition('mailer.transports', new Definition(Transports::class));
        $bare->setDefinition('mailer.transport_factory', new Definition(Transport::class));
        (new MailerIntegrationPass())->process($bare);
        $this->addToAssertionCount(1);
    }

    public function testThePassNeedsInstanceSettingsAndTheMailer(): void
    {
        try {
            (new MailerIntegrationPass())->process(new ContainerBuilder());
            self::fail('Instance Settings must be required.');
        } catch (LogicException $e) {
            self::assertStringContainsString('instance-settings-bundle', $e->getMessage());
        }
        $container = new ContainerBuilder();
        $container->setDefinition(InstanceSettingsInterface::class, new Definition('stdClass'));
        $this->expectExceptionMessage('requires the framework mailer');
        (new MailerIntegrationPass())->process($container);
    }

    public function testLoadNeedsInstanceSettings(): void
    {
        $container = new ContainerBuilder();
        $container->setParameter('kernel.bundles', []);
        $this->expectException(LogicException::class);
        (new NowoMailKitExtension())->load([], $container);
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
