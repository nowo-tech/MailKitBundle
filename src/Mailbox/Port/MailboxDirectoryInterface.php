<?php

declare(strict_types=1);

namespace Nowo\MailKitBundle\Mailbox\Port;

use Nowo\MailKitBundle\Mailbox\Value\MailboxId;
use Nowo\MailKitBundle\Mailbox\Value\MailboxProvider;
use Nowo\MailKitBundle\Mailbox\Value\MailboxSettings;

/**
 * Host port (2.5.0, required with `mailboxes.enabled`): which delegated mailboxes exist, their address, followed
 * folders and host pause. Asked on every message, so a change applies at once (no cache in MailKit).
 */
interface MailboxDirectoryInterface
{
    /**
     * Settings of one mailbox, or null when the host no longer knows it (MailKit then stops and deletes its rows).
     */
    public function mailbox(MailboxId $mailbox): ?MailboxSettings;

    /**
     * Mailboxes to keep in sync (renewal and sweep schedules).
     *
     * @return iterable<MailboxId>
     */
    public function activeMailboxes(MailboxProvider $provider): iterable;

    /**
     * The mailbox of an address (Gmail push names the account by address only; 2.6.0). Graph never calls it.
     */
    public function findByAddress(MailboxProvider $provider, string $address): ?MailboxId;
}
