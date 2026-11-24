<?php

declare(strict_types=1);

namespace Nowo\MailKitBundle\Mailbox\Port;

use Nowo\MailKitBundle\Mailbox\Value\ProviderCall;

/**
 * Optional host port (2.5.0, Q-MB-13): told after every provider call, for per-mailbox metering. Must not throw (an
 * exception is logged and ignored: metering never breaks sync); write asynchronously.
 */
interface MailboxCallObserverInterface
{
    /**
     * One provider call happened.
     */
    public function called(ProviderCall $call): void;
}
