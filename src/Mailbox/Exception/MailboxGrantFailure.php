<?php

declare(strict_types=1);

namespace Nowo\MailKitBundle\Mailbox\Exception;

use Nowo\MailKitBundle\Mailbox\Event\MailboxGrantFailedEvent;
use Nowo\MailKitBundle\Mailbox\Value\GrantFailureReason;

/**
 * The grant behind a mailbox is gone (2.5.0, design section 13). The host's token port throws it when it can no
 * longer produce a token (revoked, refresh token expired, consent withdrawn, unknown mailbox); MailKit throws it when
 * the provider refuses the grant. The mailbox is paused once ({@see MailboxGrantFailedEvent})
 * and nothing retries until the host calls resume().
 */
final class MailboxGrantFailure extends MailboxException
{
    /**
     * @param string $message fixed text (no token, address or URL)
     */
    public function __construct(
        public readonly GrantFailureReason $reason,
        string $message = 'The mailbox grant is gone.',
    ) {
        parent::__construct($message);
    }
}
