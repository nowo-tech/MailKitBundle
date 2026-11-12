<?php

declare(strict_types=1);

namespace Nowo\MailKitBundle\Tracking\Http;

use RuntimeException;
use Symfony\Component\Config\Loader\Loader;
use Symfony\Component\Routing\Route;
use Symfony\Component\Routing\RouteCollection;

/**
 * Route loader `nowo_mail_kit_tracking` (1.10, registered only with `tracking.enabled`): the open pixel
 * `GET|HEAD <tracking.path_prefix>/{token}.gif` (default `/e/o/{token}.gif`), stateless. Make the prefix public in the
 * firewall (ideally a `security: false` firewall, so nothing starts a session).
 *
 * ```yaml
 * # config/routes/nowo_mail_kit.yaml
 * nowo_mail_kit_tracking:
 *     resource: .
 *     type: nowo_mail_kit_tracking
 * ```
 */
final class TrackingRouteLoader extends Loader
{
    /** Loader type. */
    public const TYPE = 'nowo_mail_kit_tracking';

    /** Route name of the pixel. */
    public const ROUTE = 'nowo_mail_kit_tracking_open';

    private bool $loaded = false;

    /**
     * @param string $pathPrefix `tracking.path_prefix`
     */
    public function __construct(
        private readonly string $pathPrefix,
        ?string $env = null,
    ) {
        parent::__construct($env);
    }

    /**
     * Builds the collection (once).
     */
    public function load(mixed $resource, ?string $type = null): RouteCollection
    {
        if ($this->loaded) {
            throw new RuntimeException('Do not add the "nowo_mail_kit_tracking" route loader twice.');
        }
        // @igor-ignore - Route loader builds routes at cache warm-up; not per-request state.
        $this->loaded = true;
        $collection   = new RouteCollection();
        // Any token shape reaches the controller, which answers the pixel even for a forged or malformed one.
        $collection->add(self::ROUTE, new Route(
            $this->pathPrefix . '/{token}.gif',
            ['_controller' => TrackingPixelController::class, '_stateless' => true],
            ['token' => '[^/]{1,512}'],
            methods: ['GET', 'HEAD'],
        ));

        return $collection;
    }

    /**
     * Type `nowo_mail_kit_tracking`.
     */
    public function supports(mixed $resource, ?string $type = null): bool
    {
        return $type === self::TYPE;
    }
}
