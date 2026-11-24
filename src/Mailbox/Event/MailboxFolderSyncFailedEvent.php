<?php

declare(strict_types=1);

namespace Nowo\MailKitBundle\Mailbox\Event;

use Nowo\MailKitBundle\Mailbox\Value\FolderRef;
use Nowo\MailKitBundle\Mailbox\Value\MailboxId;

/**
 * A followed folder's sweeps stopped on a provider refusal that is neither a grant failure nor transient (2.5.0, audit
 * ZAT-R1): an unrecognised answer, an oversized page, a folder Graph no longer knows. Dispatched once per transition
 * (the error is kept on the folder's cursor, shown by status() and cleared by the next good sweep). The mailbox stays
 * active; its other folders keep syncing. Ids only.
 */
final readonly class MailboxFolderSyncFailedEvent
{
    /**
     * @param string $reason `refused`, `too_large` or `not_found`
     */
    public function __construct(
        public MailboxId $mailbox,
        public FolderRef $folder,
        public string $reason,
    ) {
    }
}
