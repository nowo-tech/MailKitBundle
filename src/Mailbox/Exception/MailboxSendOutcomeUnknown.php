<?php

declare(strict_types=1);

namespace Nowo\MailKitBundle\Mailbox\Exception;

/**
 * MailKit cannot prove whether the message was sent (2.5.0): the draft it submitted disappeared before its state could
 * be read, or the reconcile attempts (`send.max_attempts`) ran out. MailKit never sends it again; ask the person to
 * check the mailbox's Sent Items before sending a new message.
 */
final class MailboxSendOutcomeUnknown extends MailboxException
{
    /**
     * Fixed message.
     */
    public function __construct()
    {
        parent::__construct('Whether the message was sent cannot be proven; MailKit does not send it again.');
    }
}
