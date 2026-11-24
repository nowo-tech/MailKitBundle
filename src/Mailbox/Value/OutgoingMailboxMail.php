<?php

declare(strict_types=1);

namespace Nowo\MailKitBundle\Mailbox\Value;

use InvalidArgumentException;
use LogicException;
use Nowo\MailKitBundle\Sender\MailAddress;

use function count;
use function filter_var;
use function hash;
use function json_encode;
use function mb_check_encoding;
use function mb_strlen;
use function preg_match;
use function strlen;
use function strtolower;

use const FILTER_FLAG_EMAIL_UNICODE;
use const FILTER_VALIDATE_EMAIL;
use const JSON_THROW_ON_ERROR;
use const JSON_UNESCAPED_SLASHES;
use const JSON_UNESCAPED_UNICODE;

/**
 * A message a delegated mailbox sends from its own address (2.5.0, design sections 5.3 and 6.5, Q-MB-1, Q-MB-7,
 * Q-MB-20). Validated here against header injection and hard bounds; MailKit checks the configured `send.*` limits too.
 *
 * - `$kind` New needs no original; Reply, ReplyAll and Forward need `$inResponseTo` (the provider id of the original).
 * - Recipients: the host's lists always replace those the provider puts on a reply or forward draft (the host composes,
 *   Q-MB-7); at least one recipient in all, at most {@see MAX_RECIPIENTS}. Addresses are validated (`FILTER_VALIDATE_EMAIL`,
 *   at most 254 characters, no CR / LF, quotes or angle brackets); display names carry no control characters.
 * - `$subject` null keeps the provider's (a reply's "RE: …"; a new message needs one); no control characters, at most
 *   {@see MAX_SUBJECT} characters.
 * - The host's body replaces the body the provider generates on a reply or forward (Q-MB-7): `$html` when given (MailKit
 *   sanitises it), else `$text`.
 * - `$idempotencyKey` is mandatory (Q-MB-20): `[A-Za-z0-9_.:-]{16,128}`, unique per mailbox. A second send() with the same
 *   key never sends again; with the same key and another message it is refused.
 */
