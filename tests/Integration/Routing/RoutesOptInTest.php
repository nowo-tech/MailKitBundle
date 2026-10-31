<?php

declare(strict_types=1);

namespace Nowo\MailKitBundle\Tests\Integration\Routing;

use Nowo\MailKitBundle\Controller\MailKitController;
use Nowo\MailKitBundle\Tests\Integration\Kernel\KernelTestCase;
use Symfony\Component\Routing\Loader\AttributeServicesLoader;
use Symfony\Component\Routing\Loader\Configurator\RoutingConfigurator;
use Symfony\Component\Routing\RouterInterface;

use function class_exists;
use function is_string;
use function str_starts_with;

/**
 * REQ-SF-004 rule 7: a host whose routing is only `routing.controllers` (Symfony >= 7.3 default config/routes.yaml)
 * gets no bundle route; the routes exist only through the `nowo_mail_kit` loader import.
 */
final class RoutesOptInTest extends KernelTestCase
{
    public function testRoutingControllersDoesNotMountTheBundleRoutes(): void
    {
        if (!class_exists(AttributeServicesLoader::class)) {
            self::markTestSkipped('routing.controllers requires symfony/routing >= 7.3.');
        }

        $kernel = $this->bootKernel(routes: static function (RoutingConfigurator $routes): void {
            $routes->import('routing.controllers');
        });
        self::assertTrue($this->container($kernel)->has(MailKitController::class));

        /** @var RouterInterface $router */
        $router = $kernel->getContainer()->get('router');
        foreach ($router->getRouteCollection()->all() as $name => $route) {
            self::assertStringStartsNotWith('nowo_mail_kit', $name);
            $controller = $route->getDefault('_controller');
            self::assertFalse(is_string($controller) && str_starts_with($controller, MailKitController::class), $name);
        }
    }

    public function testTheLoaderImportMountsThemUnderThePrefix(): void
    {
        $kernel = $this->bootKernel(['web_ui' => ['path_prefix' => '/ops/mail'], 'resend' => ['enabled' => true], 'log' => ['store_bodies' => true]]);
        /** @var RouterInterface $router */
        $router = $kernel->getContainer()->get('router');
        $routes = $router->getRouteCollection();

        self::assertSame('/ops/mail/log', $routes->get('nowo_mail_kit_log')?->getPath());
        self::assertSame('/ops/mail/log/{id}/body', $routes->get('nowo_mail_kit_log_body')?->getPath());
        self::assertSame('/ops/mail/actions/resend/check', $routes->get('nowo_mail_kit_resend_check')?->getPath());
        self::assertSame(['POST'], $routes->get('nowo_mail_kit_test')?->getMethods());
    }

    public function testNoRouteWithoutTheAdminUi(): void
    {
        $kernel = $this->bootKernel(['web_ui' => ['enabled' => false]]);
        /** @var RouterInterface $router */
        $router = $kernel->getContainer()->get('router');

        self::assertNull($router->getRouteCollection()->get('nowo_mail_kit_log'));
        self::assertFalse($this->container($kernel)->has(MailKitController::class));
    }
}
