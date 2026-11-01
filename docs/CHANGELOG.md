# Changelog

All notable changes to this project are documented here. The format follows
[Keep a Changelog](https://keepachangelog.com/en/1.1.0/) and the project uses [Semantic Versioning](https://semver.org/).

## [Unreleased]

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

First release: runtime-configured mailer on InstanceSettings (encrypted DSN), DSN/sender validation with SSRF-guarded provider endpoints, test e-mail, Resend check, privacy-first sent-mail log with retention and right-to-erasure, AuthKit readiness adapter. See [UPGRADING](UPGRADING.md#to-100).

First release (1.0.0): the runtime mailer of Symfony Beacon (kit #2, phase 1) as a reusable bundle on top of
`nowo-tech/instance-settings-bundle`.

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
