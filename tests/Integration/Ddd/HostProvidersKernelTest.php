<?php

declare(strict_types=1);

namespace Nowo\MailKitBundle\Tests\Integration\Ddd;

use DateTimeImmutable;
use Doctrine\ORM\EntityManagerInterface;
use LogicException;
use Nowo\InstanceSettingsBundle\Settings\InstanceSettingsInterface;
use Nowo\MailKitBundle\DependencyInjection\NowoMailKitExtension;
use Nowo\MailKitBundle\Doctrine\TablePrefixListener;
use Nowo\MailKitBundle\EventListener\SettingsChangedListener;
use Nowo\MailKitBundle\Log\NullSentMailErasure;
use Nowo\MailKitBundle\Log\SentMailErasureInterface;
use Nowo\MailKitBundle\Readiness\OutboundMailReadinessInterface;
use Nowo\MailKitBundle\Settings\MailerSettingsSection;
use Nowo\MailKitBundle\Settings\MailSettings;
use Nowo\MailKitBundle\TestMail\TestMailOutcome;
use Nowo\MailKitBundle\TestMail\TestMailSender;
use Nowo\MailKitBundle\Tests\Integration\Kernel\KernelTestCase;
use Nowo\MailKitBundle\Tests\Integration\Kernel\MailKitTestKernel;
use Nowo\MailKitBundle\Tests\Support\RequestTenantSenderProvider;
use Nowo\MailKitBundle\Tests\Support\SendMailController;
use Nowo\MailKitBundle\Tests\Support\StaticDsnProvider;
use Symfony\Component\DependencyInjection\Loader\Configurator\ContainerConfigurator;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\HttpKernel;
use Symfony\Component\Mime\Email;
use Symfony\Component\Routing\Loader\Configurator\RoutingConfigurator;
use Symfony\Component\Routing\RouterInterface;

use function array_keys;
use function gc_collect_cycles;
use function interface_exists;
use function memory_get_usage;
use function str_contains;
use function Symfony\Component\DependencyInjection\Loader\Configurator\service;

/**
 * 1.6 (architecture bar rule 8): a strict DDD host that provides the DSN and sender ports boots MailKit without
 * Instance Settings and without Doctrine ORM, and serves many requests in one kept-alive kernel (FrankenPHP worker
 * mode, kernel reset off: `http_kernel` handle / terminate, no services resetter).
 *
 * 2.0: Instance Settings, DoctrineEncrypt and the ORM are suggested, not required. CI runs this class with the three
 * packages removed (job "Without Instance Settings, DoctrineEncrypt and the ORM"), so it must not touch their classes.
 */
final class HostProvidersKernelTest extends KernelTestCase
{
    private const HOST_CONFIG = [
        'mailer' => ['dsn_provider' => StaticDsnProvider::class, 'sender_provider' => RequestTenantSenderProvider::class],
        'log'    => ['enabled' => false],
    ];

    public function testBootsWithoutInstanceSettingsAndWithoutTheOrm(): void
    {
        $kernel    = $this->bootHost();
        $container = $this->container($kernel);

        self::assertFalse($container->has('doctrine'));
        self::assertFalse($container->has(InstanceSettingsInterface::class));
        self::assertFalse($container->has(MailSettings::class));
        self::assertFalse($container->has(MailerSettingsSection::class));
        self::assertFalse($container->has(SettingsChangedListener::class));
        self::assertFalse($container->getParameter(NowoMailKitExtension::INSTANCE_SETTINGS_PARAMETER));

        $this->mailer($kernel)->send((new Email())->to('a@example.net')->subject('Hi')->text('Hi'));
        $sent = $this->store($kernel)->sentVia('default');
        self::assertCount(1, $sent);
        $message = $sent[0]->getOriginalMessage();
        self::assertInstanceOf(Email::class, $message);
        self::assertSame('cli@example.com', $message->getFrom()[0]->getAddress());

        $readiness = $this->service($kernel, OutboundMailReadinessInterface::class);
        self::assertTrue($readiness->isOutboundMailReady());
        self::assertSame([], $readiness->missingFields());

        $erasure = $this->service($kernel, SentMailErasureInterface::class);
        self::assertInstanceOf(NullSentMailErasure::class, $erasure);
        self::assertSame(['deleted' => 0, 'unlinked' => 0, 'redacted' => 0], $erasure->forgetAddress('a@example.net'));
        self::assertSame(0, $erasure->purgeBefore(new DateTimeImmutable('+1 day')));

        // The CLI / programmatic test e-mail uses the host sender; no Resend without the settings.
        $testMail = $this->service($kernel, TestMailSender::class);
        self::assertSame(TestMailOutcome::Sent, $testMail->sendMailer('ops@example.net')->outcome);
        self::assertSame(TestMailOutcome::NotConfigured, $testMail->sendResend('ops@example.net')->outcome);

        // Admin UI on (default) but nothing to show: no settings actions without Instance Settings, no log pages.
        $router = $this->service($kernel, RouterInterface::class);
        foreach (array_keys($router->getRouteCollection()->all()) as $name) {
            self::assertFalse(str_contains($name, 'nowo_mail_kit'), $name);
        }
    }

