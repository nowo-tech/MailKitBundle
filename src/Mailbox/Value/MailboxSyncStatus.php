<?php

declare(strict_types=1);

namespace Nowo\MailKitBundle\Mailbox\Value;

/**
 * What MailKit knows of a mailbox's sync (2.5.0): status, the grant-failure reason when paused by one, and per folder
 * the last complete sweep and the subscription expiry. No address or other personal data. The provider (when MailKit
 * knows the mailbox) tells a host whether it is send-only ({@see isSendOnly()}: Gmail, no inbox).
 */
final readonly class MailboxSyncStatus
{
    /**
     * @param list<FolderSyncState> $folders folders MailKit holds a cursor or a subscription for
     * @param MailboxProvider|null $provider provider of the mailbox, null when MailKit does not know it
     */
    public function __construct(
        public MailboxId $mailbox,
        public MailboxStatus $status,
        public ?GrantFailureReason $reason = null,
        public array $folders = [],
        public ?MailboxProvider $provider = null,
    ) {
    }

    /**
     * True for a send-only mailbox (Gmail, D-20261124-09): it never syncs and has no inbox.
     */
    public function isSendOnly(): bool
    {
        return $this->provider?->isSendOnly() ?? false;
    }

    /**
     * True while the mailbox is synced.
     */
    public function isActive(): bool
    {
        return $this->status === MailboxStatus::Active;
    }
}
