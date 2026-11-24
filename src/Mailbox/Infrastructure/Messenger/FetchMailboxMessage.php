<?php

declare(strict_types=1);

namespace Nowo\MailKitBundle\Mailbox\Infrastructure\Messenger;

use Nowo\MailKitBundle\Mailbox\Value\MessageOrigin;

/**
 * Fetch one message of a delegated mailbox and hand it to the sink (2.5.0): from a change notification or a sweep
 * page. Ids only. Route it to an async transport.
 */
final readonly class FetchMailboxMessage
{
    /**
     * @param string $mailboxId the host's mailbox id
     * @param string $folderKey followed folder key
     * @param string $messageId provider message id
     * @param string $origin {@see MessageOrigin} value
     * @param int $attempt delayed re-enqueues after Retry-After answers (bounded by the handler)
     */
    public function __construct(
        public string $mailboxId,
        public string $folderKey,
        public string $messageId,
        public string $origin = MessageOrigin::Sync->value,
        public int $attempt = 0,
    ) {
    }
}
