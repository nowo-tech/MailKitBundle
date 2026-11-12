<?php

declare(strict_types=1);

namespace Nowo\MailKitBundle\Graph\Inbound;

use DateTimeImmutable;
use DateTimeZone;
use Exception;
use Nowo\MailKitBundle\Graph\GraphApiClient;
use Nowo\MailKitBundle\Graph\GraphException;
use Nowo\MailKitBundle\Graph\GraphMailbox;
use Nowo\MailKitBundle\Graph\GraphMailboxProviderInterface;
use Nowo\MailKitBundle\Graph\Inbound\Message\RenewGraphSubscriptions;
use Nowo\MailKitBundle\Graph\Inbound\Store\GraphInboundStore;
use Psr\Clock\ClockInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\Clock\NativeClock;

use function bin2hex;
use function is_string;
use function preg_match;
use function random_bytes;
use function rawurlencode;

/**
 * Change-notification subscriptions of the inbound mailboxes (1.9): creates one per profile on the folder's messages
 * (`changeType: created`, immutable ids, the webhook URL as notification and lifecycle URL, a fresh random 256-bit
 * `clientState` whose SHA-256 only is stored) and renews it when it expires within `renew_before`. A subscription
 * Graph no longer knows (404) is recreated. Without a notification URL nothing is subscribed (sweep only).
 */
final class GraphSubscriptionManager
{
    /** Graph's maximum lifetime of a subscription on Outlook messages, in minutes (just under 7 days). */
    public const MAX_LIFETIME_MINUTES = 10_070;

    /** `ensure()` outcome: nothing to do. */
    public const CURRENT = 'current';

    /** `ensure()` outcome: the expiry was extended. */
    public const RENEWED = 'renewed';

    /** `ensure()` outcome: a new subscription was created. */
    public const CREATED = 'created';

    /** `ensure()` outcome: the subscription was reauthorized (lifecycle `reauthorizationRequired`). */
    public const REAUTHORIZED = 'reauthorized';

    /** `ensure()` outcome: a lifecycle event named another subscription than the current one; ignored. */
    public const STALE = 'stale';

    /** `ensure()` outcome: no notification URL configured. */
    public const DISABLED = 'disabled';

    /**
     * @param string|null $notificationUrl public HTTPS URL of the webhook route (null = sweep only)
     * @param int $lifetimeMinutes lifetime requested on create / renew
     * @param int $renewBeforeMinutes renew when the subscription expires within this many minutes
     * @param string $folder well-known folder name
     */
    public function __construct(
        private readonly GraphMailboxProviderInterface $mailboxes,
        private readonly GraphApiClient $api,
        private readonly GraphInboundStore $store,
        private readonly ?string $notificationUrl,
        private readonly int $lifetimeMinutes = 4320,
        private readonly int $renewBeforeMinutes = 1440,
        private readonly string $folder = 'inbox',
        private readonly ?ClockInterface $clock = null,
        private readonly ?LoggerInterface $logger = null,
    ) {
    }

