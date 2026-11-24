<?php

declare(strict_types=1);

namespace Nowo\MailKitBundle\Mailbox\Value;

/**
 * Why a message left a followed folder (2.5.0, audit ZAT-L3): MailKit asks Graph whether the id still exists before
 * telling the sink.
 */
enum RemovalReason: string
{
    /** The message no longer exists in the mailbox (deleted and purged, or permanently deleted). */
    case Deleted = 'deleted';

    /**
     * The message still exists in another folder (moved, archived, or moved to Deleted Items). When that folder is
     * followed too, the same id also reaches store() (answering Duplicate): update its folder, never delete it.
     */
    case MovedOut = 'moved_out';
}
