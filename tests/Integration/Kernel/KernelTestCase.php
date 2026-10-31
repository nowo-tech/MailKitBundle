<?php

declare(strict_types=1);

namespace Nowo\MailKitBundle\Tests\Integration\Kernel;

use Closure;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Tools\SchemaTool;
use Doctrine\Persistence\ManagerRegistry;
use Nowo\InstanceSettingsBundle\Settings\InstanceSettingsInterface;
use Nowo\MailKitBundle\Tests\Support\MemoryMailStore;
use Nowo\MailKitBundle\Tests\Support\MemoryTransportFactory;
use PHPUnit\Framework\TestCase;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\DependencyInjection\Loader\Configurator\ContainerConfigurator;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\Mailer\MailerInterface;
use Symfony\Component\Routing\Loader\Configurator\RoutingConfigurator;

use function count;
use function in_array;
use function preg_replace;
use function Symfony\Component\DependencyInjection\Loader\Configurator\service;

/**
 * Boots a fresh {@see MailKitTestKernel} per call (own cache dir, schema created on the in-memory database). The
 * `memory` test transport is registered and allowed (`dsn.allowed_schemes`) unless the test overrides the list.
 */
abstract class KernelTestCase extends TestCase
{
    /** @var list<MailKitTestKernel> */
    private array $kernels = [];

    protected function tearDown(): void
    {
        foreach ($this->kernels as $kernel) {
            $kernel->shutdown();
        }
        $this->kernels = [];
        (new Filesystem())->remove(MailKitTestKernel::baseDir());
    }

    /**
     * @param array<string, mixed> $config nowo_mail_kit configuration
     * @param array<string, mixed> $settingsConfig nowo_instance_settings configuration
     * @param list<string> $without bundles / features to leave out
     * @param Closure(RoutingConfigurator): void|null $routes routes (default: both bundle loaders)
     * @param Closure(ContainerConfigurator): void|null $extra extra container configuration
     */
    protected function bootKernel(array $config = [], array $settingsConfig = [], array $without = [], ?Closure $routes = null, bool $debug = false, ?Closure $extra = null): MailKitTestKernel
    {
        $config += ['dsn' => ['allowed_schemes' => ['memory', 'smtp', 'smtps'], 'allowed_hosts' => ['fail', 'leak'], 'resolve_dns' => false]];
        $id     = (string) preg_replace('/\W+/', '_', static::class . '_' . $this->name() . '_' . count($this->kernels));
        $kernel = new MailKitTestKernel(
            $id,
            static function (ContainerConfigurator $container) use ($config, $settingsConfig, $without, $extra): void {
                if (!in_array('instance_settings', $without, true)) {
                    $container->extension('nowo_instance_settings', $settingsConfig);
                }
                $container->extension('nowo_mail_kit', $config);
                $services = $container->services();
                $services->set(MemoryMailStore::class)->public();
                $services->alias('test.mail_kit.mailer', 'mailer.mailer')->public();
                $services->set(MemoryTransportFactory::class)->args([service(MemoryMailStore::class)])->tag('mailer.transport_factory');
                if ($extra instanceof Closure) {
                    $extra($container);
                }
            },
            $routes ?? static function (RoutingConfigurator $routes): void {
                $routes->import('.', 'nowo_instance_settings');
                $routes->import('.', 'nowo_mail_kit');
            },
            $without,
            $debug,
        );
        $this->kernels[] = $kernel;
        $kernel->boot();

        $doctrine = $kernel->getContainer()->get('doctrine');
        self::assertInstanceOf(ManagerRegistry::class, $doctrine);
        $entityManager = $doctrine->getManager();
        if ($entityManager instanceof EntityManagerInterface) {
            $metadata = $entityManager->getMetadataFactory()->getAllMetadata();
            $tool     = new SchemaTool($entityManager);
            $tool->dropSchema($metadata);
            $tool->createSchema($metadata);
        }

        return $kernel;
    }

    protected function container(MailKitTestKernel $kernel): ContainerInterface
    {
        /** @var ContainerInterface $container */
        $container = $kernel->getContainer()->get('test.service_container');

        return $container;
    }

    /**
     * Browser that keeps the session (CSRF tokens) and the in-memory database (no kernel reboot) between requests.
     */
    protected function client(MailKitTestKernel $kernel, ?string $user = 'admin'): KernelBrowser
    {
        /** @var KernelBrowser $client */
        $client = $kernel->getContainer()->get('test.client');
        $client->disableReboot();
        $client->catchExceptions(true);
        $client->setServerParameters($user === null ? ['REMOTE_ADDR' => '203.0.113.9'] : ['REMOTE_ADDR' => '203.0.113.9', 'PHP_AUTH_USER' => $user, 'PHP_AUTH_PW' => $user]);

        return $client;
    }

    protected function settings(MailKitTestKernel $kernel): InstanceSettingsInterface
    {
        $settings = $this->container($kernel)->get(InstanceSettingsInterface::class);
        self::assertInstanceOf(InstanceSettingsInterface::class, $settings);

        return $settings;
    }

    protected function store(MailKitTestKernel $kernel): MemoryMailStore
    {
        $store = $this->container($kernel)->get(MemoryMailStore::class);
        self::assertInstanceOf(MemoryMailStore::class, $store);

        return $store;
    }

    protected function mailer(MailKitTestKernel $kernel): MailerInterface
    {
        $mailer = $this->container($kernel)->get('test.mail_kit.mailer');
        self::assertInstanceOf(MailerInterface::class, $mailer);

        return $mailer;
    }

    protected function connection(MailKitTestKernel $kernel): Connection
    {
        $connection = $this->container($kernel)->get('doctrine.dbal.default_connection');
        self::assertInstanceOf(Connection::class, $connection);

        return $connection;
    }

    /**
     * @template T of object
     *
     * @param class-string<T> $class
     *
     * @return T
     */
    protected function service(MailKitTestKernel $kernel, string $class): object
    {
        $service = $this->container($kernel)->get($class);
        self::assertInstanceOf($class, $service);

        return $service;
    }
}
