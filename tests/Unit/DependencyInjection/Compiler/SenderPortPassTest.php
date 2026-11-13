<?php

declare(strict_types=1);

namespace Nowo\MailKitBundle\Tests\Unit\DependencyInjection\Compiler;

use LogicException;
use Nowo\MailKitBundle\DependencyInjection\Compiler\SenderPortPass;
use Nowo\MailKitBundle\Sender\SenderProviderGate;
use Nowo\MailKitBundle\Sender\SenderProviderInterface;
use Nowo\MailKitBundle\Settings\MailSettings;
use Nowo\MailKitBundle\Tests\Support\StaticSenderIdentityProvider;
use PHPUnit\Framework\TestCase;
use stdClass;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Definition;

/**
 * 1.12: the pass puts the gate behind the deprecated sender port only where it is needed (REQ-ARCH-001 rule 3).
 */
final class SenderPortPassTest extends TestCase
{
    public function testWithoutTheAliasNothingHappens(): void
    {
        $container = new ContainerBuilder();
        (new SenderPortPass())->process($container);

        self::assertFalse($container->hasAlias(SenderProviderInterface::class));
        self::assertFalse($container->hasDefinition(SenderPortPass::GATE_ID));
    }

    public function testADeprecatedPortImplementationKeepsTodaysWiring(): void
    {
        $container = new ContainerBuilder();
        $container->setDefinition(MailSettings::class, new Definition(MailSettings::class));
        $container->setAlias(SenderProviderInterface::class, MailSettings::class);
        (new SenderPortPass())->process($container);

        self::assertSame(MailSettings::class, (string) $container->getAlias(SenderProviderInterface::class));
        self::assertFalse($container->hasDefinition(SenderPortPass::GATE_ID));
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
    }

    public function testAnUnknowableClassIsServedThroughTheGateToo(): void
    {
        $container = new ContainerBuilder();
        $container->setDefinition('app.sender', (new Definition(stdClass::class))->setFactory([stdClass::class, 'create']));
        $container->setAlias(SenderProviderInterface::class, 'app.sender');
        (new SenderPortPass())->process($container);

        self::assertSame(SenderPortPass::GATE_ID, (string) $container->getAlias(SenderProviderInterface::class));
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
