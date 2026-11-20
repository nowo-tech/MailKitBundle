<?php

declare(strict_types=1);

namespace Nowo\MailKitBundle\Tests\Unit\DependencyInjection\Compiler;

use Nowo\MailKitBundle\DependencyInjection\Compiler\SenderPortPass;
use Nowo\MailKitBundle\Sender\SenderProviderInterface;
use Nowo\MailKitBundle\Settings\MailSettings;
use Nowo\MailKitBundle\Tests\Support\DeprecationRecorder;
use Nowo\MailKitBundle\Tests\Support\DualSenderProvider;
use Nowo\MailKitBundle\Tests\Support\IdentityOnlySenderDecorator;
use Nowo\MailKitBundle\Tests\Support\LegacySenderDecorator;
use Nowo\MailKitBundle\Tests\Support\StaticSenderIdentityProvider;
use Nowo\MailKitBundle\Tests\Support\StaticSenderProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Definition;

use function sprintf;

/**
 * 1.14 (2.0 deprecation layer): `SenderProviderInterface` is removed in 2.0, so every host class behind the sender
 * that implements it gets one build-time notice; MailKit's own implementations and new-port-only classes get none.
 */
final class SenderPortDeprecationTest extends TestCase
{
    public function testAConfiguredServiceOnTheDeprecatedPortIsReported(): void
    {
        $container = $this->container('app.sender', StaticSenderProvider::class);

        self::assertSame([sprintf(
            'Since nowo-tech/mail-kit-bundle 1.14: The sender service "app.sender" (%s) set in "nowo_mail_kit.mailer.sender_provider" implements "%s", which is deprecated and removed in 2.0. Implement "Nowo\MailKitBundle\Sender\SenderIdentityProviderInterface" instead (it returns "Nowo\MailKitBundle\Sender\MailAddress" rather than a Symfony Mime Address; see UPGRADING.md "To 1.12.0").',
            StaticSenderProvider::class,
            SenderProviderInterface::class,
        )], $this->process($container));
    }

    public function testAClassImplementingBothPortsIsReportedToo(): void
    {
        $messages = $this->process($this->container('app.sender', DualSenderProvider::class));

        self::assertCount(1, $messages);
        self::assertStringContainsString(DualSenderProvider::class, $messages[0]);
    }

    public function testANewPortServiceAndTheBuiltInDefaultAreNotReported(): void
    {
        self::assertSame([], $this->process($this->container('app.sender', StaticSenderIdentityProvider::class)));
        self::assertSame([], $this->process($this->container(MailSettings::class, MailSettings::class)));
    }

    public function testADeprecatedDecoratorIsReportedOncePerClass(): void
    {
        $container = $this->container(MailSettings::class, MailSettings::class);
        $container->setDefinition('app.first', (new Definition(LegacySenderDecorator::class))->setDecoratedService(SenderProviderInterface::class));
        $container->setDefinition('app.second', (new Definition(LegacySenderDecorator::class))->setDecoratedService('app.first'));
        $container->setDefinition('app.identity', (new Definition(IdentityOnlySenderDecorator::class))->setDecoratedService(SenderProviderInterface::class));

        $messages = $this->process($container);

        self::assertCount(1, $messages);
        self::assertStringStartsWith('Since nowo-tech/mail-kit-bundle 1.14: The service "app.', $messages[0]);
        self::assertStringContainsString('(' . LegacySenderDecorator::class . ') decorates the MailKit sender and implements "' . SenderProviderInterface::class . '", which is deprecated and removed in 2.0.', $messages[0]);
    }

    public function testADeprecatedDecoratorOfTheConfiguredChainIsReported(): void
    {
        $container = $this->container('app.sender', StaticSenderIdentityProvider::class);
        $container->setAlias('app.sender_alias', 'app.sender');
        $container->setAlias(SenderProviderInterface::class, 'app.sender_alias');
        $container->setDefinition('app.legacy', (new Definition(LegacySenderDecorator::class))->setDecoratedService('app.sender'));

        $messages = $this->process($container);

        self::assertCount(1, $messages);
        self::assertStringContainsString('The service "app.legacy" (' . LegacySenderDecorator::class . ')', $messages[0]);
    }

    public function testAServiceOfUnknowableClassIsNotReported(): void
    {
        $container = new ContainerBuilder();
        $container->setDefinition('app.sender', (new Definition(StaticSenderProvider::class))->setFactory([StaticSenderProvider::class, 'create']));
        $container->setAlias(SenderProviderInterface::class, 'app.sender');

        self::assertSame([], $this->process($container));
    }

    private function container(string $id, string $class): ContainerBuilder
    {
        $container = new ContainerBuilder();
        $container->setDefinition($id, new Definition($class));
        $container->setAlias(SenderProviderInterface::class, $id);

        return $container;
    }

    /**
     * @return list<string>
     */
    private function process(ContainerBuilder $container): array
    {
        return DeprecationRecorder::record(static fn () => (new SenderPortPass())->process($container));
    }
}
