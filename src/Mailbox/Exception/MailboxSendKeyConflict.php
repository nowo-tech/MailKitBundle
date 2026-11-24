<?php

declare(strict_types=1);

namespace Nowo\MailKitBundle\Mailbox\Exception;

/**
 * The idempotency key was already used for another message of the same mailbox (2.5.0): nothing was sent. A key
 * names one message (map it to your 409 `CONFLICT_002`).
 */
final class MailboxSendKeyConflict extends MailboxException
{
    /**
     * Fixed message.
     */
    public function __construct()
    {
        parent::__construct('This idempotency key was already used for another message of the mailbox; nothing was sent.');
    }
}
