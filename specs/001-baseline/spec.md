# Feature Specification: MailKitBundle baseline (100% code coverage)

**Feature Branch**: `001-baseline`  
**Created**: 2026-10-31  
**Status**: Active  

**Package**: `nowo-tech/mail-kit-bundle`  
**Configuration root**: `nowo_mail_kit`  
**Code inventory**: [`code-inventory.md`](code-inventory.md)  
**Integrator spec**: [`docs/SPEC-DRIVEN-DEVELOPMENT.md`](../../docs/SPEC-DRIVEN-DEVELOPMENT.md)

---

## Summary

The bundle makes the Symfony mailer runtime-configurable on top of Instance Settings: it contributes the `mailer` (and
optional `resend`) settings sections, resolves the DSN on every send (framework transport as fallback, also for
Messenger), validates DSNs (scheme allow-list, SSRF-safe hosts) and senders, audits changes without secrets, offers a
test e-mail and a Resend key check, keeps a privacy-aware sent-mail log with retention and erasure, and exposes
`isOutboundMailReady()` (Twig, AuthKit adapter). Opt-in features add branded layouts, Microsoft Graph sending and
inbound mail for shared mailboxes, open and click tracking, and (2.5.0) delegated Outlook mailboxes: sync into the
host's sink and sending with a mandatory idempotency key. It is safe in FrankenPHP worker mode.

---

## User Scenarios

### US-1 — Runtime DSN with fallback (P1)

**Given** no DSN stored, **When** code sends through `MailerInterface`, **Then** the framework transport is used; **Given**
`mailer.dsn` stored, **Then** the next send (sync or Messenger worker) uses it without a deploy; a message with an
`X-Transport` header keeps its named transport; the transport is rebuilt when the DSN changes or on `kernel.reset`.

### US-2 — Validated DSN (P1)

**Given** an admin saves a DSN, **When** its scheme is not allowed (always for sendmail / native / null), it does not
parse, no factory supports it, or its host is private / local / reserved / cloud metadata / unresolvable, **Then** the
save is refused with a translated error and nothing is stored; a DSN refused at send time fails the send with the
error key (no silent fallback) and is logged as failed.

### US-3 — Sender (P1)

**Given** `mailer.from` / `from_name` / `reply_to`, **When** a message has no From / Reply-To, **Then** they are added
(Resend sender while Resend is active); messages with their own sender keep it; senders on non-public domains are
refused on save.

### US-4 — Test e-mail (P1)

**Given** an admin on the mailer section, **When** they click "Send a test e-mail" with a valid CSRF token, MailKit
access and the section access, **Then** a message is sent synchronously to the test recipient (or their own address)
and the flash shows success or the scrubbed transport error; a forged token, a user without access or the rate limit
stop it; the CLI does the same.

### US-5 — Resend (P2)

**Given** `resend.enabled`, **When** the admin checks the key, **Then** `GET /domains` (deadline, no redirect) reports
the domains, a sending-only key, a rejection or a timeout; the Resend test e-mail uses the stored key.

### US-6 — Sent-mail log (P1)

**Given** the log enabled, **When** messages are sent, **Then** each gets one row (queued → sent / failed, attempts,
provider id, size, tag, scrubbed error), bodies only with `store_bodies` and redacted values masked; the admin list
filters by search / status / period with pagination; the detail shows the envelope and (escaped) text body; the HTML
body is served alone in a sandbox; the purge deletes old rows in batches and `forget` deletes a recipient's rows; a
database error never breaks a delivery.

### US-7 — Readiness and AuthKit (P2)

**Given** a valid stored DSN, **Then** `isOutboundMailReady()` is true (with nothing stored only when
`fallback_counts_as_ready`); Twig exposes it and the redacted status; with AuthKit installed its mail-ready checker is
MailKit's.

### US-8 — Secure wiring (P1)

**Given** an unsafe or incomplete setup (no Instance Settings, no framework mailer, UI without SecurityBundle or form
CSRF, empty roles, demo switch outside debug, forbidden scheme, env placeholder switch), **Then** the container refuses
to compile with a message naming the fix; routes exist only through the `nowo_mail_kit` loader.

### US-9 — Delegated Outlook mailbox (P1, 2.5.0)

**Given** an active delegated mailbox following Inbox, **When** a message arrives, **Then** the host's sink receives it
once as a `SyncedMessage` (later deliveries answer `Duplicate`); **When** the host sends a reply twice with the same
idempotency key, **Then** Graph delivers it once, the second call answers `replayed: true` and the sent copy reaches the
sink. Details: [spec 005](../005-delegated-mailboxes/spec.md).

