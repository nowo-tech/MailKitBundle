<?php

declare(strict_types=1);

namespace Nowo\MailKitBundle\Mailbox\Exception;

/**
 * The provider refused the message for good (2.5.0): a 4xx answer such as invalid recipients, a missing original, a
 * message over the provider's size limit, or a refused upload. Nothing was sent; the key stays `failed` (send() with it
 * throws this again) until the row is purged. Keep your draft and send it again with a new key once fixed.
 */
final class MailboxSendFailed extends MailboxException
{
    /**
     * @param string $reason a short code: `refused`, `not_found` or `too_large`, with the provider error code after a
     *                       colon when known (`refused:ErrorInvalidRecipients`)
     */
    public function __construct(public readonly string $reason)
    {
        parent::__construct('The provider refused the message (' . $reason . '); nothing was sent.');
    }
}
