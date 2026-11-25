<?php

declare(strict_types=1);

namespace Nowo\MailKitBundle\Mailbox\Gmail;

/**
 * The OAuth scopes of a Gmail mailbox (phase B2, owner decision D-20261124-09): **exactly** `openid email
 * https://www.googleapis.com/auth/gmail.send`. Never `gmail.readonly`, `gmail.modify`, `gmail.compose` or
 * `https://mail.google.com/`: Gmail mailboxes are send-only, so no restricted scope and no CASA assessment.
 *
 * MailKit runs no OAuth flow (Q-254-15 = b): the host requests these scopes with its own PKCE flow, keeps the refresh
 * token and hands MailKit current access tokens through `MailboxTokenProviderInterface`, with the granted scopes
 * (Google's token response `scope` field) in `MailboxAccessToken::$scopes`. A token without {@see SEND} pauses the
 * mailbox `ScopeMissing`.
 */
final class GmailScopes
{
    /** The send scope (Google "sensitive" scope: OAuth verification, no CASA). */
    public const SEND = 'https://www.googleapis.com/auth/gmail.send';

    /** What the host requests, in this order. */
    public const REQUESTED = ['openid', 'email', self::SEND];
}
