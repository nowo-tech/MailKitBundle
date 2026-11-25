<?php

declare(strict_types=1);

namespace App\Outlook;

use DateTimeImmutable;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use Doctrine\DBAL\Types\Types;
use Nowo\MailKitBundle\Mailbox\Port\MailboxMessageSinkInterface;
use Nowo\MailKitBundle\Mailbox\Value\FolderRef;
use Nowo\MailKitBundle\Mailbox\Value\MailboxId;
use Nowo\MailKitBundle\Mailbox\Value\ProviderMessageId;
use Nowo\MailKitBundle\Mailbox\Value\RemovalReason;
use Nowo\MailKitBundle\Mailbox\Value\StoreOutcome;
use Nowo\MailKitBundle\Mailbox\Value\SyncedMessage;

use function count;
use function mb_substr;

/**
 * DEMO ONLY (Outlook manual test harness): MailKit's sink port over the `demo_outlook_message` table. Idempotent the
 * documented way: the unique key (mailbox id, provider message id) and a caught violation that answers `Duplicate`
 * (the folder is updated and a "removed" mark cleared, as a host does on a move). Only the envelope is kept: no body.
 */
final class HarnessMessageSink implements MailboxMessageSinkInterface
{
    public const TABLE = 'demo_outlook_message';

    public function __construct(
        private readonly Connection $connection,
    ) {
    }

    public function store(SyncedMessage $message): StoreOutcome
    {
        $now   = new DateTimeImmutable();
        $bytes = 0;
        foreach ($message->attachments as $attachment) {
            $bytes += $attachment->size;
        }

        try {
            $this->connection->insert(self::TABLE, [
                'mailbox_id'          => $message->mailbox->value,
                'provider_message_id' => $message->id->value,
                'folder'              => $message->folder->key,
                'origin'              => $message->origin->value,
                'subject'             => mb_substr($message->subject, 0, 998),
                'thread_key'          => $message->threadKey->value,
                'internet_message_id' => $message->internetMessageId,
                'from_address'        => $message->from?->email,
                'received_at'         => $message->receivedAt,
                'attachment_count'    => count($message->attachments),
                'attachment_bytes'    => $bytes,
                'duplicate_count'     => 0,
                'first_stored_at'     => $now,
                'last_seen_at'        => $now,
            ], ['received_at' => Types::DATETIME_IMMUTABLE, 'first_stored_at' => Types::DATETIME_IMMUTABLE, 'last_seen_at' => Types::DATETIME_IMMUTABLE]);

            return StoreOutcome::Stored;
        } catch (UniqueConstraintViolationException) {
            $this->connection->executeStatement(
                'UPDATE ' . self::TABLE . ' SET folder = ?, removed_reason = NULL, removed_folder = NULL, duplicate_count = duplicate_count + 1, last_seen_at = ? WHERE mailbox_id = ? AND provider_message_id = ?',
                [$message->folder->key, $now, $message->mailbox->value, $message->id->value],
                [Types::STRING, Types::DATETIME_IMMUTABLE, Types::STRING, Types::STRING],
            );

            return StoreOutcome::Duplicate;
        }
    }

    public function removed(MailboxId $mailbox, ProviderMessageId $message, FolderRef $folder, RemovalReason $reason): void
    {
        // Scoped to the folder: a message already stored from its new folder keeps that membership (never hard-deleted).
        $this->connection->executeStatement(
            'UPDATE ' . self::TABLE . ' SET removed_reason = ?, removed_folder = ?, last_seen_at = ? WHERE mailbox_id = ? AND provider_message_id = ? AND folder = ?',
            [$reason->value, $folder->key, new DateTimeImmutable(), $mailbox->value, $message->value, $folder->key],
            [Types::STRING, Types::STRING, Types::DATETIME_IMMUTABLE, Types::STRING, Types::STRING, Types::STRING],
        );
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function all(?string $mailbox): array
    {
        $sql = 'SELECT provider_message_id, folder, origin, subject, from_address, received_at, attachment_count, attachment_bytes, duplicate_count, removed_reason, removed_folder, thread_key FROM ' . self::TABLE;

        return $mailbox !== null
            ? $this->connection->fetchAllAssociative($sql . ' WHERE mailbox_id = ? ORDER BY received_at, id', [$mailbox])
            : $this->connection->fetchAllAssociative($sql . ' ORDER BY mailbox_id, received_at, id');
    }

    /**
     * @return array<string, mixed>|null
     */
    public function find(string $mailbox, string $providerMessageId): ?array
    {
        $row = $this->connection->fetchAssociative('SELECT provider_message_id, folder, origin, duplicate_count, removed_reason FROM ' . self::TABLE . ' WHERE mailbox_id = ? AND provider_message_id = ?', [$mailbox, $providerMessageId]);

        return $row === false ? null : $row;
    }
}