final readonly class OutgoingMailboxMail
{
    /** Accepted idempotency key. */
    public const KEY_PATTERN = '/^[A-Za-z0-9_.:-]{16,128}\z/';

    /** Hard cap of recipients (To + Cc + Bcc); the configured `send.max_recipients` is at most this. */
    public const MAX_RECIPIENTS = 500;

    /** Hard cap of attachments. */
    public const MAX_ATTACHMENTS = 250;

    /** Longest subject, in characters (one RFC 5322 line). */
    public const MAX_SUBJECT = 998;

    /** Hard cap of the text and of the HTML body, in bytes each. */
    public const MAX_BODY_BYTES = 16_777_216;

    /**
     * @param list<MailAddress> $to
     * @param list<MailAddress> $cc
     * @param list<MailAddress> $bcc
     * @param list<OutgoingAttachment> $attachments
     *
     * @throws InvalidArgumentException on any rule of the class description
     */
    public function __construct(
        public OutgoingKind $kind,
        public ?ProviderMessageId $inResponseTo,
        public array $to,
        public array $cc,
        public array $bcc,
        public ?string $subject,
        public string $text,
        public ?string $html,
        public array $attachments,
        public string $idempotencyKey,
    ) {
        if (preg_match(self::KEY_PATTERN, $idempotencyKey) !== 1) {
            throw new InvalidArgumentException('The idempotency key must be 16 to 128 characters among letters, digits, "_", ".", ":" and "-".');
        }
        if (($kind === OutgoingKind::New) !== (!$inResponseTo instanceof ProviderMessageId)) {
            throw new InvalidArgumentException('A new message has no original; a reply, reply all or forward needs the provider id of the original.');
        }
        $recipients = count($to) + count($cc) + count($bcc);
        if ($recipients === 0 || $recipients > self::MAX_RECIPIENTS) {
            throw new InvalidArgumentException('A message needs 1 to 500 recipients.');
        }
        foreach ([$to, $cc, $bcc] as $list) {
            foreach ($list as $address) {
                $this->assertAddress($address);
            }
        }
        if ($subject === null && $kind === OutgoingKind::New) {
            throw new InvalidArgumentException('A new message needs a subject.');
        }
        if ($subject !== null && (!mb_check_encoding($subject, 'UTF-8') || mb_strlen($subject) > self::MAX_SUBJECT || preg_match('/[\x00-\x1F\x7F]/', $subject) === 1)) {
            throw new InvalidArgumentException('The subject must be UTF-8 without control characters (CR / LF included), at most 998 characters.');
        }
        if (!mb_check_encoding($text, 'UTF-8') || strlen($text) > self::MAX_BODY_BYTES || ($html !== null && (!mb_check_encoding($html, 'UTF-8') || strlen($html) > self::MAX_BODY_BYTES))) {
            throw new InvalidArgumentException('The text and HTML bodies must be UTF-8, at most 16 MiB each.');
        }
        if (count($attachments) > self::MAX_ATTACHMENTS) {
            throw new InvalidArgumentException('A message carries at most 250 attachments.');
        }
    }

    /**
     * Every recipient (To, Cc, Bcc).
     */
    public function recipientCount(): int
    {
        return count($this->to) + count($this->cc) + count($this->bcc);
    }

    /**
     * Bytes of the bodies and the attachments.
     */
    public function size(): int
    {
        $size = strlen($this->text) + strlen((string) $this->html);
        foreach ($this->attachments as $attachment) {
            $size += $attachment->size;
        }

        return $size;
    }

    /**
     * SHA-256 of the message as the host described it (attachments by name, type, size, Content-ID and the SHA-256 of
     * their bytes, streamed; audit ZAV-I1): a second send() with the same key must describe the same message.
     */
    public function fingerprint(): string
    {
        $addresses = static function (array $list): array {
            $out = [];
            foreach ($list as $address) {
                $out[] = [strtolower($address->email), $address->name];
            }

            return $out;
        };
        $attachments = [];
        foreach ($this->attachments as $attachment) {
            $attachments[] = [$attachment->filename, $attachment->contentType, $attachment->size, $attachment->inline, $attachment->contentId, $attachment->contentHash()];
        }

        return hash('sha256', json_encode([
            $this->kind->value,
            $this->inResponseTo?->value,
            $addresses($this->to),
            $addresses($this->cc),
            $addresses($this->bcc),
            $this->subject,
            hash('sha256', $this->text),
            $this->html !== null ? hash('sha256', $this->html) : null,
            $attachments,
        ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
    }

    /**
     * Serialization is refused (attachments hold streams; queue the host's own command, not this object).
     *
     * @return array<string, mixed>
     */
    public function __serialize(): array
    {
        throw new LogicException('An outgoing mailbox message cannot be serialized.');
    }

    /**
     * @throws InvalidArgumentException
     */
    private function assertAddress(mixed $address): void
    {
        if (!$address instanceof MailAddress) {
            throw new InvalidArgumentException('Recipients must be MailAddress objects.');
        }
        $email = $address->email;
        if (strlen($email) > 254 || preg_match('/[\x00-\x20\x7F<>"(),;:\\\\\[\]]/', $email) === 1 || filter_var($email, FILTER_VALIDATE_EMAIL, FILTER_FLAG_EMAIL_UNICODE) === false) {
            throw new InvalidArgumentException('A recipient address is not a valid e-mail address.');
        }
        $name = $address->name;
        if ($name !== null && (!mb_check_encoding($name, 'UTF-8') || mb_strlen($name) > 256 || preg_match('/[\x00-\x1F\x7F]/', $name) === 1)) {
            throw new InvalidArgumentException('A recipient display name must be UTF-8 without control characters, at most 256 characters.');
        }
    }
}
