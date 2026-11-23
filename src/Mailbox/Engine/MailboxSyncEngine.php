<?php

declare(strict_types=1);

namespace Nowo\MailKitBundle\Mailbox\Engine;

use DateTimeImmutable;
use InvalidArgumentException;
use Nowo\MailKitBundle\Mailbox\Event\MailboxGrantFailedEvent;
use Nowo\MailKitBundle\Mailbox\Event\MailboxResyncedEvent;
use Nowo\MailKitBundle\Mailbox\Exception\MailboxGrantFailure;
use Nowo\MailKitBundle\Mailbox\Exception\MailboxProviderFailure;
use Nowo\MailKitBundle\Mailbox\Infrastructure\Messenger\FetchMailboxMessage;
use Nowo\MailKitBundle\Mailbox\Infrastructure\Messenger\RenewMailboxSubscriptions;
use Nowo\MailKitBundle\Mailbox\Infrastructure\Messenger\SyncMailboxFolder;
use Nowo\MailKitBundle\Mailbox\Infrastructure\Store\MailboxStore;
use Nowo\MailKitBundle\Mailbox\Port\MailboxCallObserverInterface;
use Nowo\MailKitBundle\Mailbox\Port\MailboxDirectoryInterface;
use Nowo\MailKitBundle\Mailbox\Port\MailboxExclusionPolicyInterface;
use Nowo\MailKitBundle\Mailbox\Port\MailboxMessageSinkInterface;
use Nowo\MailKitBundle\Mailbox\Port\MailboxTokenProviderInterface;
use Nowo\MailKitBundle\Mailbox\Service\MailboxSyncInterface;
use Nowo\MailKitBundle\Mailbox\Value\DisconnectResult;
use Nowo\MailKitBundle\Mailbox\Value\FolderRef;
use Nowo\MailKitBundle\Mailbox\Value\FolderSyncState;
use Nowo\MailKitBundle\Mailbox\Value\GrantFailureReason;
use Nowo\MailKitBundle\Mailbox\Value\MailboxId;
use Nowo\MailKitBundle\Mailbox\Value\MailboxProvider;
use Nowo\MailKitBundle\Mailbox\Value\MailboxSettings;
use Nowo\MailKitBundle\Mailbox\Value\MailboxStatus;
use Nowo\MailKitBundle\Mailbox\Value\MailboxSyncStatus;
use Nowo\MailKitBundle\Mailbox\Value\MessageEnvelope;
use Nowo\MailKitBundle\Mailbox\Value\MessageOrigin;
use Nowo\MailKitBundle\Mailbox\Value\ProviderMessageId;
use Psr\Clock\ClockInterface;
use Psr\EventDispatcher\EventDispatcherInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\Clock\NativeClock;
use Symfony\Component\Messenger\MessageBusInterface;
use Throwable;

use function bin2hex;
use function count;
use function in_array;
use function random_bytes;

/**
 * The delegated-mailbox engine (2.5.0, design sections 6 and 9): the default {@see MailboxSyncInterface} and the work
 * behind the Messenger messages. Stateless: every operation reads the directory and MailKit's rows again and builds
 * its own {@see MailboxSession} (FrankenPHP worker mode, kernel reset off).
 *
 * - **One sweeper per folder**: a lease taken by one conditional update; a sweep that finds the lease held sets
 *   `rerun` and ends; the holder enqueues one more sweep when it finishes with `rerun` set.
 * - **Cursor compare-and-set** on its version and the lease owner after every page (the page's fetches are enqueued
 *   first: at least once; the sink deduplicates). A lost lease stops the run without writing.
 * - **Lost cursor** (Graph 410): cleared in the holder's CAS, {@see MailboxResyncedEvent}, restart from the resync
 *   window.
 * - **Grant failure**: one conditional transition to `PausedGrant`; only its winner dispatches
 *   {@see MailboxGrantFailedEvent}; the failure is rethrown (the Messenger handler makes it unrecoverable). Transient
 *   errors never pause.
 * - **Exclusion** before the fetch when the change page gives the sender, and always after the fetch, before the
 *   attachments list and the normalisation (an excluded body is dropped in memory).
 */
