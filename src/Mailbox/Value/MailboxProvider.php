<?php

declare(strict_types=1);

namespace Nowo\MailKitBundle\Mailbox\Value;

/**
 * Provider of a delegated mailbox (2.5.0: Microsoft Graph; Gmail is planned for 2.6.0).
 */
enum MailboxProvider: string
{
    /** Microsoft Graph, delegated permissions (Microsoft 365 and Outlook.com). */
    case Graph = 'graph';

    /** Gmail API (2.6.0; not built yet). */
    case Gmail = 'gmail';
}
