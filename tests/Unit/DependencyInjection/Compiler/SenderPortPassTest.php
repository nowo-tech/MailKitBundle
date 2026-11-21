<?php

declare(strict_types=1);

namespace Nowo\MailKitBundle\Tests\Unit\DependencyInjection\Compiler;

use LogicException;
use Nowo\MailKitBundle\DependencyInjection\Compiler\SenderPortPass;
use Nowo\MailKitBundle\Sender\SenderIdentityProviderInterface;
use Nowo\MailKitBundle\Sender\SenderPolicy;
use Nowo\MailKitBundle\Settings\MailSettings;
use Nowo\MailKitBundle\Tests\Fixtures\RemovedPort\RemovedPortSenderDecorator;
use Nowo\MailKitBundle\Tests\Fixtures\RemovedPort\RemovedPortSenderProvider;
use Nowo\MailKitBundle\Tests\Support\IdentityOnlySenderDecorator;
use Nowo\MailKitBundle\Tests\Support\IdentitySenderDecorator;
use Nowo\MailKitBundle\Tests\Support\StaticSenderIdentityProvider;
use PHPUnit\Framework\TestCase;
use stdClass;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Definition;
use Symfony\Component\DependencyInjection\Reference;
use Symfony\Component\Mime\Email;

/**
 * 2.0: `SenderIdentityProviderInterface` is the only sender port (REQ-ARCH-001 rule 3). The pass leaves the wiring
 * alone and fails the build, naming the class and UPGRADING, on a sender service or decorator without the port — also
 * one that still implements the removed `SenderProviderInterface`, which can no longer be loaded.
 */
final class SenderPortPassTest extends TestCase
{
    public function testWithoutTheAliasNothingHappens(): void
    {
        $container = new ContainerBuilder();
        (new SenderPortPass())->process($container);

        self::assertFalse($container->hasAlias(SenderIdentityProviderInterface::class));
    }

    public function testTheSettingsDefaultIsAccepted(): void
    {
        $container = $this->host(MailSettings::class, MailSettings::class);
        (new SenderPortPass())->process($container);

        self::assertSame(MailSettings::class, (string) $container->getAlias(SenderIdentityProviderInterface::class));
    }

    public function testAHostProviderBehindAnAliasIsServedStraight(): void
    {
        $container = $this->host('app.sender', StaticSenderIdentityProvider::class);
        $container->setAlias('app.sender_alias', 'app.sender');
        $container->setAlias(SenderIdentityProviderInterface::class, 'app.sender_alias');
        $this->consumers($container);

        $email = $this->compiledMail($container);
        self::assertSame('"Tenant" <tenant@example.com>', $email->getFrom()[0]->toString());
        self::assertSame('tenant-help@example.com', $email->getReplyTo()[0]->getAddress());
    }

    public function testAnUnknowableClassIsAccepted(): void
    {
        $container = new ContainerBuilder();
        $container->setDefinition('app.sender', (new Definition(stdClass::class))->setFactory([StaticSenderIdentityProvider::class, 'create']));
        $container->setDefinition('app.synthetic', (new Definition(stdClass::class))->setSynthetic(true)->setDecoratedService('app.sender'));
        $container->setAlias(SenderIdentityProviderInterface::class, 'app.sender');
        (new SenderPortPass())->process($container);

        self::assertSame('app.sender', (string) $container->getAlias(SenderIdentityProviderInterface::class));
    }

    public function testAnUnknownServiceIdIsLeftToTheContainer(): void
    {
        $container = new ContainerBuilder();
        $container->setAlias(SenderIdentityProviderInterface::class, 'app.missing');
        (new SenderPortPass())->process($container);

        self::assertSame('app.missing', (string) $container->getAlias(SenderIdentityProviderInterface::class));
    }

    public function testAServiceWithoutThePortIsRefused(): void
    {
        $container = $this->host('app.sender', stdClass::class);

        $this->expectException(LogicException::class);
        $this->expectExceptionMessage('nowo_mail_kit.mailer.sender_provider: the service "app.sender" (stdClass) must implement "Nowo\MailKitBundle\Sender\SenderIdentityProviderInterface".');
        (new SenderPortPass())->process($container);
    }

