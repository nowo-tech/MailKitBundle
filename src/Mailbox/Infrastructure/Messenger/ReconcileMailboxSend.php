<?php

declare(strict_types=1);

namespace Nowo\MailKitBundle\Mailbox\Infrastructure\Messenger;

/**
 * Reconcile a send whose outcome was ambiguous (2.5.0 part 2, design section 9.4): once its claim is stale, read the
 * state of its one draft and settle the key (sent, submitted again while it still is a draft, or unknown). Never
 * creates a new draft. Ids only (the idempotency key is the host's opaque id). Route it to an async transport.
 */
final readonly class ReconcileMailboxSend
{
    /**
     * @param string $mailboxId the host's mailbox id
     * @param string $idempotencyKey the send's idempotency key
     * @param int $attempt delayed re-enqueues so far (bounded by the handler)
     */
    public function __construct(
        public string $mailboxId,
        public string $idempotencyKey,
        public int $attempt = 0,
    ) {
    }
}
