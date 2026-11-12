<?php

declare(strict_types=1);

namespace Nowo\MailKitBundle\Graph\Inbound\Message;

/**
 * Messenger message (1.9): run the delta sweep of one profile (null = every inbound profile). Schedule it (Symfony
 * Scheduler `RecurringMessage::every('5 minutes', new SweepGraphMailbox())`) or dispatch it on a lifecycle event.
 */
final class SweepGraphMailbox
{
    /**
     * @param string|null $profile mailbox profile, null for all
     */
    public function __construct(
        public readonly ?string $profile = null,
    ) {
    }
}
