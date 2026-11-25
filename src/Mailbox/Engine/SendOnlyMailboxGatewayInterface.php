<?php

declare(strict_types=1);

namespace Nowo\MailKitBundle\Mailbox\Engine;

use Nowo\MailKitBundle\Mailbox\Exception\MailboxGrantFailure;
use Nowo\MailKitBundle\Mailbox\Exception\MailboxProviderFailure;
use Nowo\MailKitBundle\Mailbox\Exception\MailboxTokenUnavailable;
use Nowo\MailKitBundle\Mailbox\Value\OutgoingMailboxMail;

/**
 * A provider that only sends (phase B2: Gmail with the `gmail.send` scope, D-20261124-09). It cannot read the mailbox,
 * so there is no draft, no draft state and no sent copy: a message is prepared in memory, then handed over with **one**
 * request that is never retried here. The engine creates no cursor, subscription or sweep for its mailboxes; the
 * {@see MailboxGatewayInterface} sync and draft methods of such a gateway refuse.
 *
 * The sender decides from the answer alone: a 4xx answer proves nothing was sent; any other failure (transport, 5xx, an
 * unusable 2xx answer) cannot be checked without reading the mailbox, so the key becomes `unknown` and is never sent
 * again.
 *
 * @internal
 */
interface SendOnlyMailboxGatewayInterface
{
    /**
     * Builds the message (no provider call). Only new messages: a reply or forward needs the original, which a send-only
     * grant cannot read.
     *
     * @param string $messageId Message-ID without angle brackets (derived from the idempotency key)
     *
     * @throws MailboxProviderFailure a message that cannot be built or is too large (nothing was sent)
     */
    public function prepare(MailboxSession $session, OutgoingMailboxMail $mail, string $messageId): PreparedMessage;

    /**
     * Hands the prepared message to the provider with one request (never retried here).
     *
     * @throws SubmitTokenRejected the provider answered 401 (the token was dropped; nothing was sent; the caller may retry once)
     * @throws MailboxGrantFailure a grant, scope or account refusal (nothing was sent)
     * @throws MailboxTokenUnavailable from the host's port (nothing was sent)
     * @throws MailboxProviderFailure any other failure; its status tells whether the provider answered
     */
    public function submit(MailboxSession $session, PreparedMessage $message): SubmittedMessage;
}