final class MailboxSyncEngine implements MailboxSyncInterface
{
    /**
     * @param array<string, MailboxGatewayInterface> $gateways enabled providers by {@see MailboxProvider} value
     */
    public function __construct(
        private readonly MailboxStore $store,
        private readonly MailboxDirectoryInterface $directory,
        private readonly MailboxTokenProviderInterface $tokens,
        private readonly MailboxMessageSinkInterface $sink,
        private readonly MailboxExclusionPolicyInterface $exclusion,
        private readonly MailboxCallObserverInterface $observer,
        private readonly array $gateways,
        private readonly MessageBusInterface $bus,
        private readonly MailboxSyncOptions $options = new MailboxSyncOptions(),
        private readonly ?EventDispatcherInterface $events = null,
        private readonly ?ClockInterface $clock = null,
        private readonly ?LoggerInterface $logger = null,
    ) {
    }

    /**
     * {@inheritDoc}
     */
    public function start(MailboxId $mailbox): MailboxSyncStatus
    {
        $settings = $this->directory->mailbox($mailbox);
        if (!$settings instanceof MailboxSettings) {
            $this->disconnect($mailbox);

            return $this->status($mailbox);
        }
        $gateway = $this->gateway($settings->provider);
        $this->assertSettings($settings);
        $this->store->insertMailbox($mailbox, $settings->provider);
        if ($settings->paused) {
            $this->store->pauseByHost($mailbox);
        }
        if (($this->store->mailbox($mailbox)['status'] ?? null) === MailboxStatus::Active) {
            $this->activate($settings, $gateway);
        }

        return $this->status($mailbox);
    }

    /**
     * {@inheritDoc}
     */
    public function pause(MailboxId $mailbox): MailboxSyncStatus
    {
        if ($this->store->pauseByHost($mailbox)) {
            $this->logger?->info('Mailbox paused by the host.', ['bundle' => 'NowoMailKitBundle', 'mailbox' => $mailbox->value]);
        }

        return $this->status($mailbox);
    }

    /**
     * {@inheritDoc}
     */
    public function resume(MailboxId $mailbox): MailboxSyncStatus
    {
        $row = $this->store->mailbox($mailbox);
        if ($row === null) {
            return $this->start($mailbox);
        }
        $settings = $this->directory->mailbox($mailbox);
        if (!$settings instanceof MailboxSettings) {
            $this->disconnect($mailbox);

            return $this->status($mailbox);
        }
        $gateway = $this->gateway($settings->provider);
        $this->assertSettings($settings);
        if ($settings->paused) {
            $this->store->pauseByHost($mailbox);

            return $this->status($mailbox);
        }
        if ($this->store->activate($mailbox) || $row['status'] === MailboxStatus::Active) {
            $this->activate($settings, $gateway);
        }

        return $this->status($mailbox);
    }

    /**
     * {@inheritDoc}
     */
    public function sweep(MailboxId $mailbox, ?FolderRef $folder = null): void
    {
        if (($this->store->mailbox($mailbox)['status'] ?? null) !== MailboxStatus::Active) {
            return;
        }
        $settings = $this->directory->mailbox($mailbox);
        foreach ($settings instanceof MailboxSettings ? $settings->folders : [] as $followed) {
            if (!$folder instanceof FolderRef || $folder->equals($followed)) {
                $this->bus->dispatch(new SyncMailboxFolder($mailbox->value, $followed->key));
            }
        }
    }

    /**
     * {@inheritDoc}
     */
    public function status(MailboxId $mailbox): MailboxSyncStatus
    {
        $row = $this->store->mailbox($mailbox);
        if ($row === null) {
            return new MailboxSyncStatus($mailbox, MailboxStatus::Unknown);
        }
        $states = [];
        foreach ($this->store->cursors($mailbox) as $cursor) {
            $states[$cursor['folder_key']] = ['complete' => $cursor['last_complete_at'], 'expires' => null];
        }
        foreach ($this->store->subscriptions($mailbox) as $subscription) {
            $states[$subscription['folder_key']] = ['complete' => $states[$subscription['folder_key']]['complete'] ?? null, 'expires' => $subscription['expires_at']];
        }
        $folders = [];
        foreach ($states as $key => $state) {
            $folders[] = new FolderSyncState(new FolderRef((string) $key), $this->time($state['complete']), $this->time($state['expires']));
        }

        return new MailboxSyncStatus($mailbox, $row['status'], $row['status'] === MailboxStatus::PausedGrant ? $row['reason'] : null, $folders);
    }

