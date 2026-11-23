<?php

declare(strict_types=1);

namespace Nowo\MailKitBundle\Mailbox\Event;

use Nowo\MailKitBundle\Mailbox\Value\GrantFailureReason;
use Nowo\MailKitBundle\Mailbox\Value\MailboxId;
use Nowo\MailKitBundle\Mailbox\Value\MailboxProvider;

/**
 * A mailbox moved to `PausedGrant` (2.5.0): dispatched exactly once per transition, by the worker whose conditional
 * update won, after MailKit's write. Ids only. Nothing retries until the host calls resume() (after a reconnect).
 */
final readonly class MailboxGrantFailedEvent
{
    /**
     * Carries the mailbox, its provider and the reason.
     */
    public function __construct(
        public MailboxId $mailbox,
        public MailboxProvider $provider,
        public GrantFailureReason $reason,
    ) {
    }
}