    public function testAnUnknownClassIsRefused(): void
    {
        $container = $this->host('App\Mail\MissingSender', null);

        $this->expectException(LogicException::class);
        $this->expectExceptionMessage('the service "App\Mail\MissingSender" (App\Mail\MissingSender) must implement');
        (new SenderPortPass())->process($container);
    }

    public function testAClassStillImplementingTheRemovedPortIsRefusedByName(): void
    {
        $container = $this->host('app.sender', '%sender.class%');
        $container->setParameter('sender.class', RemovedPortSenderProvider::class);

        try {
            (new SenderPortPass())->process($container);
            self::fail('A class on the removed port must fail the build.');
        } catch (LogicException $e) {
            self::assertSame(
                'nowo_mail_kit.mailer.sender_provider: the service "app.sender" (' . RemovedPortSenderProvider::class . ') must implement "Nowo\MailKitBundle\Sender\SenderIdentityProviderInterface". "Nowo\MailKitBundle\Sender\SenderProviderInterface" was removed in 2.0, so a class that still implements it no longer loads: implement "Nowo\MailKitBundle\Sender\SenderIdentityProviderInterface" (it returns "Nowo\MailKitBundle\Sender\MailAddress"; see UPGRADING.md "To 1.12.0" and "To 2.0.0").',
                $e->getMessage(),
            );
        }
    }

    public function testDecoratorsWithThePortAreAccepted(): void
    {
        $container = $this->host('app.sender', StaticSenderIdentityProvider::class);
        $this->decorate($container, 'app.decorator', IdentitySenderDecorator::class, 'app.sender');
        $this->decorate($container, 'app.outer', IdentityOnlySenderDecorator::class, 'app.decorator');
        $this->consumers($container);

        self::assertSame('identity-only@example.com', $this->compiledMail($container)->getFrom()[0]->getAddress());
    }

    public function testADecoratorOfThePortIdIsReadByMail(): void
    {
        $container = $this->host('app.sender', StaticSenderIdentityProvider::class);
        $this->decorate($container, 'app.on_port', IdentitySenderDecorator::class, SenderIdentityProviderInterface::class);
        $this->consumers($container);

        self::assertSame('decorated-identity@example.com', $this->compiledMail($container)->getFrom()[0]->getAddress());
    }

    public function testADecoratorStillImplementingTheRemovedPortIsRefusedByName(): void
    {
        $container = $this->host('app.sender', StaticSenderIdentityProvider::class);
        $this->decorate($container, 'app.decorator', IdentitySenderDecorator::class, 'app.sender');
        $this->decorate($container, 'app.legacy', RemovedPortSenderDecorator::class, 'app.decorator');

        $this->expectException(LogicException::class);
        $this->expectExceptionMessage('The service "app.legacy" (' . RemovedPortSenderDecorator::class . ') decorates the MailKit sender but does not implement "Nowo\MailKitBundle\Sender\SenderIdentityProviderInterface". "Nowo\MailKitBundle\Sender\SenderProviderInterface" was removed in 2.0');
        (new SenderPortPass())->process($container);
    }

    public function testADecoratorOfThePortIdWithoutThePortIsRefused(): void
    {
        $container = $this->host(MailSettings::class, MailSettings::class);
        $this->decorate($container, 'app.decorator', stdClass::class, SenderIdentityProviderInterface::class);

        $this->expectException(LogicException::class);
        $this->expectExceptionMessage('The service "app.decorator" (stdClass) decorates the MailKit sender but does not implement');
        (new SenderPortPass())->process($container);
    }

    public function testADecoratorOfTheRemovedPortIdIsRefused(): void
    {
        $container = $this->host(MailSettings::class, MailSettings::class);
        $this->decorate($container, 'app.decorator', IdentitySenderDecorator::class, SenderPortPass::REMOVED_PORT);

        $this->expectException(LogicException::class);
        $this->expectExceptionMessage('The service "app.decorator" decorates "Nowo\MailKitBundle\Sender\SenderProviderInterface", which was removed in MailKit 2.0. Decorate "Nowo\MailKitBundle\Sender\SenderIdentityProviderInterface" instead and implement it (see UPGRADING.md "To 1.12.0" and "To 2.0.0").');
        (new SenderPortPass())->process($container);
    }

