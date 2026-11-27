<?php

declare(strict_types=1);

namespace Nowo\MailKitBundle\Mailbox\Engine;

use Nowo\MailKitBundle\Mailbox\Value\ProviderMessageId;

/**
 * What a send-only provider answered to a submit (phase B2): the sent message's provider id and its thread.
 *
 * @internal
 */
final readonly class SubmittedMessage
{
    /**
     * @param string|null $threadId provider thread id (Gmail threadId), when given
     */
    public function __construct(
        public ProviderMessageId $id,
        public ?string $threadId,
    ) {
    }
}
