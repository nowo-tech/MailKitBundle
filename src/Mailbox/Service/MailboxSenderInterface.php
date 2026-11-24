<?php

declare(strict_types=1);

namespace Nowo\MailKitBundle\Mailbox\Service;

use InvalidArgumentException;
use LogicException;
use Nowo\MailKitBundle\Mailbox\Exception\MailboxGrantFailure;
use Nowo\MailKitBundle\Mailbox\Exception\MailboxNotActive;
use Nowo\MailKitBundle\Mailbox\Exception\MailboxProviderFailure;
use Nowo\MailKitBundle\Mailbox\Exception\MailboxSendFailed;
use Nowo\MailKitBundle\Mailbox\Exception\MailboxSendInProgress;
use Nowo\MailKitBundle\Mailbox\Exception\MailboxSendKeyConflict;
use Nowo\MailKitBundle\Mailbox\Exception\MailboxSendOutcomeUnknown;
use Nowo\MailKitBundle\Mailbox\Exception\MailboxTokenUnavailable;
use Nowo\MailKitBundle\Mailbox\Value\MailboxId;
use Nowo\MailKitBundle\Mailbox\Value\OutgoingMailboxMail;
use Nowo\MailKitBundle\Mailbox\Value\SentMessage;

/**
 * Sends from a delegated mailbox (2.5.0, design sections 6.5 and 9.4), called by the host.
 */
interface MailboxSenderInterface
{
    /**
     * Sends from the mailbox's own address, at most once per idempotency key (Q-MB-20), after checking that the account
     * behind the token is the one bound to the mailbox. Returns the provider ids at once; the full sent copy reaches the
     * sink later (`MessageOrigin::SentCopy`), whether Sent Items is followed or not (Q-MB-6). Not written to MailKit's
     * sent-mail log (Q-MB-14).
     *
     * Call it **outside** a database transaction on the mailbox connection (a rollback after the provider sent would
     * forget the send): use a dedicated `mailboxes.connection` when your handlers run in a transaction. Do not reuse a
     * key that was in flight after `MailboxSyncInterface::disconnect()` (its row is gone; use a new key).
     *
     * @throws InvalidArgumentException the message breaks a configured `send.*` limit (nothing was claimed)
     * @throws LogicException called inside a transaction on the mailbox connection (nothing was claimed)
     * @throws MailboxNotActive the mailbox is paused or unknown
     * @throws MailboxSendInProgress another call holds the key, or the outcome is being reconciled: retry with the same key
     * @throws MailboxSendKeyConflict the key was used for another message
     * @throws MailboxSendFailed the provider refused the message for good
     * @throws MailboxSendOutcomeUnknown it cannot be proven whether it was sent; never sent again
     * @throws MailboxGrantFailure the grant is gone or the token belongs to another account (the mailbox is paused)
     * @throws MailboxTokenUnavailable the host has no token right now: retry with the same key
     * @throws MailboxProviderFailure transient, before anything was submitted: retry with the same key
     */
    public function send(MailboxId $mailbox, OutgoingMailboxMail $mail): SentMessage;
}
