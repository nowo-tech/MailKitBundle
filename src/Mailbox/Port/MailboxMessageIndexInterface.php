<?php

declare(strict_types=1);

namespace Nowo\MailKitBundle\Mailbox\Port;

use Nowo\MailKitBundle\Mailbox\Value\FolderRef;
use Nowo\MailKitBundle\Mailbox\Value\MailboxId;
use Nowo\MailKitBundle\Mailbox\Value\ProviderMessageId;

/**
 * Optional host port (2.5.0, owner answer Q1 of 2026-11-24): which messages of a change page the host already stores
 * **in that folder**. The sweep asks once per page, before it enqueues the fetches, and skips those messages: a changed
 * message (a read flag, a category) is then not fetched again only for the sink to answer Duplicate. No default
 * implementation; without one every changed message is fetched again.
 *
 * Answer by the folder: a message moved between two followed folders keeps its id, and the host learns the new folder
 * only from the store() call MailKit makes when the target folder's page does not report it as known. An exception is
 * logged and every message of the page is fetched (at least once).
 */
interface MailboxMessageIndexInterface
{
    /**
     * The subset of `$messages` the host stores for this mailbox in this folder.
     *
     * @param list<ProviderMessageId> $messages at most `sync.page_size` ids
     *
     * @return list<ProviderMessageId>
     */
    public function contains(MailboxId $mailbox, FolderRef $folder, array $messages): array;
}
