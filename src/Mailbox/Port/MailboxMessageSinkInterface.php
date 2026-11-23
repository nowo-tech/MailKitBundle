<?php

declare(strict_types=1);

namespace Nowo\MailKitBundle\Mailbox\Port;

use Nowo\MailKitBundle\Mailbox\Value\FolderRef;
use Nowo\MailKitBundle\Mailbox\Value\MailboxId;
use Nowo\MailKitBundle\Mailbox\Value\ProviderMessageId;
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
     * The message left a followed folder or was deleted in the mailbox (Q-MB-5). The host decides what to do; it may
     * be called for a message the host never stored (or twice).
     */
    public function removed(MailboxId $mailbox, ProviderMessageId $message, FolderRef $folder): void;
}
