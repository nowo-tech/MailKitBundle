<?php

declare(strict_types=1);

namespace Nowo\MailKitBundle\Mailbox\Value;

/**
 * What MailKit knows of a mailbox's sync (2.5.0): status, the grant-failure reason when paused by one, and per folder
 * the last complete sweep and the subscription expiry. No address or other personal data.
 */
final readonly class MailboxSyncStatus
{
    /**
     * @param list<FolderSyncState> $folders folders MailKit holds a cursor or a subscription for
     */
    public function __construct(
        public MailboxId $mailbox,
        public MailboxStatus $status,
        public ?GrantFailureReason $reason = null,
        public array $folders = [],
    ) {
    }

    /**
     * True while the mailbox is synced.
     */
    public function isActive(): bool
    {
        return $this->status === MailboxStatus::Active;
    }
}
