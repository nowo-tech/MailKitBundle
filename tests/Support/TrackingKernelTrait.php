<?php

declare(strict_types=1);

namespace Nowo\MailKitBundle\Tests\Support;

use Closure;
use Symfony\Component\DependencyInjection\Loader\Configurator\ContainerConfigurator;

/**
 * Kernel configuration of the tracking tests (1.10): the host policy and short-link rewriter doubles, an allow-list
 * of app.example.com and a 32-byte throw-away secret.
 */
trait TrackingKernelTrait
{
    /**
     * @param array<string, mixed> $overrides merged over the `tracking` node
     *
     * @return array<string, mixed>
     */
    private static function tracking(array $overrides = []): array
    {
        return $overrides + [
            'enabled'         => true,
            'policy'          => HostTrackingPolicy::class,
            'link_rewriter'   => ShortLinkRewriter::class,
            'public_base_url' => 'https://app.example.com/',
            'secret'          => 'test-tracking-secret-REDACTED-0000000000',
            'links'           => ['allowed_hosts' => ['app.example.com']],
        ];
    }

    /**
     * Registers the doctrine-free host doubles.
     */
    private static function trackingServices(?Closure $more = null): Closure
    {
        return static function (ContainerConfigurator $container) use ($more): void {
            $services = $container->services();
            $services->set(HostTrackingPolicy::class)->public();
            $services->set(ShortLinkRewriter::class)->public();
            if ($more instanceof Closure) {
                $more($container);
            }
        };
    }
}
