<?php

declare(strict_types=1);

namespace Nowo\MailKitBundle\Mailbox\Value;

use Nowo\MailKitBundle\Mailbox\Service\MailboxSyncInterface;

/**
 * Sync state of a delegated mailbox (2.5.0).
 */
enum MailboxStatus: string
{
    /** Synced. */
    case Active = 'active';

    /** Paused by the host ({@see MailboxSyncInterface::pause()}). */
    case PausedByHost = 'paused_host';

    /** Paused by a grant, consent or account error; only the host's resume() restarts it. */
    case PausedGrant = 'paused_grant';

    /** MailKit has no row for the mailbox (never started, or disconnected). */
    case Unknown = 'unknown';
}
