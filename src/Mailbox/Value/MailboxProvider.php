<?php

declare(strict_types=1);

namespace Nowo\MailKitBundle\Mailbox\Value;

/**
 * Provider of a delegated mailbox: Microsoft Graph (2.5.0, sync and send) or Gmail (phase B2, **send-only**).
 *
 * Owner decision D-20261124-09: a Gmail mailbox only sends. The host requests exactly `openid email
 * https://www.googleapis.com/auth/gmail.send`; there is no `gmail.readonly` (so no CASA assessment), no inbox, no folder,
 * no sync, no attachment download and no sent copy. Hosts show it plainly in their UI ({@see isSendOnly()}).
 */
enum MailboxProvider: string
{
    /** Microsoft Graph, delegated permissions (Microsoft 365 and Outlook.com): sync and send. */
    case Graph = 'graph';

    /** Gmail API, send-only (scope `gmail.send`; D-20261124-09). */
    case Gmail = 'gmail';

    /**
     * True when the provider only sends: no inbox, no followed folder, no sync, no attachment download, no sent copy.
     */
    public function isSendOnly(): bool
    {
        return $this === self::Gmail;
    }
}
