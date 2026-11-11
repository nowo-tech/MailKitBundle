<?php

declare(strict_types=1);

namespace Nowo\MailKitBundle\Tests\Integration\Ddd;

use DateTimeImmutable;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Tools\SchemaTool;
use Nowo\InstanceSettingsBundle\Settings\InstanceSettingsInterface;
use Nowo\MailKitBundle\DependencyInjection\NowoMailKitExtension;
use Nowo\MailKitBundle\Entity\SentMail;
use Nowo\MailKitBundle\EventListener\SettingsChangedListener;
use Nowo\MailKitBundle\Log\NullSentMailErasure;
use Nowo\MailKitBundle\Log\SentMailErasureInterface;
use Nowo\MailKitBundle\Log\SentMailRecorder;
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
use Nowo\MailKitBundle\Tests\Support\StaticSenderProvider;
use Symfony\Component\DependencyInjection\Loader\Configurator\ContainerConfigurator;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\HttpKernel;
use Symfony\Component\Mime\Email;
use Symfony\Component\Routing\Loader\Configurator\RoutingConfigurator;
use Symfony\Component\Routing\RouterInterface;

use function array_keys;
use function gc_collect_cycles;
use function memory_get_usage;
use function str_contains;
use function Symfony\Component\DependencyInjection\Loader\Configurator\service;

/**
 * 1.6 (architecture bar rule 8): a strict DDD host that provides the DSN and sender ports boots MailKit without
 * Instance Settings and without Doctrine ORM, and serves many requests in one kept-alive kernel (FrankenPHP worker
 * mode, kernel reset off: `http_kernel` handle / terminate, no services resetter).
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
        self::assertSame(['deleted' => 0, 'redacted' => 0], $erasure->forgetAddress('a@example.net'));
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
     * LL-MK-M1: a host that turns the log off after it was on keeps its tables (no schema diff against 1.5.0) and its
     * erasure / retention really delete the rows written while the log was on.
     */
    public function testStandardHostWithTheLogOffKeepsTheMappingAndReallyErases(): void
    {
        $kernel  = $this->bootKernel(['log' => ['enabled' => false]]);
        $manager = $this->entityManager($kernel);
        self::assertContains(SentMail::class, $this->mappedClasses($manager));
        self::assertSame([], (new SchemaTool($manager))->getUpdateSchemaSql($manager->getMetadataFactory()->getAllMetadata()));
        self::assertTrue($this->container($kernel)->getParameter(NowoMailKitExtension::INSTANCE_SETTINGS_PARAMETER));

        // Rows from when the log was on.
        $recorder = $this->service($kernel, SentMailRecorder::class);
        $recorder->sent(null, (new Email())->from('ops@example.com')->to('victim@example.net')->subject('Hi')->text('Hi'), 'memory', null, 10);
        $recorder->sent(null, (new Email())->from('ops@example.com')->to('other@example.net')->subject('Hi')->text('Hi'), 'memory', null, 10);
        $connection = $this->connection($kernel);
        self::assertSame(2, (int) $connection->fetchOne('SELECT COUNT(*) FROM mail_kit_sent_mail'));

        // The log is off: a send records nothing.
        $this->mailer($kernel)->send((new Email())->from('ops@example.com')->to('victim@example.net')->subject('Hi')->text('Hi'));
        self::assertSame(2, (int) $connection->fetchOne('SELECT COUNT(*) FROM mail_kit_sent_mail'));

        $erasure = $this->service($kernel, SentMailErasureInterface::class);
        self::assertSame($recorder, $erasure);
        self::assertSame(['deleted' => 1, 'redacted' => 0], $erasure->forgetAddress('victim@example.net'));
        self::assertSame(1, $erasure->purgeBefore(new DateTimeImmutable('+1 day')));
        self::assertSame(0, (int) $connection->fetchOne('SELECT COUNT(*) FROM mail_kit_sent_mail'));
    }

    public function testMapEntitiesOffKeepsTheLogOutOfTheEntityManager(): void
    {
        $kernel  = $this->bootKernel(['log' => ['enabled' => false, 'map_entities' => false]]);
        $manager = $this->entityManager($kernel);
        self::assertNotContains(SentMail::class, $this->mappedClasses($manager));

        // No log tables: nothing stored, nothing to erase (no SQL error).
        $erasure = $this->service($kernel, SentMailErasureInterface::class);
        self::assertSame(['deleted' => 0, 'redacted' => 0], $erasure->forgetAddress('victim@example.net'));
        self::assertSame(0, $erasure->purgeBefore(new DateTimeImmutable('+1 day')));
    }

    /**
     * LL-MK-L2: with Instance Settings and a host sender port, the test e-mail uses the port, like real mail.
     */
    public function testTheTestEmailUsesTheHostSenderPortWithInstanceSettings(): void
    {
        $kernel = $this->bootKernel(['mailer' => ['sender_provider' => StaticSenderProvider::class]], extra: static function (ContainerConfigurator $container): void {
            $container->services()->set(StaticSenderProvider::class);
        });

        self::assertSame(TestMailOutcome::Sent, $this->service($kernel, TestMailSender::class)->sendMailer('ops@example.net')->outcome);
        $message = $this->store($kernel)->sentVia('fallback')[0]->getOriginalMessage();
        self::assertInstanceOf(Email::class, $message);
        self::assertSame('tenant@example.com', $message->getFrom()[0]->getAddress());
    }

    public function testStandardHostWithTheLogOnErasesThroughThePort(): void
    {
        $kernel = $this->bootKernel();
        self::assertInstanceOf(SentMailRecorder::class, $this->service($kernel, SentMailErasureInterface::class));
        $this->mailer($kernel)->send((new Email())->from('ops@example.com')->to('a@example.net')->subject('Hi')->text('Hi'));
        self::assertSame(['deleted' => 1, 'redacted' => 0], $this->service($kernel, SentMailErasureInterface::class)->forgetAddress('a@example.net'));
    }

    private function entityManager(MailKitTestKernel $kernel): EntityManagerInterface
    {
        $manager = $this->container($kernel)->get('doctrine.orm.entity_manager');
        self::assertInstanceOf(EntityManagerInterface::class, $manager);

        return $manager;
    }

    /**
     * @return list<string>
     */
    private function mappedClasses(EntityManagerInterface $manager): array
    {
        $classes = [];
        foreach ($manager->getMetadataFactory()->getAllMetadata() as $metadata) {
            $classes[] = $metadata->getName();
        }

        return $classes;
    }

    private function bootHost(): MailKitTestKernel
    {
        return $this->bootKernel(
            self::HOST_CONFIG,
            without: ['instance_settings', 'doctrine'],
            routes: static function (RoutingConfigurator $routes): void {
                $routes->import('.', 'nowo_mail_kit');
                $routes->add('host_send', '/send/{tenant}')->controller(SendMailController::class);
            },
            extra: static function (ContainerConfigurator $container): void {
                $services = $container->services();
                $services->set(StaticDsnProvider::class)->args(['memory://default']);
                $services->set(RequestTenantSenderProvider::class)->args([service('request_stack')]);
                $services->set(SendMailController::class)->args([service('mailer.mailer')])->public()->tag('controller.service_arguments');
            },
        );
    }
}
