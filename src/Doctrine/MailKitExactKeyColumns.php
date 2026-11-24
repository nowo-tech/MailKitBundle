<?php

declare(strict_types=1);

namespace Nowo\MailKitBundle\Doctrine;

use Nowo\MailKitBundle\Graph\Inbound\Store\GraphInboundStore;
use Nowo\MailKitBundle\Mailbox\Infrastructure\Store\MailboxStore;
use Nowo\MailKitBundle\Tracking\Store\TrackingStore;
use Nowo\MigrationsKitContracts\Collation\ExactKeyColumn;
use Nowo\MigrationsKitContracts\Collation\ExactKeyColumnsInterface;

/**
 * The bundle's own declaration of the identity / access-key columns of its tables (REQ-SEC-006).
 *
 * The bundle already keeps these columns on the binary NO PAD collation of the server when it builds the schema
 * ({@see ExactCollation}, 1.11.0). What was missing is the *assertion*: until now only a host's hand-kept list knew
 * that, say, `mail_kit_tracking_send.recipient_id` is the key a GDPR erasure matches on, so a table created before
 * 1.11.0, or restored from a dump, or imported by hand, kept the host's folding default and nothing said so. This
 * declaration is what `nowo:migrations:check-key-collations` checks, and MigrationsKit discovers it by
 * autoconfiguration, so a host registers **nothing** and can delete the
 * `nowo_migrations_kit.collation_check.ignore_columns` entries it kept for these columns.
 *
 * Declared, and why each one is an identity or access key:
 *
 * - `mail_kit_tracking_send.send_id` — the opaque id of one tracked send: the pixel and click routes resolve a
 *   visitor's token to this value and the open is counted with `WHERE send_id = ?`.
 * - `mail_kit_tracking_send.recipient_id` — the host's person reference, the key of the GDPR export and erasure.
 *   On a folding collation `UserA`, `usera` and `UserA ` are one person; the store's `TrackingIds` guard refuses
 *   padded input on both paths, and the collation is the half of that boundary that also holds for a host import,
 *   a data migration or hand-written SQL.
 * - `mail_kit_tracking_send.tenant` — the tenant the send belongs to, compared with `=` in every scoped read.
 * - `mail_kit_graph_subscription.subscription_id` — the id of a record in *another* system (a Microsoft Graph
 *   change-notification subscription): an incoming notification is resolved to a mailbox profile by it.
 * - `mail_kit_graph_subscription.profile`, `mail_kit_graph_delta.profile`, `mail_kit_graph_inbound.profile` — the
 *   mailbox profile key, which is what ties a subscription, a delta link and an inbound record to one mailbox.
 * - `mail_kit_graph_inbound.message_key` — the idempotency key of one inbound message: the claim that decides
 *   whether a message is handled once or twice is `WHERE message_key = ?`.
 * - `mail_kit_mailbox.mailbox_id`, `mail_kit_mailbox_cursor.mailbox_id`, `mail_kit_mailbox_subscription.mailbox_id`
 *   (2.5.0) — the host's id of a delegated mailbox: status transitions, the cursor lease and the GDPR disconnect all
 *   match `WHERE mailbox_id = ?`.
 * - `mail_kit_mailbox_cursor.folder_key`, `mail_kit_mailbox_subscription.folder_key` (2.5.0) — the followed folder
 *   (a provider folder id is case-sensitive base64).
 * - `mail_kit_mailbox_subscription.subscription_id` (2.5.0) — a Graph subscription id: a notification is resolved to
 *   a mailbox and folder by it.
 *
 * Deliberately **not** declared, because a declaration is a claim about identity and an untrue one is worse than
 * none (on a server without a NO PAD collation it would report `fallback` about a column that does not want NO PAD):
 *
 * - `mail_kit_graph_inbound.status` — a state word the bundle itself writes (`processing`, …) and compares with the
 *   same literal. It is not an identity, nothing is looked up by it, and no input reaches it.
 * - `mail_kit_graph_subscription.client_state_hash` — never compared in SQL at all: it is read back and checked with
 *   `hash_equals()`.
 * - `mail_kit_tracking_send.log_id` and `.template_id` — a label and a back-reference that are written and read out,
 *   never matched (`log_id` appears only in `SET log_id = ?`).
 * - `mail_kit_tracking_send.last_open_ip` / `.last_open_ua` — a truncated address and a user-agent family kept for
 *   the report; they are telemetry, not keys.
 * - `mail_kit_mailbox.account_hash`, `mail_kit_mailbox_subscription.client_state_hash`, `mail_kit_mailbox_cursor.lease_owner`
 *   (2.5.0) — read back and compared with `hash_equals()`, or a random run id MailKit writes and matches itself.
 *
 * The table names are resolved from `nowo_mail_kit.doctrine.table_prefix` on the stores' own unprefixed names, never
 * literals, and the columns of a feature that is off are declared **inactive** (reported as skipped with the setting
 * that gates them, never as a missing table): tracking and the Graph inbound pipeline are both off by default and
 * then have no tables at all.
 *
 * Stateless and database-free (REQ-RUNTIME-002): the list is rebuilt on every call, so the service is safe to keep
 * between requests in a FrankenPHP worker with `kernel.reset: false`.
 *
 * @author Héctor Franco Aceituno <hectorfranco@nowo.tech>
 * @copyright 2026 Nowo.tech
 */
