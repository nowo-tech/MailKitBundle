# Changelog

All notable changes to this project are documented here. The format follows
[Keep a Changelog](https://keepachangelog.com/en/1.1.0/) and the project uses [Semantic Versioning](https://semver.org/).

## Table of contents

- [[Unreleased]](#unreleased)
- [[1.8.0] - 2026-11-12](#180---2026-11-12)
  - [Added](#added)
  - [Security](#security)
  - [Tests](#tests)
- [[1.7.1] - 2026-11-11](#171---2026-11-11)
  - [Fixed](#fixed)
  - [Tests](#tests-1)
  - [Documentation](#documentation)
- [[1.7.0] - 2026-11-11](#170---2026-11-11)
  - [Added](#added-1)
  - [Changed](#changed)
- [[1.6.0] - 2026-11-11](#160---2026-11-11)
  - [Added](#added-2)
  - [Changed](#changed-1)
- [[1.5.0] - 2026-11-10](#150---2026-11-10)
  - [Added](#added-3)
  - [Changed](#changed-2)
- [[1.4.0] - 2026-11-09](#140---2026-11-09)
  - [Added](#added-4)
  - [Security](#security-1)
- [[1.3.0] - 2026-11-02](#130---2026-11-02)
  - [Added](#added-5)
  - [Changed](#changed-3)
  - [Security](#security-2)
- [[1.2.0] - 2026-11-02](#120---2026-11-02)
  - [Added](#added-6)
  - [Changed](#changed-4)
  - [Fixed](#fixed-1)
- [[1.1.1] - 2026-11-01](#111---2026-11-01)
  - [Security](#security-3)
  - [Changed](#changed-5)
  - [Fixed](#fixed-2)
- [[1.1.0] - 2026-11-01](#110---2026-11-01)
  - [Added](#added-7)
  - [Changed](#changed-6)
- [[1.0.0] - 2026-10-31](#100---2026-10-31)
  - [Added](#added-8)
  - [Security (independent audit, Appendix CA)](#security-independent-audit-appendix-ca)
  - [Security (re-verification follow-ups)](#security-re-verification-follow-ups)

## [Unreleased]

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