    /**
     * {@inheritDoc}
     */
    public function disconnect(MailboxId $mailbox): DisconnectResult
    {
        $row           = $this->store->mailbox($mailbox);
        $subscriptions = $this->store->subscriptions($mailbox);
        $deleted       = 0;
        $failed        = false;
        $provider      = MailboxProvider::tryFrom($row['provider'] ?? '');
        $gateway       = $provider !== null ? ($this->gateways[$provider->value] ?? null) : null;
        if ($subscriptions !== []) {
            if ($gateway === null) {
                $failed = true;
            } else {
                $session = new MailboxSession($mailbox, $gateway->provider(), null, $row['account_hash'] ?? null, $this->tokens, $this->observer, $this->logger);
                foreach ($subscriptions as $subscription) {
                    try {
                        $gateway->unsubscribe($session, $subscription['id']);
                        ++$deleted;
                    } catch (Throwable $failure) {
                        // Best effort: the subscription expires at the provider; its notifications are refused as unknown.
                        $failed = true;
                        $this->logger?->notice('Mailbox subscription not deleted remotely; it expires by itself.', ['bundle' => 'NowoMailKitBundle', 'mailbox' => $mailbox->value, 'error' => $failure::class]);
                        if (!$failure instanceof MailboxProviderFailure) {
                            break;
                        }
                    }
                }
            }
        }
        $rows = $this->store->deleteMailbox($mailbox);
        $this->logger?->info('Mailbox disconnected.', ['bundle' => 'NowoMailKitBundle', 'mailbox' => $mailbox->value, 'rows' => $rows, 'remote_cleanup_failed' => $failed]);

        return new DisconnectResult($deleted, $failed, $rows);
    }

    /**
     * Sweeps one folder under its lease (Messenger {@see SyncMailboxFolder}).
     *
     * @throws MailboxGrantFailure the mailbox was paused (the handler makes it unrecoverable)
     * @throws MailboxProviderFailure
     */
    public function sweepFolder(MailboxId $mailbox, string $folderKey): void
    {
        $context = $this->active($mailbox);
        if ($context === null) {
            return;
        }
        [$settings, $gateway, $session] = $context;
        $folder                         = $settings->folder($folderKey);
        if (!$folder instanceof FolderRef) {
            $this->store->deleteCursor($mailbox, $folderKey);

            return;
        }
        $this->store->ensureCursor($mailbox, $folder->key);
        $owner = bin2hex(random_bytes(16));
        if (!$this->store->acquireLease($mailbox, $folder->key, $owner, $this->options->leaseSeconds)) {
            // Another worker sweeps this folder: it sweeps once more when it finishes (no notification is lost).
            $this->store->requestRerun($mailbox, $folder->key);

            return;
        }
        $again = false;

        try {
            $again = $this->page($settings, $gateway, $session, $folder, $owner);
        } catch (MailboxGrantFailure $failure) {
            $this->grantFailed($mailbox, $settings->provider, $failure->reason);

            throw $failure;
        } finally {
            if ($this->store->releaseLease($mailbox, $folder->key, $owner) || $again) {
                $this->bus->dispatch(new SyncMailboxFolder($mailbox->value, $folder->key));
            }
        }
    }

    /**
     * Fetches one message, applies the exclusion policy and hands it to the sink (Messenger {@see FetchMailboxMessage}).
     *
     * @throws MailboxGrantFailure the mailbox was paused
     * @throws MailboxProviderFailure
     */
    public function fetchMessage(MailboxId $mailbox, string $folderKey, ProviderMessageId $message, MessageOrigin $origin): void
    {
        $context = $this->active($mailbox);
        if ($context === null) {
            return;
        }
        [$settings, $gateway, $session] = $context;
        $folder                         = $settings->folder($folderKey);
        if (!$folder instanceof FolderRef) {
            return;
        }

        try {
            $fetched = $gateway->fetch($session, $folder, $message);
            if (!$fetched instanceof FetchedMessage) {
                $this->logger?->info('Mailbox message gone before it was read.', ['bundle' => 'NowoMailKitBundle', 'mailbox' => $mailbox->value]);

                return;
            }
            if ($this->exclusion->decide($fetched->envelope)->isExcluded()) {
                $this->logger?->info('Mailbox message excluded by the host policy.', ['bundle' => 'NowoMailKitBundle', 'mailbox' => $mailbox->value]);

                return;
            }
            $synced = $gateway->normalize($session, $fetched, $gateway->attachments($session, $fetched), $origin);
        } catch (MailboxGrantFailure $failure) {
            $this->grantFailed($mailbox, $settings->provider, $failure->reason);

            throw $failure;
        }
        $outcome = $this->sink->store($synced);
        $this->logger?->info('Mailbox message delivered to the sink.', ['bundle' => 'NowoMailKitBundle', 'mailbox' => $mailbox->value, 'outcome' => $outcome->value, 'attachments' => count($synced->attachments)]);
    }

