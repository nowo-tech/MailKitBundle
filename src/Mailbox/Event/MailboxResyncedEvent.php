<?php

declare(strict_types=1);

namespace Nowo\MailKitBundle\Mailbox\Event;

use Nowo\MailKitBundle\Mailbox\Value\FolderRef;
use Nowo\MailKitBundle\Mailbox\Value\MailboxId;

/**
 * A folder's cursor was lost (Graph 410 `syncStateNotFound` / `resyncRequired`) and its sweep restarted from the
 * resync window (2.5.0). Already stored messages reach the sink again and answer Duplicate. Ids only.
 */
final readonly class MailboxResyncedEvent
{
    /** Reason: the provider no longer knows the cursor. */
    public const CURSOR_EXPIRED = 'cursor_expired';

    /**
     * @param string $reason {@see CURSOR_EXPIRED}
     */
    public function __construct(
        public MailboxId $mailbox,
        public FolderRef $folder,
        public string $reason = self::CURSOR_EXPIRED,
    ) {
    }
}
