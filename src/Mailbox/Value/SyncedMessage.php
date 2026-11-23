<?php

declare(strict_types=1);

namespace Nowo\MailKitBundle\Mailbox\Value;

use DateTimeImmutable;
use Nowo\MailKitBundle\Graph\Inbound\AuthenticationResults;
use Nowo\MailKitBundle\Sender\MailAddress;

/**
 * One message of a delegated mailbox, normalised for the host's sink (2.5.0, design section 5.3).
 *
 * Everything here comes from the sender: escape text and names on output. `$html` is already sanitised by
 * MailKit's mail HTML sanitiser (no script, event handler, form, iframe, `style` element or attribute; links `http`,
 * `https`, `mailto` only; with `html.remote_images: blocked` remote images carry their URL in
 * `data-mail-kit-remote-src` instead of `src`, counted in `$remoteImagesBlocked`; `cid:` images reference inline
 * {@see AttachmentRef}s). No attachment content: read it on demand. `$bcc` is filled for messages the mailbox sent.
 *
 * The sink stores it idempotently by (`$mailbox`, `$id`): a second delivery must answer
 * {@see StoreOutcome::Duplicate}.
 */
final readonly class SyncedMessage
{
    /** Longest snippet, in characters. */
    public const MAX_SNIPPET = 255;

    /**
     * @param string|null $providerThreadId provider thread id (Graph conversationId), or null
     * @param string $internetMessageId Message-ID without angle brackets ('' when the provider gave none)
     * @param list<string> $references References ids without angle brackets, oldest first (at most 100)
     * @param list<MailAddress> $replyTo
     * @param list<MailAddress> $to
     * @param list<MailAddress> $cc
     * @param list<MailAddress> $bcc filled only for messages the mailbox sent
     * @param string $subject subject without control characters (at most 998 characters)
     * @param string $snippet first {@see MAX_SNIPPET} characters of the text, whitespace collapsed
     * @param string $text plain text (the text body, or derived from the sanitised HTML)
     * @param string|null $html sanitised HTML, or null for a text-only message
     * @param int $remoteImagesBlocked remote images whose URL moved to `data-mail-kit-remote-src`
     * @param bool $fromMailbox the mailbox itself is the From address
     * @param string|null $listId List-Id without angle brackets, or null
     * @param list<AttachmentRef> $attachments
     * @param bool $oversized over `limits.max_message_bytes`: no body, headers or attachments were read
     */
    public function __construct(
        public MailboxId $mailbox,
        public MailboxProvider $provider,
        public FolderRef $folder,
        public ProviderMessageId $id,
        public ?string $providerThreadId,
        public ThreadKey $threadKey,
        public string $internetMessageId,
        public ?string $inReplyTo,
        public array $references,
        public ?MailAddress $from,
        public ?MailAddress $sender,
        public array $replyTo,
        public array $to,
        public array $cc,
        public array $bcc,
        public string $subject,
        public string $snippet,
        public string $text,
        public ?string $html,
        public int $remoteImagesBlocked,
        public ?DateTimeImmutable $receivedAt,
        public ?DateTimeImmutable $sentAt,
        public bool $fromMailbox,
        public ?string $listId,
        public array $attachments,
        public AuthenticationResults $authentication,
        public bool $oversized,
        public MessageOrigin $origin,
    ) {
    }
}
