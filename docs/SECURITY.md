# Security

Bundle-specific security analysis. The short GitHub reporting policy lives in [`.github/SECURITY.md`](../.github/SECURITY.md).

## Table of contents

- [Scope](#scope)
- [Attack surface](#attack-surface)
- [Threat model and mitigations](#threat-model-and-mitigations)
- [Admin UI exposure and allow_unauthenticated](#admin-ui-exposure-and-allow_unauthenticated)
- [Secrets and cryptography](#secrets-and-cryptography)
- [Personal data (GDPR)](#personal-data-gdpr)
- [Logging](#logging)
- [Resource limits and timeouts](#resource-limits-and-timeouts)
- [CSRF](#csrf)
- [Dependencies and updates](#dependencies-and-updates)
- [Release security checklist (12.4.1)](#release-security-checklist-1241)
- [AI security audit (REQ-SEC-004)](#ai-security-audit-req-sec-004)
- [Residual risks and host guidance](#residual-risks-and-host-guidance)
- [Reporting a vulnerability](#reporting-a-vulnerability)

## Scope

Covers the `mailer` / `resend` settings sections, DSN and sender validation (`MailerDsnValidator`, `DsnHostGuard`,
constraints), the runtime transport and mailer decorators, the test e-mail and Resend key check, the sent-mail log
(recorder, repository, entities, purge / forget commands), the admin UI (controller, filter form, access listener /
checker, route loader), readiness (Twig, AuthKit adapter) and the configuration tree. It does **not** cover the host
firewall, Instance Settings' own storage, encryption and settings UI (see its SECURITY.md), the Symfony mailer
transports and provider bridges, or the content of the messages your application sends.

## Attack surface

| Input | Where | Notes |
| --- | --- | --- |
| DSN, sender, API key fields | Instance Settings section form / import (session, admin) | Validated by the field constraints on save **and** import; secrets encrypted at rest by Instance Settings. |
| Admin HTTP `GET` (session) | `…/log`, `…/log/{id}`, `…/log/{id}/body` | Access checker on every request under the prefix; UUID route requirement; `no-store`. |
| Admin HTTP `POST` (session) | `…/actions/test`, `…/actions/resend/check`, `…/actions/resend/test` | CSRF-only Form, MailKit access **and** Instance Settings section access, per-user rate limit for test e-mails. |
| Outbound SMTP / provider API | `RuntimeTransport` | Only to validated DSNs (scheme allow-list, host guard); framework `MAILER_DSN` fallback trusted. |
| Outbound HTTPS | `ResendApiChecker` → `https://api.resend.com/domains` | Fixed URL, deadline, no redirects. |
| DNS | `DsnHostGuard` | Bounded child-process lookup (`dns_timeout`). |
| Message content | `SentMailRecorder` | Envelope always, bodies only with `log.store_bodies`; internal headers stripped before delivery. |
| CLI | `nowo:mail-kit:log:purge`, `…:log:forget`, `…:send-test` | Operator input (no session). |
| Configuration | `nowo_mail_kit.*` | Validated at compile time; switches must be literal booleans. |

## Threat model and mitigations

| Threat | Mitigation |
| --- | --- |
| **SSRF through the DSN** (an admin points SMTP at an internal service or cloud metadata) | Host guard on every stored / provided DSN: cloud-metadata names and addresses always refused; `localhost`, `*.local`, `*.localhost`, `*.internal`, loopback, RFC 1918, CGNAT, link-local, unique-local, documentation and reserved ranges refused unless `allow_private_hosts` / `allowed_hosts`; host names resolved (A / AAAA) and refused when any answer is private or nothing resolves; IPv4 spellings normalised (OutboundUrlGuard `PrivateNetworkTarget`). Validation runs on save, on import, and before the transport is built at send time. |
| **Host command execution** (`sendmail://…?command=`, `native://`) | `sendmail`, `sendmail+smtp` and `native` are never accepted (not in the default list, refused in `allowed_schemes` at compile time). |
| **Silent mail loss** (`null://`) | `null` refused in the settings; a refused stored DSN fails the send (no silent fallback). |
| **Credential disclosure** | DSN and API key are Instance Settings secrets (encrypted, never rendered back, sealed in exports); MailKit never logs or renders them: `MailerDsnAudit` labels (`scheme://host[:port]`), `[redacted]` invalid values in violations, `#[SensitiveParameter]` on DSN / key parameters, transport errors scrubbed (`user:pass@`, `re_…` keys, `Bearer …`) before they are stored, flashed or printed. |
| **Unauthorized access** | UI private by default (`ROLE_ADMIN`); empty role lists refused at compile time (pattern P1); a listener guards every request under the prefix and every bundle controller wherever it is mounted; actions also require the Instance Settings access of their section (denied when that checker is absent); the token is trusted only when a firewall matched the main request. |
| **CSRF** | Every POST is a FormKit CSRF-only form (`csrf_only[_token]`, one token id per action); not submitted or invalid → 403. The container refuses to compile the UI without form CSRF or a token manager (pattern P2). |
| **Mail bombing / quota burn through the test action** | Per-user sliding-window rate limit (`test_email.rate_limit` per `rate_interval`, client address for anonymous demo access); recipient is the configured test recipient or the user's own address (no free-form recipient in the UI). |
| **Stored XSS through logged content** | Subjects, addresses, tags and errors are escaped by Twig; the text body is escaped in a `<pre>`; the HTML body is never inlined in the admin page — it is served alone with `Content-Security-Policy: default-src 'none'; img-src data:; style-src 'unsafe-inline'; base-uri 'none'; form-action 'none'; frame-ancestors 'self'; sandbox`, `X-Content-Type-Options: nosniff`, `Referrer-Policy: no-referrer`, `no-store`. |
| **Injection** | ORM QueryBuilder / DBAL with bound parameters; LIKE patterns escaped (`!` escape); table names come from the configured prefix (validated `[A-Za-z0-9_]*`). |
| **Log write breaking delivery / host transactions** | Writes go through DBAL (never the host unit of work) and are best effort (warning on failure). |
| **Accidental route exposure** | The controller is not autoconfigured; routes come only from the `nowo_mail_kit` loader, groups switched off are not loaded (REQ-SF-004 rule 7, integration test). |
| **Demo configuration in production** | `security.allow_unauthenticated: true` is refused unless `kernel.debug` is true. The demo publishes its ports on `127.0.0.1` only and sends to Mailpit. |
| **Cross-request state leaks (FrankenPHP worker)** | Only the transport for the last DSN is kept, reset on `kernel.reset` and on settings changes; see [FRANKENPHP-WORKER-AUDIT.md](FRANKENPHP-WORKER-AUDIT.md). |

## Admin UI exposure and allow_unauthenticated

The log shows who received what: keep `web_ui.path_prefix` behind the firewall (`access_control`) **and** the bundle
checker. `security.allow_unauthenticated: true` is a demo / local switch only; the container refuses it without
`kernel.debug`, but never set it in a shared or staging environment either. Restrict the mail sections in Instance
Settings (`nowo_instance_settings.security.area_roles: { mailer: [ROLE_SUPER_ADMIN], resend: [ROLE_SUPER_ADMIN] }`)
when not every admin may change the transport.

## Secrets and cryptography

- `mailer.dsn` and `resend.api_key` are Instance Settings secret fields: encrypted at rest with DoctrineEncryptBundle,
  never sent back to the browser, exported only sealed. MailKit adds no cryptography of its own.
- The decrypted DSN lives in memory only while a transport is built and in the cached transport object.
- Fixtures and the demo use obvious placeholders (`REDACTED-TEST`, `REDACTED-DEMO`); the demo key is generated at
  start and ignored by git.

## Personal data (GDPR)

- The sent-mail log stores recipients' addresses and subjects (needed to answer "was it sent?"). Bodies are **off by
  default** (`log.store_bodies: false`); when on, use `MailHeaders::redact()` for one-time links and codes.
- Retention: `nowo:mail-kit:log:purge` (default `log.retention_days: 30`) — schedule it.
- Erasure: `nowo:mail-kit:log:forget <email>` / `SentMailRecorder::forgetRecipient()` deletes every row (and body)
  addressed to a person; call it from your account-deletion flow.
- `log.enabled: false` disables the log entirely.

## Logging

- `notice` "Mail settings changed." — section, field names, origin, redacted new transport, readiness.
- `warning` "Mail delivery failed." — redacted transport label and exception class (the message goes to the log row,
  scrubbed).
- `info` / `warning` test e-mail, Resend check (domain count / HTTP status), purge (days, count), erasure (count only).
- `warning` "Sent-mail log insert / update failed." — exception class only.
- Never logged: DSNs, passwords, API keys, message bodies, recipient addresses.

## Resource limits and timeouts

- DNS lookup per DSN host: `dsn.dns_timeout` (2 s), once per DSN per process (memoized).
- Resend key check: `resend.api_timeout` (10 s connect + total), `max_redirects: 0`.
- SMTP / provider sends: Symfony transport timeouts (DSN `timeout` option); keep them below PHP `max_execution_time`
  ([CONFIGURATION — Timeouts](CONFIGURATION.md#timeouts-req-runtime-001)).
- Stored bodies capped at 1 MiB each, errors at 2 000 characters, search at 120 characters, list pages at
  `web_ui.page_size` (≤ 200); purge in keyset batches.

## CSRF

Applies (session-authenticated admin mutations): FormKit CSRF-only forms on every POST action, fail-closed container
check, no `csrf_protection: false` on mutation forms (only the idempotent GET filter form has none). The CLI has no
session and no CSRF.

## Dependencies and updates

- Runtime dependencies: Symfony components (mailer, form, validator, security-csrf, rate-limiter, http-client…),
  Doctrine, Instance Settings, UiKit, FormKit, OutboundUrlGuard, Twig Extra. CI runs `composer audit --locked` on every
  matrix cell; Dependabot groups Composer, npm (demo e2e) and GitHub Actions updates.
- Security fixes are released as patch versions and noted in the changelog.

## Release security checklist (12.4.1)

| Item | How it is satisfied |
| --- | --- |
| SECURITY.md | This document + `.github/SECURITY.md`. |
| `.env` in `.gitignore` | Root and demo `.gitignore` exclude `.env`, `.env.local`, `.env.dev`, key files. |
| No secrets | Placeholders only in fixtures and demo; test DSNs built from `REDACTED-TEST`. |
| Safe config / recipe | Recipe ships `ROLE_ADMIN`, `allow_unauthenticated: false`, no bodies, the `access_control` reminder. |
| Input / output | DSN / sender constraints, UUID requirements, Twig escaping, sandboxed HTML body. |
| Dependencies | `composer audit --locked` in CI. |
| No-secret logs | See [Logging](#logging). |
| Outbound I/O | SSRF host guard, scheme allow-list, fixed Resend URL, deadlines. |
| Permissions / exposure | Opt-in routes, roles, section access for actions, firewall-only token trust, fail-closed compile checks. |
| Limits / DoS | Rate limit, pagination, capped bodies and errors. |
| AI security audit (REQ-SEC-004) | **Pending** independent audit before the first tag — see below. |

## AI security audit (REQ-SEC-004)

| Date | Revision | Auditor | Grade | Findings |
| --- | --- | --- | --- | --- |
| — | branch `feat/1.0.0` | Pending (independent auditor, not self-graded) | — | — |

The first release (1.0.0) is tagged only after an independent audit records **Pass (good)** or **Pass (conditional)**
in the monorepo `BUNDLES_SECURITY_ANALYSIS.md`.

## Residual risks and host guidance

- **DNS rebinding / TOCTOU.** The host is resolved when the DSN is validated; the transport resolves it again when it
  connects. A host that changes its answer after validation could still reach a private address. Use IP-literal or
  provider DSNs for strict environments, or an egress firewall.
- **`allowed_hosts` / `allow_private_hosts`** turn the guard off for those hosts: list only relays you operate.
- **Admins choose the transport.** A user who may edit the `mailer` section can route all mail (including password
  resets) through a server they control. Restrict the section with Instance Settings `area_roles` and audit the
  `Mail settings changed.` notices.
- **Framework fallback.** `MAILER_DSN` is not validated (ops configuration).
- **Recipients in the log** are personal data even without bodies: protect the UI, purge, honour erasure requests.
- **Demo tooling.** The Playwright screenshot converter (`sharp`, dev only, not shipped) never processes untrusted
  images.

## Reporting a vulnerability

Please follow [`.github/SECURITY.md`](../.github/SECURITY.md) — do not open a public issue.
