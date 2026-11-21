<?php

declare(strict_types=1);

namespace Nowo\MailKitBundle\Tests\Integration\Mailer;

use LogicException;
use Nowo\MailKitBundle\Sender\SenderIdentityProviderInterface;
use Nowo\MailKitBundle\Settings\MailSettings;
use Nowo\MailKitBundle\TestMail\TestMailOutcome;
use Nowo\MailKitBundle\TestMail\TestMailSender;
use Nowo\MailKitBundle\Tests\Fixtures\RemovedPort\Autodiscovered\AutodiscoveredLegacySenderDecorator;
use Nowo\MailKitBundle\Tests\Fixtures\RemovedPort\RemovedPortSenderDecorator;
use Nowo\MailKitBundle\Tests\Fixtures\RemovedPort\RemovedPortSenderProvider;
use Nowo\MailKitBundle\Tests\Integration\Kernel\KernelTestCase;
use Nowo\MailKitBundle\Tests\Support\IdentityOnlySenderDecorator;
use Nowo\MailKitBundle\Tests\Support\IdentitySenderDecorator;
use Nowo\MailKitBundle\Tests\Support\StaticSenderIdentityProvider;
use Symfony\Component\DependencyInjection\Loader\Configurator\ContainerConfigurator;
use Symfony\Component\Mime\Email;

use function Symfony\Component\DependencyInjection\Loader\Configurator\service;

/**
 * The sender port in a real kernel (1.12; the only one since 2.0): a host service on `mailer.sender_provider` and its
 * decorators fill real mail and the test e-mail, and a 1.x class still on the removed `SenderProviderInterface` fails
 * the build with its name instead of a PHP fatal error.
 */
final class SenderIdentityPortKernelTest extends KernelTestCase
{
    public function testAHostIdentityProviderFillsTheSenderAndTheTestMail(): void
    {
        $kernel = $this->bootKernel(['mailer' => ['sender_provider' => StaticSenderIdentityProvider::class]], extra: static function (ContainerConfigurator $container): void {
            $container->services()->set(StaticSenderIdentityProvider::class);
            $container->services()->alias('test.mail_kit.sender_identity', SenderIdentityProviderInterface::class)->public();
        });
        $this->settings($kernel)->save('mailer', ['dsn' => 'memory://default', 'from' => 'settings@example.com', 'test_recipient' => 'ops@example.com']);

        self::assertInstanceOf(StaticSenderIdentityProvider::class, $this->container($kernel)->get('test.mail_kit.sender_identity'));

        $this->mailer($kernel)->send((new Email())->to('a@example.com')->subject('Invite')->text('Hi'));
        $sent = $this->store($kernel)->sentVia('default')[0]->getOriginalMessage();
        self::assertInstanceOf(Email::class, $sent);
        self::assertSame('"Tenant" <tenant@example.com>', $sent->getFrom()[0]->toString());
        self::assertSame('tenant-help@example.com', $sent->getReplyTo()[0]->getAddress());

        self::assertSame(TestMailOutcome::Sent, $this->service($kernel, TestMailSender::class)->sendMailer()->outcome);
        $test = $this->store($kernel)->sentVia('default')[1]->getOriginalMessage();
        self::assertInstanceOf(Email::class, $test);
        self::assertSame('tenant@example.com', $test->getFrom()[0]->getAddress());
    }

    public function testAStandardHostGetsTheSettingsOnThePort(): void
    {
        $kernel = $this->bootKernel(extra: static function (ContainerConfigurator $container): void {
            $container->services()->alias('test.mail_kit.sender_identity', SenderIdentityProviderInterface::class)->public();
        });

        self::assertInstanceOf(MailSettings::class, $this->container($kernel)->get('test.mail_kit.sender_identity'));
    }

