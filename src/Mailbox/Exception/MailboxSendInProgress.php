<?php

declare(strict_types=1);

namespace Nowo\MailKitBundle\Mailbox\Exception;

/**
 * The idempotency key is held by another call, or the outcome of its last attempt is being reconciled with the
 * provider (2.5.0, design section 9.4): nothing was sent by this call. Retry later with the **same** key (map it to
 * your 409 `CONFLICT_001`); a later send() returns the sent message (`replayed: true`) or the final failure.
 */
final class MailboxSendInProgress extends MailboxException
{
    /**
     * @param bool $reconciling the provider may have sent it; MailKit checks the draft state before anything else
     */
    public function __construct(public readonly bool $reconciling = false)
    {
        parent::__construct($reconciling
            ? 'The outcome of this send is being reconciled with the provider; retry later with the same idempotency key.'
            : 'Another call is sending with this idempotency key; retry later with the same key.');
    }
}
