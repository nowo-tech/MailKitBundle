<?php

declare(strict_types=1);

namespace Nowo\MailKitBundle\Mailbox\Engine;

use Nowo\MailKitBundle\Mailbox\Exception\MailboxGrantFailure;
use Nowo\MailKitBundle\Mailbox\Exception\MailboxProviderFailure;
use Nowo\MailKitBundle\Mailbox\Exception\MailboxTokenUnavailable;
use Nowo\MailKitBundle\Mailbox\Value\AttachmentRef;
use Nowo\MailKitBundle\Mailbox\Value\FetchedAttachment;
use Nowo\MailKitBundle\Mailbox\Value\FolderRef;
use Nowo\MailKitBundle\Mailbox\Value\MailboxProvider;
use Nowo\MailKitBundle\Mailbox\Value\MessageOrigin;
use Nowo\MailKitBundle\Mailbox\Value\ProviderAttachmentId;
use Nowo\MailKitBundle\Mailbox\Value\ProviderMessageId;
use Nowo\MailKitBundle\Mailbox\Value\SyncedMessage;

/**
 * One provider behind the delegated-mailbox engine (2.5.0: Microsoft Graph; Gmail in 2.6.0 implements the same
 * interface). Every method fails with {@see MailboxGrantFailure} (grant, consent or account errors only),
 * {@see MailboxTokenUnavailable} (from the host's port) or {@see MailboxProviderFailure}; never with a provider or
 * HTTP type. The send PR adds the draft / send operations next to these.
 *
 * @internal
 */
interface MailboxGatewayInterface
{
    /**
     * The provider served.
     */
    public function provider(): MailboxProvider;

    /**
     * The account behind the session's token.
     */
    public function account(MailboxSession $session): ProviderAccount;

    /**
     * Creates a change-notification subscription on a folder.
     *
     * @param int $expiresAt requested expiry (UNIX seconds)
     */
    public function subscribe(MailboxSession $session, FolderRef $folder, string $notificationUrl, string $clientState, int $expiresAt): RemoteSubscription;

    /**
     * Extends a subscription; the new expiry, or null when the provider no longer knows it.
     */
    public function renew(MailboxSession $session, string $subscriptionId, int $expiresAt): ?int;

    /**
     * Deletes a subscription (an unknown one counts as deleted).
     */
    public function unsubscribe(MailboxSession $session, string $subscriptionId): void;

    /**
     * One page of a folder's changes from the cursor, or from `$since` without one.
     *
     * @param int $since UNIX seconds of the window start (first sweep or resync)
     *
     * @throws CursorExpired the provider no longer knows the cursor
     */
    public function changes(MailboxSession $session, FolderRef $folder, ?string $cursor, int $since, int $pageSize): ChangePage;

    /**
     * One message with its envelope (one provider call when it is within the size limit), or null when it is gone.
     */
    public function fetch(MailboxSession $session, FolderRef $folder, ProviderMessageId $message): ?FetchedMessage;

    /**
     * The attachment references of a kept message (no content).
     *
     * @return list<AttachmentRef>
     */
    public function attachments(MailboxSession $session, FetchedMessage $message): array;

    /**
     * The normalised, sanitised message for the sink.
     *
     * @param list<AttachmentRef> $attachments
     */
    public function normalize(MailboxSession $session, FetchedMessage $message, array $attachments, MessageOrigin $origin): SyncedMessage;

    /**
     * Streams one file attachment into a capped temporary stream.
     */
    public function open(MailboxSession $session, ProviderMessageId $message, ProviderAttachmentId $attachment, int $maxBytes): FetchedAttachment;
}
