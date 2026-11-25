<?php

declare(strict_types=1);

namespace Nowo\MailKitBundle\Mailbox\Infrastructure\Store;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Platforms\AbstractPlatform;
use Doctrine\DBAL\Schema\Schema;
use Doctrine\Persistence\ManagerRegistry;
use InvalidArgumentException;
use Nowo\MailKitBundle\Concurrency\SavepointWrites;
use Nowo\MailKitBundle\Doctrine\ExactCollation;
use Nowo\MailKitBundle\Mailbox\Engine\SendState;
use Nowo\MailKitBundle\Mailbox\Value\GrantFailureReason;
use Nowo\MailKitBundle\Mailbox\Value\MailboxId;
use Nowo\MailKitBundle\Mailbox\Value\MailboxProvider;
use Nowo\MailKitBundle\Mailbox\Value\MailboxStatus;
use Nowo\TransactionDoctrine\DoctrineTransactionRunner;
use Psr\Clock\ClockInterface;
use Psr\Log\LoggerInterface;
use SensitiveParameter;
use Symfony\Component\Clock\NativeClock;

use function array_fill;
use function assert;
use function count;
use function hash;
use function hash_equals;
use function implode;
use function in_array;
use function is_numeric;
use function is_string;

/**
 * Sync state of the delegated mailboxes (2.5.0, design section 16), through DBAL on the configured connection
 * (resolved per call; never the host's entity manager, never a flush):
 *
 * - `<prefix>mail_kit_mailbox`: status, grant-failure reason, SHA-256 of the provider account id;
 * - `<prefix>mail_kit_mailbox_cursor`: per followed folder the provider cursor (delta link), its version, the sweep
 *   lease (owner, until), the `rerun` flag, the end of the last complete sweep and the folder's sync error;
 * - `<prefix>mail_kit_mailbox_subscription`: change-notification subscriptions per folder with the **SHA-256 of the
 *   client state** only;
 * - `<prefix>mail_kit_mailbox_send` (2.5.0 part 2): one row per idempotency key of a send ({@see SendState}), with the
 *   SHA-256 of the message description, the claim token of the current holder and the provider ids once known.
 *
 * No table holds an address, a subject, a body, a token or a recipient. Every state change is one conditional
 * statement (REQ-RUNTIME-003): first rows are inserted with a caught duplicate key, status transitions and the lease
 * are conditional updates, the cursor advances by compare-and-set on its version and the lease owner. Inside an open
 * host transaction each statement runs in a savepoint ({@see SavepointWrites}); multi-statement swaps run through
 * transaction-doctrine. Times are UNIX seconds (BIGINT).
 */
final class MailboxStore
{
    /** Unprefixed table names. */
    public const MAILBOX_TABLE = 'mail_kit_mailbox';

    public const CURSOR_TABLE = 'mail_kit_mailbox_cursor';

    public const SUBSCRIPTION_TABLE = 'mail_kit_mailbox_subscription';

    public const SEND_TABLE = 'mail_kit_mailbox_send';

    /** Columns a send transition may set besides the status. */
    private const SEND_COLUMNS = ['provider_message_id', 'internet_message_id', 'thread_id', 'reason', 'sent_at'];

    private const SAVEPOINT = 'nowo_mail_kit_mailbox';

    /**
     * @param string|null $connectionName DBAL connection (null = default)
     * @param string $tablePrefix `doctrine.table_prefix`
     */
    public function __construct(
        private readonly ManagerRegistry $doctrine,
        private readonly ?string $connectionName = null,
        private readonly string $tablePrefix = '',
        private readonly ?ClockInterface $clock = null,
        private readonly ?LoggerInterface $logger = null,
    ) {
    }

    /**
     * Prefixed name of the mailbox table.
     */
    public function mailboxTable(): string
    {
        return $this->tablePrefix . self::MAILBOX_TABLE;
    }

    /**
     * Prefixed name of the cursor table.
     */
    public function cursorTable(): string
    {
        return $this->tablePrefix . self::CURSOR_TABLE;
    }

    /**
     * Prefixed name of the subscription table.
     */
    public function subscriptionTable(): string
    {
        return $this->tablePrefix . self::SUBSCRIPTION_TABLE;
    }

    /**
     * Prefixed name of the send table (2.5.0 part 2).
     */
    public function sendTable(): string
    {
        return $this->tablePrefix . self::SEND_TABLE;
    }

