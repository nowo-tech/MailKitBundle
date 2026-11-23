<?php

declare(strict_types=1);

namespace Nowo\MailKitBundle\Mailbox\Service;

use Nowo\MailKitBundle\Mailbox\Value\DisconnectResult;
use Nowo\MailKitBundle\Mailbox\Value\FolderRef;
use Nowo\MailKitBundle\Mailbox\Value\MailboxId;
use Nowo\MailKitBundle\Mailbox\Value\MailboxSyncStatus;

/**
 * Sync of a delegated mailbox (2.5.0), called by the host.
 */
interface MailboxSyncInterface
{
    /**
     * Checks that the account behind the token has the directory's address, creates the subscriptions (with a
     * notification URL) and enqueues the first sweep of each followed folder. A grant failure pauses the mailbox and is
     * reported in the returned status (no exception). Calling it again is harmless.
     */
    public function start(MailboxId $mailbox): MailboxSyncStatus;

    /**
     * Host pause: every handler skips the mailbox and its notifications are acknowledged without work.
     */
    public function pause(MailboxId $mailbox): MailboxSyncStatus;

    /**
     * After a reconnect or a host pause: re-checks the account, recreates missing subscriptions and sweeps from the
     * stored cursors.
     */
    public function resume(MailboxId $mailbox): MailboxSyncStatus;

    /**
     * Enqueues a sweep of one followed folder, or of all; never blocks the request. Does nothing for a mailbox that is
     * not active.
     */
    public function sweep(MailboxId $mailbox, ?FolderRef $folder = null): void;

    /**
     * Current status (no provider call).
     */
    public function status(MailboxId $mailbox): MailboxSyncStatus;

    /**
     * Deletes the remote subscriptions (best effort, with the current token when it still works) and every MailKit row
     * of the mailbox (GDPR erasure on MailKit's side).
     */
    public function disconnect(MailboxId $mailbox): DisconnectResult;
}
