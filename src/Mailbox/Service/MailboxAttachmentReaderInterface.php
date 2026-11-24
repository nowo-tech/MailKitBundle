<?php

declare(strict_types=1);

namespace Nowo\MailKitBundle\Mailbox\Service;

use Nowo\MailKitBundle\Mailbox\Exception\MailboxGrantFailure;
use Nowo\MailKitBundle\Mailbox\Exception\MailboxNotActive;
use Nowo\MailKitBundle\Mailbox\Exception\MailboxProviderFailure;
use Nowo\MailKitBundle\Mailbox\Exception\MailboxTokenUnavailable;
use Nowo\MailKitBundle\Mailbox\Value\FetchedAttachment;
use Nowo\MailKitBundle\Mailbox\Value\MailboxId;
use Nowo\MailKitBundle\Mailbox\Value\ProviderAttachmentId;
use Nowo\MailKitBundle\Mailbox\Value\ProviderMessageId;

/**
 * Attachments on demand (2.5.0): MailKit stores no attachment content.
 */
interface MailboxAttachmentReaderInterface
{
    /**
     * Streams one file attachment into a capped temporary stream (at most `$maxBytes`, itself capped by
     * `mailboxes.limits.max_attachment_bytes`) with the type detected from the bytes and a neutralised file name.
     *
     * @throws MailboxNotActive the mailbox is paused or unknown
     * @throws MailboxGrantFailure the grant is gone (the mailbox is paused)
     * @throws MailboxTokenUnavailable the host has no token right now
     * @throws MailboxProviderFailure too large, not found, not downloadable (attached e-mail, cloud link) or transient
     */
    public function open(MailboxId $mailbox, ProviderMessageId $message, ProviderAttachmentId $attachment, int $maxBytes): FetchedAttachment;
}
