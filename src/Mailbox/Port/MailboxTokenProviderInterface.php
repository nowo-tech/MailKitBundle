<?php

declare(strict_types=1);

namespace Nowo\MailKitBundle\Mailbox\Port;

use Nowo\MailKitBundle\Mailbox\Exception\MailboxGrantFailure;
use Nowo\MailKitBundle\Mailbox\Exception\MailboxTokenUnavailable;
use Nowo\MailKitBundle\Mailbox\Value\MailboxAccessToken;
use Nowo\MailKitBundle\Mailbox\Value\MailboxId;

/**
 * Host port (2.5.0, required with `mailboxes.enabled`): the current access token of a delegated mailbox. MailKit runs
 * no OAuth flow (Q-254-15 = b): the host owns the connection, the refresh token and the refresh. MailKit asks once per
 * Messenger message (or service call) and never caches, logs or stores the token.
 *
 * Scopes the host requests for Microsoft Graph: `Mail.ReadWrite`, `Mail.Send`, `offline_access`, `User.Read`.
 */
interface MailboxTokenProviderInterface
{
    /**
     * Current access token of the mailbox, refreshed by the host when needed.
     *
     * @throws MailboxGrantFailure the grant is gone (revoked, refresh token expired, consent withdrawn, unknown mailbox)
     * @throws MailboxTokenUnavailable transient (the host's token endpoint is down): retried by Messenger
     */
    public function accessToken(MailboxId $mailbox): MailboxAccessToken;

    /**
     * The provider answered 401 to the token with this fingerprint ({@see MailboxAccessToken::fingerprint()}): drop it
     * so the next accessToken() refreshes.
     */
    public function rejected(MailboxId $mailbox, string $tokenFingerprint): void;
}
