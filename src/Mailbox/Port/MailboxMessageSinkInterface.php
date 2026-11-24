<?php

declare(strict_types=1);

namespace Nowo\MailKitBundle\Mailbox\Port;

use Nowo\MailKitBundle\Mailbox\Value\FolderRef;
use Nowo\MailKitBundle\Mailbox\Value\MailboxId;
use Nowo\MailKitBundle\Mailbox\Value\ProviderMessageId;
use Nowo\MailKitBundle\Mailbox\Value\RemovalReason;
use Nowo\MailKitBundle\Mailbox\Value\StoreOutcome;
use Nowo\MailKitBundle\Mailbox\Value\SyncedMessage;

/**
 * Host port (2.5.0, required with `mailboxes.enabled`): stores the messages. MailKit keeps no message content and no
 * claim table (Q-MB-4): the sink's unique key is the single idempotency control.
 */
interface MailboxMessageSinkInterface
{
    /**
     * Stores one message. Must be idempotent by (mailbox id, provider message id): a second call answers
     * {@see StoreOutcome::Duplicate} (unique key + caught violation on the host side, REQ-RUNTIME-003 rule 4). Runs
     * in the host's own transaction. Duplicate notifications, a notification plus a sweep, a Messenger retry or two
     * workers all end in such a second call.
     */
    public function store(SyncedMessage $message): StoreOutcome;

    /**
     * The message left the followed folder `$folder` (Q-MB-5), scoped to that folder: {@see RemovalReason::Deleted}
     * when it no longer exists in the mailbox, {@see RemovalReason::MovedOut} when it still exists elsewhere. A message
     * moved between two followed folders keeps its id: it reaches removed() for the source folder and store() for the
     * target (answering Duplicate), in either order. So update the folder membership on a Duplicate (and clear any
     * "deleted in mailbox" mark), and never hard-delete on removed(). It may be called for a message the host never
     * stored, or twice.
     */
    public function removed(MailboxId $mailbox, ProviderMessageId $message, FolderRef $folder, RemovalReason $reason): void;
}
