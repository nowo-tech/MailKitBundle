<?php

declare(strict_types=1);

namespace Nowo\MailKitBundle\Entity;

use DateTimeImmutable;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Nowo\MailKitBundle\Log\SentMailRecorder;
use Nowo\MailKitBundle\Log\SentMailStatus;

/**
 * One outgoing message of the sent-mail log: envelope data only (subject, sender, recipients, transport scheme,
 * provider id, status, timing). Bodies live in {@see SentMailBody} and only when `log.store_bodies` is on. Rows are
 * written with DBAL by {@see SentMailRecorder} (never through the host unit of work) and read
 * through this read-only mapping. Table `<prefix>mail_kit_sent_mail` (REQ-ORM-002).
 */
#[ORM\Entity(readOnly: true)]
#[ORM\Table(name: self::TABLE)]
#[ORM\Index(name: 'idx_mail_kit_sent_mail_queued', columns: ['queued_at'])]
#[ORM\Index(name: 'idx_mail_kit_sent_mail_status', columns: ['status', 'queued_at'])]
class SentMail
{
    /** Unprefixed table name. */
    public const TABLE = 'mail_kit_sent_mail';

    #[ORM\Id]
    #[ORM\Column(length: 36)]
    private string $id;

    #[ORM\Column(length: 16, enumType: SentMailStatus::class)]
    private SentMailStatus $status;

    #[ORM\Column(length: 64, nullable: true)]
    private ?string $tag;

    #[ORM\Column(length: 255)]
    private string $subject;

    #[ORM\Column(length: 255)]
    private string $sender;

    /** @var array{to?: list<string>, cc?: list<string>, bcc?: list<string>} */
    #[ORM\Column(type: Types::JSON)]
    private array $recipients;

    /** `|a@x|b@y|` normalised to / cc / bcc addresses: search and erasure without JSON matching. */
    #[ORM\Column(name: 'recipient_index', type: Types::TEXT)]
    private string $recipientIndex;

    #[ORM\Column(length: 32)]
    private string $transport;

    #[ORM\Column(name: 'provider_message_id', length: 255, nullable: true)]
    private ?string $providerMessageId;

    #[ORM\Column(nullable: true)]
    private ?int $size;

    #[ORM\Column(type: Types::TEXT, nullable: true)]
    private ?string $error;

    #[ORM\Column(options: ['default' => 0])]
    private int $attempts;

    #[ORM\Column(name: 'queued_at', type: Types::DATETIME_IMMUTABLE)]
    private DateTimeImmutable $queuedAt;

    #[ORM\Column(name: 'sent_at', type: Types::DATETIME_IMMUTABLE, nullable: true)]
    private ?DateTimeImmutable $sentAt;

    #[ORM\Column(name: 'failed_at', type: Types::DATETIME_IMMUTABLE, nullable: true)]
    private ?DateTimeImmutable $failedAt;

    /**
     * Rows are created by the recorder (DBAL) and hydrated by Doctrine; the constructor exists for fixtures and tests.
     *
     * @param array{to?: list<string>, cc?: list<string>, bcc?: list<string>} $recipients
     */
    public function __construct(
        string $id,
        DateTimeImmutable $queuedAt,
        SentMailStatus $status = SentMailStatus::Queued,
        string $subject = '',
        string $sender = '',
        array $recipients = [],
        string $transport = '',
        ?string $tag = null,
        ?string $providerMessageId = null,
        ?int $size = null,
        ?string $error = null,
        int $attempts = 0,
        ?DateTimeImmutable $sentAt = null,
        ?DateTimeImmutable $failedAt = null,
    ) {
        $this->id                = $id;
        $this->queuedAt          = $queuedAt;
        $this->status            = $status;
        $this->subject           = $subject;
        $this->sender            = $sender;
        $this->recipients        = $recipients;
        $this->recipientIndex    = SentMailRecorder::recipientIndex($recipients);
        $this->transport         = $transport;
        $this->tag               = $tag;
        $this->providerMessageId = $providerMessageId;
        $this->size              = $size;
        $this->error             = $error;
        $this->attempts          = $attempts;
        $this->sentAt            = $sentAt;
        $this->failedAt          = $failedAt;
    }

    /** UUID v7 (RFC 4122 string). */
    public function getId(): string
    {
        return $this->id;
    }

    /** Delivery state. */
    public function getStatus(): SentMailStatus
    {
        return $this->status;
    }

    /** Category from the X-Mail-Kit-Tag header, or null. */
    public function getTag(): ?string
    {
        return $this->tag;
    }

    /** Subject (first 255 characters). */
    public function getSubject(): string
    {
        return $this->subject;
    }

    /** First From address (lowercase). */
    public function getSender(): string
    {
        return $this->sender;
    }

    /**
     * Lowercase addresses by kind.
     *
     * @return array{to?: list<string>, cc?: list<string>, bcc?: list<string>}
     */
    public function getRecipients(): array
    {
        return $this->recipients;
    }

    /**
     * To addresses.
     *
     * @return list<string>
     */
    public function getTo(): array
    {
        return $this->recipients['to'] ?? [];
    }

    /** Normalised `|a@x|b@y|` index of the recipients. */
    public function getRecipientIndex(): string
    {
        return $this->recipientIndex;
    }

    /** Transport scheme ("smtp", "resend+api", "default" for the framework transport). */
    public function getTransport(): string
    {
        return $this->transport;
    }

    /** Message id returned by the transport, or null. */
    public function getProviderMessageId(): ?string
    {
        return $this->providerMessageId;
    }

    /** Size of the sent message in bytes, or null. */
    public function getSize(): ?int
    {
        return $this->size;
    }

    /** Transport error (credentials scrubbed), or null. */
    public function getError(): ?string
    {
        return $this->error;
    }

    /** Delivery attempts recorded (Messenger retries add one each). */
    public function getAttempts(): int
    {
        return $this->attempts;
    }

    /** When the message was handed to the mailer. */
    public function getQueuedAt(): DateTimeImmutable
    {
        return $this->queuedAt;
    }

    /** When the transport accepted it, or null. */
    public function getSentAt(): ?DateTimeImmutable
    {
        return $this->sentAt;
    }

    /** When the last attempt failed, or null. */
    public function getFailedAt(): ?DateTimeImmutable
    {
        return $this->failedAt;
    }
}
