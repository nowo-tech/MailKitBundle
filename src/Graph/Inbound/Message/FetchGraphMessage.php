<?php

declare(strict_types=1);

namespace Nowo\MailKitBundle\Graph\Inbound\Message;

/**
 * Messenger message (1.9): fetch, normalise and hand one Graph message to the host. Dispatched by the webhook and by
 * the delta sweep; route it to an async transport. Carries ids only.
 */
final class FetchGraphMessage
{
    /**
     * @param string $profile mailbox profile
     * @param string $graphMessageId Graph message id
     */
    public function __construct(
        public readonly string $profile,
        public readonly string $graphMessageId,
    ) {
    }
}
