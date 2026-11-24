<?php

declare(strict_types=1);

namespace Nowo\MailKitBundle\Mailbox\Port;

use Nowo\MailKitBundle\Mailbox\Value\ExclusionDecision;
use Nowo\MailKitBundle\Mailbox\Value\MessageEnvelope;

/**
 * Default exclusion policy (2.5.0): keeps every message.
 */
final class KeepAllExclusionPolicy implements MailboxExclusionPolicyInterface
{
    /**
     * Always keep.
     */
    public function decide(MessageEnvelope $envelope): ExclusionDecision
    {
        return ExclusionDecision::keep();
    }
}
