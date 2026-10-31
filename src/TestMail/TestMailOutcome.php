<?php

declare(strict_types=1);

namespace Nowo\MailKitBundle\TestMail;

/**
 * Outcome of a "send a test e-mail" action.
 */
enum TestMailOutcome: string
{
    /** Accepted by the transport. */
    case Sent = 'sent';

    /** No valid recipient (argument, `mailer.test_recipient` or the user's e-mail). */
    case NoRecipient = 'no_recipient';

    /** No sender configured (`mailer.from` / `resend.from`). */
    case NoSender = 'no_sender';

    /** The sender is not on a public domain. */
    case InvalidSender = 'invalid_sender';

    /** Nothing to test with (Resend: no stored API key). */
    case NotConfigured = 'not_configured';

    /** Too many test e-mails from this user. */
    case RateLimited = 'rate_limited';

    /** The transport threw (scrubbed error in the result). */
    case Failed = 'failed';

    /**
     * Translation key of the flash / CLI message (`NowoMailKitBundle` domain).
     */
    public function messageKey(): string
    {
        return 'test_email.result.' . $this->value;
    }
}
