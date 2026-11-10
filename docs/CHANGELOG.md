# Changelog

All notable changes to this project are documented here. The format follows
[Keep a Changelog](https://keepachangelog.com/en/1.1.0/) and the project uses [Semantic Versioning](https://semver.org/).

## Table of contents

- [[Unreleased]](#unreleased)
- [[1.4.0] - 2026-11-09](#140---2026-11-09)
- [[1.3.0] - 2026-11-02](#130---2026-11-02)
- [[1.2.0] - 2026-11-02](#120---2026-11-02)
- [[1.1.1] - 2026-11-01](#111---2026-11-01)
- [[1.1.0] - 2026-11-01](#110---2026-11-01)
- [[1.0.0] - 2026-10-31](#100---2026-10-31)

## [Unreleased]

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
- A raw (non-MIME) message sent with the log enabled no longer reads an unset `SentMessage` Message-ID (the provider
  message id is null for it).

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
