<?php

declare(strict_types=1);

namespace Nowo\MailKitBundle\Graph\Inbound\Webhook;

use RuntimeException;
use Symfony\Component\Config\Loader\Loader;
use Symfony\Component\Routing\Route;
use Symfony\Component\Routing\RouteCollection;

/**
 * Route loader `nowo_mail_kit_graph_webhook` (1.9): the Graph notification route (`POST`, `graph.inbound.webhook.path`,
 * default `/hooks/mail-kit/v1/graph`), only when `graph.inbound.enabled`. Separate from the admin loader so the route
 * is not under the admin prefix; make its path public (and stateless) in the firewall.
 *
 * ```yaml
 * # config/routes/nowo_mail_kit.yaml
 * nowo_mail_kit_graph_webhook:
 *     resource: .
 *     type: nowo_mail_kit_graph_webhook
 * ```
 */
final class GraphWebhookRouteLoader extends Loader
{
    /** Loader type. */
    public const TYPE = 'nowo_mail_kit_graph_webhook';

    /** Route name. */
    public const ROUTE = 'nowo_mail_kit_graph_webhook';

    private bool $loaded = false;

    /**
     * @param bool $enabled `graph.inbound.enabled`
     * @param string $path `graph.inbound.webhook.path`
     */
    public function __construct(
        private readonly bool $enabled,
        private readonly string $path,
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
            throw new RuntimeException('Do not add the "nowo_mail_kit_graph_webhook" route loader twice.');
        }
        // @igor-ignore - Route loader builds routes at cache warm-up; not per-request state.
        $this->loaded = true;
        $collection   = new RouteCollection();
        if ($this->enabled) {
            $collection->add(self::ROUTE, new Route($this->path, ['_controller' => GraphWebhookController::class, '_stateless' => true], methods: ['POST']));
        }

        return $collection;
    }

    /**
     * Type `nowo_mail_kit_graph_webhook`.
     */
    public function supports(mixed $resource, ?string $type = null): bool
    {
        return $type === self::TYPE;
    }
}
