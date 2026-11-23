<?php

declare(strict_types=1);

namespace Nowo\MailKitBundle\Mailbox\Port;

use Nowo\MailKitBundle\Mailbox\Value\ExclusionDecision;
use Nowo\MailKitBundle\Mailbox\Value\MessageEnvelope;

/**
 * Optional host port (2.5.0): keeps senders, domains, lists or folders out before anything is stored (Q-MB-3). Called
 * before the sink on every path, and before the fetch when the provider's change page gives the sender
 * (`$envelope->complete === false`). An exception fails the message (Messenger retries it); it is never stored.
 * Without a policy everything is kept.
 */
interface MailboxExclusionPolicyInterface
{
    /**
     * Keep or exclude (with a rule id for the host's counter).
     */
    public function decide(MessageEnvelope $envelope): ExclusionDecision;
}
