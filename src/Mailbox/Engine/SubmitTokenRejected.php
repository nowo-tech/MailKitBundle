<?php

declare(strict_types=1);

namespace Nowo\MailKitBundle\Mailbox\Engine;

use Nowo\MailKitBundle\Mailbox\Exception\MailboxException;

/**
 * The provider answered 401 to a submit (2.5.0, audit ZAV-L1): the token was reported to the host and dropped; the
 * provider took nothing. The gateway never retries a submit itself: the sender refreshes the token, renews its claim
 * and checks its submit window first, so no other worker can submit the same draft meanwhile.
 *
 * @internal
 */
final class SubmitTokenRejected extends MailboxException
{
    /**
     * Fixed message.
     */
    public function __construct()
    {
        parent::__construct('Microsoft Graph refused the token of the submit (HTTP 401).');
    }
}
