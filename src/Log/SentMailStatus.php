<?php

declare(strict_types=1);

namespace Nowo\MailKitBundle\Log;

/**
 * Delivery state of a sent-mail log row.
 */
enum SentMailStatus: string
{
    /** Handed to the mailer; waiting for the (async) transport. */
    case Queued = 'queued';

    /** Accepted by the transport. */
    case Sent = 'sent';

    /** The transport threw. */
    case Failed = 'failed';
}
