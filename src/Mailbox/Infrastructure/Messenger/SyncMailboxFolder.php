<?php

declare(strict_types=1);

namespace Nowo\MailKitBundle\Mailbox\Infrastructure\Messenger;

/**
 * Sweep one followed folder of a delegated mailbox (2.5.0): from a notification lifecycle event (`missed`), the
 * scheduled sweep, start / resume, or a rerun requested while another worker held the folder's lease. Ids only.
 * Route it to an async transport.
 */
final readonly class SyncMailboxFolder
{
    /**
     * @param string $mailboxId the host's mailbox id
     * @param string $folderKey followed folder key
     */
    public function __construct(
        public string $mailboxId,
        public string $folderKey,
    ) {
    }
}
