# Changelog

All notable changes to this project are documented here. The format follows
[Keep a Changelog](https://keepachangelog.com/en/1.1.0/) and the project uses [Semantic Versioning](https://semver.org/).

## [Unreleased]

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
  Flex recipe; CI on PHP 8.2–8.5 × Symfony 7.4 / 8.1 with a 100% coverage gate.
