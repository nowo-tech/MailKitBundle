<?php

declare(strict_types=1);

namespace Nowo\MailKitBundle\Mailbox\Port;

use Nowo\MailKitBundle\Mailbox\Value\ProviderCall;

/**
 * Default call observer (2.5.0): ignores the calls.
 */
final class NullMailboxCallObserver implements MailboxCallObserverInterface
{
    /**
     * Does nothing.
     */
    public function called(ProviderCall $call): void
    {
    }
}
