<?php

declare(strict_types=1);

namespace Nowo\MailKitBundle\Mailbox\Value;

/**
 * Answer of the host's sink to one {@see SyncedMessage} (2.5.0).
 */
enum StoreOutcome: string
{
    /** The message was stored. */
    case Stored = 'stored';

    /** The host already holds it (unique key on mailbox id + provider message id); a normal outcome. */
    case Duplicate = 'duplicate';
}