    /**
     * Host decorators of the provider id and of the port id reach real mail and the port's consumers.
     */
    public function testHostDecoratorsReachThePortAndTheMail(): void
    {
        $cases = [
            [StaticSenderIdentityProvider::class, IdentitySenderDecorator::class, 'decorated-identity@example.com'],
            [SenderIdentityProviderInterface::class, IdentityOnlySenderDecorator::class, 'identity-only@example.com'],
        ];
        foreach ($cases as [$decorated, $decorator, $expected]) {
            $kernel = $this->bootKernel(['mailer' => ['sender_provider' => StaticSenderIdentityProvider::class]], extra: static function (ContainerConfigurator $container) use ($decorated, $decorator): void {
                $container->services()->set(StaticSenderIdentityProvider::class);
                $container->services()->set('app.sender.decorator', $decorator)->decorate($decorated)->args([service('.inner')]);
                $container->services()->alias('test.mail_kit.sender_identity', SenderIdentityProviderInterface::class)->public();
            });
            $this->settings($kernel)->save('mailer', ['dsn' => 'memory://default']);

            $identity = $this->container($kernel)->get('test.mail_kit.sender_identity');
            self::assertInstanceOf(SenderIdentityProviderInterface::class, $identity, $decorated);
            self::assertSame($expected, $identity->defaultFrom()?->email, $decorated);

            $this->mailer($kernel)->send((new Email())->to('a@example.com')->subject('Invite')->text('Hi'));
            $sent = $this->store($kernel)->sentVia('default')[0]->getOriginalMessage();
            self::assertInstanceOf(Email::class, $sent);
            self::assertSame($expected, $sent->getFrom()[0]->getAddress(), $decorated);
        }
    }

    public function testAHostSenderStillOnTheRemovedPortFailsTheBuildByName(): void
    {
        try {
            $this->bootKernel(['mailer' => ['sender_provider' => 'app.sender']], extra: static function (ContainerConfigurator $container): void {
                $container->services()->set('app.sender', RemovedPortSenderProvider::class);
            });
            self::fail('A sender on the removed port must fail the build.');
        } catch (LogicException $e) {
            self::assertStringContainsString('the service "app.sender" (' . RemovedPortSenderProvider::class . ') must implement "' . SenderIdentityProviderInterface::class . '"', $e->getMessage());
            self::assertStringContainsString('UPGRADING.md "To 1.12.0" and "To 2.0.0"', $e->getMessage());
        }
    }

    /**
     * Resource autodiscovery (`services.load()`) cannot load a class on the removed port: Symfony keeps a definition
     * with only an error and would drop it unused, so its `#[AsDecorator]` would be lost silently. The build fails.
     */
    public function testAnAutodiscoveredHostClassStillOnTheRemovedPortFailsTheBuild(): void
    {
        try {
            $this->bootKernel(extra: static function (ContainerConfigurator $container): void {
                $container->services()->defaults()->autowire()->autoconfigure()
                    ->load('Nowo\\MailKitBundle\\Tests\\Fixtures\\RemovedPort\\Autodiscovered\\', __DIR__ . '/../../Fixtures/RemovedPort/Autodiscovered/');
            });
            self::fail('An autodiscovered class on the removed port must fail the build.');
        } catch (LogicException $e) {
            self::assertStringContainsString('The service "' . AutodiscoveredLegacySenderDecorator::class . '" cannot be loaded', $e->getMessage());
            self::assertStringContainsString('Nowo\MailKitBundle\Sender\SenderProviderInterface', $e->getMessage());
            self::assertStringContainsString('UPGRADING.md "To 1.12.0" and "To 2.0.0"', $e->getMessage());
        }
    }

    public function testAHostDecoratorStillOnTheRemovedPortFailsTheBuildByName(): void
    {
        foreach ([SenderIdentityProviderInterface::class, 'Nowo\MailKitBundle\Sender\SenderProviderInterface'] as $decorated) {
            try {
                $this->bootKernel(extra: static function (ContainerConfigurator $container) use ($decorated): void {
                    $container->services()->set('app.legacy', RemovedPortSenderDecorator::class)->decorate($decorated)->args([service('.inner')]);
                });
                self::fail('A decorator on the removed port must fail the build.');
            } catch (LogicException $e) {
                self::assertStringContainsString('"app.legacy"', $e->getMessage(), $decorated);
                self::assertStringContainsString('2.0', $e->getMessage(), $decorated);
            }
        }
    }
}