    /**
     * The mailbox row, or null.
     *
     * @return array{provider: string, status: MailboxStatus, reason: GrantFailureReason|null, account_hash: string|null}|null
     */
    public function mailbox(MailboxId $mailbox): ?array
    {
        $row = $this->connection()->fetchAssociative('SELECT provider, status, reason, account_hash FROM ' . $this->mailboxTable() . ' WHERE mailbox_id = ?', [$mailbox->value]);
        if ($row === false) {
            return null;
        }

        return [
            'provider'     => is_string($row['provider'] ?? null) ? $row['provider'] : '',
            'status'       => MailboxStatus::tryFrom(is_string($row['status'] ?? null) ? $row['status'] : '') ?? MailboxStatus::Unknown,
            'reason'       => GrantFailureReason::tryFrom(is_string($row['reason'] ?? null) ? $row['reason'] : ''),
            'account_hash' => is_string($row['account_hash'] ?? null) && $row['account_hash'] !== '' ? $row['account_hash'] : null,
        ];
    }

    /**
     * Inserts an active mailbox row; false when it exists already (caught duplicate key, never check-then-insert).
     */
    public function insertMailbox(MailboxId $mailbox, MailboxProvider $provider): bool
    {
        return SavepointWrites::insertOnce($this->connection(), self::SAVEPOINT, $this->mailboxTable(), [
            'mailbox_id'   => $mailbox->value,
            'provider'     => $provider->value,
            'status'       => MailboxStatus::Active->value,
            'reason'       => null,
            'account_hash' => null,
            'updated_at'   => $this->now(),
        ]);
    }

    /**
     * Paused → active (one conditional statement); true when this call made the transition.
     */
    public function activate(MailboxId $mailbox): bool
    {
        return $this->write('UPDATE ' . $this->mailboxTable() . ' SET status = ?, reason = NULL, updated_at = ? WHERE mailbox_id = ? AND status IN (?, ?)', [
            MailboxStatus::Active->value, $this->now(), $mailbox->value, MailboxStatus::PausedByHost->value, MailboxStatus::PausedGrant->value,
        ]) === 1;
    }

    /**
     * Active → paused by the host; true when this call made the transition.
     */
    public function pauseByHost(MailboxId $mailbox): bool
    {
        return $this->write('UPDATE ' . $this->mailboxTable() . ' SET status = ?, updated_at = ? WHERE mailbox_id = ? AND status = ?', [
            MailboxStatus::PausedByHost->value, $this->now(), $mailbox->value, MailboxStatus::Active->value,
        ]) === 1;
    }

    /**
     * Active → paused by a grant failure; true for exactly one caller of all concurrent ones (it dispatches the event).
     */
    public function pauseGrant(MailboxId $mailbox, GrantFailureReason $reason): bool
    {
        return $this->write('UPDATE ' . $this->mailboxTable() . ' SET status = ?, reason = ?, updated_at = ? WHERE mailbox_id = ? AND status = ?', [
            MailboxStatus::PausedGrant->value, $reason->value, $this->now(), $mailbox->value, MailboxStatus::Active->value,
        ]) === 1;
    }

    /**
     * Records the SHA-256 of the provider account id the first time and compares it afterwards: true when the account
     * is the one first seen (or the first one), false for another account.
     */
    public function bindAccount(MailboxId $mailbox, string $accountId): bool
    {
        $hash = hash('sha256', $accountId);
        // A conditional first write (the value changes from NULL, so MySQL / MariaDB count the row), then the read decides.
        $this->write('UPDATE ' . $this->mailboxTable() . ' SET account_hash = ? WHERE mailbox_id = ? AND account_hash IS NULL', [$hash, $mailbox->value]);
        $stored = $this->connection()->fetchOne('SELECT account_hash FROM ' . $this->mailboxTable() . ' WHERE mailbox_id = ?', [$mailbox->value]);

        return is_string($stored) && hash_equals($stored, $hash);
    }

    /**
     * True when the provider account id is the one bound to the mailbox (used by the link guard).
     */
    public function isAccount(MailboxId $mailbox, string $accountId): bool
    {
        $stored = $this->connection()->fetchOne('SELECT account_hash FROM ' . $this->mailboxTable() . ' WHERE mailbox_id = ?', [$mailbox->value]);

        return is_string($stored) && $stored !== '' && hash_equals($stored, hash('sha256', $accountId));
    }

    /**
     * Inserts the cursor row of a folder when missing (caught duplicate key).
     */
    public function ensureCursor(MailboxId $mailbox, string $folder): void
    {
        SavepointWrites::insertOnce($this->connection(), self::SAVEPOINT, $this->cursorTable(), [
            'mailbox_id'       => $mailbox->value,
            'folder_key'       => $folder,
            'cursor_value'     => null,
            'cursor_version'   => 0,
            'lease_owner'      => null,
            'lease_until'      => null,
            'rerun'            => 0,
            'last_complete_at' => null,
            'sync_error'       => null,
            'sync_error_at'    => null,
        ]);
    }

