<?php

declare(strict_types=1);

namespace Nowo\MailKitBundle\Tests\Unit\DependencyInjection\Compiler;

use LogicException;
use Nowo\MailKitBundle\DependencyInjection\Compiler\SenderPortPass;
use Nowo\MailKitBundle\Sender\SenderIdentityProviderInterface;
use Nowo\MailKitBundle\Sender\SenderPolicy;
use Nowo\MailKitBundle\Sender\SenderProviderGate;
use Nowo\MailKitBundle\Sender\SenderProviderInterface;
use Nowo\MailKitBundle\Settings\MailSettings;
use Nowo\MailKitBundle\TestMail\TestMailSender;
use Nowo\MailKitBundle\Tests\Support\DualSenderProvider;
use Nowo\MailKitBundle\Tests\Support\IdentityOnlySenderDecorator;
use Nowo\MailKitBundle\Tests\Support\IdentitySenderDecorator;
use Nowo\MailKitBundle\Tests\Support\LegacySenderDecorator;
use Nowo\MailKitBundle\Tests\Support\SplitSenderDecorator;
use Nowo\MailKitBundle\Tests\Support\StaticSenderIdentityProvider;
use Nowo\MailKitBundle\Tests\Support\StaticSenderProvider;
use PHPUnit\Framework\TestCase;
use stdClass;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\DependencyInjection\Definition;
use Symfony\Component\DependencyInjection\Reference;
use Symfony\Component\Mime\Email;

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
        // 1.12.2: the gate over the decorated id exists for MailKit's mail path; unused here, so it is removed at compile.
        self::assertTrue($container->hasDefinition(SenderPortPass::IDENTITY_GATE_ID));

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
     * D-L1 (1.12.2, case 12): a decorator of the deprecated port id that implements both ports with different values —
     * real mail and the new port both get the new-port value (the new port wins), through the gate over that id.
     */
    public function testADualDecoratorOfTheDeprecatedPortIdSendsTheNewPortValue(): void
    {
        $container = $this->host(StaticSenderIdentityProvider::class);
        $this->decorate($container, 'app.sender_port.decorator', SplitSenderDecorator::class, SenderProviderInterface::class);
        $this->consumers($container);
        (new SenderPortPass())->process($container);

        self::assertSame(SenderProviderInterface::class, (string) $container->getAlias(SenderIdentityProviderInterface::class));
        $this->assertMailReadsThrough($container, SenderPortPass::IDENTITY_GATE_ID);
        self::assertSame(SenderProviderInterface::class, (string) $container->getDefinition(SenderPortPass::IDENTITY_GATE_ID)->getArgument(0));

        [, $new, $email] = $this->compiledWithMail($container);
        self::assertSame('split-new@example.com', $new->defaultFrom()?->email);
        self::assertSame('split-new@example.com', $email->getFrom()[0]->getAddress());
        self::assertSame('split-new-help@example.com', $email->getReplyTo()[0]->getAddress());
    }

    /**
     * D-L1 (1.12.2, case 13): the same dual decorator on a deprecated-only provider.
     */
    public function testADualDecoratorOfADeprecatedOnlyProviderSendsTheNewPortValue(): void
    {
        $container = $this->host(StaticSenderProvider::class);
        $this->decorate($container, 'app.sender.decorator', SplitSenderDecorator::class, 'app.sender');
        $this->consumers($container);
        (new SenderPortPass())->process($container);

        // Host consumers of the deprecated id keep the decorator they asked for; MailKit reads through the gate.
        self::assertSame('app.sender', (string) $container->getAlias(SenderProviderInterface::class));
        self::assertSame('app.sender', (string) $container->getAlias(SenderIdentityProviderInterface::class));
        $this->assertMailReadsThrough($container, SenderPortPass::GATE_ID);

        [, $new, $email] = $this->compiledWithMail($container);
        self::assertSame('split-new@example.com', $new->defaultFrom()?->email);
        self::assertSame('split-new@example.com', $email->getFrom()[0]->getAddress());
    }

    /**
     * D-I1 (1.12.2, case 6): a decorator of the deprecated port id that implements only the new port no longer fails
     * real mail with a `TypeError`.
     */
    public function testANewOnlyDecoratorOfTheDeprecatedPortIdSends(): void
    {
        $container = $this->host(StaticSenderIdentityProvider::class);
        $this->decorate($container, 'app.sender_port.decorator', IdentityOnlySenderDecorator::class, SenderProviderInterface::class);
        $this->consumers($container);
        (new SenderPortPass())->process($container);

        [, $new, $email] = $this->compiledWithMail($container, deprecated: false);
        self::assertSame('identity-only@example.com', $new->defaultFrom()?->email);
        self::assertSame('identity-only@example.com', $email->getFrom()[0]->getAddress());
        self::assertSame([], $email->getReplyTo());
    }

    /**
     * D-I1 (1.12.2, case 8): a decorator that implements only the new port on a deprecated-only provider.
     */
    public function testANewOnlyDecoratorOfADeprecatedOnlyProviderSends(): void
    {
        $container = $this->host(StaticSenderProvider::class);
        $this->decorate($container, 'app.sender.decorator', IdentityOnlySenderDecorator::class, 'app.sender');
        $this->consumers($container);
        (new SenderPortPass())->process($container);

        [, $new, $email] = $this->compiledWithMail($container, deprecated: false);
        self::assertSame('identity-only@example.com', $new->defaultFrom()?->email);
        self::assertSame('identity-only@example.com', $email->getFrom()[0]->getAddress());
    }

    /**
     * D-L1 (1.12.2): the settings default decorated on its own id reads through the gate on the mail path as well; the
     * test e-mail, which reads the settings directly in that configuration, is left alone.
     */
    public function testTheSettingsDefaultDecoratedOnItsIdIsReadThroughTheGateByMail(): void
    {
        $container = new ContainerBuilder();
        $container->setDefinition(MailSettings::class, new Definition(MailSettings::class));
        $container->setAlias(SenderProviderInterface::class, MailSettings::class);
        $this->decorate($container, 'app.settings.decorator', SplitSenderDecorator::class, MailSettings::class);
        $this->consumers($container, testMail: false);
        (new SenderPortPass())->process($container);

        self::assertSame(MailSettings::class, (string) $container->getAlias(SenderProviderInterface::class));
        self::assertSame(MailSettings::class, (string) $container->getAlias(SenderIdentityProviderInterface::class));
        self::assertSame(MailSettings::class, (string) $container->getDefinition(SenderPortPass::GATE_ID)->getArgument(0));
        self::assertEquals([new Reference(SenderPortPass::GATE_ID)], $container->getDefinition(SenderPolicy::class)->getArguments());
        self::assertNull($container->getDefinition(TestMailSender::class)->getArgument(5));
    }

    /**
     * Only MailKit's own consumers are rerouted: a host service of another class keeps the deprecated id.
     */
    public function testAHostConsumerOfTheDeprecatedPortIsLeftAlone(): void
    {
        $container = $this->host(StaticSenderProvider::class);
        $this->decorate($container, 'app.sender.decorator', SplitSenderDecorator::class, 'app.sender');
        $container->setDefinition('app.consumer', new Definition(stdClass::class, [new Reference(SenderProviderInterface::class), 'x']));
        $container->setDefinition('app.policy', new Definition(SenderPolicy::class, ['x', new Reference(SenderProviderInterface::class)]));
        (new SenderPortPass())->process($container);

        self::assertEquals([new Reference(SenderProviderInterface::class), 'x'], $container->getDefinition('app.consumer')->getArguments());
        self::assertEquals(['x', new Reference(SenderPortPass::GATE_ID)], $container->getDefinition('app.policy')->getArguments());
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
     * MailKit's two consumers of the deprecated port, wired as the extension wires them.
     */
    private function consumers(ContainerBuilder $container, bool $testMail = true): void
    {
        $container->setDefinition(SenderPolicy::class, (new Definition(SenderPolicy::class, [new Reference(SenderProviderInterface::class)]))->setPublic(true));
        $container->setDefinition(TestMailSender::class, new Definition(TestMailSender::class, [
            null,
            null,
            null,
            true,
            null,
            $testMail ? new Reference(SenderProviderInterface::class) : null,
        ]));
    }

    private function assertMailReadsThrough(ContainerBuilder $container, string $gate): void
    {
        self::assertEquals([new Reference($gate)], $container->getDefinition(SenderPolicy::class)->getArguments());
        self::assertEquals(new Reference($gate), $container->getDefinition(TestMailSender::class)->getArgument(5));
    }

    /**
     * Compiles the container and returns the new port, what the deprecated id serves (null when that is not a
     * deprecated-port object) and a message the bundle's sender policy has filled.
     *
     * @return array{0: SenderProviderInterface|null, 1: SenderIdentityProviderInterface, 2: Email}
     */
    private function compiledWithMail(ContainerBuilder $container, bool $deprecated = true): array
    {
        $container->setAlias('test.old', SenderProviderInterface::class)->setPublic(true);
        $container->setAlias('test.new', SenderIdentityProviderInterface::class)->setPublic(true);
        $container->compile(true);

        $old = $container->get('test.old');
        $new = $container->get('test.new');
        self::assertSame($deprecated, $old instanceof SenderProviderInterface);
        self::assertInstanceOf(SenderIdentityProviderInterface::class, $new);
        $policy = $container->get(SenderPolicy::class);
        self::assertInstanceOf(SenderPolicy::class, $policy);
        $email = (new Email())->to('to@example.com')->text('x');
        $policy->apply($email);

        return [$old instanceof SenderProviderInterface ? $old : null, $new, $email];
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
