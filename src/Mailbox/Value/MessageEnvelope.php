<?php

declare(strict_types=1);

namespace Nowo\MailKitBundle\Mailbox\Value;

use DateTimeImmutable;
use Nowo\MailKitBundle\Sender\MailAddress;

/**
 * What the host's exclusion policy decides on (2.5.0): the mailbox, the folder, the provider id, the sender fields,
 * List-Id, subject and date. `$complete` is false when only the fields of the provider's change page were known (the
 * message was not fetched yet: List-Id and subject are then null); the policy runs again with the full envelope after
 * the fetch. Values come from the sender: untrusted.
 */
final readonly class MessageEnvelope
{
    /**
     * @param string|null $listId List-Id without angle brackets (at most 255 characters), or null
     * @param string|null $subject subject without control characters, or null when not known yet
     * @param bool $complete false = from the change page only, before the fetch
     */
    public function __construct(
        public MailboxId $mailbox,
        public FolderRef $folder,
        public ProviderMessageId $message,
        public ?MailAddress $from,
        public ?MailAddress $sender,
        public ?string $listId,
        public ?string $subject,
        public ?DateTimeImmutable $receivedAt,
        public bool $complete,
    ) {
    }
}