---

## Functional requirements

### Bundle and dependency injection

- **FR-BUNDLE-001** — The bundle class registers the extension (alias `nowo_mail_kit`) and the compiler passes.
- **FR-CFG-001** — Strict configuration tree with safe defaults (see CONFIGURATION.md).
- **FR-CFG-002** — The extension registers services explicitly, prepends the Doctrine mapping, the FormKit profile
  `mail_kit` and the AuthKit checker, and refuses env placeholders for switches.
- **FR-CFG-003** — `MailerIntegrationPass` requires Instance Settings and the framework mailer and detects the bus.
- **FR-CFG-004** — Fail-closed passes: CSRF for the UI, `allow_unauthenticated` only in debug.
- **FR-TWIG-001** — Twig namespace `@NowoMailKitBundle` (with the `templates/bundles` override path).

### Settings

- **FR-SET-001** — `mailer` section: dsn (secret, `#[MailerDsn]`), from (`#[DeliverableSender]`), from_name, reply_to,
  test_recipient; "Send a test e-mail" action.
- **FR-SET-002** — `resend` section (opt-in): enabled, api_key (secret), from; key check and test actions.
- **FR-SET-003** — `MailSettings` typed view; Resend first when active.

### DSN and sender

- **FR-DSN-001** — `MailerDsnProviderInterface` (default: settings); `RuntimeDsn`, `DsnSource`.
- **FR-DSN-002** — `MailerDsnValidator`: scheme allow-list, parse, host guard, factory check; error keys.
- **FR-DSN-003** — `DsnHostGuard`: allowed hosts, metadata, private / local names and IPs, bounded DNS.
- **FR-DSN-004** — `MailerDsnAudit`: redacted scheme / host / port, label, scheme.
- **FR-DSN-005** — Constraints `#[MailerDsn]`, `#[DeliverableSender]` in the bundle domain, value redacted.
- **FR-SND-001** — `SenderAddress` public-domain rule; `SenderPolicy` fills missing From / Reply-To.

### Delivery

- **FR-MAIL-001** — `RuntimeTransport` decorates `mailer.transports`: provider DSN or fallback, named transports,
  validation before build, cache per DSN, reset, internal headers stripped, outcome recorded.
- **FR-MAIL-002** — `MailKitMailer` decorates `mailer.mailer`: sender, queued row with the bus.
- **FR-MAIL-003** — `MailHeaders`: tag, redact, value, strip.
- **FR-MAIL-004** — `DeliveryFailedException`: failures rethrown with a scrubbed message and a reference, never the provider body or credentials.

### Test e-mail and Resend

- **FR-TEST-001** — `TestMailSender`: recipient resolution, sender checks, synchronous send, outcomes.
- **FR-TEST-002** — `TestMailGuard` rate limit; `TestMailOutcome` / `TestMailResult`; CLI `nowo:mail-kit:send-test`.
- **FR-RES-001** — `ResendApiChecker` (deadline, no redirect, domains, sending-only keys, scrubbed errors).

### Sent-mail log

- **FR-LOG-001** — Entities `SentMail` / `SentMailBody` (read-only mapping, table prefix).
- **FR-LOG-002** — `SentMailRecorder`: queued / sent / failed, bodies with redaction, best effort, purge, forget.
- **FR-LOG-003** — `SentMailRepository` + `SentMailFilters`: filtered count / page, find.
- **FR-LOG-004** — `ErrorScrubber`; `SentMailStatus`.
- **FR-LOG-005** — CLI `nowo:mail-kit:log:purge`, `nowo:mail-kit:log:forget`.
- **FR-LOG-006** — `SentMailCsvExporter`: streamed CSV export of the filtered, sorted log through ControllerKit's formula-injection-safe writer; `max_rows` cap with a notice row; optional bodies loaded by byte budget.
- **FR-ORM-001** — `TablePrefixListener` prefixes the bundle tables and indexes only.

### Readiness

- **FR-RDY-001** — `OutboundMailReadiness` / `MailStatus`; Twig functions; AuthKit adapter.
- **FR-RDY-002** — `SettingsChangedListener` resets the transport and audits (redacted) on mail section changes.

### Admin UI

- **FR-UI-001** — `MailKitRouteLoader` (opt-in, groups by config).
- **FR-UI-002** — `MailKitController`: log list / show / body (no-store, sandbox), actions (CSRF, access, rate limit).
- **FR-UI-003** — `AdminPages` / `MailKitPages` / `SettingsActions` / `SentMailFilterType` (UiKit page contract,
  FormKit), templates `log/index`, `log/show`, translations (7 locales).