final readonly class MailKitExactKeyColumns implements ExactKeyColumnsInterface
{
    /** The package that owns the tables, as reported by the aggregate run. */
    public const OWNER = 'nowo-tech/mail-kit-bundle';

    /** Condition reported for the tracking columns when the feature is off. */
    public const TRACKING_CONDITION = 'nowo_mail_kit.tracking.enabled: true';

    /** Condition reported for the Graph inbound columns when the pipeline is off. */
    public const GRAPH_INBOUND_CONDITION = 'nowo_mail_kit.graph.inbound.enabled: true';

    /** Condition reported for the delegated-mailbox columns when they are off (2.5.0). */
    public const MAILBOXES_CONDITION = 'nowo_mail_kit.mailboxes.enabled: true';

    /**
     * @param string $tablePrefix nowo_mail_kit.doctrine.table_prefix
     * @param bool $tracking nowo_mail_kit.tracking.enabled (the tracked-sends table exists)
     * @param bool $graphInbound nowo_mail_kit.graph.inbound.enabled (the three Graph tables exist)
     * @param bool $mailboxes nowo_mail_kit.mailboxes.enabled (the delegated-mailbox tables exist, 2.5.0)
     */
    public function __construct(
        private string $tablePrefix = '',
        private bool $tracking = false,
        private bool $graphInbound = false,
        private bool $mailboxes = false,
    ) {
    }

    /**
     * {@inheritdoc}
     */
    public function exactKeyColumns(): array
    {
        $tracking = $this->tracking ? null : self::TRACKING_CONDITION;
        $inbound  = $this->graphInbound ? null : self::GRAPH_INBOUND_CONDITION;

        $send         = $this->tablePrefix . TrackingStore::TABLE;
        $subscription = $this->tablePrefix . GraphInboundStore::SUBSCRIPTION_TABLE;
        $delta        = $this->tablePrefix . GraphInboundStore::DELTA_TABLE;
        $inboundTable = $this->tablePrefix . GraphInboundStore::INBOUND_TABLE;
        $mailboxes    = $this->mailboxes ? null : self::MAILBOXES_CONDITION;
        $mailbox      = $this->tablePrefix . MailboxStore::MAILBOX_TABLE;
        $cursor       = $this->tablePrefix . MailboxStore::CURSOR_TABLE;
        $mailboxSub   = $this->tablePrefix . MailboxStore::SUBSCRIPTION_TABLE;

        return [
            new ExactKeyColumn($send, 'send_id', self::OWNER, 'id of one tracked send: the open is counted with WHERE send_id = ?', $tracking, $this->tracking),
            new ExactKeyColumn($send, 'recipient_id', self::OWNER, 'host person reference: the key of the GDPR export and erasure', $tracking, $this->tracking),
            new ExactKeyColumn($send, 'tenant', self::OWNER, 'tenant of the send, compared with = in every scoped read', $tracking, $this->tracking),
            new ExactKeyColumn($subscription, 'subscription_id', self::OWNER, 'Microsoft Graph subscription id: a notification is resolved to a mailbox by it', $inbound, $this->graphInbound),
            new ExactKeyColumn($subscription, 'profile', self::OWNER, 'mailbox profile of the subscription', $inbound, $this->graphInbound),
            new ExactKeyColumn($delta, 'profile', self::OWNER, 'mailbox profile the delta link belongs to (primary key)', $inbound, $this->graphInbound),
            new ExactKeyColumn($inboundTable, 'message_key', self::OWNER, 'idempotency key of one inbound message: handled once or twice is decided on it', $inbound, $this->graphInbound),
            new ExactKeyColumn($inboundTable, 'profile', self::OWNER, 'mailbox profile the inbound record belongs to', $inbound, $this->graphInbound),
            new ExactKeyColumn($mailbox, 'mailbox_id', self::OWNER, 'host id of a delegated mailbox: status transitions match WHERE mailbox_id = ?', $mailboxes, $this->mailboxes),
            new ExactKeyColumn($cursor, 'mailbox_id', self::OWNER, 'delegated mailbox of the folder cursor (primary key)', $mailboxes, $this->mailboxes),
            new ExactKeyColumn($cursor, 'folder_key', self::OWNER, 'followed folder of the cursor (primary key; provider folder ids are case-sensitive)', $mailboxes, $this->mailboxes),
            new ExactKeyColumn($mailboxSub, 'subscription_id', self::OWNER, 'Microsoft Graph subscription id: a notification is resolved to a mailbox and folder by it', $mailboxes, $this->mailboxes),
            new ExactKeyColumn($mailboxSub, 'mailbox_id', self::OWNER, 'delegated mailbox of the subscription', $mailboxes, $this->mailboxes),
            new ExactKeyColumn($mailboxSub, 'folder_key', self::OWNER, 'followed folder of the subscription', $mailboxes, $this->mailboxes),
        ];
    }
}
