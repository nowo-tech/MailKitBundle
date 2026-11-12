<?php

declare(strict_types=1);

namespace Nowo\MailKitBundle\Graph\Inbound;

/**
 * What the host did with an inbound message (1.9), returned in an {@see InboundDecision}.
 */
enum InboundAction: string
{
    /** Accepted into the host's model (e.g. a task thread). */
    case Route = 'route';

    /** Kept aside for a person to decide (e.g. an unknown sender). */
    case Quarantine = 'quarantine';

    /** Dropped (spam, out of scope); the message is still marked as processed. */
    case Discard = 'discard';
}