    /**
     * Creates or renews the profile's subscription when needed. A lifecycle reason (1.9, audit NQ-L1) forces the
     * matching action: {@see RenewGraphSubscriptions::REAUTHORIZE} calls `POST /subscriptions/{id}/reauthorize`;
     * {@see RenewGraphSubscriptions::REMOVED} forgets the stored subscription and creates a new one.
     *
     * @param string|null $reason null, RenewGraphSubscriptions::REAUTHORIZE or ::REMOVED
     * @param string|null $subscriptionId the subscription a lifecycle event names: the event is ignored (outcome
     *                                    {@see STALE}) unless it is the profile's current subscription
     *
     * @throws GraphException
     *
     * @return string one of the outcome constants
     */
    public function ensure(string $profile, ?string $reason = null, ?string $subscriptionId = null): string
    {
        if ($this->notificationUrl === null) {
            return self::DISABLED;
        }
        $now     = $this->now();
        $current = $this->store->subscription($profile);
        $mailbox = $this->mailboxes->mailbox($profile);
        $expiry  = $now + $this->lifetimeMinutes * 60;
        if ($reason !== null && $subscriptionId !== null && $current !== null && $current['id'] !== $subscriptionId) {
            // A lifecycle event about an older subscription (audit NQ-v L2): nothing to do.
            $this->log($profile, self::STALE);

            return self::STALE;
        }
        if ($current !== null && $reason === RenewGraphSubscriptions::REMOVED) {
            $this->store->deleteSubscription($current['id']);
            $this->deleteRemote($mailbox, $current['id']);
            $current = null;
        }
        if ($current !== null && $reason === RenewGraphSubscriptions::REAUTHORIZE) {
            try {
                $this->api->call($mailbox, 'POST', '/subscriptions/' . rawurlencode($current['id']) . '/reauthorize', ['body' => ''], [200, 204], 'reauthorize subscription');
                $this->log($profile, self::REAUTHORIZED);

                return self::REAUTHORIZED;
            } catch (GraphException $exception) {
                if ($exception->status !== 404) {
                    throw $exception;
                }
                $this->store->deleteSubscription($current['id']);
                $current = null;
            }
        }
        if ($current !== null && $current['expires_at'] > $now + $this->renewBeforeMinutes * 60) {
            return self::CURRENT;
        }
        if ($current !== null) {
            try {
                $answer = $this->api->call($mailbox, 'PATCH', '/subscriptions/' . rawurlencode($current['id']), ['json' => ['expirationDateTime' => $this->iso($expiry)]], [200], 'renew subscription');
                $this->store->updateSubscriptionExpiry($current['id'], $this->timestamp($answer['expirationDateTime'] ?? null) ?? $expiry);
                $this->log($profile, self::RENEWED);

                return self::RENEWED;
            } catch (GraphException $exception) {
                if ($exception->status !== 404) {
                    throw $exception;
                }
                $this->store->deleteSubscription($current['id']);
            }
        }

        $clientState = bin2hex(random_bytes(32));
        $answer      = $this->api->call($mailbox, 'POST', '/subscriptions', [
            'headers' => ['Prefer' => 'IdType="ImmutableId"'],
            'json'    => [
                'changeType'                => 'created',
                'notificationUrl'           => $this->notificationUrl,
                'lifecycleNotificationUrl'  => $this->notificationUrl,
                'resource'                  => 'users/' . $mailbox->address . "/mailFolders('" . $this->folder . "')/messages",
                'expirationDateTime'        => $this->iso($expiry),
                'clientState'               => $clientState,
                'latestSupportedTlsVersion' => 'v1_2',
            ],
        ], [201], 'create subscription');
        $id = is_string($answer['id'] ?? null) ? $answer['id'] : '';
        if (preg_match('/^[A-Za-z0-9-]{1,128}\z/', $id) !== 1) {
            throw new GraphException('Microsoft Graph create subscription returned no usable id.', GraphException::REASON_API, 201);
        }
        $this->store->saveSubscription($id, $profile, $clientState, $this->timestamp($answer['expirationDateTime'] ?? null) ?? $expiry);
        $this->log($profile, self::CREATED);

        return self::CREATED;
    }

    /**
     * Deletes the profile's subscription (Graph and the store); false when there was none.
     *
     * @throws GraphException
     */
    public function delete(string $profile): bool
    {
        $current = $this->store->subscription($profile);
        if ($current === null) {
            return false;
        }
        $this->api->call($this->mailboxes->mailbox($profile), 'DELETE', '/subscriptions/' . rawurlencode($current['id']), [], [204, 404], 'delete subscription');
        $this->store->deleteSubscription($current['id']);
        $this->log($profile, 'removed');

        return true;
    }

    /**
     * Best-effort DELETE of a replaced subscription on Graph (it may already be gone).
     */
    private function deleteRemote(GraphMailbox $mailbox, string $subscriptionId): void
    {
        try {
            $this->api->call($mailbox, 'DELETE', '/subscriptions/' . rawurlencode($subscriptionId), [], [204, 404], 'delete subscription');
        } catch (GraphException) {
            // Graph expires it anyway.
        }
    }

    private function log(string $profile, string $outcome): void
    {
        $this->logger?->info('Microsoft Graph subscription ' . $outcome . '.', ['bundle' => 'NowoMailKitBundle', 'profile' => $profile]);
    }

    private function iso(int $timestamp): string
    {
        return (new DateTimeImmutable('@' . $timestamp))->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d\TH:i:s.000\Z');
    }

    private function timestamp(mixed $value): ?int
    {
        if (!is_string($value)) {
            return null;
        }

        try {
            return (new DateTimeImmutable($value))->getTimestamp();
        } catch (Exception) {
            return null;
        }
    }

    private function now(): int
    {
        return ($this->clock ?? new NativeClock())->now()->getTimestamp();
    }
}