    /**
     * Takes the folder's sweep lease (one conditional statement; it also clears `rerun`): false when another worker
     * holds an unexpired lease.
     *
     * @param string $owner random id of this run
     * @param int $seconds lease length
     */
    public function acquireLease(MailboxId $mailbox, string $folder, string $owner, int $seconds): bool
    {
        $now = $this->now();

        return $this->write('UPDATE ' . $this->cursorTable() . ' SET lease_owner = ?, lease_until = ?, rerun = 0 WHERE mailbox_id = ? AND folder_key = ? AND (lease_until IS NULL OR lease_until < ?)', [
            $owner, $now + $seconds, $mailbox->value, $folder, $now,
        ]) === 1;
    }

    /**
     * Asks the lease holder for one more sweep after its run (a notification arrived meanwhile).
     */
    public function requestRerun(MailboxId $mailbox, string $folder): void
    {
        $this->write('UPDATE ' . $this->cursorTable() . ' SET rerun = 1 WHERE mailbox_id = ? AND folder_key = ?', [$mailbox->value, $folder]);
    }

    /**
     * The folder's cursor and its version, or null without a row.
     *
     * @return array{cursor: string|null, version: int}|null
     */
    public function cursor(MailboxId $mailbox, string $folder): ?array
    {
        $row = $this->connection()->fetchAssociative('SELECT cursor_value, cursor_version FROM ' . $this->cursorTable() . ' WHERE mailbox_id = ? AND folder_key = ?', [$mailbox->value, $folder]);
        if ($row === false) {
            return null;
        }

        return [
            'cursor'  => is_string($row['cursor_value'] ?? null) && $row['cursor_value'] !== '' ? $row['cursor_value'] : null,
            'version' => is_numeric($row['cursor_version'] ?? null) ? (int) $row['cursor_version'] : 0,
        ];
    }