    /**
     * Re-checks the account and creates / renews / recreates the subscriptions of one mailbox, deleting those of
     * folders no longer followed (Messenger {@see RenewMailboxSubscriptions}).
     *
     * @param string|null $reason null, {@see RenewMailboxSubscriptions::REAUTHORIZE} or ::REMOVED
     * @param string|null $subscriptionId the subscription a lifecycle event names
     *
     * @throws MailboxGrantFailure the mailbox was paused
     * @throws MailboxProviderFailure
     */
    public function renew(MailboxId $mailbox, ?string $reason = null, ?string $subscriptionId = null): void
    {
        $context = $this->active($mailbox);
        if ($context === null) {
            return;
        }
        [$settings, $gateway, $session] = $context;

        try {
            if (!$this->checkAccount($settings, $gateway, $session)) {
                return;
            }
            $this->ensureSubscriptions($settings, $gateway, $session, $reason, $subscriptionId);
        } catch (MailboxGrantFailure $failure) {
            $this->grantFailed($mailbox, $settings->provider, $failure->reason);

            throw $failure;
        }
    }

    /**
     * Renews every active mailbox of the enabled providers; returns the mailboxes that failed (one failure never
     * stops the others).
     */
    public function renewAll(): int
    {
        $failures = 0;
        foreach ($this->activeMailboxes() as $mailbox) {
            try {
                $this->renew($mailbox);
            } catch (Throwable $failure) {
                ++$failures;
                $this->logger?->warning('Mailbox subscriptions not renewed.', ['bundle' => 'NowoMailKitBundle', 'mailbox' => $mailbox->value, 'error' => $failure::class]);
            }
        }

        return $failures;
    }

    /**
     * Enqueues a sweep of every followed folder of every active mailbox (the 5-minute schedule); returns the sweeps
     * enqueued.
     */
    public function sweepAll(): int
    {
        $count = 0;
        foreach ($this->activeMailboxes() as $mailbox) {
            $settings = $this->directory->mailbox($mailbox);
            foreach ($settings instanceof MailboxSettings && !$settings->paused ? $settings->folders : [] as $folder) {
                $this->bus->dispatch(new SyncMailboxFolder($mailbox->value, $folder->key));
                ++$count;
            }
        }

        return $count;
    }

    /**
     * Grant failure: one conditional transition; its winner logs and dispatches {@see MailboxGrantFailedEvent}.
     */
    public function grantFailed(MailboxId $mailbox, MailboxProvider $provider, GrantFailureReason $reason): void
    {
        if (!$this->store->pauseGrant($mailbox, $reason)) {
            return;
        }
        $this->logger?->warning('Mailbox paused: the grant failed; nothing retries until the host resumes it.', ['bundle' => 'NowoMailKitBundle', 'mailbox' => $mailbox->value, 'reason' => $reason->value]);
        $this->events?->dispatch(new MailboxGrantFailedEvent($mailbox, $provider, $reason));
    }

    /**
     * Settings, provider and a fresh session of an active mailbox, or null when the work must be dropped (unknown to
     * MailKit, paused, gone from the directory: then its rows are deleted, paused there: then paused here).
     *
     * @return array{0: MailboxSettings, 1: MailboxGatewayInterface, 2: MailboxSession}|null
     */
    public function active(MailboxId $mailbox): ?array
    {
        $row = $this->store->mailbox($mailbox);
        if ($row === null || $row['status'] !== MailboxStatus::Active) {
            return null;
        }
        $settings = $this->directory->mailbox($mailbox);
        if (!$settings instanceof MailboxSettings) {
            $this->disconnect($mailbox);

            return null;
        }
        if ($settings->paused) {
            $this->store->pauseByHost($mailbox);

            return null;
        }
        $gateway = $this->gateways[$settings->provider->value] ?? null;
        if (!$gateway instanceof MailboxGatewayInterface) {
            return null;
        }

        return [$settings, $gateway, new MailboxSession($mailbox, $settings->provider, $settings->address, $row['account_hash'], $this->tokens, $this->observer, $this->logger)];
    }

