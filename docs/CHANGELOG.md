# Changelog

All notable changes to this project are documented here. The format follows
[Keep a Changelog](https://keepachangelog.com/en/1.1.0/) and the project uses [Semantic Versioning](https://semver.org/).

## Table of contents

- [[Unreleased]](#unreleased)
- [[2.9.0] - 2026-11-28](#290---2026-11-28)
- [[2.8.0] - 2026-11-27](#280---2026-11-27)
- [[2.7.0] - 2026-11-27](#270---2026-11-27)
- [[2.6.0] - 2026-11-27](#260---2026-11-27)
- [[2.5.0] - 2026-11-27](#250---2026-11-27)
- [[2.2.2] - 2026-11-24](#222---2026-11-24)
- [[2.2.1] - 2026-11-23](#221---2026-11-23)
- [[2.2.0] - 2026-11-23](#220---2026-11-23)
- [[2.1.0] - 2026-11-22](#210---2026-11-22)
- [[2.0.0] - 2026-11-21](#200---2026-11-21)
- [[1.14.1] - 2026-11-20](#1141---2026-11-20)
- [[1.14.0] - 2026-11-20](#1140---2026-11-20)
- [[1.13.1] - 2026-11-19](#1131---2026-11-19)
- [[1.13.0] - 2026-11-19](#1130---2026-11-19)
- [[1.12.2] - 2026-11-14](#1122---2026-11-14)
- [[1.12.1] - 2026-11-14](#1121---2026-11-14)
- [[1.12.0] - 2026-11-14](#1120---2026-11-14)
- [[1.11.0] - 2026-11-13](#1110---2026-11-13)
- [[1.10.2] - 2026-11-12](#1102---2026-11-12)
- [[1.10.1] - 2026-11-12](#1101---2026-11-12)
- [[1.10.0] - 2026-11-12](#1100---2026-11-12)
- [[1.9.1] - 2026-11-12](#191---2026-11-12)
- [[1.9.0] - 2026-11-12](#190---2026-11-12)
- [[1.8.0] - 2026-11-12](#180---2026-11-12)
- [[1.7.1] - 2026-11-11](#171---2026-11-11)
- [[1.7.0] - 2026-11-11](#170---2026-11-11)
- [[1.6.0] - 2026-11-11](#160---2026-11-11)
- [[1.5.0] - 2026-11-10](#150---2026-11-10)
- [[1.4.0] - 2026-11-09](#140---2026-11-09)
- [[1.3.0] - 2026-11-02](#130---2026-11-02)
- [[1.2.0] - 2026-11-02](#120---2026-11-02)
- [[1.1.1] - 2026-11-01](#111---2026-11-01)
- [[1.1.0] - 2026-11-01](#110---2026-11-01)
- [[1.0.0] - 2026-10-31](#100---2026-10-31)

## [Unreleased]

### Fixed

- `RuntimeTransport::close()`: Symfony 8.2 calls `close()` on the transports service at `kernel.reset`, and the tag moves to this decorator, so the call failed with an undefined method. It now closes the framework transport and the cached runtime one when they can be closed.

### Changed

- PHP 8.6: `composer.json` allows PHP below 8.7 (was below 8.6). CI adds a forward-compatibility workflow with a
  PHP 8.6 cell and a Symfony 8.2 cell (stable Symfony 8.2.0).

## [2.9.0] - 2026-11-28

Planned as **2.9.0**: fixes to the GDPR erasure of the sent-mail log, and a more complete personal-data export (pre-tag
audit of 2.8.0). No schema change, no configuration change. Two contract changes (the exception of a lock conflict in
the erasure's own transaction, and three new keys in the export rows): see [UPGRADING](UPGRADING.md#to-290).

### Changed

- **A lock conflict in the erasure's own transaction is thrown as `TransientStorageFailure`.** When no transaction is
  open on the log connection, `forgetAddress()`, `erase()` and `eraseTagged()` now run as one
  `nowo-tech/transaction-doctrine` unit. A deadlock or a lock wait timeout rolls it back and is thrown as
  `Nowo\TransactionContract\TransientStorageFailure` (the database error is its previous; nothing committed, a retry
  is safe) instead of the DBAL `RetryableException` subclasses. Inside the caller's transaction the database error
  reaches the caller, as before. The three eraser interfaces document it with `@throws`.
- **The personal-data export also returns the error text and the stored body.** `export()` / `exportTagged()` and the
  UserKit `emails_received` section return three more keys per row: `error`, `html_body` and `text_body` (null when
  there is none; bodies exist only when `log.store_bodies` kept one). Bodies are exported only for rows the address
  received (to, cc, bcc), never for rows where it is only the sender. The addresses of the row's other recipients and
  sender are masked in the error and the body, and the other recipients are still never exported. Released versions
  (>= 1.7.0) left the error and the body out of the export.

### Fixed

- **Concurrent erasures could restore an erased address** (affected releases >= 1.0.0 for the masking of subjects,
  errors and stored bodies; >= 1.7.0 for the unlink of shared rows). The erasure read a row and wrote it back without
  a lock. When two erasures touched the same row at the same time (Ana and Bob, both recipients of one message, or
  both named in one subject), the second could write back the recipient list, subject, error or stored body it had
  read before the first one committed, and put the first person's address back. Every row is now read again under a
  row lock (`SELECT … FOR UPDATE` on MySQL / MariaDB and PostgreSQL, by id, in id order within each statement) inside
  the erasure's transaction before it is written back; the second erasure waits for the first and works on its
  result. Two erasures can still deadlock across their steps: one is rolled back and can be run again (see
  [CONCURRENCY — Residual risks](CONCURRENCY.md#residual-risks)). Race tests on MySQL 8.4, MariaDB 11.4 and
  PostgreSQL 16 / 17.
- **The outcome of a queued message could re-create an erased row** (affected releases >= 1.0.0). When an erasure
  (or the retention purge) removed the `queued` row of a message still waiting in Messenger or in a retry, the later
  `sent()` / `failed()` found no row and inserted a new full one, with the erased address, subject, tag and stored
  body. A known row id whose row is gone is now treated as removed: nothing is written back, and an info line without
  the address is logged. A message that was never queued (no id) is still recorded as before.

### Notes

- No security advisory: the fixed issues are contention and timing windows of the erasure, recorded in this
  changelog.
- The tag-scoped ports of 2.8.0 point straight at `SentMailRecorder`. A host that decorated the 1.7 eraser or exporter
  alias must decorate the tag-scoped aliases as well; see
  [CONFIGURATION — personal_data](CONFIGURATION.md#personal_data). The wiring is unchanged.

## [2.8.0] - 2026-11-27

Planned as **2.8.0**: tag-scoped GDPR erasure and export of the sent-mail log, for a module that owns only the rows it
tagged (for example one tenant's messages). Additive, no BC break, no schema change; hosts need not act.

### Added

- `Log\SentMailTaggedPersonalDataEraserInterface::eraseTagged(SentMailPersonalDataSubject, string $tag)`: the erasure of
  `SentMailPersonalDataEraserInterface::erase()` limited to the rows whose tag (`MailHeaders::tag()`) is exactly the
  given one. Same semantics within the tag: exact address match, rows with other recipients unlinked and the address
  masked, rows with nobody else deleted with their body, the address masked in the subjects, errors and stored bodies of
  the other rows with that tag; idempotent; joins the caller's transaction or runs its own and dispatches
  `SentMailPersonalDataErasedEvent` after the commit. Rows with another tag, a case variant of the tag or no tag are
  never read or changed.
- `Log\SentMailTaggedPersonalDataExporterInterface::exportTagged(SentMailPersonalDataSubject, string $tag)`: the export
  of `SentMailPersonalDataExporterInterface::export()` limited to the same rows, same row shape.
- Both ports are always registered: `SentMailRecorder` whenever Doctrine is, `NullSentMailErasure` /
  `NullSentMailPersonalDataExporter` otherwise. Separate interfaces, so existing implementations of the 1.7 ports keep
  compiling.
- `SentMailRecorder::tagFilter()`: the tag a scoped call matches, trimmed like `MailHeaders::tag()` stores it; an empty
  tag, control characters, invalid UTF-8 or more than 64 characters (`MailHeaders::TAG_MAX`, the `tag` column length)
  throw `InvalidArgumentException` instead of being truncated.
- `Event\SentMailPersonalDataErasedEvent::$tag` (optional last constructor argument, default `null`): the tag a scoped
  erasure was limited to.

### Notes

- Exact tag match: SQL pre-filters with `tag = ?` and every row is re-checked in PHP with `===`, so a case- or
  pad-insensitive collation (MySQL / MariaDB) never widens the scope. No index on `tag` is added: the lookup keeps the
  address pre-filter of 1.7 (`recipient_index` / `sender`) and only narrows it, so no migration is needed.

## [2.7.0] - 2026-11-27

Planned as **2.7.0**: editable stored e-mail templates and snippets (this roadmap's Phase 2 and the CrmKit A-2 request,
Beacon spec 254 T003). Opt-in, no BC break. See [UPGRADING](UPGRADING.md#to-270).

### Added

- **Stored e-mail templates and snippets** (`nowo_mail_kit.templates.enabled`, off by default; contract
  [TEMPLATES — Stored e-mail templates](TEMPLATES.md#stored-e-mail-templates-270), spec 006). One DBAL table
  `<prefix>mail_kit_template` (key, locale, name, subject, sanitised HTML and text body, optimistic version, authors)
  with `personal` (owner only) and `shared` (tenant) scopes, created by the ORM `postGenerateSchema` listener or
  `nowo:mail-kit:templates:schema` (CREATE statement for MySQL / MariaDB, PostgreSQL or SQLite). API-first, no admin
  UI: `Template\Service\TemplateLibraryInterface` (list, get, findByKey with language fallback, create, update, delete),
  `TemplateRendererInterface` (render, renderDraft preview, placeholders) and `TemplatePersonalDataInterface`; every
  call takes the acting `TemplateActor(tenant, owner)`.
- **Host ports, deny by default**: `Template\Port\TemplatePermissionInterface` (`Personal`, `UseShared`,
  `ManageShared`; `DenyAllTemplatePermission` without `templates.permission`) and `TemplateOwnerResolverInterface`
  (GDPR). Tenant isolation in every query; personal rows visible to their owner only; invisible rows read as
  `TemplateNotFound`.
- **Closed placeholder registry**: `{{ namespace.name }}` resolved by services implementing
  `Template\Placeholder\PlaceholderProviderInterface` (autoconfigured, tag `nowo_mail_kit.template_placeholder_provider`,
  one provider per namespace, checked at build time by `TemplatePlaceholderPass`). Pure substitution in one pass, never
  evaluated or re-expanded; unknown placeholders stay as literal text (`RenderedTemplate::$unresolved`); values
  HTML-escaped in the HTML body and sanitised again, plain in subject and text, control / bidi characters removed;
  `max_body_bytes`, `max_output_bytes`, `max_placeholders` and `max_per_scope` limits. MailKit ships no provider.
- Template HTML keeps **https images only** (and `cid:` inline parts): `http`, `data:` and relative image sources,
  `srcset` / `background` / `style` URLs and audio / video are removed on save and on render. New
  `Mailbox\Html\RemoteImages::HttpsOnly` sanitiser mode (not offered by `mailboxes.html.remote_images`).
- Audit ZCZ (pre-tag): placeholders inside URL attributes (`href`, `src`, …; also percent-encoded or as character
  references) are refused on save with `InvalidTemplate('html')` (L1); `TemplateDeletedEvent` is dispatched inside the
  delete's transaction, so a throwing listener rolls the delete or the erasure back (L2); provider values are cut at
  64 KiB and a render stops once a part passes `max_output_bytes` (I2); zero-width and direction-mark characters are
  removed from values and refused in subjects, and braces written as character references never form a live
  placeholder (I3).
- `RenderedTemplate::applyTo(Email)` puts subject, HTML and text on a Symfony `Email` and tags it with the template key
  (sent-mail log tag and tracking template id).
- `TemplateDeletedEvent` after a delete and for each personal row a GDPR erasure deletes (dispatched inside the
  host's transaction when one is open).
- GDPR: UserKit `email_templates` eraser and export section (with `personal_data.enabled`): personal rows exported with
  content and deleted; shared rows the person wrote exported without content and their authorship cleared.
- REQ-RUNTIME-003: caught duplicate keys (`TemplateKeyTaken`), compare-and-set versions (`TemplateVersionConflict`),
  savepoints inside host transactions, the erasure in one transaction-doctrine unit; race tests on MySQL, MariaDB and
  PostgreSQL; worker-mode kernel test (400 renders, two tenants, no state bleed).
- REQ-SEC-006: the seven key columns of `mail_kit_template` are declared to MigrationsKit (`MailKitExactKeyColumns`
  fifth argument `$templates`, inactive until enabled).

### Changed

- **Lost savepoints close the connection** (audit ZCZ-I1, every DBAL store: Graph inbound, tracking, mailboxes,
  templates): when a write inside a host transaction fails and its savepoint cannot be rolled back (MySQL / MariaDB
  drop savepoints on a deadlock), the connection is now closed before the original failure is rethrown, instead of
  leaving the host's (already server-side rolled back) transaction open. The host's commit then fails; the next use
  reconnects.

- ROADMAP: editable templates are 2.7.0 (were planned as 2.4.0), `sending_domains` moves to 2.8.0, ICS invites (planned
  as 2.3.0) are not built yet.

## [2.6.0] - 2026-11-27

Gmail send-only mailboxes (phase B2), on top of the 2.5.0 Outlook mailboxes. Owner decision D-20261124-09: Gmail is **send-only**. See [UPGRADING](UPGRADING.md#to-260).

### Added

- **Send-only Gmail mailboxes** (`nowo_mail_kit.mailboxes.gmail.enabled`, off by default; design section 22, spec 005
  FR-019). A Gmail mailbox sends new messages from the person's own address through `MailboxSenderInterface`, with the
  token from the host's `MailboxTokenProviderInterface` (MailKit still runs no OAuth flow). Scopes exactly `openid email
  https://www.googleapis.com/auth/gmail.send` (`Mailbox\Gmail\GmailScopes`): no `gmail.readonly`, so no CASA
  assessment, no inbox, no folder, no sync, no webhook, no attachment download and no sent copy. The account is checked
  through the OpenID userinfo (verified address, bound account id) at start, resume and before every send; a token
  without `gmail.send` pauses the mailbox `ScopeMissing`. One RFC 5322 message (Symfony Mime, sanitised HTML, Bcc
  header, Message-ID derived from the idempotency key, at most 35 MB) and one `messages.send` call per key, never
  retried blindly: a 401 is retried once with a fresh token; grant, scope, Workspace-policy and "Gmail not enabled"
  answers pause the mailbox and release the key; rate and sending limits release it (retryable, `retryAfter`); another
  4xx fails it; a timeout, a 5xx or a 2xx without an id ends `unknown` and is never sent again, and a `sending` row left
  by a crashed worker is settled `unknown` once stale. Replies and forwards are refused for Gmail (the original cannot be
  read). Only `https://openidconnect.googleapis.com/v1/userinfo` and the `messages.send` upload URL receive the token,
  without redirects; no body, token or address is logged or put in an exception. Quota units reach the call observer.
- `MailboxProvider::isSendOnly()`, `MailboxSyncStatus::$provider` and `isSendOnly()`; `nowo:mail-kit:mailbox:status`
  prints the provider and "send only". Translation keys `mailbox.provider.graph`, `mailbox.provider.gmail` and
  `mailbox.provider.gmail_send_only_notice` (7 locales) for the host's UI.
- Audit ZBU fixes (Pass-Low): the HTTP client must not retry by itself (shared `MailboxHttpRetryPass`, shared with
  the 2.5.0 fix) (L1); after a 401 on the submit the refreshed token's account is checked before the one retry
  (L2); a message whose attachments cannot fit 35 MB once encoded is refused before it is rendered in memory
  (`GmailMailboxGateway::minimumMimeBytes()`) (L3); a token declaring Gmail scopes beyond `gmail.send` is logged as a
  warning (I4); a send whose `sent` row cannot be written answers `MailboxSendOutcomeUnknown` instead of a DBAL
  exception (I5); the setup guide requires `email_verified`, `id_token` `aud` / `iss` / `exp` checks and a new key after
  `accessNotConfigured` (I2, I3).
- `docs/GOOGLE-GMAIL-SETUP.md`: Google Cloud project, Gmail API, consent screen with the three scopes, web client and
  redirect URI, the PKCE + single-use `state` authorization request, verification without CASA, limits.

### Changed

- `MailboxSettings` of a send-only provider (Gmail) must follow no folders. **Behaviour change of a class released in 2.5.0**: its constructor required folders for every provider, so a Gmail `MailboxSettings` with folders now throws `InvalidArgumentException` (Gmail could not become active in 2.5.0, see [UPGRADING](UPGRADING.md#to-260)).
- The engine skips send-only mailboxes in the sweep and renewal schedules, never creates cursors or subscriptions for
  them and never delivers a sent copy; `MailboxSender` keeps the 2.5.0 rows and claim and adds the direct path for a
  send-only gateway. Internal: `SendOnlyMailboxGatewayInterface`, `PreparedMessage`, `SubmittedMessage`,
  `GmailApiClient`, `GmailMailboxGateway`; `SubmitTokenRejected` takes an optional provider name.

## [2.5.0] - 2026-11-27

Delegated Outlook mailboxes: per-person folder sync and send through Microsoft Graph delegated tokens the host provides. See [UPGRADING](https://github.com/nowo-tech/MailKitBundle/blob/v2.5.0/docs/UPGRADING.md#to-250).

### Added

- **Delegated mailboxes, sync side (2.5.0 part 1; design [`docs/design/DELEGATED_MAILBOXES.md`](design/DELEGATED_MAILBOXES.md),
  spec 005; Beacon spec 255).** `nowo_mail_kit.mailboxes` (off by default) syncs the folders a person follows in
  their own Outlook mailbox through Microsoft Graph **delegated** tokens the host provides: MailKit runs no OAuth
  flow (Q-254-15 = b) and never stores, caches or logs a token. Host ports (bundle VOs only, REQ-ARCH-001 rule 3):
  `Mailbox\Port\MailboxTokenProviderInterface`, `MailboxDirectoryInterface`, `MailboxMessageSinkInterface`
  (required), `MailboxExclusionPolicyInterface`, `MailboxCallObserverInterface` (optional). Services:
  `Mailbox\Service\MailboxSyncInterface` (start, pause, resume, sweep, status, disconnect) and
  `MailboxAttachmentReaderInterface` (on-demand, capped temp streams). Events `MailboxGrantFailedEvent` (once per
  transition) and `MailboxResyncedEvent`. Per-folder Graph subscriptions on a new webhook route
  (`nowo_mail_kit_mailbox_webhook`, `/hooks/mail-kit/v1/mailbox/graph`), per-folder delta sweep with a lease,
  `rerun` coalescing and cursor compare-and-set, exclusion before the fetch when the delta page gives the sender,
  thread key, removals reported to the sink, grant-failure pause, per-call metering. Commands
  `nowo:mail-kit:mailbox:schema`, `…:mailbox:subscriptions`, `…:mailbox:sweep`, `…:mailbox:status`. Three DBAL tables
  (`mail_kit_mailbox`, `_cursor`, `_subscription`) with no address, subject, body, token or recipient. Audit ZAT fixes before release: a grant failure acknowledges its
  Messenger message (the pause survives a `doctrine_transaction` middleware); the account is checked on every sweep;
  bounded retries (sweeps acknowledge transient failures; Retry-After re-enqueues at most 10 times); `removed()`
  carries a `RemovalReason` (`Deleted` / `MovedOut`, confirmed with Graph); delta links bound to their folder; only
  grant / consent / scope 403s pause; 400 sync-state errors resync. Re-audit ZAT-R: a folder whose sweeps are refused
  records the error on its cursor (`FolderSyncState::$syncError`, one `MailboxFolderSyncFailedEvent` per transition,
  cleared by the next good sweep) instead of failing unnoticed; an unrecognised delta-link folder form is logged and
  followed; the removal check reports `MovedOut` on an unexpected refusal and extends the folder lease after each check.
  The 2.2.2 fixes are carried into the 2.5 code: the ZAT-L4 strip rules apply to `MailHtmlSanitizer` in `dropped` and
  `blocked` mode, and the ZAT-L5 `#[\SensitiveParameter]` / no-`previous` wrapping covers the delegated credential path
  (delta links included).
  Verification ZAT-R4: a lease extension in the same second (no row changed on MySQL / MariaDB) is confirmed by a read
  of the owner instead of being taken for a lost lease; ZAT-R5: store write parameters are sensitive.
- **Delegated mailboxes, send side (2.5.0 part 2).** `Mailbox\Service\MailboxSenderInterface::send(MailboxId,
  OutgoingMailboxMail): SentMessage` sends a new message, a reply, a reply to all or a forward (`OutgoingKind`) from
  the mailbox's own address through Microsoft Graph **draft + send** (Q-MB-1: a MIME draft with a Message-ID derived
  from the key for a new message; `createReply` / `createReplyAll` / `createForward` on the original, then the host's
  recipients, subject and body PATCHed over Outlook's, Q-MB-7), with streamed attachments (upload sessions from
  2.9 MB, upload URLs checked against `mailboxes.graph.upload_hosts` and never given the token). The idempotency key is
  **mandatory** (Q-MB-20): one row per mailbox and key in the new `mail_kit_mailbox_send` table moves by conditional
  statements only (`drafting` → `sending` → `sent`; `released`, `failed`, `unknown`), and once `sending` is written only
  that one draft is ever submitted again, and only after Graph reports it still is a draft: a retried, concurrent or
  ambiguous send never delivers twice. An ambiguous submit (timeout, 5xx, 429) or an unreadable draft state answers
  `MailboxSendInProgress` (`reconciling`) and is reconciled by its draft state after `send.stale_claim_seconds`
  (`ReconcileMailboxSend`, or the next `send()` with the key); a draft that is gone, or `send.max_attempts` takeovers,
  end in `MailboxSendOutcomeUnknown` and nothing is sent again. The account behind the token is checked before every
  send (another account pauses the mailbox `AccountMismatch`). New exceptions `MailboxSendInProgress`,
  `MailboxSendKeyConflict`, `MailboxSendFailed`, `MailboxSendOutcomeUnknown`; VOs `OutgoingMailboxMail` (header
  injection and size bounds), `OutgoingKind`, `OutgoingAttachment`, `SentMessage`. The sent copy reaches the sink as
  `MessageOrigin::SentCopy` through `DeliverSentCopy` whether Sent Items is followed or not (Q-MB-6), without the
  exclusion policy. Delegated sends are not written to the sent-mail log (Q-MB-14); outgoing HTML is sanitised with
  remote images dropped. Configuration `mailboxes.send.*` and `mailboxes.graph.upload_hosts`; command
  `nowo:mail-kit:mailbox:purge` (settled send rows after `send.retention`, `P7D`). `send()` refuses to run inside a
  transaction on the mailbox connection (a rollback would forget a delivered send). Audit ZAV fixes: every submit
  (the 401 retry included, now made by the sender, not `GraphApiClient`) follows a conditional heartbeat and is refused
  later than `send.stale_claim_seconds / 2` after `sending` was written (L1); the message fingerprint includes the
  SHA-256 of the attachment bytes (I1); `DeliverSentCopy` needs a `sent` row with that id (I2); a `ReconcileMailboxSend`
  inside a transaction is logged and acknowledged, and the build warns about a `doctrine_transaction` bus without a
  dedicated `mailboxes.connection` (I3); `nowo:mail-kit:mailbox:purge --drafts` deletes leftover drafts after reading
  them as drafts and keeps a row whose draft could not be dealt with, so the next run retries it (I4). ZAV-F1: after a
  refused or vanished submit the `failed` / `unknown` transition is written first and the draft deleted only while the
  call still holds the key; a holder that lost the key answers the row's outcome (replay, failure, unknown or in
  progress). Index on (`mailbox_id`, `provider_message_id`). ZAV-F2: a failure before the submit whose
  `released` / `failed` write finds the key taken over answers the new holder's outcome (a replay when it sent).
  Audit ZBU (2.5.0 fixes): `mailboxes.http_client` that retries by itself (FrameworkBundle `retry_failed`, a
  `RetryableHttpClient` around the client, also through aliases, decorators on an alias id, argument wrappers and
  factory services, ZBUv-L1) fails the build, since it would re-POST a send outside the claim (L1); `resume()`
  enqueues the reconcile of every key a grant failure at the submit left `sending` (ZBUv-L2); after a 401 on the submit the refreshed token is checked
  against the bound account before the retry (L2); a send whose `sent` row cannot be written answers
  `MailboxSendInProgress` (`reconciling`) and is reconciled by the draft state instead of surfacing a DBAL exception
  (I5).
- `Mailbox\Port\MailboxMessageIndexInterface` (optional, `mailboxes.message_index`; owner answer Q1 of 2026-11-24):
  which messages of a change page the host already stores in that folder; the sweep asks once per page and fetches
  only the others. No default implementation.
- `Mailbox\Html\MailHtmlSanitizer`: the 1.9 inbound sanitiser, provider-neutral, with `html.remote_images: blocked`
  (default for mailboxes): remote image URLs move to `data-mail-kit-remote-src` for the host's image proxy; no
  `style`, script or event handler in any mode. `Graph\Inbound\InboundHtmlSanitizer` is now a thin subclass (no BC
  break).
- Optional OutboundUrlGuard pin of `graph.microsoft.com` for the delegated client (`mailboxes.graph.outbound_url_guard`:
  `auto` | `required` | `off`, the CrmKit 0.10 pattern).
- `GraphInboundMessage::$listId` (List-Id, optional trailing constructor argument) and
  `GraphMessageNormalizer::listIdOf()`.
- REQ-SEC-006: `MailKitExactKeyColumns` declares the mailbox key columns (inactive unless `mailboxes.enabled`),
  including `mail_kit_mailbox_send.mailbox_id` / `idempotency_key`.

### Security

- **Shared-mailbox Graph sending (1.8+) with a retrying HTTP client.** When the client given to `graph.http_client`
  retries failed requests (FrameworkBundle `retry_failed`), a Graph `POST …/send` that Microsoft accepted but answered
  502 / 503 can be POSTed again by the client, outside MailKit's control, and in a narrow window Exchange may deliver
  the message twice. Low severity (needs that host configuration plus a provider-side failure). 2.5.0 keeps the build
  passing but triggers a deprecation (`Since nowo-tech/mail-kit-bundle 2.5: … retries failed requests`); use a client
  with `retry_failed: false`. **MailKit 3.0 fails the build** for a retrying `graph.http_client`, as 2.5.0 already does
  for `mailboxes.http_client`.

### Changed

- `Graph\GraphApiClient` (`@internal`) takes a shared-mailbox profile or a `GraphCredentialInterface` per call, reports
  every call (status 0 on transport errors) and accepts optional per-request options (host pin); `uploadChunk()` and
  `cancelUpload()` take a credential too (the delegated path meters each chunk as `upload`), and `call()` takes a trailing
  `$retryUnauthorized` (false for the delegated submit: the sender retries a 401 itself). The 1.8 / 1.9 behaviour
  is unchanged. `ProviderCall::OPERATIONS` gains `delete_draft`.
- The 1.9 webhook controller's HTTP flow moved to `Graph\Inbound\Webhook\AbstractGraphWebhookController`, shared
  with the delegated-mailbox route; savepoint writes moved to `Concurrency\SavepointWrites`. No behaviour change.

## [2.2.2] - 2026-11-24

### Security

- Microsoft Graph app-only path: the bearer token no longer reaches the trace arguments of an exception (audit
  ZAT-L5, Low, affects 1.8.0 to 2.2.1; advisory to follow). `GraphApiClient::send()` received the request options,
  `Authorization` header included, as an argument, so a `GraphException` created there (transport error, timeout,
  answer over the size limit) recorded the token in its trace whenever `zend.exception_ignore_args` is Off (the PHP
  default and `php.ini-development`). `GraphTransport` chains that exception as `previous`, and Messenger's
  `ErrorDetailsStamp` keeps the flattened trace arguments, so the token could be written to the retry / failure
  transport or reach an error tracker. The options of `call()`, `callUrl()` and the internal `authorized()` /
  `send()` request helpers, and the URL of `send()`, `uploadChunk()`, `cancelUpload()` and `assertUploadUrl()`, are
  now `#[\SensitiveParameter]` (the upload session URL is pre-authenticated, so it is treated as a secret too). Any
  other failure of the HTTP client (a decorator, an outbound URL guard), whose own frames hold the token or, on the
  token request, the client secret, is now replaced by a `GraphException` transport error without `previous`, and
  only its class is logged. The delegated mailbox path (2.5, unreleased) is not affected. New test: a failing send,
  the exception chain serialised as Messenger's failure transport stores it (`ErrorDetailsStamp`,
  `FlattenException`, the raw `getTrace()` arguments), with no token, secret or upload URL in it.

### Fixed

- Graph inbound HTML (`graph.inbound`, `allow_remote_images: false`, the default): remote images could still load
  through `srcset` (audit ZAT-L4). symfony/html-sanitizer before 7.4.23 / 8.1.11 keeps `srcset` and `imagesrcset`
  without a URL check (and `poster` / `longdesc` in early 7.4 releases), so `<img src="cid:…" srcset="https://…">` or
  `<picture><source srcset="https://…">` loaded a tracking image although remote images are dropped. Without remote
  images, `InboundHtmlSanitizer` now removes `srcset`, `imagesrcset`, `lowsrc`, `dynsrc`, `poster`, `background`,
  `longdesc` and `style` from every element and the `source`, `track`, `link` and `style` elements, whatever the
  installed sanitizer version checks. `allow_remote_images: true` is unchanged. New tests for each vector.

## [2.2.1] - 2026-11-23

### Fixed

- `mail_kit_graph_inbound.status` no longer gets the exact (binary) collation when the schema is built. It is a state
  word the bundle writes and compares itself, and `MailKitExactKeyColumns` deliberately does not declare it, so the
  collation was an undeclared leftover that the uniform collation gate would report on a fresh schema. The docblock of
  `GraphInboundStore::defineSchema()` and the Graph usage note say so now. Existing tables keep their binary `status`
  column, which is harmless; nothing to run.

## [2.2.0] - 2026-11-23

### Added

- REQ-SEC-006: the bundle now declares its own identity / access-key columns, so the knowledge of which columns are
  exact keys lives in the bundle that owns the tables instead of in every host.
  `Nowo\MailKitBundle\Doctrine\MailKitExactKeyColumns` implements
  `Nowo\MigrationsKitContracts\Collation\ExactKeyColumnsInterface` (new runtime dependency
  `nowo-tech/migrations-kit-contracts` ^1.0: interfaces and value objects only, no dependency beyond PHP) and declares
  eight columns — `mail_kit_tracking_send.{send_id,recipient_id,tenant}`,
  `mail_kit_graph_subscription.{subscription_id,profile}`, `mail_kit_graph_delta.profile` and
  `mail_kit_graph_inbound.{message_key,profile}`. The bundle already gave them the server's binary NO PAD collation
  when it builds the schema (1.11.0); the declaration is what makes `nowo:migrations:check-key-collations` *assert*
  it, so a table created before 1.11.0, restored from a dump or imported by hand is reported instead of trusted.
  MigrationsKit discovers the declaration by autoconfiguration, so a host registers **nothing** and can delete the
  `nowo_migrations_kit.collation_check.ignore_columns` entries it kept for those columns (see UPGRADING). Table names
  are resolved from `nowo_mail_kit.doctrine.table_prefix`, never literals, and the columns of a feature that is off
  (tracking, the Graph inbound pipeline: both off by default) are declared **inactive**, so they are reported as
  skipped with the setting that gates them instead of as missing tables. Nothing is memoised (REQ-RUNTIME-002).
  `mail_kit_graph_inbound.status`, `mail_kit_graph_subscription.client_state_hash`,
  `mail_kit_tracking_send.{log_id,template_id,last_open_ip,last_open_ua}` are deliberately **not** declared: a state
  word the bundle writes itself, a value never compared in SQL (`hash_equals()`), back-references that are never
  matched, and telemetry. A declaration is a claim about identity, and an untrue one is worse than none.
- Tests: the bundle's own REQ-SEC-006 gate (`tests/Integration/Collation`, extending MigrationsKit's
  `ExactKeyColumnsTestCase`), on MySQL 8.4 and MariaDB 11.4 in CI. The four tables are created by the stores
  themselves under a non-empty table prefix and the declared columns are read back from `information_schema`.

### Changed

- Dev only: `require-dev` `nowo-tech/auth-kit-bundle` widened to `^1.31 || ^2.0`, so the suite also runs against
  AuthKit 2.0. The adapter uses only `OutboundMailReadyCheckerInterface`, which AuthKit 2.0 keeps unchanged. No change
  for applications.

### Fixed

- A new strict `TranslationCatalogueTest` fails on a non-en locale missing a key, on a non-en value identical to English (any length, placeholders included; values without letters are skipped) unless allow-listed per locale (brands, units, protocols, loanwords, true cognates), on a non-es value identical to the Spanish one (longer than 12 characters) unless allow-listed as genuinely shared, and on stale allow-list entries. No translation changed.

## [2.1.0] - 2026-11-22

### Changed

- Sent-mail log, empty state: one sentence through UiKit's `empty` partial. The sentence was passed as the empty state's *title*, so UiKit printed its default "No items found." as a second line under it; it is now the message, alone. A filtered, empty list also offers a *Clear filters* link (`log.action.clear_filters`, translated in the seven shipped locales); an empty, unfiltered log offers no action. Texts and routes are unchanged.

## [2.0.0] - 2026-11-21

**2.0.0 (major, REQ-ARCH-001).** It removes only what 1.x announced as deprecated; an application that builds on the
last 1.x minor (1.14) without MailKit deprecation notices upgrades without code changes. See UPGRADING "To 2.0.0".

### Removed

- **BREAKING (API):** **`Sender\SenderProviderInterface`** (the Mime-typed sender port, deprecated since 1.12, announced for removal in
  UPGRADING "To 1.12.0" and "Preparing for 2.0"). Implement `Sender\SenderIdentityProviderInterface` (returns
  `Sender\MailAddress`). Its wiring goes with it: the internal gate `Sender\SenderProviderGate`, the services
  `nowo_mail_kit.sender.provider_gate` / `nowo_mail_kit.sender.identity_gate` and the
  `Nowo\MailKitBundle\Sender\SenderProviderInterface` alias. A `mailer.sender_provider` service or a sender decorator
  that does not implement `SenderIdentityProviderInterface` (including a class that still implements the removed
  interface, which can no longer be loaded) fails the container build with a message naming the service and its class;
  so does a service that still decorates the removed id, and a class registered by resource autodiscovery that
  still implements the removed interface (Symfony keeps only a loading error on its definition and would otherwise
  drop it silently, `#[AsDecorator]` / `#[AsAlias]` unread).
- **BREAKING (API):** **`Settings\ResendSettingsSection`** (constant holder, deprecated since 1.3). Use `MailerSettingsSection::NAME` or
  `LegacyResendSettings::SECTION`.
- **BREAKING (deprecation layer):** **The 1.14 build-time deprecation notices** (`log.map_entities` unset with the log off, host classes on
  `SenderProviderInterface`), together with what they announced.

### Changed

- **BREAKING (default):** **`log.map_entities` follows `log.enabled` when it is not set** (it defaulted to `true` in 1.x; announced in
  UPGRADING "To 1.14.0"). With the log on nothing changes. With `log.enabled: false` and the option unset, the
  `SentMail` / `SentMailBody` mapping leaves your entity manager and the schema tool proposes dropping both tables:
  purge or erase them first, or set `log.map_entities: true`.
- **BREAKING (Composer, REQ-ARCH-001 rule 8):** `nowo-tech/instance-settings-bundle`, `nowo-tech/doctrine-encrypt-bundle` and
  `doctrine/orm` move from `require` to `suggest` (announced in UPGRADING "To 1.14.0" / "Preparing for 2.0").
  `conflict` refuses their versions below the old floors (`<1.3.1`, `<2.10`, `<3.3`). `doctrine/dbal`,
  `doctrine/doctrine-bundle`, `doctrine/persistence` (the tracking and Graph stores use its registry) and
  `symfony/mime` stay required.
- **BREAKING (build errors):** services that use one of the three packages are registered only when it is installed: the Doctrine ORM listeners
  (table prefix, the tracking and Graph inbound schema listeners) and the log mapping need the ORM, and the
  erasure / retention recorder falls back to the no-op one without it. `log.enabled: true` (the default) without
  `doctrine/orm` fails the build with what to install; without Instance Settings and without both host ports the
  build error now says to run `composer require nowo-tech/instance-settings-bundle`, so mail never falls back silently
  to the framework transport.
- **BREAKING (API):** `SenderPolicy` and `TestMailSender` read `SenderIdentityProviderInterface` (constructor type change). An address the
  mailer refuses is still left out with the same warning, now logged by `SenderPolicy`
  (`SenderPolicy::REFUSED_ADDRESS_MESSAGE`).
- CI: new job "Without Instance Settings, DoctrineEncrypt and the ORM"; the workflows also run on the `1.x` branch.
- demo: the demo runs inside UiKit's demo platform shell (`@NowoUiKit/demo_platform/layout.html.twig`, UiKit ^2.60): shared top bar, aside navigation and section cards, no demo masthead markup; the Web Debug Toolbar and the CDN hot-reload scripts are off in the demo and its README screenshots. Demo only, no release.

### Fixed

- **A host entity holding a log row survives the CSV export.** `SentMailRepository::iterateFiltered()` (the streamed
  export) and `findBodies()` detached every `SentMail` / `SentMailBody` they returned, including one the host had
  already loaded on the same entity manager, for example through its own `ManyToOne` to `SentMail`. The host's next
  flush then failed with "A new entity was found through the relationship …". They now detach only the rows they
  loaded themselves. MailKit still never persists, flushes, clears, resets or closes the host's entity manager, and its
  log entities have no association to host classes.

### Security

- 1.x receives security fixes for 6 months after 2.0.0 is released: fixes rated Moderate and above are backported to
  the last 1.x minor, Low ones are fixed in 2.x only (`.github/SECURITY.md`).

## [1.14.1] - 2026-11-20

### Fixed

- The Doctrine mapping the bundle prepends (`NowoMailKitBundle`) no longer sets the ORM mapping `alias` option, deprecated in DoctrineBundle 3.4 (removed in 4.0, ORM has no entity namespace aliases). The alias was not used anywhere (the bundle needs ORM 3); a test pins that booting raises no such deprecation and the entities stay mapped.

## [1.14.0] - 2026-11-20

### Added

- **2.0 deprecation layer (REQ-ARCH-001, last 1.x minor).** Build-time deprecation notices for everything 2.0 changes,
  raised once per container build, so the application's deprecation log lists what to migrate (UPGRADING
  "To 1.14.0"). UPGRADING gains "Preparing for 2.0" (Composer moves, removed port, 1.x security support).

### Changed

- Requires `nowo-tech/transaction-doctrine` ^1.9 (was ^1.8.1, which fixed GHSA-9wh3-m3m5-jffx and
  GHSA-65w2-9r4h-848g). MailKit never flushes the application's entity manager, so it uses no host-flush probe.

### Deprecated

- **`log.map_entities` unset while `log.enabled` is false** (with DoctrineBundle installed): the log entities stay
  mapped in 1.x, but in 2.0 the option defaults to `log.enabled` and they are no longer mapped. Set
  `log.map_entities` explicitly (`true` keeps the mapping, `false` is the 2.0 behaviour). With the log on, or without
  DoctrineBundle, nothing changes and there is no notice.
- **Host classes on `Sender\SenderProviderInterface`** (deprecated since 1.12, removed in 2.0) now get a build-time
  notice: the `mailer.sender_provider` service and every decorator of the sender that implements it. Before, only
  the `@deprecated` tag reported them, in debug mode.
- **Composer moves announced for 2.0:** `nowo-tech/instance-settings-bundle`, `nowo-tech/doctrine-encrypt-bundle` and
  `doctrine/orm` move from `require` to `suggest`. No runtime notice is possible; require them directly if your
  application uses them.

## [1.13.1] - 2026-11-19

### Fixed

- **Graph inbound writes no longer abort the host's transaction on a lock conflict (audit UE2-MK-1).** Inside an open
  host transaction, the delta-link `UPDATE`s of `GraphInboundStore::saveDeltaLink()`, the stale-claim `UPDATE` of
  `claim()`, and the statements of `complete()` and `release()` now run in a savepoint, like the claim and delta-link
  `INSERT`s already did. A lock wait timeout, deadlock or serialization failure rolls back only that savepoint and is
  rethrown unchanged, so the host's PostgreSQL transaction stays usable. The conflict codes and the retry rules
  (one retry outside a transaction, none inside) are unchanged. Two-connection race test on MySQL 8.4, MariaDB 11.4
  and PostgreSQL 16.

## [1.13.0] - 2026-11-19

### Added

- **Conflict answer on the bundle's routes (REQ-RUNTIME-003 rule 6).** A deadlock, lock wait timeout or serialization
  failure on an admin page or action, the Graph webhook or the tracking pixel now answers **409** with
  `X-Conflict-Code`. The code is `CONFLICT_001` with `Retry-After` when nothing was committed, and `CONFLICT_002`
  with no `Retry-After` when part of the request was. Admin routes go through your error page as an `HttpException`
  that carries the headers. Unique violations and lost connections are not mapped. See
  [CONCURRENCY.md](CONCURRENCY.md).
- `conflict_response.status` (`409` default, or `503`) and `conflict_response.retry_after` (default `1`).
- `docs/CONCURRENCY.md`: write-path table, codes, host options and residual risks.
- Race tests on two real connections (MySQL 8.4, MariaDB 11.4, PostgreSQL 16 and 17):
  - the inbound claim, the delta link and the subscription swap (including a failed INSERT that keeps the old
    subscription);
  - a lock timeout on an INSERT inside the host's transaction;
  - the open counter, and a listener conflict after a committed open.

  The real-database CI job gains PostgreSQL 16 plus MySQL 8.4 and PostgreSQL 16 cells on DBAL 3, and runs them.
- CI job "Dependency floor": the suites run with the lowest `nowo-tech/transaction-*` versions allowed, and the demo
  lock must install every `nowo-tech/*` requirement (`.scripts/check-demo-lock.py`).

### Changed

- Requires `nowo-tech/transaction-doctrine` ^1.6 and `nowo-tech/transaction-contract` ^1.0.
- **Lock conflicts are no longer swallowed (rule 8).** They now propagate:
  - from the tracking store, the tracking policy and the link rewriter before the e-mail leaves;
  - from tracking writes inside your transaction;
  - from the pixel;
  - from `MailOpenedEvent` and `MailMachineOpenedEvent` listeners;
  - from the test e-mail action.

  Other failures behave as before.
- `GraphInboundStore::release()` throws instead of swallowing a failure. It retries a lock conflict once outside a
  transaction. The processor logs a failed release ("claim not released") and still rethrows your handler's failure.

### Fixed

- `GraphInboundStore::saveSubscription()` runs its `DELETE` and `INSERT` in one transaction. A failed insert no longer
  leaves the profile without a subscription.
- `GraphInboundStore::saveDeltaLink()` no longer fails with a unique violation when two sweeps store the first link of
  a profile at the same time. It does an `UPDATE`, then an `INSERT` whose duplicate key is caught (in a savepoint
  inside a transaction).
- The Graph inbound processor no longer releases the claim when storing the decision fails after your handler already
  handled the message. A Messenger retry used to hand the message over a second time.
- A lock conflict in the Graph webhook's dispatch loop, after part of the batch was queued, answers `CONFLICT_002`
  instead of a 500.
- Inside an open transaction, the savepoint of the inbound claim and of the delta-link INSERT is now rolled back on
  any failure, not only on a duplicate key. A lock wait timeout, deadlock or serialization failure on that INSERT no
  longer leaves the caller's PostgreSQL transaction aborted. The failure is rethrown after the rollback (audit UE-MK-1).
- `GraphInboundStore::complete()` retries a lock conflict once outside a transaction, so a decision is not lost to a
  single lock wait (audit UE-MK-4).

### Demo

- The demo is built on UiKit and FormKit (REQ-DEMO-014 / REQ-DEMO-015): the Bootstrap CDN shell and the Bootstrap
  Icons CDN are gone, the demo shell is UiKit's admin layout with its default theme (also the layout of the Instance
  Settings and MailKit pages), and the demo forms are FormKit types themed by the `demo` profile on UiKit's form theme
  (no `bootstrap_5_layout`). No bundle runtime change.

## [1.12.2] - 2026-11-14

### Fixed

Target 1.12.2 (delta audit PI, Low).

- **D-L1: "the new port wins" also holds on real mail for a decorated sender.** When the `SenderProviderInterface` id
  or the `mailer.sender_provider` service is decorated, `SenderPolicy` and the test e-mail now read the decorated
  sender through a gate (`nowo_mail_kit.sender.identity_gate` over the decorated `SenderProviderInterface` id, or
  `nowo_mail_kit.sender.provider_gate` over the configured id), which asks `defaultFrom()` / `defaultReplyTo()` first.
  Before, a decorator that implements both ports gave real mail its `from()` / `replyTo()` values and new-port
  consumers its `defaultFrom()` / `defaultReplyTo()` values. Host services that inject `SenderProviderInterface` keep
  the decorator itself.
- **D-I1: a decorator that implements only `SenderIdentityProviderInterface` no longer breaks real mail.** On the
  `SenderProviderInterface` id, or on a provider that implements only the deprecated port, it made every send fail
  with a `TypeError`. It is now served through the same gate.
- A host that decorates neither sender port compiles to the same container as in 1.12.1; a test compares the
  compiled wiring of each kind of provider with a record taken from 1.12.1. The circular-reference rule of 1.12.1
  (D-I2) is unchanged and the gate adds no new cycle. See UPGRADING.

## [1.12.1] - 2026-11-14

### Added

- The InstanceSettings tab of the mailer section now has an icon (`tabler:mail-forward`).

### Fixed

Target 1.12.1 (audit PI, Low).

- **PI-L2: the new sender port no longer bypasses host decorators.** When the `SenderProviderInterface` id or the
  service in `mailer.sender_provider` is decorated, the `SenderIdentityProviderInterface` alias now follows the
  decorated service: straight when every decorator implements the new port, otherwise through the gate
  (`nowo_mail_kit.sender.provider_gate`, or the new `nowo_mail_kit.sender.identity_gate` over a decorated
  `SenderProviderInterface` id). Before, a decorator implementing only the deprecated port caused a `TypeError` where
  the new port was autowired, and a decorator of the `SenderProviderInterface` id was not seen by new-port consumers.
  The gate now reads the configured id rather than the end of its alias chain, so a decorator of an alias on the way
  is not skipped either. MailKit's own sending path keeps its precedence (the new port wins).
- **PI-L1: a sender address the mailer refuses is logged.** When a `SenderIdentityProviderInterface` provider returns
  an address that Symfony Mime rejects, the gate still leaves the header out (unchanged, same leniency as the settings
  sender) and now logs a constant warning with the provider class and the field (`from` / `replyTo`), never the
  address.
- **Behaviour change (D-I2): a decorator of `SenderProviderInterface` that injects `SenderIdentityProviderInterface`
  no longer compiles.** It worked in 1.12.0. In 1.12.1, when the decorator implements only the deprecated port, the
  new port is served by `nowo_mail_kit.sender.identity_gate` over the decorated id, so the container build fails
  with a `ServiceCircularReferenceException`. To fix it, decorators take the decorated service through `.inner` (or
  `#[AutowireDecorated]`) and do not inject the identity port. In exchange, the decorated `From` now reaches
  new-port consumers. A decorator that implements both ports should return the same values from `from()` and
  `defaultFrom()`, and from `replyTo()` and `defaultReplyTo()` (D-L1). See UPGRADING.

## [1.12.0] - 2026-11-14

### Added

- **Framework-free sender port (REQ-ARCH-001 rule 3).** `Sender\SenderIdentityProviderInterface`
  (`defaultFrom()` / `defaultReplyTo()`) is the twin of `Sender\SenderProviderInterface` and returns the new bundle
  value object `Sender\MailAddress` (address plus optional display name) instead of the Symfony Mime `Address`, so a
  DDD / hexagonal host supplies the default `From` / `Reply-To` with no Symfony component in the port. Implement it
  and set `nowo_mail_kit.mailer.sender_provider` to its service id as before: the option takes either interface.
- `Sender\MailAddress`: `__construct(string $email, ?string $name = null)`, `MailAddress::fromString()`
  (`no-reply@example.com` or `Acme <no-reply@example.com>`), `toString()` / `__toString()`.
- `MailSettings` (the `mailer` Instance Settings section) implements both ports, so the default sender exposes the
  value-object form too.
- `SenderIdentityProviderInterface` is always aliased to the configured sender provider, so the new port can be
  autowired: straight to the service when it implements the new port (or is `MailSettings`), through the internal gate
  when it implements only the deprecated one. `mailer.sender_provider` stays the single source of the provider; no
  autoconfiguration.

### Changed

- `Sender\SenderProviderInterface` is **deprecated** (removed in 2.0): implement
  `Sender\SenderIdentityProviderInterface`. Nothing else changes — the interface, its signatures and the built-in
  settings sender stay as they are, and a host service implementing only it is still asked through it.
- **Precedence: the new port wins.** A `mailer.sender_provider` class that implements both ports is asked through
  `defaultFrom()` / `defaultReplyTo()`; its deprecated `from()` / `replyTo()` are no longer called (see UPGRADING).
- A `mailer.sender_provider` service that implements the new port is wrapped in an internal gate
  (`Sender\SenderProviderGate`, service id `nowo_mail_kit.sender.provider_gate`) behind `SenderProviderInterface`, so
  the sender policy, the test e-mail and every decorator of that id keep working. A service implementing the
  deprecated port keeps today's wiring exactly. A service that implements neither now fails the container build with a
  clear message instead of a `TypeError` at send time.

### Tests

- The value object, the mapper, the gate (both ports, both directions, new-port precedence), the new compiler pass
  (`SenderPortPass`, every wiring case including both aliases) and kernel tests that send real mail through a host
  identity provider, a dual provider returning different values from each port, a deprecated-only provider (new port
  still autowired) and the standard host (`MailSettings` on both ports, no gate).

## [1.11.0] - 2026-11-13

### Changed

- **Binary NO PAD collation on the identifier and key columns (REQ-SEC-006).** `utf8mb4_bin`, which
  `<prefix>mail_kit_tracking_send` has put on `send_id`, `recipient_id` and `tenant` since 1.10, is a *binary*
  collation but a **PAD SPACE** one on MySQL 8 and MariaDB: `'bob' = 'bob   '` compares equal. The new
  `Doctrine\ExactCollation` chooser (ported from `nowo-tech/http-log-bundle` 1.20.1, `@internal`) picks a binary
  **NO PAD** collation from the connection's platform and server version instead — `utf8mb4_0900_bin` on MySQL 8.0.17
  and newer (9.x included), `utf8mb4_nopad_bin` on MariaDB — and keeps `utf8mb4_bin` only as the MySQL 5.7 fallback,
  logging a warning when it has to. PostgreSQL and SQLite compare exactly already and are left alone. No configuration:
  the choice follows the database.
- **The Graph inbound tables get the same collation.** `<prefix>mail_kit_graph_subscription.subscription_id` and
  `.profile`, `<prefix>mail_kit_graph_delta.profile`, and `<prefix>mail_kit_graph_inbound.message_key`, `.profile` and
  `.status` carried **no** collation at all, so they took the database default — `utf8mb4_0900_ai_ci` on a stock
  MySQL 8 or 9, `utf8mb4_uca1400_ai_ci` on MariaDB 11.4, both case- and accent-insensitive *and* PAD SPACE, on three
  primary keys. `client_state_hash` keeps
  the table collation on purpose: it is never compared in SQL, only read back and checked with `hash_equals()`.
- Hardening, not a fix for a reachable bug: every identifier that reaches these tables through the bundle already goes
  through `TrackingIds::check()`, which rejects `trim($value) !== $value` on the write path and on both read paths, and
  the Graph key columns only ever hold Graph-generated ids, configured profile names and a SHA-256 digest. The column
  collation makes the guarantee a property of the database instead of of one PHP guard, so it also holds for rows
  written by a host import, a data migration or hand-written SQL.
- `TrackingStore::defineSchema()` and `GraphInboundStore::defineSchema()` take an `AbstractPlatform|Connection|null`
  second argument (widened; the old `?AbstractPlatform` call still works). Passing the `Connection` lets the chooser
  read the server version. `GraphInboundStore` takes an optional PSR-3 logger as its fifth constructor argument, for
  the MySQL 5.7 fallback warning.

### Tests

- `Tests\Unit\Doctrine\ExactCollationTest`: the collation chosen per platform and server version (MySQL 5.7, 8.0
  with and without a patch number, 8.4, 9.7, MariaDB 11.4, PostgreSQL, SQLite, a primary/replica connection and an
  unreadable version), the fallback warning, and the generated `CREATE TABLE` of both stores on each of them —
  including that `template_id`, `last_open_ua` and `client_state_hash` keep the table collation.
- `Tests\Integration\Log\ExactCollationTest`: a trailing-space recipient id or tenant is refused on the **write**
  path (`TrackingDecision::track()`) and on **both read** paths (`TrackingStore::eraseRecipient()` and
  `exportRecipient()` each re-validate), so a refactor that drops the read-side check fails CI; and, on the real
  databases of the CI matrix (MySQL 8.4, MariaDB 11.4), that a row inserted around `TrackingIds` is not matched by
  `'UserA '`, `'usera'`, `'acme '`, `'ACME'` or `'acmé'`, while the old `utf8mb4_bin` would have matched the padded
  value. Skipped on PostgreSQL and SQLite, which compare exactly by default.

### Documentation

- `docs/UPGRADING.md` — *To 1.11.0*: the per-platform `ALTER` for all four tables, a detection query for columns
  already created the wrong way, which connection / entity manager to run them on (these are DBAL tables, so
  `doctrine:migrations:diff` may show nothing), and why the change can only separate values that previously compared
  equal and never merge them.

## [1.10.2] - 2026-11-12

### Fixed

- **1.10.1 regression: `public_base_url: '%env(DEFAULT_URI)%'` still failed `cache:clear`.** Symfony's
  `ValidateEnvPlaceholdersPass` re-processes the configuration with each env placeholder replaced by `""`, which the
  1.10.1 validator rejected. `Configuration` now lets `""` through; the extension, which sees the real value, rejects a
  literal that is empty or not a valid URL (`javascript:x` still fails the build) and accepts an env placeholder
  (re-checked at runtime, failing closed as before). `enabled: true` without `public_base_url` still fails.
- **`EmailTracker` argument 7 compatibility.** A host compiler pass that sets argument 7 to the full pixel base (base +
  path prefix, as before 1.10.1) no longer gets the prefix twice (pixel route 404): the prefix is appended only when
  the base does not already end with it.

## [1.10.1] - 2026-11-12

### Fixed

- **`tracking.public_base_url` accepts an env placeholder** (Symfony Beacon request). `'%env(DEFAULT_URI)%'` used to fail the
  container build because the value is still a placeholder then. A placeholder is now accepted at build time and
  checked with the same rules when the tracker first uses it (absolute http(s) URL with a host, no user info, query or
  fragment); an invalid value fails closed: critical log `Mail tracking disabled: ...`, the e-mail goes out untracked,
  no pixel and no link is generated. A literal value is still validated when the container is built. The check is stateless
  (worker-safe: nothing is cached on the service). `EmailTracker` gets a trailing `$pathPrefix`
  argument; `$pixelBase` is now the base alone (BC: a base that already includes the prefix still works with `''`).

## [1.10.0] - 2026-11-12

Next minor: 1.10.0 (Beacon spec 230 block B, D-20261112-16: e-mail open and click tracking).

### Added

- **E-mail open and click tracking (opt-in, `tracking.enabled`, default `false`: nothing is registered and every e-mail
  goes out as in 1.9; needs a DBAL connection).** Right before delivery the runtime transport asks
  `Tracking\EmailTracker` for a tracked **copy** (the original message is never changed): an opaque send id (128
  random bits), a 1×1 transparent GIF pixel and rewritten links. `MailDeliveredEvent::$trackingId` (new optional
  trailing argument) carries the send id; a failed delivery forgets the send.
- **Host ports (plain PHP, REQ-ARCH-001 rule 3):** `Tracking\EmailTrackingPolicyInterface` (required; decides per
  `TrackingCandidate` — template id, the only recipient, recipient id / tenant hints — and returns a
  `TrackingDecision`), `Tracking\EmailLinkRewriterInterface` (`TrackedLink`: send id, URL, label, position,
  `essential`, template, recipient id, tenant; default `NullEmailLinkRewriter` keeps the links),
  `Tracking\TrackableLinkFilterInterface` (default `ConfiguredTrackableLinkFilter`, deny by default:
  `links.allowed_hosts` / `allowed_path_prefixes`), `Tracking\TrackingRecipientResolverInterface` (GDPR bridge).
- **Never tracked:** `X-Mail-Kit-No-Track` (`MailHeaders::noTracking()`), several envelope recipients, no HTML body,
  template ids matching the built-in security patterns (`EmailTracker::SECURITY_TEMPLATES`, always applied) or
  `tracking.extra_never_track_templates`, declared redaction values
  (`skip_redacted`), a policy answer `none()` — no pixel, links untouched, **no row**. Credential-bearing links
  (`Tracking\CredentialLinkDetector`: user info, token / code / OTP / signature / `_hash` / pre-signed parameters,
  reset / login / magic / verify / invitation / accept / OAuth path segments, redaction values) are never rewritten;
  `mailto:`, `tel:`, anchors and relative links are never touched.
- **Pixel endpoint** (route loader `nowo_mail_kit_tracking`, `GET|HEAD <tracking.path_prefix>/{token}.gif`, default
  `/e/o/{token}.gif`, stateless): always the same 43-byte GIF with `Cache-Control: no-store` (valid, expired, forged or
  unknown token); HMAC-SHA256 tokens (`tracking.secret` or derived from `kernel.secret`, rotation through
  `previous_secrets`, expiry = `retention`); one indexed UPDATE per open, or a `RecordMailOpen` message with
  `tracking.bus`; machine opens (empty user agent, `machine_opens.user_agents`, `machine_opens.ip_ranges`, default
  Apple `17.0.0.0/8`) counted apart; IP truncated (/24, /48), user-agent family only.
- **Store** `<prefix>mail_kit_tracking_send` (DBAL, bundle-owned, savepoint inside a host transaction, binary collation
  of the id columns on MySQL / MariaDB, `postGenerateSchema` listener), read port `Tracking\EmailTrackingReaderInterface`
  (`find()`, `findMany()` → `OpenStats`), GDPR port `Tracking\EmailTrackingPersonalDataInterface`
  (`exportRecipient()`, `eraseRecipient()`, exact match) and, with `personal_data.enabled` and UserKit, the
  `email_tracking` eraser and export section.
- **Events** `MailOpenedEvent` / `MailMachineOpenedEvent` (send id and time only) after each counted open of a known send.
- **Commands** `nowo:mail-kit:tracking:purge` (retention, default `P90D`) and `nowo:mail-kit:tracking:schema [--force]`.
- `MailHeaders::template()`, `MailHeaders::trackingRecipient()`, `MailHeaders::noTracking()` (internal headers, removed
  before delivery).

### Changed

- `MailHeaders::strip()` also removes the 1.10 internal headers (`X-Mail-Kit-Template`, `X-Mail-Kit-Recipient`,
  `X-Mail-Kit-Tenant`, `X-Mail-Kit-No-Track`), with tracking on or off.
- `RuntimeTransport` gets an optional trailing constructor argument (`?EmailTracker`); defaults unchanged.

- Audit NU (before the 1.10.0 tag): the pixel signer is built lazily (a bad `%env()%` secret leaves mail untracked
  and is logged as critical; literal secrets under 32 bytes fail the build; with `secret: null` the previous secrets
  are derived from previous kernel secrets), the built-in security template patterns can no longer be replaced
  (`extra_never_track_templates` adds to them), `href` is matched only as an attribute name (tokenised attributes),
  path parameters (`;jsessionid=`) count as credential parameters, `tracking.enabled` needs `mailer.decorate: true`,
  the GDPR export includes the last open's truncated address and user-agent family, and `max_opens_per_send` caps
  the counters (default 1000).

### Tests

- Messenger retry regression (serialized `SendEmailMessage` re-handled after a failure, fix released in 1.9.1):
  security, no-track and redacted mail are never tracked on the retry.
- HTML rewriting edge cases (malformed HTML, comments, `<script>` / `<style>`, Outlook VML buttons, entities, quoting,
  labels, limits, `data-mail-kit-track="off"`, text part, PCRE failure), credential URLs, token forgery (every bit flip),
  rotation, expiry and clock skew, unknown / forged tokens still answer the GIF, opt-out and security templates → zero
  rows, machine-open classification, purge in batches, exact recipient ids, savepoint inside the host transaction
  (also on MySQL, MariaDB and PostgreSQL in CI), lint:container, and a DDD host serving 1 000 pixel requests for two
  tenants in one kept-alive kernel without the services resetter (flat memory, p95 < 20 ms).

## [1.9.1] - 2026-11-12

Next patch: 1.9.1.

### Fixed

- **Messenger retries lost MailKit's internal headers.** `RuntimeTransport` removed `X-Mail-Kit-Log-Id`,
  `X-Mail-Kit-Tag` and `X-Mail-Kit-Redact` from the caller's message object before delivery. Messenger re-sends that
  very object on a retry (serialized again by the retry or failure transport), so a retried message lost its queued
  sent-mail log row (a second row was written while the first stayed `failed`), its tag and its redaction values. The
  headers are now removed from the delivered copy only; the caller's message is never changed.

### Security

- With `log.store_bodies: true`, asynchronous mail (Messenger) whose first delivery attempt failed and was retried,
  and a message that declared values with `MailHeaders::redact()` (one-time links, codes), the retry's log row stored
  the HTML / text bodies **without** masking those values (1.0.0 – 1.9.0). The first attempt's row was masked. Fixed by
  the change above. Rows written before the upgrade are not rewritten: see UPGRADING "To 1.9.1".

## [1.9.0] - 2026-11-12

Next minor: 1.9.0 (Beacon spec 229 block B, D-20261112-09: inbound client e-mail from the Graph mailbox).

### Added

- **Inbound mail from Microsoft Graph mailboxes (opt-in, `graph.inbound.enabled`; needs `graph.enabled`,
  symfony/messenger and a DBAL connection).** Each new message is fetched, normalised and handed to your
  `Graph\Inbound\GraphInboundHandlerInterface`, which returns an `InboundDecision` (`InboundAction::Route`,
  `Quarantine` or `Discard`).
- **Webhook** (route loader `nowo_mail_kit_graph_webhook`, `POST graph.inbound.webhook.path`, default
  `/hooks/mail-kit/v1/graph`, stateless): echoes Graph's `validationToken` as `text/plain` (1–1024 printable ASCII
  characters, `nosniff`, `default-src 'none'`), checks every notification against a stored subscription and its
  `clientState` (SHA-256 digests, `hash_equals`), caps the body (`max_body_bytes`), the batch (`max_notifications`) and
  the rate (per-client-IP and verified buckets, 429), refuses anything else (400 / 403 / 413 / 415) and only **enqueues**
  `FetchGraphMessage` (lifecycle events: `RenewGraphSubscriptions` / `SweepGraphMailbox`).
- **Subscriptions** (`GraphSubscriptionManager`, command `nowo:mail-kit:graph:subscriptions [--profile] [--delete]`,
  message `RenewGraphSubscriptions`): one per profile on the folder's messages (`created`, immutable ids, lifecycle
  URL), a fresh random 256-bit `clientState` stored as SHA-256 only, renewed `renew_before_minutes` before expiry,
  recreated when Graph forgot it.
- **Delta sweep** (`GraphDeltaSweeper`, command `nowo:mail-kit:graph:sweep`, message `SweepGraphMailbox`): missed
  notifications and hosts without a public URL; the delta / next link is stored per profile after every page, links
  are followed only on `https://graph.microsoft.com/v1.0/`, the first run reads back `initial_window` (`P2D`) only, an
  expired token (410) restarts from the window; old idempotency records are purged after each run (`retention_days`).
- **Normalised DTO** `GraphInboundMessage`: from / sender / reply-to / to / cc (`InboundAddress` with the
  plus-address tag of the mailbox), `plusTags` (To, Cc and envelope headers), subject, text, sanitised HTML,
  `internetMessageId`, `inReplyTo`, `references`, `conversationId`, received time, `AuthenticationResults` (SPF / DKIM
  / DMARC of the first `Authentication-Results` header), a `forwarded` flag (subject prefixes, attached e-mail,
  `Resent-*` / `X-Forwarded-*` headers, forward markers) and `InboundAttachment`s.
- **Idempotency** by internetMessageId per profile in the bundle table `<prefix>mail_kit_graph_inbound` (SHA-256 key,
  no id or address in clear): a message seen by the webhook and the sweep is processed once; a host exception releases
  the claim (retry); a claim older than `stale_claim_seconds` is taken over. DBAL on the configured connection (never
  the host's entity manager, never a flush), in a savepoint when a host transaction is open.
- Tables `mail_kit_graph_subscription`, `mail_kit_graph_delta`, `mail_kit_graph_inbound` (with `doctrine.table_prefix`):
  added to the ORM schema of the configured entity manager (`postGenerateSchema`, so migrations diff creates them) and
  printed / created by `nowo:mail-kit:graph:schema [--force]`.
- Host port `GraphInboundProfilesProviderInterface` (default: `graph.inbound.profiles`, else the default profile).
- `GraphApiClient::callUrl()` (absolute Graph links, `assertGraphUrl()`), `download()` (streamed into a sink, capped)
  and a response size cap on every call (`GraphException::REASON_TOO_LARGE`).
- Dependencies: `symfony/messenger`, `symfony/html-sanitizer` and `ext-fileinfo` are suggested; `graph.inbound`
  refuses to compile without them (no new hard requirement for hosts that do not read mail).

### Changed

- After an ambiguous send failure that still reads as a draft (review of NK-1, audit NQ-L5): with
  `graph.draft_cleanup.bus` the sender dispatches a delayed `DeleteGraphDraft` (`DelayStamp`, `delay_ms`, default
  5 s) that deletes the message only if it is still a draft, and never blocks the worker; without a bus it reads
  `isDraft` once more at once before deleting (a send Exchange completes after that read is still deleted with its
  draft: residual race, documented).

### Audit NQ (before the 1.9.0 tag)

- M1: the webhook reads the request body to the cap whatever the stream's chunk size (`php://input` hands out 8 KiB
  per `fread()`); test with an 8 KiB-chunk stream wrapper.
- M2: two rate-limit buckets — a per-client-IP bucket for unverified traffic (validation, malformed, rejected;
  `webhook.client_rate_limit`, default 60 per minute) and the global `webhook.rate_limit` consumed only by requests
  with a valid notification; each subscription is verified once per request; a request stops with 403 after 5
  rejected items; optional source allow-list `webhook.allowed_ips` (CIDR).
- L1: lifecycle events carry a reason: `reauthorizationRequired` → `POST /subscriptions/{id}/reauthorize` (404 →
  recreate), `subscriptionRemoved` → forget and recreate (`RenewGraphSubscriptions::$reason`).
- L2: inline attachments stream through `$value` like the others; their Content-ID comes from
  `?$select=id,contentId,isInline` (no base64 in memory).
- L3: dangerous extensions (`html`, `svg`, `js`, `php*`, `exe`, `bat`, `ps1`, `lnk`, macro-enabled Office…,
  `InboundFileTypes::DANGEROUS_EXTENSIONS`) are rejected (`extension`) without being downloaded, whatever the bytes;
  `InboundAttachment::$safeFilename` carries the extension of the detected type when the name disagrees;
  **`application/zip` is no longer in the default `allowed_types`** (owner decision 2026-11-12; add it explicitly).
- L4: SPF / DKIM / DMARC are read only when Exchange marked the message as received from outside
  (`X-MS-Exchange-Organization-AuthAs: Anonymous`); otherwise null.
- L5: see Changed.
- L6: a message over `limits.max_message_bytes` is handed to the host without body or headers, flagged
  `GraphInboundMessage::$oversized`, instead of failing and retrying forever; `retention_days` must exceed the sweep's
  `initial_window` by more than a day (checked at compile time).
- I2: notifications of an expired subscription or of a profile no longer in `inboundProfiles()` are refused;
  `nowo:mail-kit:graph:subscriptions --delete` offboards a mailbox. I3: delta links are followed only when shaped as a user's
  mail-folder message delta (`users/{id}/…` or `users('{id}')/…` with `mailFolders` and `messages/delta`); an
  identifier other than the profile's address (Graph often uses the object id) is logged once per run and followed. I7: renewals purge old idempotency records too. I9: see the dependencies.

### Audit NQ-v (verification, before the 1.9.0 tag)

- M1: an exhausted per-client-IP bucket no longer refuses verifiable notifications (Graph shares Microsoft's egress
  addresses): it narrows the request to a cheap path (the first unknown subscription ends it, else 429). IPv6 clients
  are keyed by /64. Docs: `trusted_proxies` must list only your proxies.
- L1: delta links must match exactly `/v1.0/users/{id}/mailFolders/{folder}/messages/delta` (or the `('…')` key
  forms), with no `.` / `..` segment, `;` or stray quote even after a second URL-decoding; `{id}` is the profile's
  address or a GUID (logged once per run).
- L2: `RenewGraphSubscriptions::$subscriptionId`: lifecycle events act only on the profile's current subscription
  (else `stale`); only `subscriptionRemoved` recreates (with a best-effort DELETE of the old one); unknown lifecycle
  values are logged and ignored; rejected subscriptions are counted distinct and a request with valid items is never
  refused for stale ones.
- L3: with a clean-up bus, an ambiguous send whose message is still a draft fails with `ambiguous` (not retried) while
  the delayed `DeleteGraphDraft` resolves it; a failing dispatch falls back to the immediate decision without
  replacing the original error; the handler ignores malformed ids.
- L4: oversized messages are handed over without attachments; an envelope over 256 KiB is read again without To / Cc.
- I1: SECURITY / USAGE state the Exchange Online Protection header-firewall assumption and the hybrid caveat. I2:
  `allowed_ips` validated at compile time. I3: IPv6 /64 keying. I5: attachment names lose `:` and trailing dots /
  spaces; more dangerous extensions (`ps1xml`, `psc1`, `wsc`, `sct`, `ws`, `msc`, `msh*`, `xll`, `xla`, `mht`,
  `mhtml`, `jnlp`, `cab`, `settingcontent-ms`, `library-ms`, `search-ms`, `ade`, `adp`, `mde`, `accde`, `pyw`,
  `command`, `desktop`, `apk`, `dmg`, `pkg` …).
### Security

- Untrusted inbound content: HTML through symfony/html-sanitizer (safe elements only, no script / style / iframe /
  form / event handlers / `style` attributes, links http(s) / mailto with `rel="noopener noreferrer nofollow"`, images
  `cid:` only unless `allow_remote_images`), input cut to `max_html_bytes`; attachments streamed into capped
  `php://temp` streams, content type **detected** with finfo (the declared one is informative only) and checked against
  `limits.allowed_types`, names neutralised, size / count / total limits, item and reference attachments not read;
  the message JSON is capped (`limits.max_message_bytes`). Logs carry profiles, decisions and counts only.
- The webhook trusts nothing but the stored client-state digest; Graph links in delta pages are followed only on
  Graph (SSRF guard shared with 1.8); `assertGraphUrl()` refuses ports other than a literal `:443`.

### Tests

- Webhook (validation echo, client state, forged cross-tenant notification, caps, rate limit, lifecycle), subscription
  create / renew / recreate / remove, delta sweep (paging, stored links, untrusted links, 410), normalisation
  (plus-address tags, envelope headers, forwarded, Authentication-Results, references cap), sanitizer, processor
  (finfo types, limits, inline Content-IDs, idempotency, host failure release, 404), store on SQLite (savepoints, stale
  claims, purge), commands, Messenger handler, schema listener.
- `tests/Integration/Graph/GraphInboundKernelTest.php`: DDD host (no Instance Settings, host mailbox and handler ports)
  serving 600 webhook requests through `http_kernel` with two organizations alternating, no services resetter, each
  request with a forged notification for the other organization (refused); each message read from its own mailbox
  with its own token; one token per tenant; memory under 2 MiB; `lint:container`.

## [1.8.0] - 2026-11-12

Next minor: 1.8.0 (Beacon spec 229, D-20261112-09: client e-mail threads; the inbound Graph pipeline follows in 1.9.0).

### Added

- **Microsoft Graph sending from a shared mailbox (opt-in, `graph.enabled`).** `Graph\Send\GraphMailSender::send()`
  sends a `GraphOutgoingMail` (plain value objects: recipients, Reply-To, subject, text / HTML, attachments,
  `messageId`, `inReplyTo`, `references`) from the profile's mailbox with app-only credentials (OAuth 2.0 client
  credentials, scope `https://graph.microsoft.com/.default`) and returns a `GraphSendResult`: the immutable Graph id,
  the `internetMessageId` and the conversation id. The message is created as a draft from MIME (the only Graph path
  that keeps `Message-ID`, `In-Reply-To` and `References`; JSON `internetMessageHeaders` accept `x-` headers only),
  Bcc is set on the draft, attachments are added (below 2.9 MB in one call, from 2.9 MB through an upload session in
  3.75 MiB chunks read from the stream), then the draft is sent and saved in Sent Items. A failure before the send
  call, or a definite refusal of it (4xx), deletes the draft; an ambiguous send failure (network error, timeout, 5xx,
  429 / 503) reads the draft state first: still a draft → deleted and the error rethrown; sent → success; unknown →
  the draft is kept and the send fails with the non-retryable reason `ambiguous`. `PlusAddress::compose()` / `tag()` build and read plus addresses (`projects+t-42@…`) for a per-thread Reply-To.
- **`microsoft+graph://<profile>` mailer transport** (`GraphTransportFactory`, tag `mailer.transport_factory`):
  standard hosts send a Mailer `Email` through Graph as a named transport or `MAILER_DSN` (queued e-mails stay
  serializable). The `SentMessage` carries Graph's internetMessageId; envelope-only recipients become Bcc. The DSN
  carries no credentials (refused).
- **Host port `Graph\GraphMailboxProviderInterface`** (tenant, client id, `GraphSecret`, mailbox per profile) with the
  default `ConfiguredGraphMailboxProvider` (`graph.default_profile` + `graph.profiles`, REQ-CFG-001 shape) or your
  service (`graph.mailbox_provider`, e.g. encrypted settings per organization). `GraphMailbox` validates the tenant
  (GUID or domain), the client id (GUID), the mailbox and the profile name.
- **Token cache** (`GraphTokenProvider`): PSR-6 pool `graph.token_cache` (default `cache.app`), key = SHA-256 of
  tenant, client id and secret fingerprint (a rotated secret gets a new token at once), kept until 5 minutes before
  expiry, expiry re-checked on read; one retry with a fresh token on 401.
- Configuration `nowo_mail_kit.graph` (`enabled`, `mailbox_provider`, `default_profile`, `profiles`, `http_client`,
  `token_cache`, `timeout`, `upload_hosts`, `limits.max_attachments` / `max_attachment_bytes` / `max_message_bytes`).
- `docs/MICROSOFT-GRAPH-SETUP.md`: Entra app registration (`Mail.ReadWrite` + `Mail.Send` application permissions),
  `New-ApplicationAccessPolicy` (or RBAC for Applications) to restrict the app to the mailbox, plus addressing, secret
  rotation, size limits, egress / HttpLog hosts and the webhook URL requirements of the coming inbound pipeline.
- Dependency: `psr/cache` `^2.0 || ^3.0` (already installed through the framework bundle).

### Security

- Graph secrets and tokens never reach a log, an exception or a dump: `GraphSecret` / `GraphMailbox` /
  `ConfiguredGraphMailboxProvider` redact `__debugInfo()`, `GraphSecret` and `GraphAttachment` refuse serialization,
  `#[SensitiveParameter]` on secret parameters. `GraphException` messages hold fixed text, the HTTP status, the Graph
  / Entra error code and the request id (both token-checked) — never a response body, a URL (upload URLs carry a
  token), a token, the secret or content — and no `previous`. Logs carry the profile, operation, status and counts only.
- Only `graph.microsoft.com` receives the bearer token (relative paths only); upload session URLs returned by Graph
  must be HTTPS on port 443 to a `graph.upload_hosts` host (default `outlook.office.com`, `outlook.office365.com`)
  and get no token; no redirects are followed on any call; deadlines on every call.
- Outgoing attachment names are neutralised (`AttachmentFilename`: no directory part, control or bidi characters,
  leading dots; at most 200 characters), content types must be a `type/subtype` token, Content-IDs are checked;
  subject line breaks are removed; message ids are validated before they reach a header.
- Limits checked before any Graph call: attachments per e-mail, bytes per attachment (≤ 150 MiB), bytes per e-mail,
  and the MIME part capped at 2.9 MB.
- Audit NK (before the 1.8.0 tag):
  - NK-1 (Medium): an ambiguous failure of the send call no longer deletes the draft blindly (with immutable ids the
    sent copy keeps the draft's id, so a delete could move a sent message to Deleted Items, and a retry duplicated
    it). The draft state decides (see above); an unknown outcome is `GraphException::REASON_AMBIGUOUS`
    (`isRetryable()` false), thrown by the transport as `GraphUnrecoverableTransportException` and kept by
    `DeliveryFailedException::from()` as `UnrecoverableDeliveryFailedException` (both Messenger
    `UnrecoverableExceptionInterface`), so Messenger does not retry it. `DeliveryFailedException` is no longer `final`.
  - NK-2 (Low): upload sessions from 2.9 MB (`GraphMailSender::LARGE_ATTACHMENT_BYTES` = `MAX_MIME_BYTES`), so a
    plain attachment POST stays under Graph's 4 MB request limit after base64.
  - NK-3 (Low): `GraphAttachment::fromStream()` spools a non-seekable stream up to 35 MiB by default
    (`GraphSendLimits::DEFAULT_MAX_BYTES`, was 150 MiB); pass your attachment limit as `$maxBytes`.
  - NK-4 (Info): upload URL host check removes one trailing dot only and refuses a leading dot, empty labels and a
    port written other than `:443` (e.g. `:0443`).
  - NK-5 (Info): HttpLog wording: HttpLog never records bodies, query strings or headers; `microsoft_graph` is a
    provider the host declares.
  - NK-6 (Info): comment of the Bcc-only rendering fixed (the placeholder Bcc only satisfies Symfony Mime; delivery
    relies on the PATCH of `bccRecipients`).

### Tests

- Graph simulated with `MockHttpClient` (`tests/Support/GraphApiMock.php`, fixture tenants, clients and mailboxes):
  token flow and cache (expiry, rotation, refusal without leaking the secret), 401 retry, error mapping, throttling,
  send with the threading headers and plus-address Reply-To, Bcc, small / inline / large attachments with the upload
  session (chunk ranges, no token on the upload host), SSRF-refused upload URL, draft clean-up, limits, no content in
  logs; the mailer transport (mapping, envelope Bcc, `cid:` parts, errors).
- Kernel tests (`tests/Integration/Graph/GraphKernelTest.php`): off by default; standard host (configured profile,
  transport factory from `mailer.transport_factory`); strict DDD host (no Instance Settings, no ORM, host mailbox
  port) serving 600 requests through `http_kernel` with two organizations alternating, no services resetter (each
  request uses its own mailbox, token and Reply-To; one token per tenant; memory under 2 MiB); `lint:container`.

## [1.7.1] - 2026-11-11

Next patch: 1.7.1.

### Fixed

- **Legacy Resend rows kept from before another worker's edit (REQ-RUNTIME-002).** `LegacyResendSettings` memoises
  the MailKit 1.2 `resend` rows (enabled flag, API key, sender) that `MailSettings` still reads until they are seeded
  into the `mailer` section; only `kernel.reset` forgot them. Where the services resetter does not run between units
  of work (a host that removes, replaces or bypasses `services_resetter` or drives `http_kernel` directly, a consumer
  started with `messenger:consume --no-reset`, or a long-running process that runs several console commands), a
  worker kept sending with the rows it read first after another worker migrated, purged or edited them (old sender,
  revoked key). New `LegacyResendSettingsResetListener` forgets the memo at the start of every main request, at
  `kernel.terminate`, `WorkerMessageReceivedEvent`, `WorkerMessageHandledEvent`, `WorkerMessageFailedEvent`,
  `console.command` and `console.terminate` (event names as strings; Messenger and Console stay optional). The memo
  is kept within a unit of work because one send reads it several times. The listener receives the memo as an
  iterator of `IGNORE_ON_UNINITIALIZED_REFERENCE` references, so a boundary never builds it; `ResetInterface` /
  `kernel.reset` are kept. Affected: 1.3.0 to 1.7.0, only with `resend.enabled: true`, 1.2 `resend` rows still
  stored, and one of those paths.

### Tests

- `tests/Integration/Settings/LegacyResendBoundariesTest.php`: one booted kernel, services resetter never called;
  500 HTTP requests through `http_kernel` (with a sub-request), 500 messages through a real Messenger `Worker` without
  `ResetServicesListener` (every 7th fails) and 500 console `Application` runs; before each unit another worker
  alternates the legacy sender between tenant A's and tenant B's address; asserts each unit reads the stored sender
  and memory grows less than 1 MiB after a 50-unit warm-up. Listener wiring test (only with `resend.enabled`, on every
  boundary) and a direct `reset()` unit test.

### Documentation

- `docs/FRANKENPHP-WORKER-AUDIT.md`: precise precondition (`FRANKENPHP_RESET_KERNEL=false` still runs the resetter
  with the stock kernel), the legacy Resend memo and its boundaries, the worker tests, and the host rule for Doctrine
  entity managers; the Messenger recommendation no longer assumes `kernel.reset` runs between messages.

## [1.7.0] - 2026-11-11

### Added

- **GDPR export port** (Beacon re-audit extraction #4): `Log\SentMailPersonalDataExporterInterface::export(SentMailPersonalDataSubject)`
  returns the log rows an address received (to, cc, bcc) or sent — `id`, `roles`, `status`, `template` (tag),
  `subject`, `queued_at` / `sent_at` / `failed_at` (ATOM). Envelope only: no bodies, other recipients, transport,
  provider id or error. Backed by `SentMailRecorder` (Doctrine registered) or `NullSentMailPersonalDataExporter`.
- **GDPR erasure port keyed by a subject**: `Log\SentMailPersonalDataEraserInterface::erase(SentMailPersonalDataSubject)`
  returns a `Log\SentMailErasureResult` (`deleted`, `unlinked`, `redacted`, `toArray()`, `total()`); it delegates
  to `forgetAddress()`. Backed by `SentMailRecorder` or `NullSentMailErasure`.
- `Log\SentMailPersonalDataSubject` (address value object, the log's own normalisation: trim, NFC, lower-case, IDN)
  and the `Log\SentMailPersonalDataSubjectResolverInterface` SPI with `DefaultSentMailPersonalDataSubjectResolver`.
- `Event\SentMailPersonalDataErasedEvent` (address + `deleted` / `unlinked` / `redacted` counts), dispatched after `forgetAddress()` committed its own
  transaction; not dispatched inside a caller's transaction.
- **UserKit bridge (opt-in)**: with `nowo_mail_kit.personal_data.enabled: true`, `nowo-tech/user-kit-bundle`
  >= 1.12 installed and the log entities mapped, the eraser `emails_received` (priority 110: original identifier,
  `getEmail()`, `context['previous_email']`) and the export section `emails_received` register. New config node
  `personal_data` (`enabled: false`, `subject_resolver: null`). Off by default so an upgrade never starts erasing a
  log the host must keep (GDPR art. 17(3)) or clashes with the host's own `emails_received` export section.

### Changed

- **Behaviour change:** `SentMailErasureInterface::forgetAddress()` no longer deletes a row other people share. A row
  that still has another recipient is **unlinked**: the address is removed from `recipients` and `recipient_index`, a
  matching sender becomes `[redacted]`, the address is masked in the subject, the delivery error and the body; the row is deleted only when
  no other recipient remains. The result gains an `unlinked` count (`['deleted', 'unlinked', 'redacted']`);
  `forgetRecipient()` returns deleted + unlinked rows; `nowo:mail-kit:log:forget` prints the three counts.
- `SentMailErasureInterface::forgetAddress()` matches exactly: SQL only pre-filters, every row is re-checked in PHP
  against the normalised address (an accent-insensitive MySQL / MariaDB collation no longer erases `josé@` for
  `jose@`), rows are deleted by id in batches of 500, inside a transaction of its own (or the caller's). `redacted`
  counts only rows whose subject, delivery error or body really changed; the stored delivery error (SMTP bounce)
  is masked too. Masking replaces whole addresses only: erasing
  `na@example.com` no longer masks part of `ana@example.com` or `na@example.com.au` in other rows.

## [1.6.0] - 2026-11-11

Instance Settings and the ORM are optional when the host provides the configuration ports; real GDPR erasure also with the log off; test e-mail uses the host sender port. See [UPGRADING](https://github.com/nowo-tech/MailKitBundle/blob/v1.6.0/docs/UPGRADING.md#to-160).

Architecture bar W2 (standard Symfony by default, strict DDD opt-in): rule 8 (configuration through provider ports)
and rule 1 (ports for the services used as API).

### Added

- Instance Settings is optional when the host provides both configuration ports: with
  `nowo_mail_kit.mailer.dsn_provider` **and** `nowo_mail_kit.mailer.sender_provider` set to host services and no
  `NowoInstanceSettingsBundle` registered, the bundle boots without it. The `mailer` settings section, its
  `SettingsChangedEvent` listener and the settings actions (`/actions/...` routes) are then not registered; the
  mailer decorators, readiness, the CLI test e-mail (sender from the host port) and the log pages keep working.
  Without either provider the build fails and names the missing option(s); `resend.enabled` needs Instance Settings.
- With `log.enabled: false` the bundle needs no Doctrine either (no ORM, no DoctrineEncrypt registered). With the log
  on and no DoctrineBundle the build now fails with a clear message instead of a missing-service error.
- `Readiness\OutboundMailReadinessInterface` (`isOutboundMailReady()`, `status()`, `missingFields()`), aliased to
  `OutboundMailReadiness`; the Twig extension, the AuthKit adapter, the settings listener and the controller depend on
  the interface.
- `Log\SentMailErasureInterface` (`forgetAddress()`, `purgeBefore()`), always registered: `SentMailRecorder` whenever
  Doctrine is registered — also with `log.enabled: false`, so rows written while the log was on are really erased and
  purged (and `nowo:mail-kit:log:forget` / `log:purge` stay available) — and `Log\NullSentMailErasure` (no-op) only on
  a host without Doctrine, where nothing can be stored. A missing log table never
  reads as success: with the entities mapped, erasure and retention throw `Log\SentMailLogTableMissingException`
  (the forget / purge commands exit non-zero); when only the body table is missing the main-table rows are erased
  first and the exception carries the counts. Only with `log.map_entities: false` do missing tables mean "nothing
  stored" (zero).
- `log.map_entities` (default `true`): `false` (only with `log.enabled: false`) keeps the `SentMail` / `SentMailBody`
  mapping out of your entity manager, for XML-mapping-only hosts. Deprecation notice: in 2.0 the default follows
  `log.enabled`.
- With a host `mailer.sender_provider`, the test e-mail (CLI and admin action) now uses that port, the same sender as
  real mail, also when Instance Settings is registered (it read the `mailer` settings before, LL-MK-L2).
- Container parameter `nowo_mail_kit.instance_settings` (whether the configuration is read from Instance Settings).

### Changed

- `TestMailSender` takes `?MailSettings` and an optional trailing `?SenderProviderInterface`; `MailKitController`
  takes `?MailSettings`; `MailKitRouteLoader` takes an optional trailing `$settingsActions` flag. Existing callers keep
  working.
- `MailerIntegrationPass` checks for the Instance Settings store only when the configuration is read from it.
- `composer.json` keeps `nowo-tech/instance-settings-bundle`, `nowo-tech/doctrine-encrypt-bundle` and `doctrine/orm`
  in `require` until 2.0 (they move to `suggest` then); the code paths are optional now.

## [1.5.0] - 2026-11-10

Target 1.5.0. DDD / hexagonal P2 fixes (audit 2026-11-09).

### Added

- `Sender\SenderProviderInterface` (`from()`, `replyTo()`): the default sender of the sender policy is now a port.
  `MailSettings` implements it and stays the default. New option `nowo_mail_kit.mailer.sender_provider` (service id,
  null = the settings).
- Id-only delivery events `Event\MailDeliveredEvent` (`logId`, `tag`, `transport`, `providerMessageId`, `size`,
  `occurredAt`) and `Event\MailDeliveryFailedEvent` (`logId`, `tag`, `transport`, `reference`, `occurredAt`),
  dispatched by `RuntimeTransport` after the log write. A listener exception is logged and never changes the outcome.

### Changed

- `SenderPolicy` takes a `SenderProviderInterface` (a `MailSettings` still fits).
- `SentMailRecorder::sent()` / `failed()` return the log row id (`?string`) instead of `void`.
- `RuntimeTransport` takes two more optional constructor arguments (`?EventDispatcherInterface`, `?ClockInterface`).
- Fixes a possible duplicate send of raw (non-MIME) messages: with the log enabled, reading the unset `SentMessage`
  Message-ID threw an `Error` after delivery, so Messenger retried an e-mail that had already gone out. The provider
  message id is now null for raw messages.
- Delivery events are not re-entrant: a mail sent synchronously from a delivery listener dispatches no event of its
  own (audit KI-M-L1); send alerts asynchronously.

- Docs: new USAGE section "Using it from a DDD / hexagonal application" (where the bundle sits in a hexagonal host, the ports, events and DTOs to build an anti-corruption layer on, and the known limitations).

## [1.4.0] - 2026-11-09

Branded e-mails (spec 220 S7, target 1.4.0): an optional layout with the brand name, colours and logo of the project,
the logo attached as an inline CID part. Opt-in; e-mails that do not use it are unchanged. See
[UPGRADING](UPGRADING.md#unreleased).

### Added

- `@NowoMailKit/email/branded_layout.html.twig` (table layout, inline styles, blocks `html_lang`, `title`,
  `preheader`, `header`, `content`, `footer`) and `@NowoMailKit/email/branded_layout.txt.twig` (plain-text part).
- `Nowo\MailKitBundle\Brand\BrandedEmailHelper` (public service): `apply(TemplatedEmail)` puts the brand name, the
  light colours and the `logo_light` (or `mark`) image into the e-mail; `embed(Email, slot)` attaches `logo_light`,
  `mark` or `extra.email_<id>` and returns its `cid:` reference. Reads an optional `nowo-tech/brand-contracts`
  `BrandProviderInterface` and `BrandAssetReaderInterface` (owner decision Q-O6: CID, no remote image).
- Twig `nowo_mail_kit_brand()` (sanitised brand view, the configured fallback without a provider) and the
  `nowo_mail_kit_plain_text` filter.
- `nowo_mail_kit.brand`: `mode` (`auto` / `off`), fallback `name` and `colors`, `max_image_bytes` (512 KiB),
  `max_total_bytes` (1 MiB), `max_image_side` (4096).
- `nowo-tech/brand-contracts` in `require-dev` and `suggest`, with `conflict` `<1.0 || >=2.0`.

### Security

- Brand images are attached only when their bytes are PNG, JPEG or WebP (magic bytes and image header agreeing) and
  within the size and dimension limits; SVG is never attached, whatever its origin. Nothing is fetched from the asset
  URL (bytes come only from the asset reader), and the layout prints only re-checked `cid:` references, so no remote
  image or tracking pixel is added.
- Name escaped by Twig in the HTML part and stripped of control, bidi and separator characters in the plain-text part;
  colours only `#rrggbb` from the brand contract or the validated fallback, checked again before printing.
- A failing provider, reader or image never fails the send: the e-mail goes out with the configured brand and without
  the logo (logged with the slot and a hash prefix only). The helper is stateless (worker-safe).
- Audit IW-L2: the helper reuses and counts only the parts it attached itself (`BrandedEmailPart`); an application
  attachment named like a brand part (`nowo-brand-logo-light.png`) is never used as the logo, and the brand part then
  gets a unique `nowo-brand-<slot>-<12 hex>.<ext>` name so the `cid:` reference cannot resolve to the other part.
- Audit status: Appendix IW Pass-Low; the IW-L2 fix is **re-verification pending**.

## [1.3.0] - 2026-11-02

provider choice (DSN / Resend) with provider-dependent fields, key check on save, legacy purge

Provider choice in the mailer settings: with `resend.enabled`, the `mailer` section offers `dsn` / `resend` and shows only the selected provider's fields (InstanceSettings 1.3 `visible_when`); a Resend key is checked before it goes live. Requires InstanceSettings ^1.3.1 and UiKit ^2.23. See [UPGRADING](UPGRADING.md#to-130).

### Added

- `mailer.provider` choice (`dsn` / `resend`, only with `resend.enabled: true`): `mailer.dsn` is shown for `dsn`,
  `mailer.resend_api_key` / `mailer.resend_from` for `resend`; sender, Reply-To and test recipient for both. Hidden
  rows are neither validated nor written by a save, so switching back keeps the other provider's values.
- `ResendApiKeyAccepted` constraint (admin form, audit MK-L1): a save with `provider = resend` that would put a key live
  (a typed key, or the stored key when switching from `dsn`) checks it with `GET https://api.resend.com/domains` first
  and is refused when Resend rejects it or no key is stored; a key equal to the live one is not checked again; rate
  limited with the test e-mails. Messages
  `resend.save.*` (7 locales).
- `LegacyResendSettings`: reads the 1.2 `resend` rows; `MailSettings` uses them until `mailer.provider` is stored, and
  the mailer section exposes them as seed fallbacks, so `nowo:instance-settings:seed-from-fallbacks --section=mailer`
  migrates them. Meanwhile the mailer page shows what is live (`LegacyResendSettingsTypeExtension`: Resend
  pre-selected when the old switch was on, old key counted as stored, old sender pre-filled) and a notice
  (`settings.mailer.notice_legacy_resend`); saving it unchanged stores the migrated values and keeps Resend live.
- `nowo:mail-kit:purge-legacy-resend [--dry-run] [--force]`: opt-in deletion of the 1.2 `resend` rows (encrypted key
  included) once they are copied into the mailer section; refuses and lists the rows that were not copied;
  idempotent.
- `MailSettings::provider()` and `MailSettings::legacyResendPending()`.
- Demo: Resend provider enabled with an offline Resend stand-in (`re_demo_…` keys are valid), UiKit `visible-when`
  peer, Playwright e2e of the provider switch and the check-before-save path, axe in both states.

### Changed

- **With `resend.enabled: true`:** the `resend` settings section is removed; `resend.enabled` / `resend.api_key` /
  `resend.from` became `mailer.provider` / `mailer.resend_api_key` / `mailer.resend_from`, and the Resend actions moved
  to the mailer page (same routes, now gated on the `mailer` section access). This reverses the 1.2 decision to keep
  the Resend fields always visible. See [UPGRADING](UPGRADING.md#to-130).
- `MailSettings::missingFields()` reports the selected provider only (`mailer.dsn` or `mailer.resend_api_key`).
- `bootstrap.resend_api_key` seeds `mailer.resend_api_key`.
- `ResendSettingsSection` is deprecated and only holds its `NAME` constant.
- Requires `nowo-tech/instance-settings-bundle` ^1.3.1 (was ^1.2) and `nowo-tech/ui-kit-bundle` ^2.23 (was ^2.21);
  `nowo-tech/doctrine-encrypt-bundle` ^2.10 is now a direct requirement (secret form data of the key check).
- `composer test` / `test-coverage` run PHPUnit with `-d memory_limit=512M`.

### Security

- CU-L1 (Low): with `resend.enabled`, a `nowo_instance_settings.security.area_roles.resend` entry fails the container
  build (it would otherwise be silently ignored and the Resend key left under the possibly weaker `mailer` roles).
- CU-I1 / CU-I3 (Info): the "same live key" comparison uses `hash_equals()`; a host section named `resend` is never
  treated as the 1.2 rows (`LegacyResendSettings::sectionTaken()`, the purge command refuses).

- MK-L1 (Low, Appendix CT): with the Resend fields provider-dependent, a new or rotated Resend key could go live in the
  same save without being checked, and a wrong key stops every mail (password resets included). The save is now
  refused unless Resend accepts the key.

## [1.2.0] - 2026-11-02

DB-only mail settings: bootstrap.mailer_dsn / bootstrap.resend_api_key feed nowo:instance-settings:seed-from-fallbacks (same DSN checks as an admin save), missing-field notices. Requires InstanceSettings ^1.2. See [UPGRADING](https://github.com/nowo-tech/MailKitBundle/blob/v1.2.0/docs/UPGRADING.md#to-120).

### Added

- DB-only mode: config keys `bootstrap.mailer_dsn` and `bootstrap.resend_api_key` (default null, read only by the
  seed); the `mailer` and `resend` sections implement InstanceSettings 1.2 `SectionFallbackProviderInterface` over
  them, so `nowo:instance-settings:seed-from-fallbacks` copies them once into the encrypted secrets. See
  [UPGRADING](UPGRADING.md#to-120).
- Missing settings report: `MailSettings::missingFields()`, `OutboundMailReadiness::missingFields()` /
  `missingFieldsOf()`, `MailStatus::$missingFields`; the `mailer` / `resend` settings pages show a warning naming the
  empty required fields (`settings.notice_missing`, 7 locales).

### Changed

- Requires `nowo-tech/instance-settings-bundle` ^1.2 (was ^1.1).

### Fixed

- Release notes link UPGRADING with an absolute URL: `release.yml` rewrites relative `UPGRADING.md` links to the
  tagged `docs/UPGRADING.md` blob, as InstanceSettings, SmsKit and MessengerMonitorKit already do; relative links broke
  on the GitHub release page. Existing releases are unchanged.

## [1.1.1] - 2026-11-01

CSV export: body batches by byte budget, max_rows capped at 100000, controller-kit ^2.3.1 (formula guard after separators); demo install race fixed See [UPGRADING](UPGRADING.md#to-111).

### Security

- Formula injection through an embedded list separator (CA delta 1.1.0 D1, Low): `nowo-tech/controller-kit-bundle`
  raised to `^2.3.1`, whose `CsvSafeWriter` also puts a space before a formula trigger that follows `;`, `,`, tab,
  `|` or a line break inside a cell (Excel in `;` list-separator locales split `Hi;=cmd|…` into an evaluated cell).
  The CSV and JSON responses also send `X-Content-Type-Options: nosniff`. Regression test reads the export with `;`.
- Memory of the export with bodies (CA delta 1.1.0 D2, Low, opt-in): bodies were loaded 100 rows per query, up to
  ~200 MiB with 1 MiB text and HTML parts. They are now loaded in chunks of about `web_ui.export.body_batch_bytes`
  (new, default 8 MiB, 1 MiB … 64 MiB), sized first with a `LENGTH()` query (`SentMailRepository::bodySizes()`); a
  single row over the budget is fetched alone. New `SentMailCsvExporter::chunkBySize()` / `BODY_BATCH_BYTES`.
- `web_ui.export.max_rows` is capped at 100 000 (was 1 000 000; `SentMailCsvExporter::MAX_ROWS_LIMIT`): `pdo_mysql`
  buffers the whole result in the client, so memory grows with the row cap (CA delta 1.1.0 I-D2).
- `docs/SECURITY.md` no longer overstates the CSV guarantee (best effort for common spreadsheets), and documents the
  MySQL buffered-result cost and the partial file on a mid-stream error.

### Changed

- `nowo-tech/ui-kit-bundle` raised to `^2.21` (bundle and demo).
- Root `docker-compose.yml`: optional MySQL 8.4 `db` service (`db` profile, tmpfs data); the root image gets
  `pdo_mysql`. `make test-with-db` runs the Integration suite on SQLite, then `tests/Integration/Log` on that service
  with `MAIL_KIT_TEST_DATABASE_URL` exported, like the "Log on …" CI jobs; `make test-coverage-with-db` does the same
  with coverage (REQ-TEST-005).
- `specs/001-baseline`: `SentMailCsvExporter` mapped to the new FR-LOG-006 (70 files, REQ-SPECKIT-003).
- Docs: README coverage figures refreshed; the duplicate "To 1.0.0" UPGRADING section and the repeated 1.0.0 intro
  line of this changelog removed.

### Fixed

- Demo `make up` (`demo/symfony8/Makefile`) waits until the entrypoint's first `composer install` has finished (the
  server answers) before it runs its own install. The two installs raced on the shared `vendor/` and corrupted the
  downloaded archives, which turned the Playwright e2e job of the v1.1.0 tag red (REQ-DEMO-013).
- Demo lock: the bundle is pinned as `dev-main` (path repository `versions` option) instead of the feature branch
  it was last refreshed from.

## [1.1.0] - 2026-11-01

Sent-mail log CSV export (formula-injection safe, filters applied, row cap) and server-side sortable columns (UiKit 2.20 SortRequest). See [UPGRADING](UPGRADING.md#to-110).

### Added

- **CSV export of the sent-mail log** (`GET <path_prefix>/log/export`, route `nowo_mail_kit_log_export`, "Export CSV"
  action on the list): current filters and sort; columns date, status, tag, from, to, cc, bcc, subject, error
  (scrubbed), reference; translated header row. Streamed through `nowo-tech/controller-kit-bundle`
  `Http\CsvStreamedResponse` / `CsvSafeWriter` (formula-injection safe, `no-store`, attachment), capped at
  `web_ui.export.max_rows` (10 000) with a closing notice row and `X-Mail-Kit-Export-Truncated`. Same access as the
  log plus optional `web_ui.export.access_roles`; bodies only with `log.store_bodies` + `web_ui.export.include_bodies`
  + `?bodies=1`. New config `web_ui.export.{enabled, max_rows, access_roles, include_bodies}`; `SentMailCsvExporter`,
  `AccessGuard::isExportGranted()`.
- **Sortable log columns** (date, status, subject, tag, transport) on the server with UiKit 2.20 `Listing\SortRequest`
  and `Column` `sortable` / `sortFirst`: whitelist (`SentMailRepository::SORT_FIELDS`), unknown keys fall back to
  newest first, ties broken by date and id; the sort is kept by pagination, the filter form (hidden `sort` / `dir`)
  and the export link. `SentMailRepository::findFiltered()` takes an optional sort key and direction (refuses keys
  outside the whitelist); new `iterateFiltered()` / `findBodies()`.

### Changed

- Requires `nowo-tech/ui-kit-bundle` `^2.20` (was `^2.17.3`) and `nowo-tech/controller-kit-bundle` `^2.3` (new).

## [1.0.0] - 2026-10-31

First release: runtime-configured mailer on InstanceSettings (encrypted DSN), DSN/sender validation with SSRF-guarded provider endpoints, test e-mail, Resend check, privacy-first sent-mail log with retention and right-to-erasure, AuthKit readiness adapter. See [UPGRADING](UPGRADING.md#to-100-initial-release).

### Added

- **Settings sections** contributed to Instance Settings: `mailer` (DSN as encrypted secret, sender address and name,
  Reply-To, test recipient) and, with `resend.enabled`, `resend` (switch, encrypted API key, sender).
- **Runtime DSN**: `RuntimeTransport` decorates `mailer.transports` (framework mailer and Messenger
  `SendEmailMessage` handler): the DSN is resolved on every send from a `MailerDsnProviderInterface` (Instance
  Settings by default, Resend key first when active), the framework transport (`MAILER_DSN`) is the fallback, named
  transports (`X-Transport`) are kept, the built transport is cached per DSN and reset on `kernel.reset` and
  `SettingsChangedEvent` (worker safe); a refused DSN fails the send.
- **`MailerInterface` decorator** (`MailKitMailer`): runtime From / Reply-To when missing, "queued" log rows when the
  mailer dispatches to Messenger.
- **DSN validation** (`MailerDsnValidator`, `#[MailerDsn]`): scheme allow-list (SMTP + provider bridges; `sendmail`,
  `native`, `null` always refused), parse and build check, SSRF host guard (`DsnHostGuard`, OutboundUrlGuard rules,
  bounded DNS resolution, `allowed_hosts`, `allow_private_hosts`).
- **Sender rules** (`SenderAddress`, `#[DeliverableSender]`): public-domain senders only.
- **Redacted audit**: `MailerDsnAudit` (`scheme://host[:port]`), `Mail settings changed.` notices, `ErrorScrubber`
  for stored and displayed transport errors.
- **Test e-mail**: "Send a test e-mail" action on the mailer section (CSRF, MailKit + section access, per-user rate
  limit), `TestMailSender`, `nowo:mail-kit:send-test [to] [--resend]`.
- **Resend**: "Check the API key" (`ResendApiChecker`, deadline, no redirects, sending-only keys) and a Resend test
  e-mail.
- **Sent-mail log**: `SentMail` / `SentMailBody` entities (`doctrine.table_prefix`), DBAL recorder (best effort,
  never the host unit of work), bodies only with `log.store_bodies` (redacted values masked), tags and redaction
  headers (`MailHeaders`), UiKit list (filters, pagination) and detail pages, sandboxed HTML body,
  `nowo:mail-kit:log:purge` (retention, keyset batches) and `nowo:mail-kit:log:forget <email>` (erasure).
- **Readiness**: `OutboundMailReadiness::isOutboundMailReady()` / `status()`, Twig `nowo_mail_kit_outbound_mail_ready()`
  and `nowo_mail_kit_status()`, AuthKit `OutboundMailReadyCheckerInterface` adapter prepended automatically.
- **Admin UI**: opt-in `nowo_mail_kit` route loader (REQ-SF-004 rule 7), `security.access_roles` /
  `access_checker` / `allow_unauthenticated`, FormKit profile `mail_kit`, UiKit page contract (REQ-UI-003),
  translations in en, es, fr, de, it, pt, nl.
- **Fail-closed wiring**: compile errors without Instance Settings, the framework mailer, form CSRF, roles, or with
  `allow_unauthenticated` outside `kernel.debug`.
- Demo `demo/symfony8` (Symfony 8.1, FrankenPHP worker, MySQL, Mailpit) with Playwright e2e and README screenshots;
  Flex recipe; CI on PHP 8.2–8.5 × Symfony 7.4 / 8.1 with a 100% coverage gate, plus the log tests on MySQL, MariaDB
  and PostgreSQL.

### Security (independent audit, Appendix CA)

- **M1** DSN query options are part of the SSRF guard: `region` only from a per-bridge allow-list, no host-shaping
  characters in any option, and the built transport's real endpoint host must be a provider host or pass the guard.
- **M1 / L3 / I3** Transport failures become `DeliveryFailedException` (short scrubbed message + reference, no provider
  response body, no `previous`): safe in flashes, the log, the CLI and the Messenger failure transport. The scrubber
  masks the DSN credentials in use and provider key formats (`SG.`, `xkeysib-`, `key-`, `username "…"`, UUID tokens).
- **L1** `isOutboundMailReady()` / `status()` validate without DNS (no child process per anonymous login page).
- **L2** Normalised `recipient_index` column for search and erasure (no JSON matching: works on SQLite, MySQL / MariaDB
  and PostgreSQL, also for `/` and IDN addresses); `forgetAddress()` also deletes rows sent by the address and masks
  it in subjects and bodies; the CLI accepts internationalised addresses.
- **I1** The Resend key check shares the per-user rate limit. **I2** Single-label DSN hosts are refused unless
  allow-listed. **I4** Redacted values are also masked URL-encoded and base64. **I7** `#[SensitiveParameter]` on the
  validators.
- Requires `nowo-tech/instance-settings-bundle` ^1.1 (secret-field errors rendered on the section page).

### Security (re-verification follow-ups)

- **N2** SES (`ses+api`, `ses+https`) and Scaleway (`scaleway+api`) DSNs validate again: the endpoint of these bridges is
  read per bridge (`accessKey@region` → `email.<region>.amazonaws.com`, `endpoint@project`), `scaleway.com` is a provider
  host; CI installs the amazon and scaleway bridges and sends through a mocked client.
- **N1** An unreachable API endpoint (connect / TLS / DNS failure) gives the scrubbed `DeliveryFailedException`
  ("could not be reached") instead of the raw HttpClient exception.
- **R1** The recipient index separates addresses with a control character no address can contain (forgetting
  `bob@x.com` no longer touches `a|bob@x.com`). **R2** Addresses are NFC-normalised and indexed in Unicode and punycode
  forms (erasing `user@xn--bcher-kva.de` also erases `user@bücher.de`). **R3** documented.
- `verify_peer=0` / `verify_peer_name=0` are refused unless `dsn.allow_insecure_tls: true` (dev only, literal
  boolean); the SES region pattern is anchored at the end of the string; a backslash-escaping bug that refused every
  option value containing `s` is fixed.
