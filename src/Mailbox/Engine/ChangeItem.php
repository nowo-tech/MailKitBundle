<?php

declare(strict_types=1);

namespace Nowo\MailKitBundle\Mailbox\Engine;

use DateTimeImmutable;
use Nowo\MailKitBundle\Mailbox\Value\ProviderMessageId;
use Nowo\MailKitBundle\Sender\MailAddress;

/**
 * One entry of a provider change page (2.5.0): a new or changed message with the envelope fields the page gave, or a
 * removal.
 *
 * @internal
 */
final readonly class ChangeItem
{
    /**
     * @param bool $removed deleted or moved out of the folder
     * @param bool $hasEnvelope the page gave the sender fields (the exclusion policy can run before the fetch)
     */
    public function __construct(
        public ProviderMessageId $id,
        public bool $removed,
        public bool $hasEnvelope = false,
        public ?MailAddress $from = null,
        public ?MailAddress $sender = null,
        public ?DateTimeImmutable $receivedAt = null,
    ) {
    }
}
