<?php

declare(strict_types=1);

namespace Nowo\MailKitBundle\Graph\Inbound\Webhook;

use Nowo\MailKitBundle\Graph\Inbound\GraphInboundProcessor;
use Nowo\MailKitBundle\Graph\Inbound\GraphInboundProfilesProviderInterface;
use Nowo\MailKitBundle\Graph\Inbound\Message\FetchGraphMessage;
use Nowo\MailKitBundle\Graph\Inbound\Message\RenewGraphSubscriptions;
use Nowo\MailKitBundle\Graph\Inbound\Message\SweepGraphMailbox;
use Nowo\MailKitBundle\Graph\Inbound\Store\GraphInboundStore;
use Psr\Log\LoggerInterface;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\RateLimiter\RateLimiterFactory;

use function in_array;
use function is_array;
use function is_string;
use function preg_match;

/**
 * Microsoft Graph change-notification endpoint of the shared-mailbox inbound pipeline (1.9; route from the
 * `nowo_mail_kit_graph_webhook` loader, POST only). The HTTP flow (allow-list, buckets, validation echo, caps) is
 * {@see AbstractGraphWebhookController}; here every notification must name a stored, unexpired subscription of an
 * inbound profile with its `clientState` (SHA-256 digests compared in constant time), and the endpoint enqueues one
 * {@see FetchGraphMessage} per new message, a renewal or a sweep for lifecycle events.
 */
final class GraphWebhookController extends AbstractGraphWebhookController
{
    /**
     * @param RateLimiterFactory $verifiedLimiter global bucket of requests with a valid notification
     * @param RateLimiterFactory $clientLimiter per-client-IP bucket of unverified requests
     * @param int $maxBodyBytes largest request body read
     * @param int $maxNotifications most notifications per request
     * @param list<string> $allowedIps accepted source addresses / CIDR ranges (empty = any)
     */
    public function __construct(
        private readonly GraphInboundStore $store,
        MessageBusInterface $bus,
        RateLimiterFactory $verifiedLimiter,
        RateLimiterFactory $clientLimiter,
        private readonly GraphInboundProfilesProviderInterface $profiles,
        int $maxBodyBytes = 1_048_576,
        int $maxNotifications = 200,
        array $allowedIps = [],
        ?LoggerInterface $logger = null,
    ) {
        parent::__construct($bus, $verifiedLimiter, $clientLimiter, $maxBodyBytes, $maxNotifications, $allowedIps, $logger);
    }

    /**
     * The profile of a known, unexpired subscription of an inbound profile with the right client state.
     */
    protected function resolve(string $subscriptionId, string $clientState): ?string
    {
        $profile = $this->store->verifyNotification($subscriptionId, $clientState);

        return $profile !== null && in_array($profile, $this->profiles->inboundProfiles(), true) ? $profile : null;
    }

    /**
     * {@inheritDoc}
     */
    protected function messages(string $target, array $item, array &$seen): array
    {
        $lifecycle = is_string($item['lifecycleEvent'] ?? null) ? $item['lifecycleEvent'] : null;
        if ($lifecycle !== null) {
            $subscription = (string) $item['subscriptionId'];
            $message      = match ($lifecycle) {
                'missed'                  => new SweepGraphMailbox($target),
                'reauthorizationRequired' => new RenewGraphSubscriptions($target, RenewGraphSubscriptions::REAUTHORIZE, $subscription),
                'subscriptionRemoved'     => new RenewGraphSubscriptions($target, RenewGraphSubscriptions::REMOVED, $subscription),
                default                   => null,
            };
            if ($message === null) {
                $this->logger?->notice('Microsoft Graph lifecycle event ignored (unknown).', ['bundle' => 'NowoMailKitBundle', 'profile' => $target]);

                return [];
            }

            return [$message];
        }
        $resource = is_array($item['resourceData'] ?? null) ? $item['resourceData'] : [];
        $id       = is_string($resource['id'] ?? null) ? $resource['id'] : '';
        if (($item['changeType'] ?? null) === 'created' && preg_match(GraphInboundProcessor::ID_PATTERN, $id) === 1 && !isset($seen[$target . "\n" . $id])) {
            $seen[$target . "\n" . $id] = true;

            return [new FetchGraphMessage($target, $id)];
        }

        return [];
    }

    /**
     * {@inheritDoc}
     */
    protected function isFetch(object $message): bool
    {
        return $message instanceof FetchGraphMessage;
    }
}
