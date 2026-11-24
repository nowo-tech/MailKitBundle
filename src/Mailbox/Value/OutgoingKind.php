<?php

declare(strict_types=1);

namespace Nowo\MailKitBundle\Mailbox\Value;

/**
 * What a delegated mailbox sends (2.5.0, design section 6.5).
 */
enum OutgoingKind: string
{
    /** A new message (Graph: a MIME draft). */
    case New = 'new';

    /** A reply to one message (Graph: `createReply` on the original). */
    case Reply = 'reply';

    /** A reply to all (Graph: `createReplyAll` on the original). */
    case ReplyAll = 'reply_all';

    /** A forward (Graph: `createForward` on the original; the original's attachments travel with it). */
    case Forward = 'forward';
}