    /**
     * Compare-and-set of the cursor by the lease holder: stores the new cursor (null = lost, restart from the resync
     * window), bumps the version and extends the lease. False when the version moved or the lease changed hands (the
     * caller stops without writing anything else).
     *
     * @param bool $complete the page ended with a delta link: records the end of a complete sweep
     */
    public function advanceCursor(MailboxId $mailbox, string $folder, string $owner, int $version, #[SensitiveParameter] ?string $cursor, int $leaseSeconds, bool $complete): bool
    {
        $now = $this->now();

        return $this->write(
            'UPDATE ' . $this->cursorTable() . ' SET cursor_value = ?, cursor_version = cursor_version + 1, lease_until = ?, sync_error = NULL, sync_error_at = NULL, last_complete_at = ' . ($complete ? '?' : 'last_complete_at')
            . ' WHERE mailbox_id = ? AND folder_key = ? AND cursor_version = ? AND lease_owner = ?',
            $complete ? [$cursor, $now + $leaseSeconds, $now, $mailbox->value, $folder, $version, $owner] : [$cursor, $now + $leaseSeconds, $mailbox->value, $folder, $version, $owner],
        ) === 1;
    }

    /**
     * Extends the lease of this run (between slow provider checks); false when the lease changed hands.
     *
     * MySQL / MariaDB count changed rows only: an extension in the same second as the previous lease write changes
     * nothing and answers 0 although this run still holds the lease (audit ZAT-R4). A 0 is therefore confirmed with a
     * read of the owner (a primary-key look-up, only on that path) rather than by forcing a change (`GREATEST()` is not
     * portable to SQLite, a sequence column needs a schema change).
     */
    public function extendLease(MailboxId $mailbox, string $folder, string $owner, int $seconds): bool
    {
        return $this->write('UPDATE ' . $this->cursorTable() . ' SET lease_until = ? WHERE mailbox_id = ? AND folder_key = ? AND lease_owner = ?', [
            $this->now() + $seconds, $mailbox->value, $folder, $owner,
        ]) === 1 || $this->connection()->fetchOne('SELECT lease_owner FROM ' . $this->cursorTable() . ' WHERE mailbox_id = ? AND folder_key = ?', [$mailbox->value, $folder]) === $owner;
    }

    /**
     * Records a folder sync error (audit ZAT-R1): true only for the call that moved the folder from "no error" to this
     * error (one event per transition). The next good cursor advance clears it.
     *
     * @param string $error a short reason (`refused`, `too_large`, `not_found`)
     */
    public function markFolderError(MailboxId $mailbox, string $folder, string $error): bool
    {
        return $this->write('UPDATE ' . $this->cursorTable() . ' SET sync_error = ?, sync_error_at = ? WHERE mailbox_id = ? AND folder_key = ? AND sync_error IS NULL', [
            $error, $this->now(), $mailbox->value, $folder,
        ]) === 1;
    }

    /**
     * Releases the lease of this run: true when a rerun was requested meanwhile (the caller enqueues one more sweep).
     * Each statement changes the owner, so MySQL / MariaDB count the row; a rerun requested between them is caught by
     * the loop.
     */
    public function releaseLease(MailboxId $mailbox, string $folder, string $owner): bool
    {
        for ($i = 0; $i < 3; ++$i) {
            if ($this->write('UPDATE ' . $this->cursorTable() . ' SET lease_owner = NULL, lease_until = NULL, rerun = 0 WHERE mailbox_id = ? AND folder_key = ? AND lease_owner = ? AND rerun = 1', [$mailbox->value, $folder, $owner]) === 1) {
                return true;
            }
            if ($this->write('UPDATE ' . $this->cursorTable() . ' SET lease_owner = NULL, lease_until = NULL WHERE mailbox_id = ? AND folder_key = ? AND lease_owner = ? AND rerun = 0', [$mailbox->value, $folder, $owner]) === 1) {
                return false;
            }
        }

        // The lease changed hands (it expired): the new holder sweeps.
        return false;
    }

    /**
     * Cursor rows of a mailbox.
     *
     * @return list<array{folder_key: string, last_complete_at: int|null, sync_error: string|null, sync_error_at: int|null}>
     */
    public function cursors(MailboxId $mailbox): array
    {
        $rows = [];
        foreach ($this->connection()->fetchAllAssociative('SELECT folder_key, last_complete_at, sync_error, sync_error_at FROM ' . $this->cursorTable() . ' WHERE mailbox_id = ? ORDER BY folder_key', [$mailbox->value]) as $row) {
            if (is_string($row['folder_key'] ?? null)) {
                $rows[] = [
                    'folder_key'       => $row['folder_key'],
                    'last_complete_at' => is_numeric($row['last_complete_at'] ?? null) ? (int) $row['last_complete_at'] : null,
                    'sync_error'       => is_string($row['sync_error'] ?? null) && $row['sync_error'] !== '' ? $row['sync_error'] : null,
                    'sync_error_at'    => is_numeric($row['sync_error_at'] ?? null) ? (int) $row['sync_error_at'] : null,
                ];
            }
        }

        return $rows;
    }

    /**
     * Forgets the cursor of a folder the host no longer follows.
     */
    public function deleteCursor(MailboxId $mailbox, string $folder): void
    {
        $this->write('DELETE FROM ' . $this->cursorTable() . ' WHERE mailbox_id = ? AND folder_key = ?', [$mailbox->value, $folder]);
    }

    /**
     * Stores the subscription of a folder (the client state is hashed) and drops the folder's previous one, in one
     * transaction (a failed insert keeps the previous subscription).
     */
    public function saveSubscription(string $subscriptionId, MailboxId $mailbox, string $folder, string $clientState, int $expiresAt): void
    {
        $connection = $this->connection();
        $row        = [
            'subscription_id'   => $subscriptionId,
            'mailbox_id'        => $mailbox->value,
            'folder_key'        => $folder,
            'client_state_hash' => hash('sha256', $clientState),
            'expires_at'        => $expiresAt,
            'created_at'        => $this->now(),
        ];
        // @igor-ignore - Closure runs synchronously inside run(); it is not stored on the service.
        DoctrineTransactionRunner::forConnection($connection, null, $this->logger)->run(function () use ($connection, $mailbox, $folder, $row): void {
            $connection->executeStatement('DELETE FROM ' . $this->subscriptionTable() . ' WHERE mailbox_id = ? AND folder_key = ?', [$mailbox->value, $folder]);
            $connection->insert($this->subscriptionTable(), $row);
        });
    }

    /**
     * Subscriptions of a mailbox.
     *
     * @return list<array{id: string, folder_key: string, expires_at: int}>
     */
    public function subscriptions(MailboxId $mailbox): array
    {
        $rows = [];
        foreach ($this->connection()->fetchAllAssociative('SELECT subscription_id, folder_key, expires_at FROM ' . $this->subscriptionTable() . ' WHERE mailbox_id = ? ORDER BY folder_key', [$mailbox->value]) as $row) {
            if (is_string($row['subscription_id'] ?? null) && is_string($row['folder_key'] ?? null) && is_numeric($row['expires_at'] ?? null)) {
                $rows[] = ['id' => $row['subscription_id'], 'folder_key' => $row['folder_key'], 'expires_at' => (int) $row['expires_at']];
            }
        }

        return $rows;
    }

    /**
     * The mailbox and folder of a notification whose subscription is known and unexpired and whose client state
     * matches (SHA-256 digests compared in constant time, also for unknown ids), else null. `active` tells whether the
     * mailbox is synced (a paused mailbox's notifications are acknowledged without work).
     *
     * @return array{mailbox: MailboxId, folder: string, active: bool}|null
     */
    public function verifyNotification(string $subscriptionId, string $clientState): ?array
    {
        $row = $this->connection()->fetchAssociative(
            'SELECT s.mailbox_id, s.folder_key, s.client_state_hash, s.expires_at, m.status FROM ' . $this->subscriptionTable() . ' s LEFT JOIN ' . $this->mailboxTable() . ' m ON m.mailbox_id = s.mailbox_id WHERE s.subscription_id = ?',
            [$subscriptionId],
        );
        $expected = $row !== false && is_string($row['client_state_hash'] ?? null) ? $row['client_state_hash'] : hash('sha256', 'nowo-mail-kit-unknown-subscription');
        $valid    = hash_equals($expected, hash('sha256', $clientState));
        if (!$valid || $row === false || !is_numeric($row['expires_at'] ?? null) || (int) $row['expires_at'] <= $this->now() || !is_string($row['mailbox_id'] ?? null) || !is_string($row['folder_key'] ?? null)) {
            return null;
        }

        return ['mailbox' => new MailboxId($row['mailbox_id']), 'folder' => $row['folder_key'], 'active' => ($row['status'] ?? null) === MailboxStatus::Active->value];
    }

    /**
     * New expiry of a renewed subscription.
     */
    public function updateSubscriptionExpiry(string $subscriptionId, int $expiresAt): void
    {
        $this->write('UPDATE ' . $this->subscriptionTable() . ' SET expires_at = ? WHERE subscription_id = ?', [$expiresAt, $subscriptionId]);
    }

    /**
     * Forgets a subscription.
     */
    public function deleteSubscription(string $subscriptionId): void
    {
        $this->write('DELETE FROM ' . $this->subscriptionTable() . ' WHERE subscription_id = ?', [$subscriptionId]);
    }

    /**
     * Claims an idempotency key (design section 9.4): inserts a `drafting` row held by `$token`; false when the key
     * exists already for this mailbox (caught duplicate key, never check-then-insert).
     *
     * @param string $payloadHash SHA-256 of the message description
     * @param string $token random claim token of this call
     */
    public function claimSend(MailboxId $mailbox, string $key, string $payloadHash, string $token): bool
    {
        $now = $this->now();

        return SavepointWrites::insertOnce($this->connection(), self::SAVEPOINT, $this->sendTable(), [
            'mailbox_id'          => $mailbox->value,
            'idempotency_key'     => $key,
            'payload_hash'        => $payloadHash,
            'status'              => SendState::Drafting->value,
            'claim_token'         => $token,
            'provider_message_id' => null,
            'internet_message_id' => null,
            'thread_id'           => null,
            'reason'              => null,
            'attempts'            => 1,
            'row_version'         => 0,
            'claimed_at'          => $now,
            'updated_at'          => $now,
            'sent_at'             => null,
        ]);
    }

    /**
     * The send row of a key, or null.
     *
     * @return array{status: SendState, token: string, payload_hash: string, message_id: string|null, internet_message_id: string, thread_id: string|null, reason: string|null, attempts: int, updated_at: int, sent_at: int|null}|null
     */
    public function sendRow(MailboxId $mailbox, string $key): ?array
    {
        $row = $this->connection()->fetchAssociative(
            'SELECT status, claim_token, payload_hash, provider_message_id, internet_message_id, thread_id, reason, attempts, updated_at, sent_at FROM ' . $this->sendTable() . ' WHERE mailbox_id = ? AND idempotency_key = ?',
            [$mailbox->value, $key],
        );
        if ($row === false) {
            return null;
        }
        $text = static fn (mixed $value): ?string => is_string($value) && $value !== '' ? $value : null;

        return [
            'status'              => SendState::tryFrom(is_string($row['status'] ?? null) ? $row['status'] : '') ?? SendState::Unknown,
            'token'               => $text($row['claim_token'] ?? null) ?? '',
            'payload_hash'        => $text($row['payload_hash'] ?? null) ?? '',
            'message_id'          => $text($row['provider_message_id'] ?? null),
            'internet_message_id' => $text($row['internet_message_id'] ?? null) ?? '',
            'thread_id'           => $text($row['thread_id'] ?? null),
            'reason'              => $text($row['reason'] ?? null),
            'attempts'            => is_numeric($row['attempts'] ?? null) ? (int) $row['attempts'] : 0,
            'updated_at'          => is_numeric($row['updated_at'] ?? null) ? (int) $row['updated_at'] : 0,
            'sent_at'             => is_numeric($row['sent_at'] ?? null) ? (int) $row['sent_at'] : null,
        ];
    }

    /**
     * True when a send of this mailbox is `sent` with this provider message id (audit ZAV-I2: only MailKit's own sends
     * are delivered as sent copies).
     */
    public function isSentMessage(MailboxId $mailbox, string $providerMessageId): bool
    {
        return $this->connection()->fetchOne('SELECT 1 FROM ' . $this->sendTable() . ' WHERE mailbox_id = ? AND provider_message_id = ? AND status = ?', [$mailbox->value, $providerMessageId, SendState::Sent->value]) !== false;
    }

    /**
     * Idempotency keys of a mailbox left `sending` (audit ZBUv-L2): reconciled when the mailbox is resumed.
     *
     * @return list<string>
     */
    public function sendingKeys(MailboxId $mailbox, int $limit = 500): array
    {
        $connection = $this->connection();
        $keys       = [];
        foreach ($connection->fetchFirstColumn(
            $connection->getDatabasePlatform()->modifyLimitQuery('SELECT idempotency_key FROM ' . $this->sendTable() . ' WHERE mailbox_id = ? AND status = ? ORDER BY updated_at', $limit),
            [$mailbox->value, SendState::Sending->value],
        ) as $key) {
            $keys[] = (string) $key;
        }

        return $keys;
    }

    /**
     * Settled rows (`released`, `failed`) not updated since `$before` that still name a draft (audit ZAV-I4).
     *
     * @return list<array{mailbox_id: string, idempotency_key: string, token: string, status: SendState, draft: string}>
     */
    public function orphanDrafts(int $before, int $limit = 500): array
    {
        $connection = $this->connection();
        $rows       = [];
        foreach ($connection->fetchAllAssociative(
            $connection->getDatabasePlatform()->modifyLimitQuery('SELECT mailbox_id, idempotency_key, claim_token, status, provider_message_id FROM ' . $this->sendTable() . ' WHERE updated_at < ? AND status IN (?, ?) AND provider_message_id IS NOT NULL ORDER BY updated_at', $limit),
            [$before, SendState::Released->value, SendState::Failed->value],
        ) as $row) {
            $rows[] = [
                'mailbox_id'      => (string) $row['mailbox_id'],
                'idempotency_key' => (string) $row['idempotency_key'],
                'token'           => (string) $row['claim_token'],
                'status'          => SendState::from((string) $row['status']),
                'draft'           => (string) $row['provider_message_id'],
            ];
        }

        return $rows;
    }

    /**
     * Forgets the draft id of a settled row whose draft was dealt with (purge `--drafts`), without touching
     * `updated_at`, so the same purge run deletes the row. Conditional on the token and state; `row_version` changes.
     */
    public function forgetDraft(MailboxId $mailbox, string $key, string $token, SendState $status): void
    {
        $this->write('UPDATE ' . $this->sendTable() . ' SET provider_message_id = NULL, row_version = row_version + 1 WHERE mailbox_id = ? AND idempotency_key = ? AND claim_token = ? AND status = ?', [$mailbox->value, $key, $token, $status->value]);
    }

    /**
     * A transition by the claim holder (one conditional statement): `$from` → `$to` with the given columns, only while
     * the row is held by `$token` in state `$from`. Every transition bumps `row_version`, so MySQL / MariaDB count the
     * row even when nothing else changes within the same second (audit ZAT-R4): `$from === $to` is the heartbeat of a
     * long upload.
     *
     * @param array<string, int|string|null> $set columns among provider_message_id, internet_message_id, thread_id, reason, sent_at
     */
    public function updateSend(MailboxId $mailbox, string $key, string $token, SendState $from, SendState $to, array $set = []): bool
    {
        $sql    = 'UPDATE ' . $this->sendTable() . ' SET status = ?, row_version = row_version + 1, updated_at = ?';
        $params = [$to->value, $this->now()];
        foreach ($set as $column => $value) {
            if (!in_array($column, self::SEND_COLUMNS, true)) {
                throw new InvalidArgumentException('Unknown send column.');
            }
            $sql .= ', ' . $column . ' = ?';
            $params[] = $value;
        }

        return $this->write($sql . ' WHERE mailbox_id = ? AND idempotency_key = ? AND claim_token = ? AND status = ?', [...$params, $mailbox->value, $key, $token, $from->value]) === 1;
    }

    /**
     * Takes a key over from its previous holder (design section 9.4): one conditional statement on the old claim token
     * and state (and, for an in-flight state, on no progress since `$staleBefore`); it writes a new random token, so
     * exactly one of several concurrent callers wins. Counts the attempt.
     *
     * @param int|null $staleBefore UNIX seconds: the row must not have moved since (null for a released row)
     */
    public function takeOverSend(MailboxId $mailbox, string $key, string $oldToken, SendState $from, string $newToken, SendState $to, ?int $staleBefore): bool
    {
        $params = [$newToken, $to->value, $this->now(), $mailbox->value, $key, $oldToken, $from->value];
        if ($staleBefore !== null) {
            $params[] = $staleBefore;
        }

        return $this->write(
            'UPDATE ' . $this->sendTable() . ' SET claim_token = ?, status = ?, attempts = attempts + 1, row_version = row_version + 1, updated_at = ? WHERE mailbox_id = ? AND idempotency_key = ? AND claim_token = ? AND status = ?' . ($staleBefore !== null ? ' AND updated_at < ?' : ''),
            $params,
        ) === 1;
    }

    /**
     * Deletes settled send rows (`sent`, `failed`, `unknown`, `released`) not updated since `$before`, in batches;
     * in-flight rows (`drafting`, `sending`) are never purged. With `$keepDrafts`, `released` / `failed` rows that still
     * name a draft are kept, so the next `--drafts` purge retries that draft (audit ZAV-I). Returns the rows deleted.
     *
     * @param int $before UNIX seconds
     */
    public function purgeSends(int $before, int $batch = 500, bool $keepDrafts = false): int
    {
        $connection = $this->connection();
        $settled    = [SendState::Sent->value, SendState::Failed->value, SendState::Unknown->value, SendState::Released->value];
        $in         = implode(', ', array_fill(0, count($settled), '?'));
        $keep       = $keepDrafts ? ' AND NOT (status IN (?, ?) AND provider_message_id IS NOT NULL)' : '';
        $keepArgs   = $keepDrafts ? [SendState::Released->value, SendState::Failed->value] : [];
        $deleted    = 0;
        do {
            $rows = $connection->fetchAllAssociative(
                $connection->getDatabasePlatform()->modifyLimitQuery('SELECT mailbox_id, idempotency_key FROM ' . $this->sendTable() . ' WHERE updated_at < ? AND status IN (' . $in . ')' . $keep . ' ORDER BY updated_at', $batch),
                [$before, ...$settled, ...$keepArgs],
            );
            foreach ($rows as $row) {
                $deleted += $this->write('DELETE FROM ' . $this->sendTable() . ' WHERE mailbox_id = ? AND idempotency_key = ? AND updated_at < ? AND status IN (' . $in . ')' . $keep, [$row['mailbox_id'], $row['idempotency_key'], $before, ...$settled, ...$keepArgs]);
            }
        } while (count($rows) === $batch);

        return $deleted;
    }

    /**
     * Deletes every row of a mailbox in one transaction (disconnect, or a mailbox the host no longer knows); returns
     * the rows deleted.
     */
    public function deleteMailbox(MailboxId $mailbox): int
    {
        $connection = $this->connection();
        $deleted    = 0;
        // @igor-ignore - Closure runs synchronously inside run(); it is not stored on the service.
        DoctrineTransactionRunner::forConnection($connection, null, $this->logger)->run(function () use ($connection, $mailbox, &$deleted): void {
            $deleted = 0;
            foreach ($this->tables() as $table) {
                $deleted += (int) $connection->executeStatement('DELETE FROM ' . $table . ' WHERE mailbox_id = ?', [$mailbox->value]);
            }
        });

        return $deleted;
    }

    /**
     * Adds the tables to a schema (ORM `postGenerateSchema`, migrations, the schema command). Key columns get a binary
     * NO PAD collation on MySQL / MariaDB (REQ-SEC-006); hashes, statuses and the lease owner keep the table collation.
     *
     * @param AbstractPlatform|Connection|null $source the connection the schema is built for, its platform, or null for the
     *                                                 store's connection
     */
    public function defineSchema(Schema $schema, AbstractPlatform|Connection|null $source = null): void
    {
        $exact = ExactCollation::columnOptions($source ?? $this->connection(), $this->logger);
        if (!$schema->hasTable($this->mailboxTable())) {
            $table = $schema->createTable($this->mailboxTable());
            $table->addColumn('mailbox_id', 'string', ['length' => 128] + $exact);
            $table->addColumn('provider', 'string', ['length' => 16]);
            $table->addColumn('status', 'string', ['length' => 16]);
            $table->addColumn('reason', 'string', ['length' => 32, 'notnull' => false]);
            $table->addColumn('account_hash', 'string', ['length' => 64, 'notnull' => false]);
            $table->addColumn('updated_at', 'bigint');
            $table->setPrimaryKey(['mailbox_id']);
        }
        if (!$schema->hasTable($this->cursorTable())) {
            $table = $schema->createTable($this->cursorTable());
            $table->addColumn('mailbox_id', 'string', ['length' => 128] + $exact);
            $table->addColumn('folder_key', 'string', ['length' => 255] + $exact);
            $table->addColumn('cursor_value', 'text', ['notnull' => false]);
            $table->addColumn('cursor_version', 'integer', ['default' => 0]);
            $table->addColumn('lease_owner', 'string', ['length' => 32, 'notnull' => false]);
            $table->addColumn('lease_until', 'bigint', ['notnull' => false]);
            $table->addColumn('rerun', 'smallint', ['default' => 0]);
            $table->addColumn('last_complete_at', 'bigint', ['notnull' => false]);
            $table->addColumn('sync_error', 'string', ['length' => 32, 'notnull' => false]);
            $table->addColumn('sync_error_at', 'bigint', ['notnull' => false]);
            $table->setPrimaryKey(['mailbox_id', 'folder_key']);
        }
        if (!$schema->hasTable($this->subscriptionTable())) {
            $table = $schema->createTable($this->subscriptionTable());
            $table->addColumn('subscription_id', 'string', ['length' => 128] + $exact);
            $table->addColumn('mailbox_id', 'string', ['length' => 128] + $exact);
            $table->addColumn('folder_key', 'string', ['length' => 255] + $exact);
            $table->addColumn('client_state_hash', 'string', ['length' => 64]);
            $table->addColumn('expires_at', 'bigint');
            $table->addColumn('created_at', 'bigint');
            $table->setPrimaryKey(['subscription_id']);
            $table->addUniqueIndex(['mailbox_id', 'folder_key'], $this->tablePrefix . 'uniq_mail_kit_mailbox_sub_folder');
        }
        if (!$schema->hasTable($this->sendTable())) {
            $table = $schema->createTable($this->sendTable());
            $table->addColumn('mailbox_id', 'string', ['length' => 128] + $exact);
            $table->addColumn('idempotency_key', 'string', ['length' => 128] + $exact);
            $table->addColumn('payload_hash', 'string', ['length' => 64]);
            $table->addColumn('status', 'string', ['length' => 16]);
            $table->addColumn('claim_token', 'string', ['length' => 32]);
            $table->addColumn('provider_message_id', 'string', ['length' => 512, 'notnull' => false]);
            $table->addColumn('internet_message_id', 'string', ['length' => 512, 'notnull' => false]);
            $table->addColumn('thread_id', 'string', ['length' => 512, 'notnull' => false]);
            $table->addColumn('reason', 'string', ['length' => 128, 'notnull' => false]);
            $table->addColumn('attempts', 'integer', ['default' => 1]);
            $table->addColumn('row_version', 'integer', ['default' => 0]);
            $table->addColumn('claimed_at', 'bigint');
            $table->addColumn('updated_at', 'bigint');
            $table->addColumn('sent_at', 'bigint', ['notnull' => false]);
            $table->setPrimaryKey(['mailbox_id', 'idempotency_key']);
            $table->addIndex(['updated_at'], $this->tablePrefix . 'idx_mail_kit_mailbox_send_updated');
            $table->addIndex(['mailbox_id', 'provider_message_id'], $this->tablePrefix . 'idx_mail_kit_mailbox_send_message');
        }
    }

    /**
     * CREATE statements of the tables missing on the connection.
     *
     * @return list<string>
     */
    public function missingTablesSql(): array
    {
        $connection = $this->connection();
        $manager    = $connection->createSchemaManager();
        $schema     = new Schema();
        $this->defineSchema($schema, $connection);
        $sql = [];
        foreach ($this->tables() as $name) {
            if (!$manager->tablesExist([$name])) {
                foreach ($connection->getDatabasePlatform()->getCreateTableSQL($schema->getTable($name)) as $statement) {
                    $sql[] = $statement;
                }
            }
        }

        return $sql;
    }

    /**
     * Runs SQL statements on the store connection (the schema command).
     *
     * @param list<string> $statements
     */
    public function execute(array $statements): void
    {
        $connection = $this->connection();
        foreach ($statements as $statement) {
            $connection->executeStatement($statement);
        }
    }

    /**
     * The DBAL connection the store writes to (resolved per call).
     */
    public function connection(): Connection
    {
        $connection = $this->doctrine->getConnection($this->connectionName);
        assert($connection instanceof Connection);

        return $connection;
    }

    /**
     * The mailbox tables, children first (the delete order of {@see deleteMailbox()}).
     *
     * @return list<string>
     */
    private function tables(): array
    {
        return [$this->sendTable(), $this->subscriptionTable(), $this->cursorTable(), $this->mailboxTable()];
    }

    /**
     * Every other state change decides on its affected rows only where the statement always changes the row it matches
     * (a new status, owner or version, NULL to a value), so MySQL's changed-rows count equals the matched rows (audit
     * ZAT-R4). The parameters may hold a delta link: sensitive (audit ZAT-R5).
     *
     * @param list<mixed> $params
     */
    private function write(string $sql, #[SensitiveParameter] array $params): int
    {
        return SavepointWrites::write($this->connection(), self::SAVEPOINT, $sql, $params);
    }

    private function now(): int
    {
        return ($this->clock ?? new NativeClock())->now()->getTimestamp();
    }
}
