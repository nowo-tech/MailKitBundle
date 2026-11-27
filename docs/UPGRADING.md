# Upgrading

## Table of contents

- [Unreleased](#unreleased)
- [To 2.7.0](#to-270)
  - [Stored e-mail templates (2.7.0, opt-in)](#stored-e-mail-templates-270-opt-in)
  - [Images in template HTML: https only](#images-in-template-html-https-only)
  - [`MailKitExactKeyColumns` gets a fifth argument](#mailkitexactkeycolumns-gets-a-fifth-argument)
- [To 2.6.0](#to-260)
  - [Gmail send-only (2.6.0, opt-in)](#gmail-send-only-260-opt-in)
  - [Behaviour change: `MailboxSettings` refuses folders for Gmail](#behaviour-change-mailboxsettings-refuses-folders-for-gmail)
  - [For code that used internal classes (2.6.0)](#for-code-that-used-internal-classes-260)
  - [Owner / Beacon checklist before enabling Gmail](#owner--beacon-checklist-before-enabling-gmail)
- [To 2.5.0](#to-250)
  - [Delegated mailboxes (2.5.0, opt-in)](#delegated-mailboxes-250-opt-in)
- [To 2.2.2](#to-222)
- [To 2.2.1](#to-221)
- [To 2.2.0](#to-220)
  - [The bundle declares its own identity / access-key columns (REQ-SEC-006)](#the-bundle-declares-its-own-identity--access-key-columns-req-sec-006)
- [To 2.1.0](#to-210)
- [To 2.0.0](#to-200)
  - [Before you upgrade](#before-you-upgrade)
  - [`log.map_entities` follows `log.enabled`](#logmap_entities-follows-logenabled)
  - [Sender: `SenderIdentityProviderInterface` only](#sender-senderidentityproviderinterface-only)
  - [Removed](#removed)
  - [Composer moves](#composer-moves)
  - [1.x support](#1x-support)
- [To 1.14.1](#to-1141)
- [To 1.14.0](#to-1140)
- [To 1.14.0](#to-1140-1)
  - [`log.map_entities` unset with the log off](#logmap_entities-unset-with-the-log-off)
  - [Host sender services on `SenderProviderInterface`](#host-sender-services-on-senderproviderinterface)
  - [No host-flush probe in MailKit](#no-host-flush-probe-in-mailkit)
  - [Preparing for 2.0](#preparing-for-20)
- [To 1.13.1](#to-1131)
- [To 1.13.0](#to-1130)
  - [Lock conflicts answer 409](#lock-conflicts-answer-409)
  - [Lock conflicts are no longer swallowed](#lock-conflicts-are-no-longer-swallowed)
  - [Graph inbound: a failed decision write keeps the claim](#graph-inbound-a-failed-decision-write-keeps-the-claim)
  - [transaction-doctrine and your entity manager](#transaction-doctrine-and-your-entity-manager)
  - [Request-level CONFLICT_002](#request-level-conflict_002)
- [To 1.12.2](#to-1122)
- [To 1.12.1](#to-1121)
- [To 1.12.0](#to-1120)
  - [Sender identity without Symfony Mime (`SenderIdentityProviderInterface`)](#sender-identity-without-symfony-mime-senderidentityproviderinterface)
- [To 1.11.0](#to-1110)
  - [What changed and why](#what-changed-and-why)
  - [These tables are DBAL, not ORM](#these-tables-are-dbal-not-orm)
  - [Find the columns that still have the wrong collation](#find-the-columns-that-still-have-the-wrong-collation)
  - [MySQL 8.0.17 and newer (8.x and 9.x)](#mysql-8017-and-newer-8x-and-9x)
  - [MariaDB](#mariadb)
  - [Is the ALTER safe?](#is-the-alter-safe)
- [To 1.10.2](#to-1102)
  - [To 1.10.2](#to-1102-1)
- [To 1.10.1](#to-1101)
- [To 1.10.0](#to-1100)
- [To 1.9.1](#to-191)
- [To 1.9.0](#to-190)
- [To 1.8.0](#to-180)
- [To 1.7.1](#to-171)
- [To 1.7.0](#to-170)
- [To 1.6.0](#to-160)
- [To 1.5.0](#to-150)
- [To 1.4.0](#to-140)
- [To 1.3.0](#to-130)
  - [To 1.3.0](#to-130-1)
- [To 1.2.0](#to-120)
  - [To 1.2.0](#to-120-1)
- [To 1.1.1](#to-111)
- [To 1.1.0](#to-110)
- [To 1.0.0 (initial release)](#to-100-initial-release)
  - [Requirements](#requirements)
  - [Enable and configure](#enable-and-configure)
  - [Migrating from an in-app mailer (Beacon-style)](#migrating-from-an-in-app-mailer-beacon-style)
  - [Template contract](#template-contract)
  - [Breaking changes](#breaking-changes)

## Unreleased

## To 2.7.0

From **2.6.0**:

```bash
composer update nowo-tech/mail-kit-bundle
```

Nothing changes unless you set `nowo_mail_kit.templates.enabled: true`: no new table, route, service, Messenger
message or schedule otherwise. No BC break.

### Stored e-mail templates (2.7.0, opt-in)

Contract: [TEMPLATES — Stored e-mail templates](TEMPLATES.md#stored-e-mail-templates-270); usage:
[USAGE](USAGE.md#stored-e-mail-templates-270); keys: [CONFIGURATION — templates](CONFIGURATION.md#templates).

1. Require `symfony/html-sanitizer` (the build fails without it when templates are on) and keep doctrine/doctrine-bundle.
2. Implement `Template\Port\TemplatePermissionInterface` and set `templates.permission` (without it everything is
   denied). Beacon: `ManageShared` ⇐ `crm.manage`; `Personal` / `UseShared` ⇐ CRM members of the organisation; check
   that the person belongs to the actor's tenant (required: MailKit trusts the tenant it is given).
3. With `personal_data.enabled` and UserKit: implement `Template\Port\TemplateOwnerResolverInterface` and set
   `templates.owner_resolver` (the build fails otherwise). The new `email_templates` eraser deletes a person's personal
   templates and clears their authorship of shared ones.
4. Create the table: `doctrine:migrations:diff` (ORM hosts, the `postGenerateSchema` listener adds
   `<prefix>mail_kit_template`) or `nowo:mail-kit:templates:schema` (prints the CREATE statement for your platform;
   `--force` runs it).
5. Register your placeholder providers (`PlaceholderProviderInterface`, autoconfigured). MailKit ships none.
6. Build `TemplateActor(tenant, owner)` from your security context and call the services.

### Images in template HTML: https only

Template HTML keeps absolute `https` images and `cid:` references only (owner decision 2026-11-27); `http`, `data:` and
relative image sources are removed on save and again on render (`MailHtmlSanitizer` mode `RemoteImages::HttpsOnly`,
new enum case in 2.7.0). Host logos must be served over https or attached inline (`cid:`). If you `match` exhaustively
on `Mailbox\Html\RemoteImages`, add the `HttpsOnly` case; `mailboxes.html.remote_images` does not offer it.

### `MailKitExactKeyColumns` gets a fifth argument

`Doctrine\MailKitExactKeyColumns::__construct()` has a new optional fifth argument `$templates` (default `false`). The
seven key columns of `mail_kit_template` are declared (inactive until `templates.enabled`). Hosts that construct the
class themselves keep working.

## To 2.6.0

From **2.5.0**:

```bash
composer update nowo-tech/mail-kit-bundle
```

No new table, route, Messenger message or schedule; nothing changes unless you set `nowo_mail_kit.mailboxes.gmail.enabled: true`.

### Gmail send-only (2.6.0, opt-in)

Nothing changes unless you set `nowo_mail_kit.mailboxes.gmail.enabled: true` (it needs `mailboxes.enabled: true`). Owner
decision D-20261124-09: a Gmail mailbox only sends. Usage: [USAGE — Send-only Gmail mailboxes](USAGE.md#send-only-gmail-mailboxes-260);
Google Cloud: [GOOGLE-GMAIL-SETUP](GOOGLE-GMAIL-SETUP.md).

1. Your OAuth connection requests exactly `Mailbox\Gmail\GmailScopes::REQUESTED` (`openid email
   https://www.googleapis.com/auth/gmail.send`) with PKCE and a single-use `state`, and your
   `MailboxTokenProviderInterface` returns the granted scopes in `MailboxAccessToken::$scopes` (a token without
   `gmail.send` pauses the mailbox `ScopeMissing`).
2. Your directory returns `new MailboxSettings($id, MailboxProvider::Gmail, $address, [])`: a Gmail mailbox follows no
   folders (a non-empty list is refused).
3. Send new messages only; `send()` refuses a reply or forward for a Gmail mailbox. No sent copy is delivered.
4. No new table, route, Messenger message or schedule; Gmail mailboxes are skipped by the sweep and renewal commands.
5. Show the mailbox as send-only (`MailboxProvider::isSendOnly()`, `MailboxSyncStatus::isSendOnly()`, translation keys
   `mailbox.provider.*`).

### Behaviour change: `MailboxSettings` refuses folders for Gmail

`MailboxSettings` (released in 2.5.0) required at least one folder for every provider. From 2.6.0 it refuses folders
for a send-only provider (Gmail) and still requires them for Outlook. In 2.5.0 a Gmail mailbox had no gateway and could
never become active (`MailboxNotActive`), so only a host directory that already pre-built Gmail rows with folders is
affected: its `directory->mailbox()` would now throw `InvalidArgumentException`. Return `[]` for Gmail. `MailboxSyncStatus`
gains a trailing optional `$provider` argument (no break).

### For code that used internal classes (2.6.0)

`Mailbox\Engine\SubmitTokenRejected` takes an optional provider name; new internal `SendOnlyMailboxGatewayInterface`,
`PreparedMessage`, `SubmittedMessage`, `Mailbox\Gmail\GmailApiClient` and `GmailMailboxGateway`.

### Owner / Beacon checklist before enabling Gmail

1. In Google Cloud, on the consent screen's Data access, re-add exactly `openid`, `email` and
   `https://www.googleapis.com/auth/gmail.send`, and remove any leftover `gmail.readonly` from an earlier plan.
2. Submit OAuth verification for the sensitive `gmail.send` scope (no CASA assessment: no restricted scope).
3. Run the manual Bcc check with two owner-controlled test accounts.

## To 2.5.0

From **2.2.2**:

```bash
composer update nowo-tech/mail-kit-bundle
```

### Delegated mailboxes (2.5.0, opt-in)

Nothing changes unless you set `nowo_mail_kit.mailboxes.enabled: true`. Then you implement the host ports, create
four tables and schedule three commands. Usage: [USAGE — Delegated Outlook mailboxes](USAGE.md#delegated-outlook-mailboxes-250).

1. Implement `MailboxTokenProviderInterface` (your OAuth connection: MailKit only asks for the current access token;
   scopes `Mail.ReadWrite`, `Mail.Send`, `offline_access`, `User.Read`), `MailboxDirectoryInterface` and
   `MailboxMessageSinkInterface` (with a **unique key on mailbox id + provider message id**: a second `store()` must
   answer `StoreOutcome::Duplicate`). Optionally `MailboxExclusionPolicyInterface`, `MailboxCallObserverInterface` and
   `MailboxMessageIndexInterface` (`message_index`: the messages of a page you already store in that folder are not
   fetched again).
2. Configure:

   ```yaml
   nowo_mail_kit:
       mailboxes:
           enabled: true
           token_provider: App\Mail\MailboxTokens
           directory: App\Mail\MailboxDirectory
           sink: App\Mail\MailboxSink
           graph:
               enabled: true
               webhook:
                   notification_url: 'https://app.example.com/hooks/mail-kit/v1/mailbox/graph'
   ```

3. Import the route loader `nowo_mail_kit_mailbox_webhook` and make its path public, stateless and outside your HTTP
   log; route `SyncMailboxFolder`, `FetchMailboxMessage`, `RenewMailboxSubscriptions`, `DeliverSentCopy` and
   `ReconcileMailboxSend` to an async transport that supports delays (the last two are sent with a `DelayStamp`).
4. Schedule `nowo:mail-kit:mailbox:sweep` every 5 minutes, `nowo:mail-kit:mailbox:subscriptions` hourly and
   `nowo:mail-kit:mailbox:purge` daily (settled send rows after `send.retention`).
5. If `framework.http_client` (or the scoped client you give MailKit) uses `retry_failed`, set
   `mailboxes.http_client` to a client without it: a retrying client fails the build (a retried send could go out
   twice). The 1.8 `graph.http_client` (shared mailboxes) keeps building in 2.x but triggers a deprecation
   (`Since nowo-tech/mail-kit-bundle 2.5: … retries failed requests`, shown by the profiler and the deprecation log):
   give it a client with `retry_failed: false` now; **MailKit 3.0 fails the build** for a retrying `graph.http_client`.
6. Sending (`MailboxSenderInterface`): call `send()` **outside** a transaction on the mailbox connection. If your
   Messenger handlers run inside a `doctrine_transaction` middleware, point `mailboxes.connection` at a dedicated
   DBAL connection (the same database is fine) so the send rows commit on their own.
7. Create the tables: with the ORM the `postGenerateSchema` listener adds them to `doctrine:migrations:diff`;
   otherwise run `nowo:mail-kit:mailbox:schema --force`, or apply the DDL below (no table prefix shown; key columns
   use the binary NO PAD collation, REQ-SEC-006).

MySQL 8.4:

```sql
CREATE TABLE mail_kit_mailbox (mailbox_id VARCHAR(128) CHARACTER SET utf8mb4 NOT NULL COLLATE `utf8mb4_0900_bin`, provider VARCHAR(16) NOT NULL, status VARCHAR(16) NOT NULL, reason VARCHAR(32) DEFAULT NULL, account_hash VARCHAR(64) DEFAULT NULL, updated_at BIGINT NOT NULL, PRIMARY KEY (mailbox_id));
CREATE TABLE mail_kit_mailbox_cursor (mailbox_id VARCHAR(128) CHARACTER SET utf8mb4 NOT NULL COLLATE `utf8mb4_0900_bin`, folder_key VARCHAR(255) CHARACTER SET utf8mb4 NOT NULL COLLATE `utf8mb4_0900_bin`, cursor_value LONGTEXT DEFAULT NULL, cursor_version INT DEFAULT 0 NOT NULL, lease_owner VARCHAR(32) DEFAULT NULL, lease_until BIGINT DEFAULT NULL, rerun SMALLINT DEFAULT 0 NOT NULL, last_complete_at BIGINT DEFAULT NULL, sync_error VARCHAR(32) DEFAULT NULL, sync_error_at BIGINT DEFAULT NULL, PRIMARY KEY (mailbox_id, folder_key));
CREATE TABLE mail_kit_mailbox_subscription (subscription_id VARCHAR(128) CHARACTER SET utf8mb4 NOT NULL COLLATE `utf8mb4_0900_bin`, mailbox_id VARCHAR(128) CHARACTER SET utf8mb4 NOT NULL COLLATE `utf8mb4_0900_bin`, folder_key VARCHAR(255) CHARACTER SET utf8mb4 NOT NULL COLLATE `utf8mb4_0900_bin`, client_state_hash VARCHAR(64) NOT NULL, expires_at BIGINT NOT NULL, created_at BIGINT NOT NULL, UNIQUE INDEX uniq_mail_kit_mailbox_sub_folder (mailbox_id, folder_key), PRIMARY KEY (subscription_id));
CREATE TABLE mail_kit_mailbox_send (mailbox_id VARCHAR(128) CHARACTER SET utf8mb4 NOT NULL COLLATE `utf8mb4_0900_bin`, idempotency_key VARCHAR(128) CHARACTER SET utf8mb4 NOT NULL COLLATE `utf8mb4_0900_bin`, payload_hash VARCHAR(64) NOT NULL, status VARCHAR(16) NOT NULL, claim_token VARCHAR(32) NOT NULL, provider_message_id VARCHAR(512) DEFAULT NULL, internet_message_id VARCHAR(512) DEFAULT NULL, thread_id VARCHAR(512) DEFAULT NULL, reason VARCHAR(128) DEFAULT NULL, attempts INT DEFAULT 1 NOT NULL, row_version INT DEFAULT 0 NOT NULL, claimed_at BIGINT NOT NULL, updated_at BIGINT NOT NULL, sent_at BIGINT DEFAULT NULL, INDEX idx_mail_kit_mailbox_send_updated (updated_at), INDEX idx_mail_kit_mailbox_send_message (mailbox_id, provider_message_id), PRIMARY KEY (mailbox_id, idempotency_key));
```

MariaDB 11.4: the same with `utf8mb4_nopad_bin` instead of `utf8mb4_0900_bin`.

PostgreSQL 16:

```sql
CREATE TABLE mail_kit_mailbox (mailbox_id VARCHAR(128) NOT NULL, provider VARCHAR(16) NOT NULL, status VARCHAR(16) NOT NULL, reason VARCHAR(32) DEFAULT NULL, account_hash VARCHAR(64) DEFAULT NULL, updated_at BIGINT NOT NULL, PRIMARY KEY (mailbox_id));
CREATE TABLE mail_kit_mailbox_cursor (mailbox_id VARCHAR(128) NOT NULL, folder_key VARCHAR(255) NOT NULL, cursor_value TEXT DEFAULT NULL, cursor_version INT DEFAULT 0 NOT NULL, lease_owner VARCHAR(32) DEFAULT NULL, lease_until BIGINT DEFAULT NULL, rerun SMALLINT DEFAULT 0 NOT NULL, last_complete_at BIGINT DEFAULT NULL, sync_error VARCHAR(32) DEFAULT NULL, sync_error_at BIGINT DEFAULT NULL, PRIMARY KEY (mailbox_id, folder_key));
CREATE TABLE mail_kit_mailbox_subscription (subscription_id VARCHAR(128) NOT NULL, mailbox_id VARCHAR(128) NOT NULL, folder_key VARCHAR(255) NOT NULL, client_state_hash VARCHAR(64) NOT NULL, expires_at BIGINT NOT NULL, created_at BIGINT NOT NULL, PRIMARY KEY (subscription_id));
CREATE UNIQUE INDEX uniq_mail_kit_mailbox_sub_folder ON mail_kit_mailbox_subscription (mailbox_id, folder_key);
CREATE TABLE mail_kit_mailbox_send (mailbox_id VARCHAR(128) NOT NULL, idempotency_key VARCHAR(128) NOT NULL, payload_hash VARCHAR(64) NOT NULL, status VARCHAR(16) NOT NULL, claim_token VARCHAR(32) NOT NULL, provider_message_id VARCHAR(512) DEFAULT NULL, internet_message_id VARCHAR(512) DEFAULT NULL, thread_id VARCHAR(512) DEFAULT NULL, reason VARCHAR(128) DEFAULT NULL, attempts INT DEFAULT 1 NOT NULL, row_version INT DEFAULT 0 NOT NULL, claimed_at BIGINT NOT NULL, updated_at BIGINT NOT NULL, sent_at BIGINT DEFAULT NULL, PRIMARY KEY (mailbox_id, idempotency_key));
CREATE INDEX idx_mail_kit_mailbox_send_updated ON mail_kit_mailbox_send (updated_at);
CREATE INDEX idx_mail_kit_mailbox_send_message ON mail_kit_mailbox_send (mailbox_id, provider_message_id);
```

SQLite: as PostgreSQL, with `cursor_value CLOB` and `INTEGER` for `cursor_version`, `attempts` and `row_version`.

The send table (2.5.0 part 2) is new in this version; there is no earlier send table to migrate.

#### For code that used internal classes

`Graph\GraphApiClient` (`@internal`) now accepts `?GraphTokenProvider` and a trailing `?GraphRequestOptionsInterface`,
and its `call()`, `callUrl()`, `download()`, `uploadChunk()` and `cancelUpload()` take
`GraphMailbox|GraphCredentialInterface`; `call()` takes a trailing `bool $retryUnauthorized = true`.
`Graph\Inbound\GraphMessageNormalizer` takes any `Mailbox\Html\MailHtmlSanitizer` (an `InboundHtmlSanitizer` still
fits). Public 1.x / 2.x classes are unchanged.

## To 2.2.2

From **2.2.1**:

```bash
composer update nowo-tech/mail-kit-bundle
```

From **2.2.1**: `composer update nowo-tech/mail-kit-bundle`. Nothing to change in the configuration.

- **Graph app-only send (ZAT-L5):** if you run Messenger with a retry or failure transport, failed Graph sends
  stored before this release may hold an app-only bearer token in their flattened trace. Those tokens expire within
  about an hour, but purge or inspect old `messenger_messages` failure rows (and error-tracker events) if you keep
  them. A failure of a decorated HTTP client is now reported as a Graph transport error (retryable) instead of the
  decorator's own exception.
- **Graph inbound HTML (ZAT-L4):** with `allow_remote_images: false`, `srcset`, `poster`, `background` and the other
  URL-bearing media attributes, and the `<source>` / `<track>` elements, are now always removed. Messages already
  stored keep the HTML they were sanitised with.

## To 2.2.1

From **2.2.0**:

```bash
composer update nowo-tech/mail-kit-bundle
```

## To 2.2.0

From **2.1.0**:

```bash
composer update nowo-tech/mail-kit-bundle
```

### The bundle declares its own identity / access-key columns (REQ-SEC-006)

Nothing to do: the declaration is discovered by autoconfiguration, and a host that does not install
`nowo-tech/migrations-kit-bundle` is unaffected.

What you can **remove**: if you excused this bundle's key columns by hand so the uniform collation gate would pass,
those entries are now redundant — a declared exact-key column is exempt from the uniform gate by construction, and
`nowo:migrations:check-key-collations` checks it under its real owner (`nowo-tech/mail-kit-bundle`) instead of yours.
Delete them:

```diff
 nowo_migrations_kit:
     collation_check:
         ignore_columns:
-            'mail_kit_tracking_send.send_id': 'MailKit tracking send id'
-            'mail_kit_tracking_send.recipient_id': 'MailKit tracking recipient'
-            'mail_kit_tracking_send.tenant': 'MailKit tracking tenant'
```

The Graph inbound tables (`mail_kit_graph_subscription`, `mail_kit_graph_delta`, `mail_kit_graph_inbound`) go the same
way, prefixed tables included: the declaration resolves `nowo_mail_kit.doctrine.table_prefix` itself. Keeping an entry
is not an error, but it outlives the declaration and goes on excusing a column nothing checks any more, so drop it.

If the gate now *fails* on one of these columns, it is telling the truth: that table predates 1.11.0, or it was
restored from a dump or created by hand, and it still has the host's folding default collation. The check prints the
`ALTER TABLE … MODIFY … COLLATE …` to run.

With `tracking.enabled: false` or `graph.inbound.enabled: false` the columns of that feature are reported as
**skipped** with their setting, never as a failure: there is no table to check.

## To 2.1.0

From **2.0.0**:

```bash
composer update nowo-tech/mail-kit-bundle
```

## To 2.0.0

From **1.14.1**:

```bash
composer update nowo-tech/mail-kit-bundle
```

Target **2.0.0** (major), from **1.14.x**:

```bash
composer require nowo-tech/mail-kit-bundle:^2.0 -W
```

2.0 contains only changes that 1.x announced through deprecation notices: an application that runs the last 1.x
minor without deprecation notices from this bundle upgrades without code changes. After 2.0.0 is released, the 1.x
line receives security fixes for 6 months: fixes rated Moderate and above are backported to the last 1.x minor;
Low ones are fixed in 2.x only.

The PHP, Symfony and database requirements are unchanged.

### Before you upgrade

On 1.14, build the container and clear every MailKit deprecation notice. They are build-time notices: they show in
`bin/console debug:container --deprecations` (and the profiler's "Deprecations" tab or a test run with deprecations
reported), never per request. 1.14 raises:

> Since nowo-tech/mail-kit-bundle 1.14: Not setting "nowo_mail_kit.log.map_entities" while "nowo_mail_kit.log.enabled"
> is false is deprecated: it keeps the log entities (SentMail, SentMailBody) mapped in your entity manager. In 2.0 the
> option defaults to "log.enabled" (false here), so they are no longer mapped and your schema tool proposes dropping
> their tables. Set "nowo_mail_kit.log.map_entities: true" to keep the mapping, or "nowo_mail_kit.log.map_entities:
> false" to opt in to the 2.0 behaviour now (see UPGRADING.md "To 1.14.0").

> Since nowo-tech/mail-kit-bundle 1.14: The sender service "app.sender" (App\Mail\TenantSender) set in
> "nowo_mail_kit.mailer.sender_provider" implements "Nowo\MailKitBundle\Sender\SenderProviderInterface", which is
> deprecated and removed in 2.0. Implement "Nowo\MailKitBundle\Sender\SenderIdentityProviderInterface" instead (it
> returns "Nowo\MailKitBundle\Sender\MailAddress" rather than a Symfony Mime Address; see UPGRADING.md "To 1.12.0").

> Since nowo-tech/mail-kit-bundle 1.14: The service "app.sender_decorator" (App\Mail\SenderDecorator) decorates the
> MailKit sender and implements "Nowo\MailKitBundle\Sender\SenderProviderInterface", which is deprecated and removed
> in 2.0. Implement "Nowo\MailKitBundle\Sender\SenderIdentityProviderInterface" instead (see UPGRADING.md "To
> 1.12.0").

Also check what no notice can report:

- services of yours that **inject** `SenderProviderInterface` (inject `SenderIdentityProviderInterface`);
- sender services built by a factory (their class is only known at runtime);
- the packages your application uses itself but got through MailKit (see [Composer moves](#composer-moves)).

### `log.map_entities` follows `log.enabled`

| `log.enabled` | `log.map_entities` | 1.x | 2.0 |
| --- | --- | --- | --- |
| `true` (default) | unset | mapped | mapped (unchanged) |
| `false` | unset | mapped | **not mapped** |
| `false` | `true` | mapped | mapped (unchanged) |
| `false` | `false` | not mapped | not mapped (unchanged) |

When the mapping leaves your entity manager, the schema tool proposes dropping `<prefix>mail_kit_sent_mail` and
`<prefix>mail_kit_sent_mail_body`. Purge or erase the rows first (`nowo:mail-kit:log:purge`,
`nowo:mail-kit:log:forget`), then let the tables go; or keep them mapped:

```yaml
nowo_mail_kit:
    log:
        enabled: false
        map_entities: true # keeps the 1.x behaviour
```

What still works on the rows written while the log was on, once the mapping leaves your entity manager (log off,
`log.map_entities` unset) while the old tables are still in the database:

- `nowo:mail-kit:log:purge`, `nowo:mail-kit:log:forget` and `SentMailErasureInterface` still delete and redact them
  (with the Doctrine ORM installed and configured in your application);
- the UserKit GDPR integration (`personal_data.enabled`: the `emails_received` eraser and export section) is **no
  longer registered**, so a UserKit erasure or export request no longer covers those leftover rows.

So purge (or forget) and drop the old log tables before you upgrade, or keep `log.map_entities: true` until you have.
Without the ORM (installed and configured) nothing is erased: the erasure port is a no-op.

### Sender: `SenderIdentityProviderInterface` only

`Sender\SenderProviderInterface` is removed, so a class that still implements it **no longer loads**. Move every host
sender service and every decorator of the sender to `Sender\SenderIdentityProviderInterface`, as shown in
[To 1.12.0](#sender-identity-without-symfony-mime-senderidentityproviderinterface):

| Removed | Implement instead |
| --- | --- |
| `SenderProviderInterface::from(): ?Symfony\Component\Mime\Address` | `SenderIdentityProviderInterface::defaultFrom(): ?Sender\MailAddress` |
| `SenderProviderInterface::replyTo(): ?Symfony\Component\Mime\Address` | `SenderIdentityProviderInterface::defaultReplyTo(): ?Sender\MailAddress` |

The configuration does not change: `nowo_mail_kit.mailer.sender_provider` keeps your service id. Decorate
`Nowo\MailKitBundle\Sender\SenderIdentityProviderInterface` (or your service) instead of the removed id.

If something was missed, the container build fails with a message that names the service and its class, never with a
PHP fatal error or a `TypeError` on the first send:

- a `mailer.sender_provider` service, or a decorator of the sender, that does not implement
  `SenderIdentityProviderInterface` (a class that still implements the removed interface included);
- a service that still decorates `Nowo\MailKitBundle\Sender\SenderProviderInterface`;
- a class registered by resource autodiscovery (`services: App\: resource: ...`) that still implements the removed
  interface, for example with `#[AsDecorator]` or `#[AsAlias]`. Symfony cannot load it and would otherwise drop the
  definition without a word, losing your sender change.

`MailSettings` (the built-in sender) implements `SenderIdentityProviderInterface`; its `from()` / `replyTo()` methods
stay and return the same addresses as Symfony Mime values. `SenderPolicy` and `TestMailSender` now take
`SenderIdentityProviderInterface` in their constructors (only relevant if you build them yourself).

### Removed

| Removed | Deprecated since | Replacement |
| --- | --- | --- |
| `Sender\SenderProviderInterface` | 1.12 ([To 1.12.0](#sender-identity-without-symfony-mime-senderidentityproviderinterface)) | `Sender\SenderIdentityProviderInterface` + `Sender\MailAddress` |
| `Sender\SenderProviderGate` (internal) and the services `nowo_mail_kit.sender.provider_gate` / `nowo_mail_kit.sender.identity_gate` | 1.12 (wiring of the removed port) | none: the port is wired straight to your service |
| The `Nowo\MailKitBundle\Sender\SenderProviderInterface` service alias | 1.12 | `Nowo\MailKitBundle\Sender\SenderIdentityProviderInterface` |
| `Settings\ResendSettingsSection` (constant holder) | 1.3 ([To 1.3.0](#to-130-1)) | `Settings\MailerSettingsSection::NAME` or `Settings\LegacyResendSettings::SECTION` |
| `log.map_entities` defaulting to `true` | 1.14 ([To 1.14.0](#logmap_entities-unset-with-the-log-off)) | the option follows `log.enabled`; set `true` to keep the mapping |
| The 1.14 build-time deprecation notices | 1.14 | none (what they announced is done) |

`SenderPolicy::REFUSED_ADDRESS_MESSAGE` replaces `SenderProviderGate::REFUSED_ADDRESS_MESSAGE` (same text) for the
warning logged when the mailer refuses an address of the sender port.

### Composer moves

`nowo-tech/instance-settings-bundle`, `nowo-tech/doctrine-encrypt-bundle` and `doctrine/orm` move from `require` to
`suggest`. A host that got them through MailKit loses them on the next `composer update`, and Symfony Flex then
removes their bundle lines and configuration. Require what you use **before or with** the update:

| Package | Needed for |
| --- | --- |
| `nowo-tech/instance-settings-bundle` (^1.3.1) | the default configuration source: the `mailer` settings section, the test e-mail and the Resend actions. It installs `nowo-tech/doctrine-encrypt-bundle` and `doctrine/orm` itself. |
| `doctrine/orm` (^3.3) | the sent-mail log (`log.enabled: true`, the default) and its admin pages, and erasure / retention of log rows |
| `nowo-tech/doctrine-encrypt-bundle` (^2.10) | the encrypted settings fields (through Instance Settings) and your own encrypted fields |

The default setup (the `mailer` settings section and the sent-mail log) therefore needs:

```bash
composer require nowo-tech/instance-settings-bundle doctrine/orm
composer require nowo-tech/mail-kit-bundle:^2.0 -W
```

`conflict` refuses versions below the old floors (`nowo-tech/instance-settings-bundle` <1.3.1,
`nowo-tech/doctrine-encrypt-bundle` <2.10, `doctrine/orm` <3.3). `symfony/mime`, `doctrine/dbal`,
`doctrine/doctrine-bundle` and `doctrine/persistence` stay required.

A configuration that needs a missing package fails the build instead of degrading silently:

- **No Instance Settings and no host ports.** Without `mailer.dsn_provider` and `mailer.sender_provider`, the build
  fails and asks you to run `composer require nowo-tech/instance-settings-bundle` (or to set both ports). Mail never
  falls back silently to the framework transport.
- **The log without the ORM.** `log.enabled: true` (the default) without `doctrine/orm` fails the build: run
  `composer require doctrine/orm`, or set `log.enabled: false`.

A host that provides both ports and turns the log off runs with none of the three packages (the setup of
USAGE.md, "Without Instance Settings and without the ORM"), as in 1.x.

### 1.x support

1.x receives security fixes for 6 months after 2.0.0 is released. Fixes rated Moderate and above are backported to
the last 1.x minor as a 1.x patch; Low ones are fixed in 2.x only, with the advice to upgrade. Advisories name both
lines.

## To 1.14.1

From **1.14.0**:

```bash
composer update nowo-tech/mail-kit-bundle
```

## To 1.14.0

From **1.13.1**:

```bash
composer update nowo-tech/mail-kit-bundle
```

## To 1.14.0

From **1.13.x**:

```bash
composer update nowo-tech/mail-kit-bundle -w
```

`-w` installs the new floor `nowo-tech/transaction-doctrine` ^1.9. This minor changes no behaviour: it adds build-time
deprecation notices for everything 2.0 changes, so the deprecation log of your application (the profiler's
"Deprecations" tab, `bin/console debug:container --deprecations`, or the test suite with deprecations reported)
becomes the list of what to migrate. The notices are raised once, when the container is built, never per request or
per message. A host with none of the cases below gets no notice.

### `log.map_entities` unset with the log off

In 1.x `log.map_entities` defaults to `true`; in 2.0 it defaults to `log.enabled`. The two differ only when the log is
off (`log.enabled: false`) and DoctrineBundle is installed: in 2.0 the `SentMail` / `SentMailBody` mapping then leaves
your entity manager and your schema tool proposes dropping both tables. In that case, while the option is unset, the
build triggers:

> Since nowo-tech/mail-kit-bundle 1.14: Not setting "nowo_mail_kit.log.map_entities" while "nowo_mail_kit.log.enabled"
> is false is deprecated: it keeps the log entities (SentMail, SentMailBody) mapped in your entity manager. In 2.0 the
> option defaults to "log.enabled" (false here), so they are no longer mapped and your schema tool proposes dropping
> their tables. Set "nowo_mail_kit.log.map_entities: true" to keep the mapping, or "nowo_mail_kit.log.map_entities:
> false" to opt in to the 2.0 behaviour now (see UPGRADING.md "To 1.14.0").

Choose one and set it explicitly; either value silences the notice and keeps working unchanged in 2.0:

```yaml
nowo_mail_kit:
    log:
        enabled: false
        map_entities: true    # keep the tables mapped (the 1.x behaviour)
        # map_entities: false # the 2.0 default: purge or erase the log rows first, then drop the tables
```

With the log on (the default), or without DoctrineBundle, nothing changes in 2.0 and there is no notice.

### Host sender services on `SenderProviderInterface`

`Sender\SenderProviderInterface` (deprecated since 1.12) is removed in 2.0, so a class that still implements it no
longer loads. Until now the deprecation only reached you through the `@deprecated` tag in debug mode. Since 1.14 the
build triggers one notice per host class behind the sender that implements it: the service named in
`nowo_mail_kit.mailer.sender_provider`, and every decorator of the sender (of the
`Nowo\MailKitBundle\Sender\SenderProviderInterface` id or of the configured service):

> Since nowo-tech/mail-kit-bundle 1.14: The sender service "app.sender" (App\Mail\TenantSender) set in
> "nowo_mail_kit.mailer.sender_provider" implements "Nowo\MailKitBundle\Sender\SenderProviderInterface", which is
> deprecated and removed in 2.0. Implement "Nowo\MailKitBundle\Sender\SenderIdentityProviderInterface" instead (it
> returns "Nowo\MailKitBundle\Sender\MailAddress" rather than a Symfony Mime Address; see UPGRADING.md "To 1.12.0").

> Since nowo-tech/mail-kit-bundle 1.14: The service "app.sender_decorator" (App\Mail\SenderDecorator) decorates the
> MailKit sender and implements "Nowo\MailKitBundle\Sender\SenderProviderInterface", which is deprecated and removed
> in 2.0. Implement "Nowo\MailKitBundle\Sender\SenderIdentityProviderInterface" instead (see UPGRADING.md "To
> 1.12.0").

A class that implements both interfaces is reported too: drop the deprecated one once nothing injects it. Move each
class as shown in [To 1.12.0](#sender-identity-without-symfony-mime-senderidentityproviderinterface); the configured
service id stays the same. The built-in `MailSettings` sender is never reported. A service built by a factory cannot
be checked when the container is built; check it yourself. Services of yours that **inject**
`SenderProviderInterface` are not reported either: inject `SenderIdentityProviderInterface` instead (it is always
autowirable).

### No host-flush probe in MailKit

MailKit never flushes your entity manager: the sent-mail log, tracking and Graph inbound stores write through the
DBAL connection, and the settings are written by Instance Settings. There is therefore no host-flush probe and no
flush default to flip in 2.0.

### Preparing for 2.0

2.0 contains only what 1.x has already announced; nothing new breaks:

- **Composer moves.** `nowo-tech/instance-settings-bundle`, `nowo-tech/doctrine-encrypt-bundle` and `doctrine/orm`
  move from `require` to `suggest`. MailKit already runs without them (`mailer.dsn_provider` /
  `mailer.sender_provider` ports, `log.enabled: false`), but a host that got them through MailKit loses them on the
  next `composer update`. This cannot raise a runtime notice. **If your application uses any of them itself (its own
  settings sections, encrypted fields, entities), require them directly now:**

  ```bash
  composer require nowo-tech/instance-settings-bundle nowo-tech/doctrine-encrypt-bundle doctrine/orm
  ```

  A host that keeps the default MailKit setup (the `mailer` settings section, the sent-mail log) needs Instance
  Settings and the ORM in 2.0 as well, so require them too.
- **`SenderProviderInterface` removed** (with its wiring: the gate between the two ports). Clear the notices above.
- **`log.map_entities` follows `log.enabled`.** Clear the notice above.
- `symfony/mime` stays in `require` (the mailer needs it); it only leaves the public port.

1.x keeps receiving security fixes for 6 months after 2.0.0 is released: fixes rated Moderate and above are
backported to the last 1.x minor.

## To 1.13.1

From **1.13.0**:

```bash
composer update nowo-tech/mail-kit-bundle
```

## To 1.13.0

From **1.12.2**:

```bash
composer update nowo-tech/mail-kit-bundle
```

Target **1.13.0** (minor), from **1.12.x**:

```bash
composer update nowo-tech/mail-kit-bundle -w
```

`-w` installs the new requirements, `nowo-tech/transaction-doctrine` ^1.6 and `nowo-tech/transaction-contract` ^1.0.
No schema change and no configuration is needed. Read [CONCURRENCY.md](CONCURRENCY.md) for the full picture.

### Lock conflicts answer 409

A deadlock, lock wait timeout or serialization failure on one of MailKit's routes (admin pages and actions, the Graph
webhook, the tracking pixel) used to be a 500, or was swallowed. It now answers **409** with `X-Conflict-Code`:

- `CONFLICT_001` and `Retry-After` when nothing was committed;
- `CONFLICT_002` and no `Retry-After` when part of the request was.

To keep a Service Unavailable status:

```yaml
nowo_mail_kit:
    conflict_response:
        status: 503
        retry_after: 1
```

Admin routes reach your error page as an `HttpException` with that status and those headers. Host routes are never
touched. Unique violations and lost connections are not conflicts and keep their old behaviour.

### Lock conflicts are no longer swallowed

These places now let a deadlock, lock wait timeout or serialization failure propagate. Other failures behave as
before.

- **Tracking before the e-mail leaves.** `EmailTracker::track()` (the tracking store, your tracking policy, your link
  rewriter) throws the conflict instead of sending the e-mail untracked. Nothing was sent, so your unit of work or
  Messenger can replay the send.
- **Tracking writes inside your transaction.** On MySQL / MariaDB a deadlock rolls back your whole transaction, so you
  must see it.
- **The pixel, and your `MailOpenedEvent` / `MailMachineOpenedEvent` listeners.** A listener's conflict becomes a
  `Nowo\TransactionDoctrine\WriteUnit\ConcurrencyConflict` with `committed: true`, because the open was already
  counted.
- **The test e-mail action.** It answers 409 instead of a "failed" flash message.
- **`GraphInboundStore::release()`.** It throws instead of swallowing every failure. If you call it yourself, catch
  what you need. MailKit's processor logs it.

After the send, the sent-mail log write and the `MailDeliveredEvent` / `MailDeliveryFailedEvent` listeners still only
log a failure: a replay would send the e-mail twice.

### Graph inbound: a failed decision write keeps the claim

When your handler returned a decision but storing it failed, the claim used to be released. Messenger's retry then
called your handler a second time. Now the claim is kept, the failure goes to Messenger, and the retry skips the
message. If you relied on the second call, make your handler idempotent instead (rule 7).

### transaction-doctrine and your entity manager

MailKit's own runner works on its DBAL connection only (`DoctrineTransactionRunner::forConnection()`). It never
receives, flushes, clears or resets your entity manager. If you also adopt transaction-doctrine 1.6's
`DoctrineTransactionRunner::forHostEntityManager()` in your application, it throws a `LogicException` when your
manager has unflushed changes as a run starts: flush or clear them first.

### Request-level CONFLICT_002

To answer `CONFLICT_002` when something was committed earlier in the same request (by your code or another bundle),
register `Nowo\TransactionDoctrine\Symfony\NowoTransactionDoctrineBundle` with its commit detection on. Without it,
the failure alone decides the code.

## To 1.12.2

From **1.12.1**:

```bash
composer update nowo-tech/mail-kit-bundle
```

Target 1.12.2. No action is needed unless you decorate the sender port with a class that implements
`SenderIdentityProviderInterface`. Without a decorator, the compiled container is the same as in 1.12.1.

- **Real mail now uses the new port of a decorator too (D-L1).** When the `SenderProviderInterface` id or the
  `mailer.sender_provider` service is decorated, MailKit's own sending path (the sender policy of real mail and the
  test e-mail) reads the decorated sender through an internal gate (`nowo_mail_kit.sender.identity_gate` or
  `nowo_mail_kit.sender.provider_gate`). The gate asks `defaultFrom()` / `defaultReplyTo()` first. So a decorator
  that implements both interfaces now gives real mail its `defaultFrom()` / `defaultReplyTo()` values, the same ones
  new-port consumers get. In 1.12.1 real mail called its `from()` / `replyTo()`. If your decorator returns the same
  addresses from both pairs of methods (the 1.12.1 advice), nothing changes for you.
- **A decorator that implements only `SenderIdentityProviderInterface` works on the mail path (D-I1).** In 1.12.1 it
  made every send fail with a `TypeError` when it decorated the `SenderProviderInterface` id or a provider that
  implements only the deprecated interface. It is now asked through the gate.
- **Unchanged.** Your own services that inject `SenderProviderInterface` still get the decorator itself. A decorator
  that implements only the deprecated interface is still asked `from()` / `replyTo()`. The circular-reference rule of
  1.12.1 (D-I2) still holds and the gate adds no new cycle: a decorator of the sender (either port id or the
  `mailer.sender_provider` service), whatever interface it implements, takes the decorated service through `.inner`
  (or `#[AutowireDecorated]`) and must not inject `SenderIdentityProviderInterface`.

## To 1.12.1

From **1.12.0**:

```bash
composer update nowo-tech/mail-kit-bundle
```

Target 1.12.1. No action is needed unless you decorate the sender port (see the circular-reference note below):

- **Decorators of the sender provider reach the new port.** When you decorate the `SenderProviderInterface` id or the
  service in `mailer.sender_provider`, services that autowire `SenderIdentityProviderInterface` now get the decorated
  sender — the same one real mail uses — instead of the undecorated provider (or a `TypeError` when the decorator
  implements only the deprecated interface). Where a decorator implements only the deprecated interface, the new port
  is served through an internal gate (`nowo_mail_kit.sender.provider_gate` or `nowo_mail_kit.sender.identity_gate`).
  MailKit's own sending path and its precedence (the new port wins) are unchanged.
- **A refused sender address is logged.** When your `SenderIdentityProviderInterface` provider returns an address the
  mailer refuses, the header is still left out (unchanged), and MailKit now logs a warning with the provider class and
  the field (`from` / `replyTo`). The address itself is never logged.
- **A decorator of `SenderProviderInterface` must not inject `SenderIdentityProviderInterface` (D-I2).** When a
  decorator implements only the deprecated interface, `SenderIdentityProviderInterface` is now served through
  `nowo_mail_kit.sender.identity_gate`, and that gate reads the decorated `SenderProviderInterface`. So a decorator
  that also takes `SenderIdentityProviderInterface` in its constructor depends on itself. It compiled in 1.12.0,
  where the new port pointed straight at the provider. In 1.12.1 the container build fails with a
  `ServiceCircularReferenceException`. To fix it, inject the decorated service through `.inner` (or
  `#[AutowireDecorated]`), and do not inject either sender port in the decorator:

  ```php
  #[AsDecorator(SenderProviderInterface::class)]
  final readonly class AuditedSender implements SenderProviderInterface
  {
      public function __construct(#[AutowireDecorated] private SenderProviderInterface $inner) {}
      // from() / replyTo() delegate to $this->inner
  }
  ```

  In exchange, services that autowire `SenderIdentityProviderInterface` now see the decorated `From` / `Reply-To`.
  These are the same addresses real mail uses.
- **A decorator that implements both interfaces must return the same values from both (D-L1; fixed in 1.12.2, where
  real mail reads the new port of the decorator too).** If every decorator
  implements `SenderIdentityProviderInterface`, the new port points straight at the decorated id. Its consumers
  then call `defaultFrom()` / `defaultReplyTo()`, while MailKit's sending path calls `from()` / `replyTo()` on the
  same decorator. Return the same addresses from `from()` and `defaultFrom()` (and from `replyTo()` and
  `defaultReplyTo()`). For example, build one from the other.

## To 1.12.0

From **1.11.0**:

```bash
composer update nowo-tech/mail-kit-bundle
```

### Sender identity without Symfony Mime (`SenderIdentityProviderInterface`)

`Nowo\MailKitBundle\Sender\SenderProviderInterface` returns the Symfony Mime `Address`, a Symfony **component**
type in a public port (REQ-ARCH-001 rule 3). It is now **deprecated** and gains a framework-free twin:

| Deprecated (removed in 2.0) | Implement instead |
| --- | --- |
| `Sender\SenderProviderInterface::from(): ?Symfony\Component\Mime\Address` | `Sender\SenderIdentityProviderInterface::defaultFrom(): ?Sender\MailAddress` |
| `Sender\SenderProviderInterface::replyTo(): ?Symfony\Component\Mime\Address` | `Sender\SenderIdentityProviderInterface::defaultReplyTo(): ?Sender\MailAddress` |

**Nothing to do now.** The deprecated interface, its signatures and its wiring are unchanged, the built-in
`MailSettings` sender keeps implementing it (it implements both), and `nowo_mail_kit.mailer.sender_provider` takes
either interface. A host service that implements only the deprecated port is still asked through it, exactly as
before.

**When you move a host sender provider over**, return the bundle value object `Sender\MailAddress` and keep the
service id in `nowo_mail_kit.mailer.sender_provider`:

```php
use Nowo\MailKitBundle\Sender\MailAddress;
use Nowo\MailKitBundle\Sender\SenderIdentityProviderInterface;

final readonly class TenantSenderProvider implements SenderIdentityProviderInterface
{
    public function __construct(private TenantContext $tenants)
    {
    }

    public function defaultFrom(): ?MailAddress
    {
        $tenant = $this->tenants->current();

        return $tenant === null ? null : new MailAddress($tenant->mailFrom(), $tenant->name());
        // or MailAddress::fromString('Acme <no-reply@acme.example>')
    }

    public function defaultReplyTo(): ?MailAddress
    {
        return null;
    }
}
```

```yaml
# config/packages/nowo_mail_kit.yaml — unchanged
nowo_mail_kit:
    mailer:
        sender_provider: App\Infrastructure\Mail\TenantSenderProvider
```

MailKit then wraps your service in an internal gate (`nowo_mail_kit.sender.provider_gate`) behind
`SenderProviderInterface`, so the sender policy, the test e-mail (`nowo:mail-kit:send-test` and the admin action) and
any decorator of that id keep working unchanged. `MailAddress` is lenient — it only requires a non-blank address — and
an address the mailer refuses is dropped (the message goes out without that header), the same behaviour as the
settings sender.

Two things to know:

1. **Implement one of the two, not neither.** A `mailer.sender_provider` service that implements neither interface now
   fails the container build with a message naming both, instead of a `TypeError` on the first send.
2. **A class may implement both** (like `MailSettings`); the method names differ, so there is no conflict. The
   **new port wins**: MailKit reads `defaultFrom()` / `defaultReplyTo()` and never calls `from()` / `replyTo()` on
   that class. If your two implementations return different addresses, the `MailAddress` ones are what is sent from
   1.12 on — make them agree, or drop the deprecated methods. Nothing changes for the built-in `MailSettings`: both of
   its ports return the same values (and it keeps today's direct wiring).
3. **Both interfaces can be autowired.** `SenderIdentityProviderInterface` is aliased to the configured provider
   (through the internal gate when that provider implements only the deprecated interface), so your own services can
   type-hint the new port today. `mailer.sender_provider` stays the single source of which provider is used: an alias
   of `SenderIdentityProviderInterface` you register yourself is replaced by MailKit's.

## To 1.11.0

From **1.10.2**:

```bash
composer update nowo-tech/mail-kit-bundle
```

Nothing to configure. New installations get the right collation by themselves; **existing** MySQL / MariaDB
installations need one `ALTER` per table, below.

### What changed and why

`utf8mb4_bin` — the collation the tracking table put on `send_id`, `recipient_id` and `tenant` since 1.10 — is a
*binary* collation, but on MySQL 8 and MariaDB it is also a **PAD SPACE** one: it ignores trailing spaces, so
`'bob' = 'bob   '` is true. REQ-SEC-006 asks for a binary **NO PAD** collation on identifier and key columns, which
1.11.0 now picks per server:

| Server | Collation |
| --- | --- |
| MySQL >= 8.0.17 (8.x, 9.x) | `utf8mb4_0900_bin` |
| MariaDB (all supported) | `utf8mb4_nopad_bin` |
| MySQL 5.7 | `utf8mb4_bin` (fallback; a warning is logged) |
| PostgreSQL, SQLite | none — they compare exactly already |

The Graph inbound tables had **no** collation at all, so their key columns took the database default — on a stock
MySQL 8 or 9 that is `utf8mb4_0900_ai_ci`, on MariaDB 11.4 `utf8mb4_uca1400_ai_ci`; both fold case **and** accents
**and** pad, on three primary keys. They get the same treatment. `client_state_hash` deliberately keeps the table collation: it is never compared in SQL, only
read back and checked with `hash_equals()`.

Columns affected:

| Table (`<prefix>` = `doctrine.table_prefix`) | Columns |
| --- | --- |
| `<prefix>mail_kit_tracking_send` | `send_id`, `recipient_id`, `tenant` |
| `<prefix>mail_kit_graph_subscription` | `subscription_id`, `profile` |
| `<prefix>mail_kit_graph_delta` | `profile` |
| `<prefix>mail_kit_graph_inbound` | `message_key`, `profile`, `status` |

### These tables are DBAL, not ORM

They are bundle-owned tables written through DBAL on the connection configured in `tracking.connection` and
`graph.inbound.connection` (`null` = the default connection). They are **not** entities, so:

- `doctrine:migrations:diff` only proposes these `ALTER`s if that connection's entity manager is the one configured in
  `nowo_mail_kit.doctrine.entity_manager` — the schema listeners add the tables to *that* manager's schema and to no
  other. A host with several managers, or with the tracking / inbound connection pointing somewhere else, will see
  **nothing** in the diff and must run the SQL by hand.
- Run the statements on the connection that actually holds the tables:
  `php bin/console dbal:run-sql --connection=<the one in tracking.connection / graph.inbound.connection> "<statement>"`,
  or `--em=<nowo_mail_kit.doctrine.entity_manager>` for the migration route. With the default configuration both are
  the default connection / `default` entity manager.
- `php bin/console nowo:mail-kit:tracking:schema` and `nowo:mail-kit:graph:schema` only **create** missing tables; they
  never alter an existing one.

### Find the columns that still have the wrong collation

Run on the connection that holds the tables (MySQL / MariaDB only), with `<prefix>` substituted:

```sql
SELECT TABLE_NAME, COLUMN_NAME, COLLATION_NAME
FROM information_schema.COLUMNS
WHERE TABLE_SCHEMA = DATABASE()
  AND TABLE_NAME IN ('mail_kit_tracking_send', 'mail_kit_graph_subscription', 'mail_kit_graph_delta', 'mail_kit_graph_inbound')
  AND COLUMN_NAME IN ('send_id', 'recipient_id', 'tenant', 'subscription_id', 'profile', 'message_key', 'status')
  AND COLLATION_NAME NOT IN ('utf8mb4_0900_bin', 'utf8mb4_nopad_bin')
ORDER BY TABLE_NAME, COLUMN_NAME;
```

Every row it returns needs the `ALTER` below. An empty result means there is nothing to do.

### MySQL 8.0.17 and newer (8.x and 9.x)

```sql
ALTER TABLE mail_kit_tracking_send
  MODIFY send_id      VARCHAR(32)  CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_bin NOT NULL,
  MODIFY recipient_id VARCHAR(128) CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_bin NULL,
  MODIFY tenant       VARCHAR(64)  CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_bin NULL;

ALTER TABLE mail_kit_graph_subscription
  MODIFY subscription_id VARCHAR(128) CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_bin NOT NULL,
  MODIFY profile         VARCHAR(64)  CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_bin NOT NULL;

ALTER TABLE mail_kit_graph_delta
  MODIFY profile VARCHAR(64) CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_bin NOT NULL;

ALTER TABLE mail_kit_graph_inbound
  MODIFY message_key VARCHAR(64) CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_bin NOT NULL,
  MODIFY profile     VARCHAR(64) CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_bin NOT NULL,
  MODIFY status      VARCHAR(16) CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_bin NOT NULL;
```

### MariaDB

The same statements with `utf8mb4_nopad_bin` (MariaDB has no `utf8mb4_0900_bin`):

```sql
ALTER TABLE mail_kit_tracking_send
  MODIFY send_id      VARCHAR(32)  CHARACTER SET utf8mb4 COLLATE utf8mb4_nopad_bin NOT NULL,
  MODIFY recipient_id VARCHAR(128) CHARACTER SET utf8mb4 COLLATE utf8mb4_nopad_bin NULL,
  MODIFY tenant       VARCHAR(64)  CHARACTER SET utf8mb4 COLLATE utf8mb4_nopad_bin NULL;

ALTER TABLE mail_kit_graph_subscription
  MODIFY subscription_id VARCHAR(128) CHARACTER SET utf8mb4 COLLATE utf8mb4_nopad_bin NOT NULL,
  MODIFY profile         VARCHAR(64)  CHARACTER SET utf8mb4 COLLATE utf8mb4_nopad_bin NOT NULL;

ALTER TABLE mail_kit_graph_delta
  MODIFY profile VARCHAR(64) CHARACTER SET utf8mb4 COLLATE utf8mb4_nopad_bin NOT NULL;

ALTER TABLE mail_kit_graph_inbound
  MODIFY message_key VARCHAR(64) CHARACTER SET utf8mb4 COLLATE utf8mb4_nopad_bin NOT NULL,
  MODIFY profile     VARCHAR(64) CHARACTER SET utf8mb4 COLLATE utf8mb4_nopad_bin NOT NULL,
  MODIFY status      VARCHAR(16) CHARACTER SET utf8mb4 COLLATE utf8mb4_nopad_bin NOT NULL;
```

MySQL 5.7 has no binary NO PAD utf8mb4 collation; keep `utf8mb4_bin` there and upgrade the server when you can.
PostgreSQL and SQLite need no change.

### Is the ALTER safe?

Yes, and it is one-way safe by construction. Moving from a PAD SPACE or case/accent-insensitive collation to a binary
NO PAD one can only make the comparison **stricter**: values that used to compare equal may now compare different, and
no pair of values that compared different can start comparing equal. So:

- it can never collapse two rows into one, and never creates a duplicate-key conflict on a column that is a primary key
  or has a unique index — the `ALTER` cannot fail for that reason;
- it never deletes or rewrites data; only the comparison rules of the column change;
- the stored bytes are unchanged (the columns are already `utf8mb4`), so this is a metadata-and-rebuild `ALTER`, not a
  character-set conversion.

It does rebuild the table and its indexes (MySQL runs it `ALGORITHM=COPY`, MariaDB similarly), so schedule it like any
other table rebuild on a large `mail_kit_tracking_send`. The tracking table is append-mostly and purgeable
(`nowo:mail-kit:tracking:purge`); purging first shortens the rebuild.

Practical effect afterwards: a GDPR export or erasure for recipient id `bob` no longer matches a row stored as
`'bob '`, `'Bob'` or `'bób'`. Identifiers written through the bundle were already rejected if they had surrounding white
space, so in practice nothing changes for them; rows written around the bundle (an import, a data migration,
hand-written SQL) are the ones this protects.

## To 1.10.2

From **1.10.1**:

```bash
composer update nowo-tech/mail-kit-bundle
```

### To 1.10.2

From **1.10.1**: `composer update nowo-tech/mail-kit-bundle`. No change is needed. `public_base_url: '%env(DEFAULT_URI)%'`
now builds (1.10.1 failed `cache:clear`). A compiler pass that still sets `EmailTracker` argument 7 to the full pixel
base (base + `/e/o`) keeps working; it can be dropped in favour of the config value.

## To 1.10.1

From **1.10.0**:

```bash
composer update nowo-tech/mail-kit-bundle
```

`nowo_mail_kit.tracking.public_base_url` accepts `'%env(DEFAULT_URI)%'`. Hosts can drop any custom compiler pass whose only
job was to set that value after the build; set it through config instead:

```yaml
nowo_mail_kit:
    tracking:
        public_base_url: '%env(DEFAULT_URI)%'
```

An env value is checked when the tracker first uses it; an invalid one (`javascript:x`, `http://user@host`, a query or
fragment) disables tracking for every e-mail (critical log) instead of emitting a bad pixel or link. No other change.

## To 1.10.0

From **1.9.1**:

```bash
composer update nowo-tech/mail-kit-bundle
```

Next minor: 1.10.0. **No change is needed**: e-mail open and click tracking is opt-in (`nowo_mail_kit.tracking.enabled`,
default `false`); with it off no service, route, table, command or handler is added and every e-mail goes out exactly
as in 1.9.1. Two small, compatible changes apply to everyone:

- `MailHeaders::strip()` also removes the new internal headers `X-Mail-Kit-Template`, `X-Mail-Kit-Recipient`,
  `X-Mail-Kit-Tenant` and `X-Mail-Kit-No-Track` before delivery (if your application sent headers with these exact
  names to recipients, rename them).
- `MailDeliveredEvent` and `RuntimeTransport` get an optional trailing constructor argument (`$trackingId`,
  `$tracker`); code that builds them positionally keeps working.

To track opens and clicks ([USAGE](USAGE.md#open-and-click-tracking), privacy notes in [SECURITY](SECURITY.md#e-mail-open-and-click-tracking-110)):

1. Implement `Tracking\EmailTrackingPolicyInterface` (never the security templates, never an opted-out recipient;
   return the recipient id and tenant to store) and, for clicks, `Tracking\EmailLinkRewriterInterface` (e.g. short
   links of kind `email`; an `essential` link must always redirect).
2. Tag your e-mails: `MailHeaders::template($email, 'issue_alert')`, `MailHeaders::trackingRecipient($email, $userId, $tenant)`,
   and `MailHeaders::noTracking($email)` on security e-mails. Keep `mailer.decorate: true` (the default; required).
3. Enable it:

   ```yaml
   nowo_mail_kit:
       tracking:
           enabled: true
           policy: App\Mail\MailTrackingPolicy
           link_rewriter: App\Mail\ShortLinkEmailRewriter
           public_base_url: '%env(APP_PUBLIC_URL)%'
           secret: '%env(MAIL_KIT_TRACKING_SECRET)%'   # ≥ 32 bytes; null = derived from kernel.secret
           links:
               allowed_hosts: [app.example.com]
   ```

4. Create the table: `doctrine:migrations:diff` (ORM hosts) or `nowo:mail-kit:tracking:schema` (prints the SQL).
5. Import the pixel route (`type: nowo_mail_kit_tracking`) and exclude its prefix from sessions
   (`firewalls: { mail_kit_pixel: { pattern: ^/e/o/, security: false } }`).
6. Schedule `nowo:mail-kit:tracking:purge` daily; with `personal_data.enabled` the UserKit `email_tracking` eraser and
   export section are registered (set `tracking.recipient_resolver` when your recipient ids are not `getId()`).
7. Update your privacy policy and processing register (what is stored: send id, template, recipient id, tenant, open
   counters, truncated IP and user-agent family of the last open; default retention 90 days).

## To 1.9.1

From **1.9.0** (patch, no configuration change):

```bash
composer update nowo-tech/mail-kit-bundle
```

`RuntimeTransport` no longer removes MailKit's internal headers (`X-Mail-Kit-Log-Id`, `-Tag`, `-Redact`) from **your**
message object, only from the copy it delivers, so a Messenger retry keeps its sent-mail log row, its tag and its
redaction values. If you inspected the message after `send()` and relied on those headers being gone, read the
delivered message from the `SentMessage` instead.

**If you ran with `log.store_bodies: true` and asynchronous mail with retries** (1.0.0 – 1.9.0), a retried message
that used `MailHeaders::redact()` may have a stored body with the one-time value in clear. The affected rows are the
extra rows written by a retry: no tag, `attempts = 1`, and a twin row (same subject and recipients, status `failed`)
written just before. Run `nowo:mail-kit:log:purge` with a short `--older-than-days`, or delete the bodies of those rows
(`<prefix>mail_kit_sent_mail_body`), and treat any link or code still valid in them as exposed to log readers.

## To 1.9.0

From **1.8.0**:

```bash
composer update nowo-tech/mail-kit-bundle
```

Next minor: 1.9.0. **No change is needed**: the Graph inbound pipeline is opt-in (`nowo_mail_kit.graph.inbound.enabled`,
default `false`); with it off no route, table, handler or command is added and no new package is required.
`graph.inbound` needs `symfony/messenger`, `symfony/html-sanitizer` and the PHP `fileinfo` extension (suggested; the
container refuses to compile without them). New sender option `graph.draft_cleanup` (`bus`, `delay_ms`): after an
ambiguous send failure a delayed `DeleteGraphDraft` message deletes the draft only if it is still a draft (route it
to a transport that supports delays); without a bus the draft state is read once more at once.

The default `graph.inbound.limits.allowed_types` does **not** include `application/zip` (owner decision 2026-11-12):
add it explicitly if your users must send archives (their content is not scanned).

To read the shared mailbox ([USAGE](USAGE.md#inbound-mail-from-a-graph-mailbox)):

1. Require `symfony/messenger` and `symfony/html-sanitizer` (and the `fileinfo` extension), and route `Nowo\MailKitBundle\Graph\Inbound\Message\FetchGraphMessage`,
   `SweepGraphMailbox` and `RenewGraphSubscriptions` to an async transport.
2. Implement `GraphInboundHandlerInterface` (route / quarantine / discard; copy attachment streams inside the call).
3. Enable it:

   ```yaml
   nowo_mail_kit:
       graph:
           inbound:
               enabled: true
               handler: App\Mail\TaskMailInbound
               webhook:
                   notification_url: 'https://app.example.com/hooks/mail-kit/v1/graph'
   ```

4. Create the tables: `doctrine:migrations:diff` (ORM hosts) or `nowo:mail-kit:graph:schema` (prints the SQL).
5. Import the webhook route (`type: nowo_mail_kit_graph_webhook`) and make its path public and stateless in the
   firewall (`access_control: { path: ^/hooks/mail-kit/, roles: PUBLIC_ACCESS }`).
6. Schedule `nowo:mail-kit:graph:subscriptions` (hourly) and `nowo:mail-kit:graph:sweep` (every 5 minutes), or the
   `RenewGraphSubscriptions` / `SweepGraphMailbox` messages with Symfony Scheduler.

## To 1.8.0

From **1.7.1**:

```bash
composer update nowo-tech/mail-kit-bundle
```

Next minor: 1.8.0. **No change is needed**: Microsoft Graph sending is opt-in (`nowo_mail_kit.graph.enabled`, default
`false`); with it off no new service, route, table or listener is registered and every existing send, setting and
page behaves as in 1.7.1. No database migration. New runtime requirement `psr/cache` (`^2.0 || ^3.0`), already
installed with the framework bundle.

To send from a Microsoft 365 shared mailbox:

1. Do the Microsoft 365 steps once ([MICROSOFT-GRAPH-SETUP.md](MICROSOFT-GRAPH-SETUP.md)): app registration with the
   **application** permissions `Mail.Send` and `Mail.ReadWrite` (admin consent), a client secret, and an
   application access policy (`New-ApplicationAccessPolicy`) or RBAC for Applications that limits the app to the
   mailbox.
2. Enable Graph with a profile (secrets from the environment) or point `graph.mailbox_provider` at your own
   `GraphMailboxProviderInterface` service (credentials from your encrypted settings):

   ```yaml
   nowo_mail_kit:
       graph:
           enabled: true
           profiles:
               default:
                   tenant_id: '%env(MAIL_KIT_GRAPH_TENANT_ID)%'
                   client_id: '%env(MAIL_KIT_GRAPH_CLIENT_ID)%'
                   client_secret: '%env(MAIL_KIT_GRAPH_CLIENT_SECRET)%'
                   mailbox: '%env(MAIL_KIT_GRAPH_MAILBOX)%'
   ```

3. Send with `GraphMailSender::send()` (returns the Graph id and internetMessageId) or through a
   `microsoft+graph://default` mailer transport ([USAGE](USAGE.md#microsoft-graph-shared-mailbox)).
4. With HttpLog, add `outlook.office.com` and `outlook.office365.com` (attachment upload sessions) to the hosts of
   your `microsoft_graph` outbound provider next to `graph.microsoft.com` and `login.microsoftonline.com`, with body
   capture off.
5. Tokens are cached in `cache.app` by default; on a shared or persistent cache, point `graph.token_cache` at a pool
   with an encrypting marshaller.

`microsoft+graph` is not added to `dsn.allowed_schemes`: the runtime DSN from the settings cannot select Graph (its
credentials never live in a DSN).

## To 1.7.1

From **1.7.0**:

```bash
composer update nowo-tech/mail-kit-bundle
```

Next patch: 1.7.1. No configuration change.

- With `resend.enabled: true`, the memo of the MailKit 1.2 `resend` rows is now also forgotten at the start of each
  main request, at `kernel.terminate`, at every Messenger message boundary and when a console command starts and
  terminates, so a worker picks up a migration (`nowo:instance-settings:seed-from-fallbacks --section=mailer`), a purge
  or an edit made by another worker on its next unit of work. One extra read of the `resend` rows per unit of work
  while they are still stored; none once they are gone.
- Hosts that disable or bypass the services resetter must also clear their own entity managers at every boundary
  (see `docs/FRANKENPHP-WORKER-AUDIT.md`, "Host rule").

## To 1.7.0

From **1.6.0**:

```bash
composer update nowo-tech/mail-kit-bundle
```

Target **1.7.0** (next minor), from **1.6.0**: `composer update nowo-tech/mail-kit-bundle`. **Nothing to change**,
but read item 4: `forgetAddress()` keeps shared rows for the other recipients now. With UserKit installed, MailKit
1.7 needs UserKit >= 1.12 (`conflict`).

1. **GDPR ports.** `Log\SentMailPersonalDataExporterInterface` (new) exports the log rows of one address;
   `Log\SentMailPersonalDataEraserInterface` (new) erases them for a `SentMailPersonalDataSubject` and returns a
   `SentMailErasureResult` (`forgetAddress()` stays). If
   your application reads `mail_kit_sent_mail` itself for a GDPR export (e.g. with `SentMailRecorder::forms()` /
   `likeEscape()`), call the port instead. See [USAGE — GDPR erasure and export](USAGE.md#gdpr-erasure-and-export).
2. **UserKit bridge (opt-in).** Nothing registers by default (`nowo_mail_kit.personal_data.enabled: false`). To let
   UserKit erase and export the log, set it to `true` (UserKit >= 1.12, log entities mapped): the eraser and the
   export section `emails_received` register. **Before you turn it on, delete your own `emails_received` export
   provider** for these tables: UserKit's `PersonalDataExporter` throws a `LogicException` ("Duplicate personal data
   export section") on two sections with the same key, which makes the whole GDPR export fail. Two erasers with the
   same key both run (the second finds nothing), but remove yours too. Keep the bridge off if you must retain the
   log (GDPR art. 17(3)).
3. **Exact erasure.** `forgetAddress()` re-checks every row in PHP: on MySQL / MariaDB with an accent-insensitive
   collation an address no longer erases its accent variants (`jose@` vs `josé@`). Case variants are still one
   address (the log stores addresses in lower case). It now runs in its own transaction, or joins yours, and
   dispatches `SentMailPersonalDataErasedEvent` after its own commit. `redacted` counts only rows that really
   changed, and masking replaces whole addresses only (`na@` no longer masks part of `ana@`).
4. **Behaviour change: shared rows are unlinked, not deleted.** In 1.6 `forgetAddress()` deleted every row the address
   received or sent, also when the message went to other people. In 1.7 a row that still has another recipient is
   kept: the address is removed from `recipients` and `recipient_index`, a matching sender becomes `[redacted]`, and
   the address is masked in the subject and the stored body. Only a row with no other recipient left is deleted. The
   result has a new key, `['deleted' => n, 'unlinked' => n, 'redacted' => n]` (also on
   `SentMailPersonalDataErasedEvent::$unlinked` and `SentMailErasureResult::$unlinked`); `forgetRecipient()` returns
   deleted + unlinked rows. If your code compares the whole array, add `unlinked`. If the site's `default_from` is a
   person's own mailbox, erasing that person no longer wipes the log of what was sent to everybody else.

## To 1.6.0

Target **1.6.0**, from **1.5.0**: `composer update nowo-tech/mail-kit-bundle`. **Standard hosts: nothing to change**
(Instance Settings registered → same services, same defaults).

1. **Without Instance Settings (opt-in, DDD hosts).** Set both `nowo_mail_kit.mailer.dsn_provider` and
   `nowo_mail_kit.mailer.sender_provider` to your own services and leave `NowoInstanceSettingsBundle` out of
   `config/bundles.php`. Keep `resend.enabled: false`. With `log.enabled: false` you can leave DoctrineBundle and
   DoctrineEncrypt out too. The settings actions disappear (there is no settings page); `nowo:mail-kit:send-test`
   uses your sender port.
2. **Log off: schema unchanged, erasure real.** With `log.enabled: false` the log entities stay mapped (no schema
   diff against 1.5.0) and `SentMailErasureInterface` / `nowo:mail-kit:log:forget` / `log:purge` still delete the
   rows written while the log was on. To keep the mapping out of an XML-only entity manager, set
   `log.map_entities: false` (only with the log off) **after** purging or erasing what those tables hold: the next
   schema diff proposes dropping them. Deprecation notice: in 2.0 `log.map_entities` defaults to `log.enabled`.
3. **Test e-mail sender.** With a host `mailer.sender_provider`, `nowo:mail-kit:send-test` and the admin test action
   now use your port (the sender real mail uses) instead of the `mailer` settings.
4. **New ports (optional).** Prefer `OutboundMailReadinessInterface` over `OutboundMailReadiness`, and
   `SentMailErasureInterface` over an optional `SentMailRecorder` for erasure / retention.
5. **Deprecation notice.** Instance Settings, DoctrineEncrypt and the ORM stay in `require` in 1.x; 2.0 moves them to
   `suggest`. If your application uses Instance Settings itself, require `nowo-tech/instance-settings-bundle`
   explicitly now instead of relying on MailKit to install it.

## To 1.5.0

From **1.4.0**:

```bash
composer update nowo-tech/mail-kit-bundle
```

Nothing to change by default.

1. **Sender port (optional).** To take the default `From` / `Reply-To` from your own context instead of the
   Instance Settings `mailer` section, implement `Nowo\MailKitBundle\Sender\SenderProviderInterface` and set
   `nowo_mail_kit.mailer.sender_provider: <service id>`. Messages that set their own `From` / `Reply-To` keep them.
   Code that builds `SenderPolicy` by hand with a `MailSettings` keeps working.
2. **Delivery events (new).** `MailDeliveredEvent` / `MailDeliveryFailedEvent` are dispatched on every send through
   the runtime transport. Listening is optional; a throwing listener is logged at `error`, not rethrown.
3. Instance Settings stays required (`^1.3.1`); this release does not need a newer one.

## To 1.4.0

From **1.3.0**:

```bash
composer update nowo-tech/mail-kit-bundle
```

Nothing to do: the branded e-mail layout (spec 220 S7) is opt-in.

- **New, optional:** `@NowoMailKit/email/branded_layout.html.twig` / `.txt.twig`, `Nowo\MailKitBundle\Brand\BrandedEmailHelper`
  (`apply()`, `embed()`) and the `nowo_mail_kit.brand` configuration ([USAGE — Branded e-mails](USAGE.md#branded-e-mails)).
  E-mails that do not extend the layout or go through the helper are unchanged.
- **Brand source:** `composer require nowo-tech/brand-contracts:^1.0` and a `BrandProviderInterface` service (for
  example InstanceSettings ^1.5 with `nowo_instance_settings.brand.enabled: true`, which also aliases
  `BrandAssetReaderInterface`). `brand-contracts` stays optional (`suggest`; versions outside 1.x conflict). Without it,
  or with `nowo_mail_kit.brand: off`, the layout prints `brand.name` / `brand.colors`.
- **Images are CID parts**, PNG / JPEG / WebP only: an SVG logo (also a committed default asset) is not sent. Give
  e-mails a raster `logo_light` or `mark`. Hosts that embedded their logo by path (Beacon's `EmailTemplateRenderer`
  LOGO / MASCOT paths) can switch to `embed($email, 'mark')` / `embed($email, 'extra.email_mascot')`.
- **Messenger:** call `apply()` where the e-mail is built; the brand view and image parts are serialised with the
  message, so each queued e-mail carries up to `brand.max_total_bytes` (1 MiB by default, 5 MiB at most) in the
  transport (for example a Doctrine `messenger_messages` row). With a size-limited transport keep the defaults or lower
  them, or call `apply()` in your own handler before sending (audit IW-I11).

## To 1.3.0

From **1.2.0**:

```bash
composer update nowo-tech/mail-kit-bundle
```

### To 1.3.0

From **1.2.x**. The bundle now requires `nowo-tech/instance-settings-bundle` ^1.3.1 and `nowo-tech/ui-kit-bundle`
^2.23:

```bash
composer update nowo-tech/mail-kit-bundle nowo-tech/instance-settings-bundle nowo-tech/ui-kit-bundle --with-dependencies
```

**Only installs with `resend.enabled: true` are affected.** Without it the `mailer` page, its keys and the behaviour
are unchanged.

> **Security — access to the Resend settings changes.** The Resend API key, sender and the "Check the API key" /
> "Send a test e-mail with Resend" actions are now governed by the Instance Settings access of the **`mailer`**
> section. A `nowo_instance_settings.security.area_roles.resend` entry would no longer protect anything (with
> `mailer: [ROLE_ADMIN]`, `resend: [ROLE_SUPER_ADMIN]`, any `ROLE_ADMIN` could replace the Resend key), so with
> `resend.enabled: true` the container build **fails** while that entry exists. Merge its roles into
> `area_roles.mailer` (whoever may change the Resend key may now change the DSN too, and the other way round) or
> remove it:
>
> ```yaml
> nowo_instance_settings:
>     security:
>         area_roles:
>             mailer: [ROLE_SUPER_ADMIN]   # was mailer: [ROLE_ADMIN] + resend: [ROLE_SUPER_ADMIN]
> ```

What changed with `resend.enabled: true`:

- The `resend` settings section is gone. Its fields moved into the `mailer` section, which gets an explicit provider
  choice and shows only the selected provider's rows (InstanceSettings 1.3 `visible_when`):

  | 1.2 key | 1.3 key |
  | --- | --- |
  | `resend.enabled` (bool) | `mailer.provider` (`resend` when it was on, else `dsn`) |
  | `resend.api_key` (secret) | `mailer.resend_api_key` (secret) |
  | `resend.from` | `mailer.resend_from` |

  `mailer.dsn`, `mailer.from`, `mailer.from_name`, `mailer.reply_to`, `mailer.test_recipient` keep their keys.
- `bootstrap.resend_api_key` now seeds `mailer.resend_api_key`.
- "Check the API key" / "Send a test e-mail with Resend" are on the mailer page; their routes, names and CSRF token ids
  are unchanged. They now require the Instance Settings access of the `mailer` section (was `resend`).
- Saving the mailer page with `provider = resend` checks the key that would go live against the Resend API and refuses
  the save when Resend rejects it (audit MK-L1). The check makes one outbound HTTPS call per such save and shares
  `test_email.rate_limit`.
- `MailSettings::missingFields()` reports the selected provider only (`mailer.dsn` or `mailer.resend_api_key`); the
  1.2 names `resend.api_key` are no longer reported.

**Migrate the stored rows** (once, after deploying 1.3):

```bash
php bin/console nowo:instance-settings:seed-from-fallbacks --section=mailer --dry-run
php bin/console nowo:instance-settings:seed-from-fallbacks --section=mailer --strict
```

The mailer section exposes the old `resend` rows as its fallbacks, so the existing InstanceSettings seeder copies them
(insert-only: a value already stored in the mailer section wins; field names only in the output; also callable from a
non-transactional Doctrine migration, as in [To 1.2.0](#to-120)). Until then nothing breaks: with no
`mailer.provider` stored, `MailSettings` reads the old rows (Resend stays live when it was) and the mailer page shows
what is live — Resend pre-selected when `resend.enabled` was on, the old key shown as stored, the old sender in the
Resend sender — plus a notice. Saving the page unchanged migrates too: it stores `mailer.provider = resend`, the key
and the sender, without a new Resend check (the old key is the live one).

After the migration the old `resend` rows are no longer read, but they stay in the Instance Settings table (the
encrypted 1.2 key included). Delete them with the opt-in purge:

```bash
php bin/console nowo:mail-kit:purge-legacy-resend --dry-run
php bin/console nowo:mail-kit:purge-legacy-resend
```

It deletes only the `resend` section rows, only once `mailer.provider` is stored and the old key / sender equal
`mailer.resend_api_key` / `mailer.resend_from`; otherwise it lists the rows that were not copied and deletes nothing
(exit 1). `--force` deletes anyway. Idempotent. (A purge option cannot live on the InstanceSettings seeder, which knows
nothing about MailKit's old section, hence the MailKit command.)

Host code:

- `MailSettings` keeps every public method (`dsn()`, `resendActive()`, `resendApiKey()`, `resendDsn()`, `from()`,
  `resendFrom()`, `mailerFrom()`, `replyTo()`, `testRecipient()`, `missingFields()`), now backed by the new keys;
  `provider()` and `legacyResendPending()` are new. Its constructor got an optional trailing argument.
- Code reading `resend.*` keys through `InstanceSettingsInterface` must switch to the `mailer.*` keys above (reading an
  unregistered section throws `UnknownSettingException`).
- `ResendSettingsSection` is now a deprecated holder of the `NAME` constant (`'resend'`), no longer a section service.
- Instance Settings `security.area_roles.resend` is refused at build time with `resend.enabled` (see the security note
  above); the `mailer` roles cover Resend.
- An application section of its own named `resend` is never read, migrated or purged as MailKit 1.2 rows (the purge
  command refuses).
- Scripts calling `seed-from-fallbacks --section=resend` must drop that option (unknown section).
- Register the UiKit `visible-when` Stimulus peer so the provider rows hide in the browser (InstanceSettings 1.3
  [UPGRADING](https://github.com/nowo-tech/InstanceSettingsBundle/blob/main/docs/UPGRADING.md)); without it every row
  stays visible and the server rules still apply.

## To 1.2.0

From **1.1.1**:

```bash
composer update nowo-tech/mail-kit-bundle
```

### To 1.2.0

From **1.1.x**. The bundle now requires `nowo-tech/instance-settings-bundle` ^1.2:

```bash
composer update nowo-tech/mail-kit-bundle nowo-tech/instance-settings-bundle --with-dependencies
```

No BC break; new, optional:

- `bootstrap.mailer_dsn` / `bootstrap.resend_api_key` (default null): one-time bootstrap values for
  `nowo:instance-settings:seed-from-fallbacks` (DB-only mode).
- Settings page warnings and `MailStatus::$missingFields` / `OutboundMailReadiness::missingFields()` for health pages.
  `MailStatus` and `OutboundMailReadiness` got an optional trailing constructor argument (named arguments unaffected).

To go DB-only, **in this order**: (1) upgrade and map `bootstrap.mailer_dsn: '%env(default::MAILER_DSN)%'` (and
`bootstrap.resend_api_key`) while the env vars still exist; (2) run
`php bin/console nowo:instance-settings:seed-from-fallbacks --section=mailer --section=resend` (1.2; since 1.3 `--section=mailer` only) (try `--dry-run`
first); (3) only then remove the `bootstrap` keys and the env vars, and set `framework.mailer.dsn` to a literal such as
`null://null`. Removing them first would seed nothing. From a Doctrine migration, call the seeder from a
non-transactional migration (see InstanceSettings
[USAGE](https://github.com/nowo-tech/InstanceSettingsBundle/blob/main/docs/USAGE.md#calling-the-seeder-from-a-migration)).

## To 1.1.1

From **1.1.0**:

```bash
composer update nowo-tech/mail-kit-bundle
```

- `nowo-tech/controller-kit-bundle` is now required at `^2.3.1` (`composer update nowo-tech/controller-kit-bundle`).
  Exported cells with a formula trigger after an embedded `;`, `,`, tab, `|` or line break now read `Hi; =x` instead
  of `Hi;=x`, and the response sends `X-Content-Type-Options: nosniff`.
- **Notable behaviour change:** `web_ui.export.max_rows` accepts at most **100 000** (was 1 000 000). A configuration
  above it no longer compiles; lower it, or narrow the filters of the export.
- New optional `web_ui.export.body_batch_bytes` (default 8 MiB, 1 MiB … 64 MiB): approximate body bytes loaded per
  query by the export with bodies. Nothing to change unless you want a different memory bound.

## To 1.1.0

From **1.0.0**:

```bash
composer update nowo-tech/mail-kit-bundle
```

No code change is required. Review two defaults:

- The log list now offers **"Export CSV"** to whoever can open it (`web_ui.export.enabled: true`). To keep it for a
  narrower role, set `web_ui.export.access_roles: [ROLE_…]`; to remove it, `web_ui.export.enabled: false`. Bodies are
  never exported unless you also set `web_ui.export.include_bodies: true` (needs `log.store_bodies`).
- New requirements: `nowo-tech/ui-kit-bundle` `^2.20` and `nowo-tech/controller-kit-bundle` `^2.3` (only its
  `Http\CsvStreamedResponse` / `CsvSafeWriter` classes are used; registering `NowoControllerKitBundle` is not needed).
- A template override of `log/index.html.twig` keeps working; the table headers now sort (UiKit `sorting`) and the
  filter form carries two hidden fields (`sort`, `dir`), rendered by `form_end` if your override does not.

## To 1.0.0 (initial release)

### Requirements

- PHP `>=8.2 <8.6`, Symfony 7.4 / 8.0 / 8.1, Doctrine ORM 3.3+, `symfony/mailer` with `framework.mailer` configured.
- `nowo-tech/instance-settings-bundle` ^1.0 registered (and DoctrineEncrypt configured for its secrets): the container
  refuses to compile without it.

### Enable and configure

1. `composer require nowo-tech/mail-kit-bundle` (Flex registers the bundle and copies the recipe).
2. Import the routes (opt-in) — `nowo_mail_kit` and `nowo_instance_settings` loaders.
3. Generate a migration for `mail_kit_sent_mail` / `mail_kit_sent_mail_body` (with your `doctrine.table_prefix`).
4. Add `access_control` rules for `/admin/mail` and `/admin/settings`.
5. Schedule `nowo:mail-kit:log:purge`.

### Migrating from an in-app mailer (Beacon-style)

Apps that kept the mailer DSN, sender and Resend key in their own settings entity and wrapped the mailer themselves:

1. **Move the values** into the Instance Settings rows (once, in a migration or a command):
   `$settings->save('mailer', ['dsn' => $old->getMailerDsn(), 'from' => $old->getMailerFrom()])` and, with
   `resend.enabled: true`, `$settings->save('resend', ['enabled' => $old->isResendEnabled(), 'api_key' => $old->getResendApiKey(), 'from' => $old->getResendFrom()])`.
   Then drop the old columns.
2. **Delete the wrappers**: the configured `MailerInterface` alias, the transport used by
   `mailer.messenger.message_handler`, the DSN validator / audit / sender helpers, the Resend checker, the settings
   controllers, forms and templates — MailKit's decorators cover both the sync and the Messenger path.
3. **Callers** of `sendNow()` / `sendSample()` use `Nowo\MailKitBundle\Mailer\RuntimeTransport::send()` (synchronous) or
   `TestMailSender`; `getFromAddress()` → `MailSettings::from()`; `isMagicLoginAvailable()` / `canDeliver()` →
   `OutboundMailReadiness::isOutboundMailReady()`; `MailerDsnAudit::redact()` keeps its shape (`scheme`, `host`, plus
   `port`).
4. **Log**: the old e-mail log table can be dropped or migrated into `mail_kit_sent_mail` (same columns, `template` →
   `tag`); the tag header is `X-Mail-Kit-Tag` (`MailHeaders::tag()`), the redaction header `X-Mail-Kit-Redact`
   (`MailHeaders::redact()`). Bodies are no longer stored unless `log.store_bodies: true`.
5. **Test environment**: MailKit does not swap SMTP for `null://` in `APP_ENV=test`; store no DSN in test databases (the
   framework test DSN is used) or set `mailer.decorate: false` in `when@test`.

### Template contract

First release: pages `log/index.html.twig` (list scaffold, block `nowo_ui_list_row`) and `log/show.html.twig` (show
scaffold, blocks `nowo_ui_show_after` / `nowo_mail_kit_text_body`), view models from `Ui\AdminPages`. Later changes to
blocks, view-model fields or theme slots will be listed here under "Template contract".

### Breaking changes

None (initial release).
