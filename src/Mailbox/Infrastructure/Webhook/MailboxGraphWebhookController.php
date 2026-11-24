<?php

declare(strict_types=1);

namespace Nowo\MailKitBundle\Mailbox\Infrastructure\Webhook;

use Nowo\MailKitBundle\Graph\Inbound\Webhook\AbstractGraphWebhookController;
use Nowo\MailKitBundle\Mailbox\Infrastructure\Messenger\FetchMailboxMessage;
use Nowo\MailKitBundle\Mailbox\Infrastructure\Messenger\RenewMailboxSubscriptions;
use Nowo\MailKitBundle\Mailbox\Infrastructure\Messenger\SyncMailboxFolder;
use Nowo\MailKitBundle\Mailbox\Infrastructure\Store\MailboxStore;
use Nowo\MailKitBundle\Mailbox\Value\ProviderMessageId;
use Psr\Log\LoggerInterface;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\RateLimiter\RateLimiterFactory;

use function explode;
use function is_array;
use function is_string;
use function preg_match;

/**
 * Microsoft Graph change-notification endpoint of the delegated mailboxes (2.5.0; route from the
 * `nowo_mail_kit_mailbox_webhook` loader, default `/hooks/mail-kit/v1/mailbox/graph`, POST only; separate from the
 * 1.9 route). The HTTP flow is the 1.9 one ({@see AbstractGraphWebhookController}); every notification must name a
 * stored, unexpired subscription with its 256-bit `clientState` (SHA-256, constant time). The mailbox and the folder
 * come from MailKit's subscription row, **never** from the payload's `resource`. A paused mailbox's notifications are
 * acknowledged without work. Only enqueues: {@see FetchMailboxMessage} per new message (id format-checked, replays
 * are idempotent), {@see SyncMailboxFolder} on `missed`, {@see RenewMailboxSubscriptions} on
 * `reauthorizationRequired` / `subscriptionRemoved`.
 */
final class MailboxGraphWebhookController extends AbstractGraphWebhookController
{
    /**
     * @param RateLimiterFactory $verifiedLimiter global bucket of requests with a valid notification
     * @param RateLimiterFactory $clientLimiter per-client-IP bucket of unverified requests
     * @param int $maxBodyBytes largest request body read
     * @param int $maxNotifications most notifications per request
     * @param list<string> $allowedIps accepted source addresses / CIDR ranges (empty = any)
     */
    public function __construct(
        private readonly MailboxStore $store,
        MessageBusInterface $bus,
        RateLimiterFactory $verifiedLimiter,
        RateLimiterFactory $clientLimiter,
        int $maxBodyBytes = 1_048_576,
        int $maxNotifications = 200,
        array $allowedIps = [],
        ?LoggerInterface $logger = null,
    ) {
        parent::__construct($bus, $verifiedLimiter, $clientLimiter, $maxBodyBytes, $maxNotifications, $allowedIps, $logger);
    }

    /**
     * `<mailbox id>\n<folder key>\n<1 = active | 0 = paused>` of a known, unexpired subscription with the right
     * client state.
     */
    protected function resolve(string $subscriptionId, string $clientState): ?string
    {
        $target = $this->store->verifyNotification($subscriptionId, $clientState);

        return $target !== null ? $target['mailbox']->value . "\n" . $target['folder'] . "\n" . ($target['active'] ? '1' : '0') : null;
    }

    /**
     * {@inheritDoc}
     */
    protected function messages(string $target, array $item, array &$seen): array
    {
        [$mailbox, $folder, $active] = explode("\n", $target) + ['', '', '0'];
        if ($active !== '1') {
            return [];
        }
        $lifecycle = is_string($item['lifecycleEvent'] ?? null) ? $item['lifecycleEvent'] : null;
        if ($lifecycle !== null) {
            $subscription = (string) $item['subscriptionId'];
            $message      = match ($lifecycle) {
                'missed'                  => new SyncMailboxFolder($mailbox, $folder),
                'reauthorizationRequired' => new RenewMailboxSubscriptions($mailbox, RenewMailboxSubscriptions::REAUTHORIZE, $subscription),
                'subscriptionRemoved'     => new RenewMailboxSubscriptions($mailbox, RenewMailboxSubscriptions::REMOVED, $subscription),
                default                   => null,
            };
            if ($message === null) {
                $this->logger?->notice('Microsoft Graph lifecycle event ignored (unknown).', ['bundle' => 'NowoMailKitBundle', 'mailbox' => $mailbox]);

                return [];
            }

            return [$message];
        }
        $resource = is_array($item['resourceData'] ?? null) ? $item['resourceData'] : [];
        $id       = is_string($resource['id'] ?? null) ? $resource['id'] : '';
        $key      = $mailbox . "\n" . $id;
        if (($item['changeType'] ?? null) === 'created' && preg_match(ProviderMessageId::PATTERN, $id) === 1 && !isset($seen[$key])) {
            $seen[$key] = true;

            return [new FetchMailboxMessage($mailbox, $folder, $id)];
        }

        return [];
    }

    /**
     * {@inheritDoc}
     */
    protected function isFetch(object $message): bool
    {
        return $message instanceof FetchMailboxMessage;
    }
}