    public function testBootsWithDoctrineButWithoutTheOrm(): void
    {
        $kernel    = $this->bootHost(['orm']);
        $container = $this->container($kernel);

        self::assertTrue($container->has('doctrine'));
        self::assertFalse($container->has('doctrine.orm.entity_manager'));
        self::assertFalse($container->has(TablePrefixListener::class));
        self::assertInstanceOf(NullSentMailErasure::class, $this->service($kernel, SentMailErasureInterface::class));

        $this->mailer($kernel)->send((new Email())->to('a@example.net')->subject('Hi')->text('Hi'));
        self::assertCount(1, $this->store($kernel)->sentVia('default'));
    }

    /**
     * Q7: a host that lost Instance Settings (it came with MailKit 1.x) and configured no DSN / sender port must not
     * fall back silently to the framework transport: the build fails and says what to install.
     */
    public function testWithoutInstanceSettingsOrHostPortsTheBuildFails(): void
    {
        try {
            $this->bootKernel(['log' => ['enabled' => false]], without: ['instance_settings', 'doctrine']);
            self::fail('The build must fail without Instance Settings and without host ports.');
        } catch (LogicException $e) {
            self::assertStringContainsString('composer require nowo-tech/instance-settings-bundle', $e->getMessage());
            self::assertStringContainsString('nowo_mail_kit.mailer.dsn_provider and nowo_mail_kit.mailer.sender_provider', $e->getMessage());
        }
    }

    /**
     * Without doctrine/orm installed (the CI job without the suggested packages) the log, on by default, fails the
     * build with what to do; with the ORM installed MailKit configures it for the log mapping, as in 1.x.
     */
    public function testTheLogOnNeedsTheOrm(): void
    {
        $boot = fn (): MailKitTestKernel => $this->bootKernel(['log' => ['enabled' => true]] + self::HOST_CONFIG, without: ['instance_settings', 'orm'], extra: $this->hostServices(...));
        if (interface_exists(EntityManagerInterface::class)) {
            self::assertTrue($this->container($boot())->has('doctrine.orm.entity_manager'));

            return;
        }

        $this->expectException(LogicException::class);
        $this->expectExceptionMessage(NowoMailKitExtension::LOG_NEEDS_ORM);
        $boot();
    }

    public function testKeptAliveKernelServesManyTenantsWithoutLeakingState(): void
    {
        $kernel = $this->bootHost();
        $http   = $kernel->getContainer()->get('http_kernel');
        self::assertInstanceOf(HttpKernel::class, $http);
        $store  = $this->store($kernel);
        $before = 0;

        for ($i = 0; $i < 600; ++$i) {
            $tenant   = $i % 2 === 0 ? 'acme' : 'globex';
            $request  = Request::create('/send/' . $tenant);
            $response = $http->handle($request);
            self::assertSame(200, $response->getStatusCode());
            $http->terminate($request, $response);

            self::assertCount(1, $store->sent);
            $message = $store->sent[0]['message']->getOriginalMessage();
            self::assertInstanceOf(Email::class, $message);
            self::assertSame($tenant . '@example.com', $message->getFrom()[0]->getAddress());
            $store->sent = [];

            if ($i === 99) {
                gc_collect_cycles();
                $before = memory_get_usage();
            }
        }
        gc_collect_cycles();
        self::assertLessThan(2 * 1024 * 1024, memory_get_usage() - $before, 'Memory must stay flat over 500 requests.');

        // Outside a request again: nothing of the last tenant is left in a service.
        $this->mailer($kernel)->send((new Email())->to('a@example.net')->subject('Hi')->text('Hi'));
        $message = $store->sentVia('default')[0]->getOriginalMessage();
        self::assertInstanceOf(Email::class, $message);
        self::assertSame('cli@example.com', $message->getFrom()[0]->getAddress());
    }

    /**
     * @param list<string> $without `doctrine` (no DoctrineBundle) or `orm` (DoctrineBundle with the DBAL only)
     */
    private function bootHost(array $without = ['doctrine']): MailKitTestKernel
    {
        return $this->bootKernel(
            self::HOST_CONFIG,
            without: ['instance_settings', ...$without],
            routes: static function (RoutingConfigurator $routes): void {
                $routes->import('.', 'nowo_mail_kit');
                $routes->add('host_send', '/send/{tenant}')->controller(SendMailController::class);
            },
            extra: $this->hostServices(...),
        );
    }

    private function hostServices(ContainerConfigurator $container): void
    {
        $services = $container->services();
        $services->set(StaticDsnProvider::class)->args(['memory://default']);
        $services->set(RequestTenantSenderProvider::class)->args([service('request_stack')]);
        $services->set(SendMailController::class)->args([service('mailer.mailer')])->public()->tag('controller.service_arguments');
    }
}
