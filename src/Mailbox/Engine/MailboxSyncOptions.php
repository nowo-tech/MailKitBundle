<?php

declare(strict_types=1);

namespace Nowo\MailKitBundle\Mailbox\Engine;

use DateInterval;
use DateTimeImmutable;

/**
 * Configured limits of the delegated-mailbox engine (2.5.0, `nowo_mail_kit.mailboxes`). Immutable.
 *
 * @internal
 */
final readonly class MailboxSyncOptions
{
    /**
     * @param int $maxFolders `max_folders`
     * @param string $initialWindow `sync.initial_window` (ISO 8601)
     * @param string $maxInitialWindow `sync.max_initial_window`
     * @param string $resyncWindow `sync.resync_window`
     * @param int $pageSize `sync.page_size`
     * @param int $maxPagesPerRun `sync.max_pages_per_run`
     * @param int $leaseSeconds `sync.lease_seconds`
     * @param int $maxAttachmentBytes `limits.max_attachment_bytes`
     * @param string|null $notificationUrl `graph.webhook.notification_url` (null = sweep only)
     * @param string $renewBefore `graph.renew_before`
     * @param int $subscriptionMinutes lifetime requested for a subscription
     */
    public function __construct(
        public int $maxFolders = 10,
        public string $initialWindow = 'P7D',
        public string $maxInitialWindow = 'P90D',
        public string $resyncWindow = 'P7D',
        public int $pageSize = 50,
        public int $maxPagesPerRun = 20,
        public int $leaseSeconds = 120,
        public int $maxAttachmentBytes = 26_214_400,
        public ?string $notificationUrl = null,
        public string $renewBefore = 'P1D',
        public int $subscriptionMinutes = 10_070,
    ) {
    }

    /**
     * Seconds of an ISO 8601 duration (days / hours).
     */
    public static function seconds(string $duration): int
    {
        return (new DateTimeImmutable('@0'))->add(new DateInterval($duration))->getTimestamp();
    }
}
