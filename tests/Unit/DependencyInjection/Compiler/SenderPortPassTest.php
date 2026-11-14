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
use Nowo\MailKitBundle\Tests\Support\IdentitySenderDecorator;
use Nowo\MailKitBundle\Tests\Support\LegacySenderDecorator;
use Nowo\MailKitBundle\Tests\Support\StaticSenderIdentityProvider;
use Nowo\MailKitBundle\Tests\Support\StaticSenderProvider;
use PHPUnit\Framework\TestCase;
use stdClass;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\DependencyInjection\Definition;
use Symfony\Component\DependencyInjection\Reference;

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
        // 1.12.1: the gate reads the configured id, so a decorator of that alias is not skipped.
        self::assertSame('app.sender.alias', (string) $gate->getArgument(0));
        $logger = $gate->getArgument(1);
        self::assertInstanceOf(Reference::class, $logger);
        self::assertSame('logger', (string) $logger);
        self::assertSame(ContainerInterface::NULL_ON_INVALID_REFERENCE, $logger->getInvalidBehavior());
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

    /**
     * PI-L2 (a): a decorator of the host new-port provider that implements only the deprecated port no longer breaks
     * the autowired new port; both ports see the decorated sender.
     */
    public function testANewPortProviderDecoratedWithADeprecatedOnlyDecoratorIsServedThroughTheGate(): void
    {
        $container = $this->host(StaticSenderIdentityProvider::class);
        $this->decorate($container, 'app.sender.decorator', LegacySenderDecorator::class, 'app.sender');
        (new SenderPortPass())->process($container);

        self::assertSame(SenderPortPass::GATE_ID, (string) $container->getAlias(SenderProviderInterface::class));
        self::assertSame(SenderPortPass::GATE_ID, (string) $container->getAlias(SenderIdentityProviderInterface::class));

        [$old, $new] = $this->compiled($container);
        self::assertSame('decorated@example.com', $old->from()?->getAddress());
        self::assertSame('decorated@example.com', $new->defaultFrom()?->email);
    }

    /**
     * PI-L2 (a): a decorator that implements the new port is served straight, without the gate.
     */
    public function testANewPortDecoratorOfTheProviderIsServedStraight(): void
    {
        $container = $this->host(StaticSenderProvider::class);
        $this->decorate($container, 'app.sender.decorator', IdentitySenderDecorator::class, 'app.sender');
        (new SenderPortPass())->process($container);

        self::assertSame('app.sender', (string) $container->getAlias(SenderProviderInterface::class));
        self::assertSame('app.sender', (string) $container->getAlias(SenderIdentityProviderInterface::class));

        [$old, $new] = $this->compiled($container);
        self::assertInstanceOf(IdentitySenderDecorator::class, $new);
        self::assertSame('decorated-identity@example.com', $old->from()?->getAddress());
    }

    /**
     * PI-L2 (b): a host decorator of the deprecated port id is what the new port serves too, through a second gate
     * over that id; MailKit's own path still asks the configured provider through the new port first.
     */
    public function testADecoratorOfTheDeprecatedPortIdReachesTheNewPortToo(): void
    {
        $container = $this->host(StaticSenderIdentityProvider::class);
        $this->decorate($container, 'app.sender_port.decorator', LegacySenderDecorator::class, SenderProviderInterface::class);
        (new SenderPortPass())->process($container);

        self::assertSame(SenderPortPass::GATE_ID, (string) $container->getAlias(SenderProviderInterface::class));
        self::assertSame(SenderPortPass::IDENTITY_GATE_ID, (string) $container->getAlias(SenderIdentityProviderInterface::class));
        self::assertSame(SenderProviderInterface::class, (string) $container->getDefinition(SenderPortPass::IDENTITY_GATE_ID)->getArgument(0));

        [$old, $new] = $this->compiled($container);
        self::assertInstanceOf(LegacySenderDecorator::class, $old);
        // MailKit's path: decorator -> gate -> provider, and the gate reads defaultFrom() (new port wins).
        self::assertInstanceOf(SenderProviderGate::class, $old->inner);
        self::assertSame('tenant@example.com', $old->inner->from()?->getAddress());
        self::assertSame('decorated@example.com', $old->from()->getAddress());
        self::assertSame('decorated@example.com', $new->defaultFrom()?->email);
    }

    public function testANewPortDecoratorOfTheDeprecatedPortIdIsServedStraight(): void
    {
        $container = $this->host(DualSenderProvider::class);
        $this->decorate($container, 'app.sender_port.decorator', IdentitySenderDecorator::class, SenderProviderInterface::class);
        (new SenderPortPass())->process($container);

        self::assertSame(SenderProviderInterface::class, (string) $container->getAlias(SenderIdentityProviderInterface::class));
        self::assertFalse($container->hasDefinition(SenderPortPass::IDENTITY_GATE_ID));

        [$old, $new] = $this->compiled($container);
        self::assertSame($old, $new);
        self::assertInstanceOf(IdentitySenderDecorator::class, $new);
    }

    public function testTheSettingsDefaultDecoratedOnTheDeprecatedPortIdReachesTheNewPortToo(): void
    {
        $container = new ContainerBuilder();
        $container->setDefinition(MailSettings::class, new Definition(MailSettings::class));
        $container->setAlias(SenderProviderInterface::class, MailSettings::class);
        $this->decorate($container, 'app.sender_port.decorator', LegacySenderDecorator::class, SenderProviderInterface::class);
        (new SenderPortPass())->process($container);

        self::assertSame(MailSettings::class, (string) $container->getAlias(SenderProviderInterface::class));
        self::assertSame(SenderPortPass::IDENTITY_GATE_ID, (string) $container->getAlias(SenderIdentityProviderInterface::class));
        self::assertFalse($container->hasDefinition(SenderPortPass::GATE_ID));
    }

    public function testTheSettingsDefaultDecoratedWithADeprecatedOnlyDecoratorIsServedThroughTheGate(): void
    {
        $container = new ContainerBuilder();
        $container->setDefinition(MailSettings::class, new Definition(MailSettings::class));
        $container->setAlias(SenderProviderInterface::class, MailSettings::class);
        $this->decorate($container, 'app.settings.decorator', LegacySenderDecorator::class, MailSettings::class);
        (new SenderPortPass())->process($container);

        self::assertSame(MailSettings::class, (string) $container->getAlias(SenderProviderInterface::class));
        self::assertSame(SenderPortPass::GATE_ID, (string) $container->getAlias(SenderIdentityProviderInterface::class));
        self::assertSame(MailSettings::class, (string) $container->getDefinition(SenderPortPass::GATE_ID)->getArgument(0));
    }

    public function testADecoratorOfADecoratorCountsToo(): void
    {
        $container = $this->host(StaticSenderIdentityProvider::class);
        $this->decorate($container, 'app.sender.decorator', IdentitySenderDecorator::class, 'app.sender');
        $this->decorate($container, 'app.sender.decorator.outer', LegacySenderDecorator::class, 'app.sender.decorator');
        (new SenderPortPass())->process($container);

        self::assertSame(SenderPortPass::GATE_ID, (string) $container->getAlias(SenderIdentityProviderInterface::class));

        [, $new] = $this->compiled($container);
        self::assertSame('decorated@example.com', $new->defaultFrom()?->email);
    }

    public function testADecoratorOfUnknowableClassIsServedThroughTheGate(): void
    {
        $container = $this->host(StaticSenderIdentityProvider::class);
        $container->setDefinition('app.sender.decorator', (new Definition(stdClass::class))->setFactory([stdClass::class, 'create'])->setDecoratedService('app.sender'));
        (new SenderPortPass())->process($container);

        self::assertSame(SenderPortPass::GATE_ID, (string) $container->getAlias(SenderIdentityProviderInterface::class));
    }

    public function testASelfDecoratingDefinitionDoesNotLoop(): void
    {
        $container = $this->host(StaticSenderIdentityProvider::class);
        $container->getDefinition('app.sender')->setDecoratedService('app.sender');
        (new SenderPortPass())->process($container);

        self::assertSame('app.sender', (string) $container->getAlias(SenderIdentityProviderInterface::class));
    }

    /**
     * A host whose `mailer.sender_provider` is the service `app.sender` of the given class.
     */
    private function host(string $class): ContainerBuilder
    {
        $container = new ContainerBuilder();
        $container->setDefinition('app.sender', new Definition($class));
        $container->setAlias(SenderProviderInterface::class, 'app.sender');

        return $container;
    }

    private function decorate(ContainerBuilder $container, string $id, string $class, string $decorated): void
    {
        $container->setDefinition($id, (new Definition($class, [new Reference($id . '.inner')]))->setDecoratedService($decorated));
    }

    /**
     * Compiles the container and returns what the deprecated and the new port serve.
     *
     * @return array{0: SenderProviderInterface, 1: SenderIdentityProviderInterface}
     */
    private function compiled(ContainerBuilder $container): array
    {
        $container->setAlias('test.old', SenderProviderInterface::class)->setPublic(true);
        $container->setAlias('test.new', SenderIdentityProviderInterface::class)->setPublic(true);
        $container->compile(true);

        $old = $container->get('test.old');
        $new = $container->get('test.new');
        self::assertInstanceOf(SenderProviderInterface::class, $old);
        self::assertInstanceOf(SenderIdentityProviderInterface::class, $new);

        return [$old, $new];
    }
}
