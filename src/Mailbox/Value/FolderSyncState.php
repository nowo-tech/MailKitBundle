<?php

declare(strict_types=1);

namespace Nowo\MailKitBundle\Mailbox\Value;

use DateTimeImmutable;

/**
 * Sync state of one followed folder (2.5.0), part of {@see MailboxSyncStatus}.
 */
final readonly class FolderSyncState
{
    /**
     * @param DateTimeImmutable|null $lastCompleteSweep end of the last sweep that reached the provider's delta link
     * @param DateTimeImmutable|null $subscriptionExpiresAt expiry of the change-notification subscription, or null
     *                                                      (none: sweep only)
     */
    public function __construct(
        public FolderRef $folder,
        public ?DateTimeImmutable $lastCompleteSweep,
        public ?DateTimeImmutable $subscriptionExpiresAt,
    ) {
    }
}
