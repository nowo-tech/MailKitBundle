<?php

declare(strict_types=1);

namespace Nowo\MailKitBundle\Tests\Integration\Ddd;

use Nowo\MailKitBundle\Sender\SenderIdentityProviderInterface;
use Nowo\MailKitBundle\Sender\SenderProviderGate;
use Nowo\MailKitBundle\Sender\SenderProviderInterface;
use Nowo\MailKitBundle\Settings\MailSettings;
use Nowo\MailKitBundle\Tests\Integration\Kernel\KernelTestCase;
use Nowo\MailKitBundle\Tests\Support\DualSenderProvider;
use Nowo\MailKitBundle\Tests\Support\StaticSenderIdentityProvider;
use Nowo\MailKitBundle\Tests\Support\StaticSenderProvider;
use Symfony\Component\DependencyInjection\Loader\Configurator\ContainerConfigurator;
use Symfony\Component\Mime\Email;

/**
 * 1.12 in a real kernel: `mailer.sender_provider` set to a host service that implements only the framework-free
 * {@see SenderIdentityProviderInterface} — the gate serves the rest of the bundle, and the
 * test e-mail uses the same sender as real mail.
 */
final class SenderIdentityPortKernelTest extends KernelTestCase
{
    public function testAHostIdentityProviderFillsTheSenderAndTheTestMail(): void
    {
        $kernel = $this->bootKernel(['mailer' => ['sender_provider' => StaticSenderIdentityProvider::class]], extra: static function (ContainerConfigurator $container): void {
            $container->services()->set(StaticSenderIdentityProvider::class);
            $container->services()->alias('test.mail_kit.sender_provider', SenderProviderInterface::class)->public();
            $container->services()->alias('test.mail_kit.sender_identity', SenderIdentityProviderInterface::class)->public();
        });
        $this->settings($kernel)->save('mailer', ['dsn' => 'memory://default', 'from' => 'settings@example.com', 'test_recipient' => 'ops@example.com']);

        $gate = $this->container($kernel)->get('test.mail_kit.sender_provider');
        self::assertInstanceOf(SenderProviderGate::class, $gate);
        // The new port autowires straight to the host service.
        self::assertInstanceOf(StaticSenderIdentityProvider::class, $this->container($kernel)->get('test.mail_kit.sender_identity'));

        $this->mailer($kernel)->send((new Email())->to('a@example.com')->subject('Invite')->text('Hi'));

        $sent = $this->store($kernel)->sentVia('default')[0]->getOriginalMessage();
        self::assertInstanceOf(Email::class, $sent);
        self::assertSame('"Tenant" <tenant@example.com>', $sent->getFrom()[0]->toString());
        self::assertSame('tenant-help@example.com', $sent->getReplyTo()[0]->getAddress());
    }

    public function testTheNewPortWinsWhenTheHostClassImplementsBoth(): void
    {
        $kernel = $this->bootKernel(['mailer' => ['sender_provider' => DualSenderProvider::class]], extra: static function (ContainerConfigurator $container): void {
            $container->services()->set(DualSenderProvider::class);
            $container->services()->alias('test.mail_kit.sender_identity', SenderIdentityProviderInterface::class)->public();
        });
        $this->settings($kernel)->save('mailer', ['dsn' => 'memory://default']);

        self::assertInstanceOf(DualSenderProvider::class, $this->container($kernel)->get('test.mail_kit.sender_identity'));

        $this->mailer($kernel)->send((new Email())->to('a@example.com')->subject('Invite')->text('Hi'));

        $sent = $this->store($kernel)->sentVia('default')[0]->getOriginalMessage();
        self::assertInstanceOf(Email::class, $sent);
        self::assertSame('"Identity" <identity@example.com>', $sent->getFrom()[0]->toString());
        self::assertSame('identity-help@example.com', $sent->getReplyTo()[0]->getAddress());
    }

    public function testADeprecatedOnlyHostKeepsItsSenderAndTheNewPortStillAutowires(): void
    {
        $kernel = $this->bootKernel(['mailer' => ['sender_provider' => StaticSenderProvider::class]], extra: static function (ContainerConfigurator $container): void {
            $container->services()->set(StaticSenderProvider::class);
            $container->services()->alias('test.mail_kit.sender_provider', SenderProviderInterface::class)->public();
            $container->services()->alias('test.mail_kit.sender_identity', SenderIdentityProviderInterface::class)->public();
        });
        $this->settings($kernel)->save('mailer', ['dsn' => 'memory://default']);

        self::assertInstanceOf(StaticSenderProvider::class, $this->container($kernel)->get('test.mail_kit.sender_provider'));
        $identity = $this->container($kernel)->get('test.mail_kit.sender_identity');
        self::assertInstanceOf(SenderProviderGate::class, $identity);
        self::assertSame('tenant@example.com', $identity->defaultFrom()?->email);

        $this->mailer($kernel)->send((new Email())->to('a@example.com')->subject('Invite')->text('Hi'));

        $sent = $this->store($kernel)->sentVia('default')[0]->getOriginalMessage();
        self::assertInstanceOf(Email::class, $sent);
        self::assertSame('"Tenant" <tenant@example.com>', $sent->getFrom()[0]->toString());
    }

    public function testAStandardHostGetsTheSettingsOnBothPortsWithoutTheGate(): void
    {
        $kernel = $this->bootKernel(extra: static function (ContainerConfigurator $container): void {
            $container->services()->alias('test.mail_kit.sender_provider', SenderProviderInterface::class)->public();
            $container->services()->alias('test.mail_kit.sender_identity', SenderIdentityProviderInterface::class)->public();
        });

        self::assertInstanceOf(MailSettings::class, $this->container($kernel)->get('test.mail_kit.sender_provider'));
        self::assertInstanceOf(MailSettings::class, $this->container($kernel)->get('test.mail_kit.sender_identity'));
    }
}
