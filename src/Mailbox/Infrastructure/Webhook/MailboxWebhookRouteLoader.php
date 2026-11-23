<?php

declare(strict_types=1);

namespace Nowo\MailKitBundle\Mailbox\Infrastructure\Webhook;

use RuntimeException;
use Symfony\Component\Config\Loader\Loader;
use Symfony\Component\Routing\Route;
use Symfony\Component\Routing\RouteCollection;

/**
 * Route loader `nowo_mail_kit_mailbox_webhook` (2.5.0): the delegated mailboxes' Graph notification route (`POST`,
 * `mailboxes.graph.webhook.path`, default `/hooks/mail-kit/v1/mailbox/graph`), only when `mailboxes.graph.enabled`.
 * Make its path public and stateless in the firewall, outside the HTTP log.
 *
 * ```yaml
 * # config/routes/nowo_mail_kit.yaml
 * nowo_mail_kit_mailbox_webhook:
 *     resource: .
 *     type: nowo_mail_kit_mailbox_webhook
 * ```
 */
final class MailboxWebhookRouteLoader extends Loader
{
    /** Loader type. */
    public const TYPE = 'nowo_mail_kit_mailbox_webhook';

    /** Route name. */
    public const ROUTE = 'nowo_mail_kit_mailbox_graph_webhook';

    private bool $loaded = false;

    /**
     * @param bool $enabled `mailboxes.enabled` and `mailboxes.graph.enabled`
     * @param string $path `mailboxes.graph.webhook.path`
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
            throw new RuntimeException('Do not add the "nowo_mail_kit_mailbox_webhook" route loader twice.');
        }
        // @igor-ignore - Route loader builds routes at cache warm-up; not per-request state.
        $this->loaded = true;
        $collection   = new RouteCollection();
        if ($this->enabled) {
            $collection->add(self::ROUTE, new Route($this->path, ['_controller' => MailboxGraphWebhookController::class, '_stateless' => true], methods: ['POST']));
        }

        return $collection;
    }

    /**
     * Type `nowo_mail_kit_mailbox_webhook`.
     */
    public function supports(mixed $resource, ?string $type = null): bool
    {
        return $type === self::TYPE;
    }
}
