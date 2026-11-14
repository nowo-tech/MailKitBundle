<?php

declare(strict_types=1);

namespace Nowo\MailKitBundle\Tests\Unit\DependencyInjection\Compiler;

use LogicException;
use Nowo\MailKitBundle\DependencyInjection\Compiler\SenderPortPass;
use Nowo\MailKitBundle\Sender\SenderIdentityProviderInterface;
use Nowo\MailKitBundle\Sender\SenderProviderGate;
use Nowo\MailKitBundle\Sender\SenderProviderInterface;
use Nowo\MailKitBundle\Settings\MailSettings;
use Nowo\MailKitBundle\Tests\Support\DualSenderProvider;
use Nowo\MailKitBundle\Tests\Support\StaticSenderIdentityProvider;
use Nowo\MailKitBundle\Tests\Support\StaticSenderProvider;
use PHPUnit\Framework\TestCase;
use stdClass;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Definition;

/**
 * 1.12: the pass puts the gate behind the deprecated sender port only where it is needed and always offers the
 * framework-free port as an autowirable alias (REQ-ARCH-001 rule 3); the new port wins when a class implements both.
 */
final class SenderPortPassTest extends TestCase
{
    public function testWithoutTheAliasNothingHappens(): void
    {
        $container = new ContainerBuilder();
        (new SenderPortPass())->process($container);

        self::assertFalse($container->hasAlias(SenderProviderInterface::class));
        self::assertFalse($container->hasAlias(SenderIdentityProviderInterface::class));
        self::assertFalse($container->hasDefinition(SenderPortPass::GATE_ID));
    }

    public function testADeprecatedPortImplementationKeepsTodaysWiring(): void
    {
        $container = new ContainerBuilder();
        $container->setDefinition(MailSettings::class, new Definition(MailSettings::class));
        $container->setAlias(SenderProviderInterface::class, MailSettings::class);
        (new SenderPortPass())->process($container);

        self::assertSame(MailSettings::class, (string) $container->getAlias(SenderProviderInterface::class));
        self::assertSame(MailSettings::class, (string) $container->getAlias(SenderIdentityProviderInterface::class));
        self::assertFalse($container->hasDefinition(SenderPortPass::GATE_ID));
    }

    public function testADeprecatedOnlyHostKeepsItsWiringAndGetsTheNewPortThroughTheAdapter(): void
    {
        $container = new ContainerBuilder();
        $container->setDefinition('app.sender', new Definition(StaticSenderProvider::class));
        $container->setAlias(SenderProviderInterface::class, 'app.sender');
        (new SenderPortPass())->process($container);

        self::assertSame('app.sender', (string) $container->getAlias(SenderProviderInterface::class));
        $alias = $container->getAlias(SenderIdentityProviderInterface::class);
        self::assertSame(SenderPortPass::GATE_ID, (string) $alias);
        self::assertFalse($alias->isPublic());
        self::assertSame('app.sender', (string) $container->getDefinition(SenderPortPass::GATE_ID)->getArgument(0));
    }

    public function testAClassImplementingBothPortsIsAskedThroughTheNewOne(): void
    {
        $container = new ContainerBuilder();
        $container->setDefinition('app.sender', new Definition(DualSenderProvider::class));
        $container->setAlias(SenderProviderInterface::class, 'app.sender');
        (new SenderPortPass())->process($container);

        self::assertSame(SenderPortPass::GATE_ID, (string) $container->getAlias(SenderProviderInterface::class));
        self::assertSame('app.sender', (string) $container->getAlias(SenderIdentityProviderInterface::class));
        self::assertSame('app.sender', (string) $container->getDefinition(SenderPortPass::GATE_ID)->getArgument(0));
    }

    public function testANewPortImplementationIsServedThroughTheGate(): void
    {
        $container = new ContainerBuilder();
        $container->setDefinition('app.sender', new Definition(StaticSenderIdentityProvider::class));
        $container->setAlias('app.sender.alias', 'app.sender');
        $container->setAlias(SenderProviderInterface::class, 'app.sender.alias')->setPublic(true);
        (new SenderPortPass())->process($container);

        $alias = $container->getAlias(SenderProviderInterface::class);
        self::assertSame(SenderPortPass::GATE_ID, (string) $alias);
        self::assertTrue($alias->isPublic());
        $gate = $container->getDefinition(SenderPortPass::GATE_ID);
        self::assertSame(SenderProviderGate::class, $gate->getClass());
        self::assertSame('app.sender', (string) $gate->getArgument(0));
        $identity = $container->getAlias(SenderIdentityProviderInterface::class);
        self::assertSame('app.sender', (string) $identity);
        self::assertTrue($identity->isPublic());
    }

    public function testAnUnknowableClassIsServedThroughTheGateToo(): void
    {
        $container = new ContainerBuilder();
        $container->setDefinition('app.sender', (new Definition(stdClass::class))->setFactory([stdClass::class, 'create']));
        $container->setAlias(SenderProviderInterface::class, 'app.sender');
        (new SenderPortPass())->process($container);

        self::assertSame(SenderPortPass::GATE_ID, (string) $container->getAlias(SenderProviderInterface::class));
        self::assertSame(SenderPortPass::GATE_ID, (string) $container->getAlias(SenderIdentityProviderInterface::class));
    }

    public function testASyntheticServiceIsServedThroughTheGateToo(): void
    {
        $container = new ContainerBuilder();
        $container->setDefinition('app.sender', (new Definition(stdClass::class))->setSynthetic(true));
        $container->setAlias(SenderProviderInterface::class, 'app.sender');
        (new SenderPortPass())->process($container);

        self::assertSame(SenderPortPass::GATE_ID, (string) $container->getAlias(SenderProviderInterface::class));
    }

    public function testAnUnknownServiceIdIsLeftToTheContainer(): void
    {
        $container = new ContainerBuilder();
        $container->setAlias(SenderProviderInterface::class, 'app.sender.missing');
        (new SenderPortPass())->process($container);

        self::assertSame('app.sender.missing', (string) $container->getAlias(SenderProviderInterface::class));
        self::assertFalse($container->hasAlias(SenderIdentityProviderInterface::class));
        self::assertFalse($container->hasDefinition(SenderPortPass::GATE_ID));
    }

    public function testAnUnknownClassNameIsServedThroughTheGateToo(): void
    {
        $container = new ContainerBuilder();
        $container->setDefinition('app.sender', new Definition('App\\Mail\\NotLoadedSender'));
        $container->setAlias(SenderProviderInterface::class, 'app.sender');
        (new SenderPortPass())->process($container);

        self::assertSame(SenderPortPass::GATE_ID, (string) $container->getAlias(SenderProviderInterface::class));
    }

    public function testAServiceImplementingNeitherPortIsRefused(): void
    {
        $container = new ContainerBuilder();
        $container->setDefinition('app.sender', new Definition(stdClass::class));
        $container->setAlias(SenderProviderInterface::class, 'app.sender');

        $this->expectException(LogicException::class);
        $this->expectExceptionMessage('nowo_mail_kit.mailer.sender_provider: the service "app.sender" (stdClass) must implement');
        (new SenderPortPass())->process($container);
    }
}