- **FR-UI-004** — Access: `MailKitAccessCheckerInterface`, role / allow-all checkers, `AccessGuard` (firewall-aware,
  section access), `MailKitAccessListener`.


### Events and personal data

- **FR-EVT-001** — `MailDeliveredEvent` / `MailDeliveryFailedEvent`: scalar delivery outcome events dispatched after the
  transport answered (no entity, no Mime object).
- **FR-GDPR-001** — `SentMailErasureInterface` (null default) and `SentMailPersonalDataEraserInterface`: erase or redact
  log rows of a subject in batches; `SentMailErasureResult`.
- **FR-GDPR-002** — `SentMailPersonalDataSubject` (refuses unusable ids), subject resolver port with the default
  resolver; `SentMailPersonalDataErasedEvent` after the erasure committed.
- **FR-GDPR-003** — `SentMailPersonalDataExporterInterface` (null default) and the optional UserKit eraser / export
  providers (`Integration/UserKit`).
- **FR-SEC-001** — `ExactCollation`: binary NO PAD collation on identifier and key columns of the bundle tables (log,
  Graph inbound, tracking), with a logged fallback when the server version has no patch number (REQ-SEC-006).
- **FR-WRK-001** — `LegacyResendSettingsResetListener` forgets the legacy `resend` memo at request, Messenger and
  console boundaries (worker mode without services resetter, REQ-RUNTIME-002).

### Concurrency (1.13, REQ-RUNTIME-003)

- **FR-CONC-001** — `StorageConflicts` classifies a storage conflict of the bundle's writes on top of transaction-doctrine (deadlock, lock wait timeout, serialization failure, a transient runner failure, a lost savepoint of unknown outcome; unique violations and lost connections are never conflicts). `MailKitConflictListener` (priority -64, stateless) answers a conflict on the bundle's own routes (admin pages and actions, Graph webhook, tracking pixel) with 409 (or 503, `conflict_response.status`) and `X-Conflict-Code`: `CONFLICT_001` with `Retry-After` when nothing was committed, `CONFLICT_002` without it when part of the request was. Host routes are never answered.

### Branded e-mail

- **FR-BRAND-001** — `BrandedEmailHelper`, `BrandedEmailImage`, `BrandedEmailPart`, `BrandedEmailText` and the
  `email/branded_layout` templates (details: [spec 004](../004-branded-email/spec.md)).
- **FR-BRAND-002** — `MailKitBrandTwigExtension`: plain-text and colour helpers of the branded layouts.

### Microsoft Graph sending (1.8)

- **FR-GRS-001** — Mailbox port `GraphMailboxProviderInterface` (configured profiles by default), `GraphMailbox`,
  `GraphSecret` (never printed), `GraphTokenProvider` (PSR-6 cached app-only tokens), `GraphApiClient` (deadline,
  allowed upload hosts), `GraphException`.
- **FR-GRS-002** — `GraphMailSender`: value objects in (`GraphOutgoingMail`, `GraphRecipient`, `GraphAttachment`,
  `PlusAddress`, `AttachmentFilename`), Graph id and internetMessageId out (`GraphSendResult`), limits
  (`GraphSendLimits`), upload sessions from 2.9 MB.
- **FR-GRS-003** — `DeleteGraphDraft` + handler: delayed cleanup of an abandoned draft.
- **FR-GRS-004** — `microsoft+graph://<profile>` mailer transport (`GraphTransportFactory`, `GraphTransport`,
  `GraphUnrecoverableTransportException`).

### Microsoft Graph inbound (1.9)

- **FR-GRI-001** — Webhook (`GraphWebhookController`, opt-in `GraphWebhookRouteLoader`): validation echo, constant-time
  client state, caps, rate limit, enqueue only.
- **FR-GRI-002** — `GraphSubscriptionManager` (create / renew) and `GraphDeltaSweeper` (delta sweep).
- **FR-GRI-003** — `GraphMessageNormalizer` to `GraphInboundMessage`: addresses, threading ids, sanitised HTML
  (`InboundHtmlSanitizer`), finfo-typed capped attachments (`InboundFileTypes`, `InboundLimits`), SPF / DKIM / DMARC
  (`AuthenticationResults`).
- **FR-GRI-004** — `GraphInboundProcessor` hands each message once to the host port `GraphInboundHandlerInterface`
  (`InboundDecision`, `InboundAction`); profiles port with the configured default; Messenger messages and handler.
- **FR-GRI-005** — CLI `nowo:mail-kit:graph:schema`, `:subscriptions`, `:sweep`.
- **FR-GRI-006** — `GraphInboundStore` (dedupe by internetMessageId, delta links) and its schema listener.

