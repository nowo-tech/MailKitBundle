<?php

declare(strict_types=1);

namespace App\Entity;

use App\Outlook\HarnessMessageSink;
use DateTimeImmutable;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/**
 * DEMO ONLY (Outlook manual test harness): what MailKit's sink received, one row per (mailbox, provider message id),
 * the unique key the sink relies on to answer `Duplicate`. Counters show how often the same id arrived, so the manual
 * test can tell "delivered once" from "delivered twice". Written through DBAL by {@see HarnessMessageSink}.
 */
#[ORM\Entity]
#[ORM\Table(name: 'demo_outlook_message')]
#[ORM\UniqueConstraint(name: 'uniq_demo_outlook_message', columns: ['mailbox_id', 'provider_message_id'])]
class OutlookMessage
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    public ?int $id = null;

    #[ORM\Column(name: 'mailbox_id', length: 128)]
    public string $mailboxId = '';

    #[ORM\Column(name: 'provider_message_id', length: 512)]
    public string $providerMessageId = '';

    #[ORM\Column(length: 255)]
    public string $folder = '';

    #[ORM\Column(length: 16)]
    public string $origin = '';

    #[ORM\Column(type: Types::TEXT)]
    public string $subject = '';

    #[ORM\Column(name: 'thread_key', type: Types::TEXT)]
    public string $threadKey = '';

    #[ORM\Column(name: 'internet_message_id', type: Types::TEXT)]
    public string $internetMessageId = '';

    #[ORM\Column(name: 'from_address', length: 254, nullable: true)]
    public ?string $fromAddress = null;

    #[ORM\Column(name: 'received_at', nullable: true)]
    public ?DateTimeImmutable $receivedAt = null;

    #[ORM\Column(name: 'attachment_count')]
    public int $attachmentCount = 0;

    #[ORM\Column(name: 'attachment_bytes', type: Types::BIGINT)]
    public string $attachmentBytes = '0';

    #[ORM\Column(name: 'duplicate_count')]
    public int $duplicateCount = 0;

    #[ORM\Column(name: 'removed_reason', length: 16, nullable: true)]
    public ?string $removedReason = null;

    #[ORM\Column(name: 'removed_folder', length: 255, nullable: true)]
    public ?string $removedFolder = null;

    #[ORM\Column(name: 'first_stored_at')]
    public DateTimeImmutable $firstStoredAt;

    #[ORM\Column(name: 'last_seen_at')]
    public DateTimeImmutable $lastSeenAt;

    public function __construct()
    {
        $this->firstStoredAt = new DateTimeImmutable();
        $this->lastSeenAt    = $this->firstStoredAt;
    }
}