    /**
     * Pages of one folder under the lease; true when more pages remain after `max_pages_per_run` (one more sweep).
     */
    private function page(MailboxSettings $settings, MailboxGatewayInterface $gateway, MailboxSession $session, FolderRef $folder, string $owner): bool
    {
        $mailbox = $settings->id;
        $state   = $this->store->cursor($mailbox, $folder->key) ?? ['cursor' => null, 'version' => 0];
        $cursor  = $state['cursor'];
        $version = $state['version'];
        $origin  = MessageOrigin::Sync;
        $window  = $settings->initialWindow ?? $this->options->initialWindow;
        for ($pages = 0; $pages < $this->options->maxPagesPerRun;) {
            try {
                $page = $gateway->changes($session, $folder, $cursor, $this->now() - MailboxSyncOptions::seconds($window), $this->options->pageSize);
            } catch (CursorExpired) {
                if ($origin === MessageOrigin::Resync || !$this->store->advanceCursor($mailbox, $folder->key, $owner, $version, null, $this->options->leaseSeconds, false)) {
                    return false;
                }
                ++$version;
                $cursor = null;
                $origin = MessageOrigin::Resync;
                $window = $this->options->resyncWindow;
                $this->logger?->notice('Mailbox folder cursor expired; resync from the resync window.', ['bundle' => 'NowoMailKitBundle', 'mailbox' => $mailbox->value]);
                $this->events?->dispatch(new MailboxResyncedEvent($mailbox, $folder));

                continue;
            }
            ++$pages;
            foreach ($page->items as $item) {
                if ($item->removed) {
                    $this->sink->removed($mailbox, $item->id, $folder);

                    continue;
                }
                if ($item->hasEnvelope && $this->exclusion->decide(new MessageEnvelope($mailbox, $folder, $item->id, $item->from, $item->sender, null, null, $item->receivedAt, false))->isExcluded()) {
                    continue;
                }
                $this->bus->dispatch(new FetchMailboxMessage($mailbox->value, $folder->key, $item->id->value, $origin->value));
            }
            if (!$this->store->advanceCursor($mailbox, $folder->key, $owner, $version, $page->cursor, $this->options->leaseSeconds, $page->complete)) {
                // The lease expired and another worker took over: stop without writing (the fetches are idempotent).
                return false;
            }
            ++$version;
            $cursor = $page->cursor;
            if ($page->complete) {
                return false;
            }
        }

        return true;
    }

    /**
     * Account check, cursors, subscriptions and the first sweep of an active mailbox (start / resume).
     */
    private function activate(MailboxSettings $settings, MailboxGatewayInterface $gateway): void
    {
        $mailbox = $settings->id;
        $session = new MailboxSession($mailbox, $settings->provider, $settings->address, $this->store->mailbox($mailbox)['account_hash'] ?? null, $this->tokens, $this->observer, $this->logger);

        try {
            if (!$this->checkAccount($settings, $gateway, $session)) {
                return;
            }
            foreach ($settings->folders as $folder) {
                $this->store->ensureCursor($mailbox, $folder->key);
            }
            $this->ensureSubscriptions($settings, $gateway, $session, null, null);
        } catch (MailboxGrantFailure $failure) {
            $this->grantFailed($mailbox, $settings->provider, $failure->reason);

            return;
        }
        foreach ($settings->folders as $folder) {
            $this->bus->dispatch(new SyncMailboxFolder($mailbox->value, $folder->key));
        }
    }

    /**
     * The account behind the token must have the directory's address and be the account first bound to the mailbox;
     * otherwise the mailbox is paused `AccountMismatch` (false).
     */
    private function checkAccount(MailboxSettings $settings, MailboxGatewayInterface $gateway, MailboxSession $session): bool
    {
        $account = $gateway->account($session);
        if (!in_array($settings->address, $account->addresses, true) || !$this->store->bindAccount($settings->id, $account->id)) {
            $this->grantFailed($settings->id, $settings->provider, GrantFailureReason::AccountMismatch);

            return false;
        }

        return true;
    }

