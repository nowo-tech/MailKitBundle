<?php

declare(strict_types=1);

namespace Nowo\MailKitBundle\Mailbox\Exception;

/**
 * The host cannot produce a token right now (its token endpoint is down, 2.5.0): transient, retried by Messenger;
 * never pauses the mailbox.
 */
final class MailboxTokenUnavailable extends MailboxException
{
    /**
     * @param string $message fixed text (no token or URL)
     * @param int|null $retryAfter seconds to wait, when known
     */
    public function __construct(string $message = 'The mailbox access token is unavailable right now.', public readonly ?int $retryAfter = null)
    {
        parent::__construct($message);
    }
}