    public function testADefinitionThatCouldNotLoadBecauseOfTheRemovedPortIsRefused(): void
    {
        $container = $this->host(MailSettings::class, MailSettings::class);
        $container->setDefinition('App\Mail\Other', (new Definition('App\Mail\Other'))->addError('Class "App\Mail\Other" not found while loading "App\".'));
        $container->setDefinition('App\Mail\LegacySender', (new Definition('App\Mail\LegacySender'))->addError('Interface "Nowo\MailKitBundle\Sender\SenderProviderInterface" not found while loading "App\Mail\LegacySender".'));

        $this->expectException(LogicException::class);
        $this->expectExceptionMessage('The service "App\Mail\LegacySender" cannot be loaded: Interface "Nowo\MailKitBundle\Sender\SenderProviderInterface" not found while loading "App\Mail\LegacySender". "Nowo\MailKitBundle\Sender\SenderProviderInterface" was removed in 2.0');
        (new SenderPortPass())->process($container);
    }

    public function testADecoratorOfTheRemovedPortIdIsRefusedWithoutASenderAlias(): void
    {
        $container = new ContainerBuilder();
        $this->decorate($container, 'app.decorator', IdentitySenderDecorator::class, SenderPortPass::REMOVED_PORT);

        $this->expectException(LogicException::class);
        $this->expectExceptionMessage('which was removed in MailKit 2.0');
        (new SenderPortPass())->process($container);
    }

    public function testDecoratorLoopsAndSharedDecoratorsAreVisitedOnce(): void
    {
        $container = $this->host('app.sender', StaticSenderIdentityProvider::class);
        $this->decorate($container, 'app.decorator', IdentitySenderDecorator::class, 'app.sender');
        $this->decorate($container, 'app.loop', IdentitySenderDecorator::class, 'app.loop');
        $this->decorate($container, 'app.second', IdentityOnlySenderDecorator::class, 'app.decorator');
        $this->decorate($container, 'app.third', IdentityOnlySenderDecorator::class, 'app.second');
        $this->decorate($container, 'app.fourth', IdentityOnlySenderDecorator::class, 'app.decorator');
        (new SenderPortPass())->process($container);

        self::assertSame('app.sender', (string) $container->getAlias(SenderIdentityProviderInterface::class));
    }

    public function testACircularAliasChainDoesNotLoop(): void
    {
        $container = new ContainerBuilder();
        $container->setAlias(SenderIdentityProviderInterface::class, 'app.a');
        $container->setAlias('app.a', 'app.b');
        $container->setAlias('app.b', 'app.a');
        (new SenderPortPass())->process($container);

        self::assertSame('app.a', (string) $container->getAlias(SenderIdentityProviderInterface::class));
    }

    public function testASelfDecoratingSenderDoesNotLoop(): void
    {
        $container = $this->host('app.sender', StaticSenderIdentityProvider::class);
        $container->getDefinition('app.sender')->setDecoratedService('app.sender');
        (new SenderPortPass())->process($container);

        self::assertSame('app.sender', (string) $container->getAlias(SenderIdentityProviderInterface::class));
    }

    private function host(string $id, ?string $class): ContainerBuilder
    {
        $container = new ContainerBuilder();
        $container->setDefinition($id, new Definition($class));
        $container->setAlias(SenderIdentityProviderInterface::class, $id);

        return $container;
    }

    private function decorate(ContainerBuilder $container, string $id, string $class, string $decorated): void
    {
        $container->setDefinition($id, (new Definition($class, [new Reference($id . '.inner')]))->setDecoratedService($decorated));
    }

    private function consumers(ContainerBuilder $container): void
    {
        $container->setDefinition(SenderPolicy::class, (new Definition(SenderPolicy::class, [new Reference(SenderIdentityProviderInterface::class)]))->setPublic(true));
        $container->addCompilerPass(new SenderPortPass());
    }

    private function compiledMail(ContainerBuilder $container): Email
    {
        $container->compile(true);
        $policy = $container->get(SenderPolicy::class);
        self::assertInstanceOf(SenderPolicy::class, $policy);
        $email = (new Email())->to('a@example.com')->text('Hi');
        $policy->apply($email);

        return $email;
    }
}