    /**
     * One subscription per followed folder (with a notification URL): created when missing or gone, renewed when it
     * expires within `renew_before` or a lifecycle event asks; those of folders no longer followed are deleted (with
     * their cursors). A refused creation (a personal account without subscriptions) leaves the folder sweep-only.
     */
    private function ensureSubscriptions(MailboxSettings $settings, MailboxGatewayInterface $gateway, MailboxSession $session, ?string $reason, ?string $subscriptionId): void
    {
        $mailbox = $settings->id;
        $current = [];
        foreach ($this->store->subscriptions($mailbox) as $subscription) {
            if ($settings->folder($subscription['folder_key']) instanceof FolderRef) {
                $current[$subscription['folder_key']] = $subscription;

                continue;
            }
            $this->dropSubscription($gateway, $session, $subscription['id']);
        }
        foreach ($this->store->cursors($mailbox) as $cursor) {
            if (!$settings->folder($cursor['folder_key']) instanceof FolderRef) {
                $this->store->deleteCursor($mailbox, $cursor['folder_key']);
            }
        }
        $url = $this->options->notificationUrl;
        if ($url === null) {
            return;
        }
        $now    = $this->now();
        $expiry = $now + $this->options->subscriptionMinutes * 60;
        foreach ($settings->folders as $folder) {
            $subscription = $current[$folder->key] ?? null;
            $named        = $subscription !== null && $subscriptionId !== null && $subscription['id'] === $subscriptionId;
            if ($subscription !== null && $named && $reason === RenewMailboxSubscriptions::REMOVED) {
                $this->store->deleteSubscription($subscription['id']);
                $subscription = null;
            }
            if ($subscription !== null && (!$named || $reason !== RenewMailboxSubscriptions::REAUTHORIZE) && $subscription['expires_at'] > $now + MailboxSyncOptions::seconds($this->options->renewBefore)) {
                continue;
            }
            if ($subscription !== null) {
                $renewed = $gateway->renew($session, $subscription['id'], $expiry);
                if ($renewed !== null) {
                    $this->store->updateSubscriptionExpiry($subscription['id'], $renewed);

                    continue;
                }
                $this->store->deleteSubscription($subscription['id']);
            }

            try {
                $clientState = bin2hex(random_bytes(32));
                $remote      = $gateway->subscribe($session, $folder, $url, $clientState, $expiry);
            } catch (MailboxProviderFailure $failure) {
                if ($failure->isRetryable()) {
                    throw $failure;
                }
                $this->logger?->notice('Mailbox subscription refused by the provider; the folder is synced by the sweep only.', ['bundle' => 'NowoMailKitBundle', 'mailbox' => $mailbox->value, 'status' => $failure->status]);

                continue;
            }
            $this->store->saveSubscription($remote->id, $mailbox, $folder->key, $clientState, $remote->expiresAt);
        }
    }

    /**
     * Deletes a subscription at the provider (best effort) and here.
     */
    private function dropSubscription(MailboxGatewayInterface $gateway, MailboxSession $session, string $subscriptionId): void
    {
        try {
            $gateway->unsubscribe($session, $subscriptionId);
        } catch (MailboxProviderFailure) {
            // Best effort: it expires by itself; its notifications are refused once the row is gone.
        }
        $this->store->deleteSubscription($subscriptionId);
    }

    /**
     * The settings within the configured bounds (a host bug otherwise).
     *
     * @throws InvalidArgumentException
     */
    private function assertSettings(MailboxSettings $settings): void
    {
        if (count($settings->folders) > $this->options->maxFolders) {
            throw new InvalidArgumentException('The mailbox follows more folders than nowo_mail_kit.mailboxes.max_folders allows.');
        }
        if ($settings->initialWindow !== null && MailboxSyncOptions::seconds($settings->initialWindow) > MailboxSyncOptions::seconds($this->options->maxInitialWindow)) {
            throw new InvalidArgumentException('The mailbox initial window exceeds nowo_mail_kit.mailboxes.sync.max_initial_window.');
        }
    }

    /**
     * The gateway of an enabled provider.
     *
     * @throws MailboxProviderFailure for a provider that is not enabled (Gmail in 2.5.0)
     */
    private function gateway(MailboxProvider $provider): MailboxGatewayInterface
    {
        $gateway = $this->gateways[$provider->value] ?? null;
        if (!$gateway instanceof MailboxGatewayInterface) {
            throw new MailboxProviderFailure('The mailbox provider "' . $provider->value . '" is not enabled.', MailboxProviderFailure::REFUSED);
        }

        return $gateway;
    }

    /**
     * Active mailboxes of the enabled providers that MailKit holds as active.
     *
     * @return iterable<MailboxId>
     */
    private function activeMailboxes(): iterable
    {
        foreach ($this->gateways as $gateway) {
            foreach ($this->directory->activeMailboxes($gateway->provider()) as $mailbox) {
                if (($this->store->mailbox($mailbox)['status'] ?? null) === MailboxStatus::Active) {
                    yield $mailbox;
                }
            }
        }
    }

    private function time(?int $timestamp): ?DateTimeImmutable
    {
        return $timestamp !== null ? new DateTimeImmutable('@' . $timestamp) : null;
    }

    private function now(): int
    {
        return ($this->clock ?? new NativeClock())->now()->getTimestamp();
    }
}
