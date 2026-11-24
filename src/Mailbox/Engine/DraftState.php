<?php

declare(strict_types=1);

namespace Nowo\MailKitBundle\Mailbox\Engine;

/**
 * What the provider says about a draft MailKit created (2.5.0): still a draft, sent (Graph immutable ids keep the id),
 * gone, or unreadable right now (a transient failure or an answer without the flag).
 *
 * @internal
 */
enum DraftState
{
    case Draft;
    case Sent;
    case Gone;
    case Unreadable;
}
