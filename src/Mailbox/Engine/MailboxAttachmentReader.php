<?php

declare(strict_types=1);

namespace Nowo\MailKitBundle\Mailbox\Engine;

use InvalidArgumentException;
use Nowo\MailKitBundle\Mailbox\Exception\MailboxGrantFailure;
use Nowo\MailKitBundle\Mailbox\Exception\MailboxNotActive;
use Nowo\MailKitBundle\Mailbox\Exception\MailboxProviderFailure;
use Nowo\MailKitBundle\Mailbox\Exception\MailboxTokenUnavailable;
use Nowo\MailKitBundle\Mailbox\Service\MailboxAttachmentReaderInterface;
use Nowo\MailKitBundle\Mailbox\Value\FetchedAttachment;
use Nowo\MailKitBundle\Mailbox\Value\MailboxId;
use Nowo\MailKitBundle\Mailbox\Value\ProviderAttachmentId;
use Nowo\MailKitBundle\Mailbox\Value\ProviderMessageId;

use function min;

/**
 * Default {@see MailboxAttachmentReaderInterface} (2.5.0): checks the mailbox is active, takes a token for this call
 * only and streams the attachment through the provider gateway into a capped temporary stream. A grant failure
 * pauses the mailbox (one event) and is rethrown. Stateless.
 */
final readonly class MailboxAttachmentReader implements MailboxAttachmentReaderInterface
{
    /**
     * @param int $maxAttachmentBytes `mailboxes.limits.max_attachment_bytes` (ceiling of `$maxBytes`)
     */
    public function __construct(
        private MailboxSyncEngine $engine,
        private int $maxAttachmentBytes = 26_214_400,
    ) {
    }

    /**
     * {@inheritDoc}
     *
     * @throws InvalidArgumentException when `$maxBytes` is below 1
     * @throws MailboxNotActive the mailbox is paused or unknown
     * @throws MailboxGrantFailure the grant is gone (the mailbox is paused)
     * @throws MailboxTokenUnavailable the host has no token right now
     * @throws MailboxProviderFailure too large, not found, not downloadable or transient
     */
    public function open(MailboxId $mailbox, ProviderMessageId $message, ProviderAttachmentId $attachment, int $maxBytes): FetchedAttachment
    {
        if ($maxBytes < 1) {
            throw new InvalidArgumentException('The attachment size limit must be at least one byte.');
        }
        $context = $this->engine->active($mailbox);
        if ($context === null) {
            throw new MailboxNotActive($this->engine->status($mailbox)->status);
        }
        [$settings, $gateway, $session] = $context;

        try {
            return $gateway->open($session, $message, $attachment, min($maxBytes, $this->maxAttachmentBytes));
        } catch (MailboxGrantFailure $failure) {
            $this->engine->grantFailed($mailbox, $settings->provider, $failure->reason);

            throw $failure;
        }
    }
}
