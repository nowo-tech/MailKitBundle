<?php

declare(strict_types=1);

namespace Nowo\MailKitBundle\Mailbox\Infrastructure\Messenger;

/**
 * Re-check the account and create / renew / recreate the subscriptions of one delegated mailbox, or of every active
 * one (2.5.0): the hourly renewal, or a Graph lifecycle event. Ids only.
 */
final readonly class RenewMailboxSubscriptions
{
    /** Lifecycle `reauthorizationRequired`: renew at once. */
    public const REAUTHORIZE = 'reauthorize';

    /** Lifecycle `subscriptionRemoved`: recreate. */
    public const REMOVED = 'removed';

    /**
     * @param string|null $mailboxId one mailbox, or null for every active one
     * @param string|null $reason null, {@see REAUTHORIZE} or {@see REMOVED}
     * @param string|null $subscriptionId the subscription a lifecycle event names
     * @param int $attempt delayed re-enqueues after Retry-After answers (bounded by the handler)
     */
    public function __construct(
        public ?string $mailboxId = null,
        public ?string $reason = null,
        public ?string $subscriptionId = null,
        public int $attempt = 0,
    ) {
    }
}
