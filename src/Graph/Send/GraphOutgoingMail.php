<?php

declare(strict_types=1);

namespace Nowo\MailKitBundle\Graph\Send;

use Nowo\MailKitBundle\Graph\GraphException;

use function array_slice;
use function array_values;
use function count;
use function preg_match;
use function preg_replace;
use function strlen;
use function trim;

/**
 * An e-mail to send through Microsoft Graph from a shared mailbox (1.8), as plain values: no Symfony Mime type, so a
 * DDD host builds it from its own domain objects. The From address is always the profile's mailbox (Graph sends as
 * that mailbox); `$fromName` sets its display name.
 *
 * Threading: `$messageId` (generated on the mailbox's domain when null), `$inReplyTo` and `$references` are written
 * as the RFC 5322 `Message-ID`, `In-Reply-To` and `References` headers of the MIME draft. Message ids are given
 * without angle brackets (`abc123@example.com`); brackets are accepted and stripped.
 */
final class GraphOutgoingMail
{
    /** Most entries kept in References (the newest ones). */
    public const MAX_REFERENCES = 50;

    /** @var list<GraphRecipient> */
    public readonly array $to;

    /** @var list<GraphRecipient> */
    public readonly array $cc;

    /** @var list<GraphRecipient> */
    public readonly array $bcc;

    /** @var list<GraphRecipient> */
    public readonly array $replyTo;

    /** @var list<GraphAttachment> */
    public readonly array $attachments;

    /** Subject without line breaks. */
    public readonly string $subject;

    /** Own Message-ID (no brackets), or null to generate one. */
    public readonly ?string $messageId;

    /** Parent Message-ID (no brackets), or null. */
    public readonly ?string $inReplyTo;

    /** @var list<string> References, oldest first (no brackets) */
    public readonly array $references;

    /**
     * @param list<GraphRecipient> $to
     * @param list<GraphRecipient> $cc
     * @param list<GraphRecipient> $bcc
     * @param list<GraphRecipient> $replyTo per-message Reply-To (e.g. a plus address of the mailbox)
     * @param string|null $text plain-text body
     * @param string|null $html HTML body (sent as written: sanitise user content before)
     * @param list<GraphAttachment> $attachments
     * @param list<string> $references
     * @param string|null $fromName display name of the mailbox
     */
    public function __construct(
        array $to,
        string $subject,
        public readonly ?string $text = null,
        public readonly ?string $html = null,
        array $cc = [],
        array $bcc = [],
        array $replyTo = [],
        array $attachments = [],
        ?string $messageId = null,
        ?string $inReplyTo = null,
        array $references = [],
        public readonly ?string $fromName = null,
    ) {
        $this->to          = $this->recipients($to);
        $this->cc          = $this->recipients($cc);
        $this->bcc         = $this->recipients($bcc);
        $this->replyTo     = $this->recipients($replyTo);
        $this->attachments = $this->attachments($attachments);
        if ($this->to === [] && $this->cc === [] && $this->bcc === []) {
            throw GraphException::invalidMessage('An e-mail needs at least one To, Cc or Bcc recipient.');
        }
        if ($text === null && $html === null) {
            throw GraphException::invalidMessage('An e-mail needs a text or an HTML body.');
        }
        $this->subject   = trim((string) preg_replace('/[\r\n\x00]+/', ' ', $subject));
        $this->messageId = $messageId === null ? null : self::messageId($messageId);
        $this->inReplyTo = $inReplyTo === null ? null : self::messageId($inReplyTo);
        $list            = [];
        foreach ($references as $reference) {
            $list[] = self::messageId($reference);
        }
        $this->references = count($list) > self::MAX_REFERENCES ? array_values(array_slice($list, -self::MAX_REFERENCES)) : $list;
    }

    /**
     * A message id without angle brackets, validated (`local@domain`, no spaces, at most 250 characters).
     */
    public static function messageId(string $id): string
    {
        $id = trim($id);
        if (preg_match('/^<(.*)>\z/s', $id, $match) === 1) {
            $id = $match[1];
        }
        if (preg_match('/^[\x21-\x3B\x3D\x3F-\x7E]{1,200}@[A-Za-z0-9.\[\]:_-]{1,200}\z/', $id) !== 1 || strlen($id) > 250) {
            throw GraphException::invalidMessage('Invalid message id.');
        }

        return $id;
    }

    /**
     * @param array<mixed> $list
     *
     * @return list<GraphRecipient>
     */
    private function recipients(array $list): array
    {
        $out = [];
        foreach ($list as $recipient) {
            if (!$recipient instanceof GraphRecipient) {
                throw GraphException::invalidMessage('Recipients must be GraphRecipient instances.');
            }
            $out[] = $recipient;
        }

        return $out;
    }

    /**
     * @param array<mixed> $list
     *
     * @return list<GraphAttachment>
     */
    private function attachments(array $list): array
    {
        $out = [];
        foreach ($list as $attachment) {
            if (!$attachment instanceof GraphAttachment) {
                throw GraphException::invalidMessage('Attachments must be GraphAttachment instances.');
            }
            $out[] = $attachment;
        }

        return $out;
    }
}
