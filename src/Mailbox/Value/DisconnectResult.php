<?php

declare(strict_types=1);

namespace Nowo\MailKitBundle\Mailbox\Value;

use Nowo\MailKitBundle\Mailbox\Service\MailboxSyncInterface;

/**
 * Outcome of {@see MailboxSyncInterface::disconnect()} (2.5.0).
 */
final readonly class DisconnectResult
{
    /**
     * @param int $subscriptionsDeleted remote subscriptions deleted at the provider
     * @param bool $remoteCleanupFailed at least one remote subscription could not be deleted (it expires by itself
     *                                  within 7 days; its notifications are refused as unknown)
     * @param int $rowsDeleted MailKit rows deleted for the mailbox
     */
    public function __construct(
        public int $subscriptionsDeleted,
        public bool $remoteCleanupFailed,
        public int $rowsDeleted,
    ) {
    }
}
