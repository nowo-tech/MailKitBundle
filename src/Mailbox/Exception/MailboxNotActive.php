<?php

declare(strict_types=1);

namespace Nowo\MailKitBundle\Mailbox\Exception;

use Nowo\MailKitBundle\Mailbox\Value\MailboxStatus;

/**
 * An on-demand call (attachment read) for a mailbox that is paused, unknown to MailKit or no longer in the host's
 * directory (2.5.0).
 */
final class MailboxNotActive extends MailboxException
{
    /**
     * @param MailboxStatus $status the mailbox's status
     */
    public function __construct(public readonly MailboxStatus $status)
    {
        parent::__construct('The mailbox is not active (' . $status->value . ').');
    }
}
