<?php

declare(strict_types=1);

namespace Nowo\MailKitBundle\Mailbox\Value;

/**
 * How a {@see SyncedMessage} reached the sink (2.5.0).
 */
enum MessageOrigin: string
{
    /** A change notification or a sweep. */
    case Sync = 'sync';

    /** A sweep that restarted from the resync window after a lost cursor. */
    case Resync = 'resync';

    /** The copy of a message sent through MailKit (send PR of 2.5.0). */
    case SentCopy = 'sent_copy';
}