### Open and click tracking (1.10)

- **FR-TRK-001** — `EmailTracker` with the policy port, recipient resolver port (default resolver), per-send opaque ids
  (`TrackingIds`, `TrackedEmail`, `TrackingCandidate`, `TrackingDecision`, `TrackingBaseUrl`).
- **FR-TRK-002** — Link rewriting through the host short-link port (`HtmlLinkTracker`, `TrackedLink`, link filter,
  `CredentialLinkDetector`; null rewriter by default); credential links are never tracked.
- **FR-TRK-003** — Signed pixel (`TrackingTokenSigner`, `TrackingPixelController`, `TrackingRouteLoader`), open
  recording through Messenger (`RecordMailOpen` + handler, `OpenRecorder`, `OpenClassifier` human / machine).
- **FR-TRK-004** — `MailOpenedEvent` / `MailMachineOpenedEvent` with the send id.
- **FR-TRK-005** — Reader port `EmailTrackingReaderInterface` and `OpenStats`.
- **FR-TRK-006** — `TrackingStore` + schema listener; CLI `nowo:mail-kit:tracking:schema`, `:purge` (retention).
- **FR-TRK-007** — GDPR export / erasure of tracking data (`EmailTrackingPersonalDataInterface`, UserKit providers).

### Delegated mailboxes (2.5.0, [spec 005](../005-delegated-mailboxes/spec.md))

- **FR-MBX-001** — Host ports (`MailboxTokenProviderInterface`, `MailboxDirectoryInterface`, `MailboxMessageSinkInterface`,
  optional `MailboxExclusionPolicyInterface`, `MailboxCallObserverInterface`, `MailboxMessageIndexInterface`; defaults
  `KeepAllExclusionPolicy`, `NullMailboxCallObserver`) and the services `MailboxSyncInterface` and
  `MailboxAttachmentReaderInterface`; bundle value objects only (REQ-ARCH-001 rule 3).
- **FR-MBX-002** — `MailboxSyncEngine`: start / pause / resume / sweep / status / disconnect, per-folder lease with
  `rerun`, cursor compare-and-set, resync, account check on every run, grant-failure pause with one event, on-demand
  attachments (`MailboxAttachmentReader`), per-operation `MailboxSession` (worker mode).
- **FR-MBX-003** — Value objects of the mailbox layer (`MailboxId`, `MailboxSettings`, `FolderRef`, `MailboxAccessToken`
  (redacted, not serialisable), `SyncedMessage`, `ThreadKey`, `ProviderCall`, …).
- **FR-MBX-004** — Microsoft Graph provider: `GraphMailboxGateway` (`/me` paths only, delta-link guard, failure
  mapping), `DelegatedGraphCredential`, the credential / request-option abstractions of `GraphApiClient`, the optional
  OutboundUrlGuard pin (`MailboxUrlGuardPass`).
- **FR-MBX-005** — `MailHtmlSanitizer` with `RemoteImages` modes (`blocked` by default).
- **FR-MBX-006** — Webhook (`MailboxGraphWebhookController`, `MailboxWebhookRouteLoader`, shared
  `AbstractGraphWebhookController`): validation echo, hashed client state, enqueue only.
- **FR-MBX-007** — Messenger messages and handler (bounded retries) and the CLI `nowo:mail-kit:mailbox:schema`,
  `:subscriptions`, `:sweep`, `:status`, `:purge`.
- **FR-MBX-008** — `MailboxStore` (mailbox, cursor, subscription and send tables; conditional transitions, savepoints)
  and its schema listener.
- **FR-MBX-009** — Send: `MailboxSenderInterface` / `MailboxSender` (draft + send, mandatory idempotency key, the
  `SendState` machine with fenced submits, reconcile by draft state, sent copy), `OutgoingMailboxMail`,
  `OutgoingAttachment`, `OutgoingKind`, `SentMessage`, the send exceptions, `DeliverSentCopy`, `ReconcileMailboxSend`,
  the purge command and the build-time transaction warning.
- **FR-MBX-010** — Events (`MailboxGrantFailedEvent`, `MailboxResyncedEvent`, `MailboxFolderSyncFailedEvent`) and the
  exceptions of the mailbox layer.

---

## Success criteria

- 100% statement and element coverage of `src/` (CI gate), PHPStan max with empty `ignoreErrors`, Igor clean.
- Every file of `src/` listed in [`code-inventory.md`](code-inventory.md): **282 / 282** mapped (2.5.0, audited 2026-11-24).
