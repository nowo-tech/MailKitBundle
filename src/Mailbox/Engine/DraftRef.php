<?php

declare(strict_types=1);

namespace Nowo\MailKitBundle\Mailbox\Engine;

use Nowo\MailKitBundle\Mailbox\Value\ProviderMessageId;

/**
 * A draft MailKit created to send (2.5.0): its provider id (the sent copy keeps it), its Message-ID without angle
 * brackets ('' when the provider gave none) and its provider thread.
 *
 * @internal
 */
final readonly class DraftRef
{
    /**
     * @param string $internetMessageId Message-ID without angle brackets, or ''
     * @param string|null $threadId provider thread id (Graph conversationId)
     */
    public function __construct(
        public ProviderMessageId $id,
        public string $internetMessageId,
        public ?string $threadId,
    ) {
    }
}
