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
     * @param string|null $syncError why the folder's sweeps stopped (`refused`, `too_large`, `not_found`), or null; the
     *                               next good sweep clears it (audit ZAT-R1)
     * @param DateTimeImmutable|null $syncErrorSince when that error started
     */
    public function __construct(
        public FolderRef $folder,
        public ?DateTimeImmutable $lastCompleteSweep,
        public ?DateTimeImmutable $subscriptionExpiresAt,
        public ?string $syncError = null,
        public ?DateTimeImmutable $syncErrorSince = null,
    ) {
    }
}
