# Configuration

## Table of contents

- [Full reference](#full-reference)
- [mailer](#mailer)
- [dsn](#dsn)
- [sender](#sender)
- [resend](#resend)
- [bootstrap](#bootstrap)
- [test_email](#test_email)
- [log](#log)
- [personal_data](#personal_data)
- [brand](#brand)
- [graph](#graph)
  - [graph.inbound](#graphinbound)
- [doctrine](#doctrine)
- [web_ui](#web_ui)
  - [Layout and CSS stack (UiKit)](#layout-and-css-stack-uikit)
- [security](#security)
- [FormKit profile](#formkit-profile)
- [Instance Settings](#instance-settings)
- [Timeouts (REQ-RUNTIME-001)](#timeouts-req-runtime-001)
- [Profiles](#profiles)
- [Container parameters and services](#container-parameters-and-services)
- [Fail-closed checks](#fail-closed-checks)

## Full reference

```yaml
# config/packages/nowo_mail_kit.yaml (defaults shown)
nowo_mail_kit:
    mailer:
        decorate: true                 # decorate mailer.mailer + mailer.transports (runtime DSN on every send)
        dsn_provider: null             # service id implementing MailerDsnProviderInterface (null = Instance Settings)
        sender_provider: null          # service id implementing SenderProviderInterface (null = Instance Settings, 1.5)
        fallback_counts_as_ready: false # MAILER_DSN counts for isOutboundMailReady() when nothing is stored
    dsn:
        allowed_schemes: [smtp, smtps, mailgun, mailgun+api, …, scaleway+smtp]   # sendmail / native / null refused
        allowed_hosts: []              # hosts accepted even when private ("mailpit", "relay.corp.example")
        allow_private_hosts: false     # SSRF guard off for private / local hosts (metadata stays refused)
        allow_insecure_tls: false      # DEV only: accept verify_peer=0 / verify_peer_name=0
        resolve_dns: true              # resolve host names; refuse private answers
        dns_timeout: 2.0               # seconds per lookup (0.1 … 10)
    sender:
        default_from: null             # default of mailer.from
        default_from_name: null        # default of mailer.from_name
        require_public_domain: true    # refuse .local / .localhost / single-label senders
    resend:
        enabled: false                 # adds the Resend provider to the mailer section (needs symfony/resend-mailer to send)
        api_timeout: 10.0              # seconds, key check (1 … 60)
    bootstrap:                         # 1.2: read only by nowo:instance-settings:seed-from-fallbacks
        mailer_dsn: null               # e.g. '%env(default::MAILER_DSN)%' while migrating to DB-only mode
        resend_api_key: null           # e.g. '%env(default::RESEND_API_KEY)%'
    test_email:
        rate_limit: 5                  # per user and window (1 … 100)
        rate_interval: '10 minutes'
    log:
        enabled: true
        map_entities: true             # 1.6: false (only with enabled: false) = no SentMail mapping in your EM
        store_bodies: false            # personal data: off by default
        retention_days: 30             # nowo:mail-kit:log:purge default (1 … 3650)
        purge_batch_size: 1000         # rows per DELETE (1 … 100000)
    personal_data:                     # 1.7: GDPR bridge for nowo-tech/user-kit-bundle
        enabled: false                 # opt-in: register the emails_received eraser + export section (UserKit installed, log mapped)
        subject_resolver: null         # service id implementing Log\SentMailPersonalDataSubjectResolverInterface
    brand:                             # 1.4, spec 220: optional branded e-mail layout ("auto" / "off" shorthand)
        mode: auto                     # auto = read a brand-contracts BrandProviderInterface when one exists; off = ignore it
        name: null                     # name printed without a provider (≤ 80 characters, no control / bidi characters)
        colors:                        # fallback colours without a provider (#rrggbb only)
            primary: '#2563eb'
            primary_contrast: '#ffffff'
            text: '#0f172a'
            muted: '#64748b'
            paper: '#f8fafc'
            surface: '#ffffff'
            border: '#e2e8f0'
        max_image_bytes: 524288        # largest brand image attached to one e-mail (1 KiB … 2 MiB)
        max_total_bytes: 1048576       # all brand images of one e-mail (1 KiB … 5 MiB, ≥ max_image_bytes)
        max_image_side: 4096           # longest image side in pixels, from the image header (16 … 8192)
    graph:                             # 1.8, spec 229: Microsoft Graph sending from shared mailboxes (opt-in)
        enabled: false                 # register GraphMailSender and the microsoft+graph://<profile> transport factory
        mailbox_provider: null         # service id implementing Graph\GraphMailboxProviderInterface; null = profiles below
        default_profile: default       # profile used when the caller names none
        profiles: {}                   # name => { tenant_id, client_id, client_secret, mailbox } (use %env()%)
        http_client: http_client       # keep the decorated client so HttpLog counts the calls
        token_cache: cache.app         # PSR-6 pool of the app-only tokens
        timeout: 30.0                  # idle deadline of one Graph / token call, seconds (1 … 300)
        upload_hosts: [outlook.office.com, outlook.office365.com]  # accepted upload session hosts (HTTPS)
        limits:
            max_attachments: 50        # attachments per e-mail (0 … 250)
            max_attachment_bytes: 36700160  # one attachment (≤ 150 MiB, the Graph maximum)
            max_message_bytes: 36700160     # attachments + bodies of one e-mail
        draft_cleanup:                 # 1.9: after an ambiguous send whose message is still a draft
            bus: null                  # Messenger bus of the delayed DeleteGraphDraft (null = read isDraft once more at once)
            delay_ms: 5000
        inbound:                       # 1.9: read the mailboxes (webhook + delta sweep) — opt-in
            enabled: false
            handler: null              # service id implementing Graph\Inbound\GraphInboundHandlerInterface (required)
            profiles: []               # mailbox profiles read; [] = the default profile
            profiles_provider: null    # service id implementing Graph\Inbound\GraphInboundProfilesProviderInterface
            bus: messenger.default_bus # receives FetchGraphMessage / SweepGraphMailbox / RenewGraphSubscriptions
            connection: null           # DBAL connection of the inbound tables (null = default)
            folder: inbox              # well-known folder read
            webhook:
                path: /hooks/mail-kit/v1/graph   # route of the loader type nowo_mail_kit_graph_webhook (POST)
                notification_url: null # public https URL of that route; null = no subscriptions (sweep only)
                max_body_bytes: 1048576
                max_notifications: 200
                rate_limit: 1200       # requests with a valid notification per minute, all together (429 above)
                client_rate_limit: 60  # unverified requests (validation, rejected, malformed) per minute and client IP
                allowed_ips: []        # optional source allow-list (addresses / CIDR); [] = any
            subscriptions:
                lifetime_minutes: 4320 # ≤ 10070 (Graph maximum for messages)
                renew_before_minutes: 1440
            sweep:
                initial_window: P2D    # first sweep reads back this long only
                page_size: 50
                max_pages: 20          # per run; the next run resumes
            allow_remote_images: false # keep http(s) <img> sources (tracking) in the sanitised HTML
            retention_days: 30         # idempotency records kept
            stale_claim_seconds: 900   # an unfinished claim older than this is taken over
            limits:
                max_message_bytes: 4194304
                max_attachments: 20
                max_attachment_bytes: 26214400
                max_total_attachment_bytes: 52428800
                max_html_bytes: 1048576
                allowed_types: [application/pdf, image/png, image/jpeg, image/gif, image/webp, text/plain, text/csv, application/msword, application/vnd.ms-excel, application/vnd.ms-powerpoint, 'application/vnd.openxmlformats-officedocument.*', 'application/vnd.oasis.opendocument.*']
    doctrine:
        table_prefix: ''               # 'app_' => app_mail_kit_sent_mail
        entity_manager: null           # ORM entity manager name (null = default)
    web_ui:
        enabled: true                  # false = no routes / controller / settings actions
        path_prefix: /admin/mail       # protect it with security.access_control
        layout_template: null          # null = nowo_ui_kit.layouts.admin
        templates: {}                  # log_index, log_show
        page_size: 25                  # rows per log page (5 … 200)
        export:
            enabled: true              # GET <path_prefix>/log/export (CSV of the filtered, sorted log)
            max_rows: 10000            # rows per export (1 … 100 000); longer results end with a notice row
            access_roles: []           # extra roles the export needs (any of them); [] = the log access is enough
            include_bodies: false      # offer "Export CSV with bodies" (needs log.store_bodies: true)
            body_batch_bytes: 8388608  # approximate body bytes loaded per query by the export with bodies (1 MiB … 64 MiB)
    security:
        access_roles: [ROLE_ADMIN]     # any of them; must not be empty (unless access_checker / allow_unauthenticated)
        access_checker: null           # service id implementing MailKitAccessCheckerInterface
        allow_unauthenticated: false   # DEV / DEMO only; refused unless kernel.debug is true
```

Unknown keys are rejected. Switches (`mailer.decorate`, `dsn.allow_private_hosts`, `dsn.allow_insecure_tls`, `resend.enabled`, `log.enabled`, `log.map_entities`, `log.store_bodies`, `personal_data.enabled`, `graph.enabled`, `graph.inbound.enabled`, `graph.inbound.allow_remote_images`, `web_ui.enabled`, `web_ui.export.enabled`, `web_ui.export.include_bodies`, `security.allow_unauthenticated`) must be literal booleans: `%env(bool:…)%` placeholders are refused because they would read as `true` while the container is built.

## mailer

| Key | Default | Description |
| --- | --- | --- |
| `decorate` | `true` | Registers `nowo_mail_kit.mailer` (decorates `mailer.mailer`, the `MailerInterface`) and `RuntimeTransport` as decorator of `mailer.transports`, so the framework mailer **and** the Messenger `SendEmailMessage` handler use the runtime DSN. With `false`, the framework mailer is untouched; `RuntimeTransport` (fallback = `mailer.transports`), the test e-mail, readiness and the log still work for code that uses them directly. |
| `dsn_provider` | `null` | Service id of a `MailerDsnProviderInterface`; the default reads the `mailer` settings. Since 1.6, with `sender_provider` also set, Instance Settings becomes optional ([USAGE](USAGE.md#using-it-from-a-ddd--hexagonal-application)). |
| `sender_provider` | `null` | Since 1.5. Service id of a `Sender\SenderProviderInterface` (default `From` / `Reply-To` of messages without one); the default reads the `mailer` settings (`MailSettings`). |
| `fallback_counts_as_ready` | `false` | `isOutboundMailReady()` is false while nothing is stored, because `MAILER_DSN` cannot be inspected through the framework transports. Set it to `true` when your `MAILER_DSN` is a real transport. |

## dsn

Applies to the DSNs stored in the settings and returned by a custom provider; the framework `MAILER_DSN` fallback is trusted ops configuration and not validated.

| Key | Default | Description |
| --- | --- | --- |
| `allowed_schemes` | SMTP + provider bridges | `smtp`, `smtps`, `mailgun(+api/+https/+smtp)`, `postmark(+api/+smtp)`, `sendgrid(+api/+smtp)`, `ses(+api/+https/+smtp)`, `brevo(+api/+smtp)`, `resend(+api/+smtp)`, `mailjet(+api/+smtp)`, `mailpace(+api/+smtp)`, `scaleway(+api/+smtp)`. `sendmail`, `sendmail+smtp`, `native` and `null` are refused at compile time. |
| `allowed_hosts` | `[]` | Exact host names (case-insensitive) accepted without the private / DNS checks. Single-label names (`mailpit`, `relay`) are refused unless listed here. |
| `allow_private_hosts` | `false` | Accept private, loopback, link-local and reserved hosts. Cloud-metadata hosts (`169.254.169.254`, `metadata.google.internal`, `100.100.100.200`, `fd00:ec2::254`…) stay refused. |
| `allow_insecure_tls` | `false` | DEV only. Accept `verify_peer=0` / `verify_peer_name=0` (TLS certificate checks off) in stored DSNs; otherwise `dsn.insecure_tls`. Literal boolean. |
| `resolve_dns` | `true` | Resolve host names (A / AAAA, `gethostbynamel` fallback) and refuse a name that does not resolve or resolves to any private address. The lookup runs in a bounded child process (`dns_timeout`). |
| `dns_timeout` | `2.0` | Seconds per lookup. |

Validation (query options and the built transport's endpoint included) runs on save / import and once per DSN per worker before sending (memoized with the transport). Readiness checks skip DNS. DNS rebinding after validation is a residual risk: see [SECURITY](SECURITY.md).

## sender

| Key | Default | Description |
| --- | --- | --- |
| `default_from` | `null` | Default of `mailer.from` (validated as an e-mail address). |
| `default_from_name` | `null` | Default of `mailer.from_name`. |
| `require_public_domain` | `true` | Adds `#[DeliverableSender]` to `mailer.from` / `mailer.resend_from` and checks the test e-mail sender. |

## resend

| Key | Default | Description |
| --- | --- | --- |
| `enabled` | `false` | Adds the provider choice (`mailer.provider`: `dsn` / `resend`) and the provider-dependent Resend fields (`mailer.resend_api_key`, `mailer.resend_from`) to the `mailer` section (since 1.3; before, a separate `resend` section), the Resend actions and routes (`/actions/resend/*`), `ResendApiChecker` and the save-time key check (`ResendApiKeyAccepted`). Sending needs `symfony/resend-mailer`. |
| `api_timeout` | `10.0` | Connect + total deadline of the key check (`timeout` and `max_duration` of HttpClient), also for the check during a save that puts a Resend key live. |

## bootstrap

| Key | Default | Description |
| --- | --- | --- |
| `mailer_dsn` | `null` | Copied once into the `mailer.dsn` secret by `nowo:instance-settings:seed-from-fallbacks` when none is stored; skipped when it fails the DSN validation. Never used to send. |
| `resend_api_key` | `null` | Copied once into the `mailer.resend_api_key` secret (with `resend.enabled: true`; `resend.api_key` before 1.3). The provider is not seeded from it (the stored 1.2 `resend` rows are: [UPGRADING](UPGRADING.md#to-130)). |

Map them to `%env(default::…)%` only while migrating to DB-only mode, then remove them; never put a literal secret
here (it would be compiled into the container). See [USAGE](USAGE.md#db-only-mode).

## test_email

| Key | Default | Description |
| --- | --- | --- |
| `rate_limit` | `5` | Test e-mails and Resend key checks per user (`getUserIdentifier()`, else the client address) per window. Sliding window over `cache.app` (limiter `nowo_mail_kit.test_email.limiter`). |
| `rate_interval` | `'10 minutes'` | Relative time (`strtotime` syntax). |

## log

| Key | Default | Description |
| --- | --- | --- |
| `enabled` | `true` | Sent-mail log writes and log pages. With `false` and Doctrine registered, the purge / forget commands and `SentMailErasureInterface` still work on rows written while the log was on (1.6). |
| `map_entities` | `true` | 1.6. Doctrine mapping of `SentMail` / `SentMailBody`. `false` is allowed only with `enabled: false` (XML-only entity managers); purge or erase the tables first, the next schema diff proposes dropping them. In 2.0 the default follows `enabled`. |
| `store_bodies` | `false` | Store the HTML / text bodies (≤ 1 MiB each, redacted values masked) and expose `GET …/log/{id}/body`. |
| `retention_days` | `30` | Default age of `nowo:mail-kit:log:purge`. |
| `purge_batch_size` | `1000` | Rows per keyset batch. |

## personal_data

Since 1.7. The ports themselves (`Log\SentMailErasureInterface`, `Log\SentMailPersonalDataEraserInterface`, `Log\SentMailPersonalDataExporterInterface`) are
always registered; this node only drives the UserKit bridge. See [USAGE — GDPR erasure and export](USAGE.md#gdpr-erasure-and-export).

| Key | Default | Description |
| --- | --- | --- |
| `enabled` | `false` | Opt-in. `true`: with `nowo-tech/user-kit-bundle` installed and the log entities mapped (`log.map_entities: true`, Doctrine registered), register the UserKit eraser `emails_received` (priority 110, before the host identity eraser) and the export section `emails_received`. Remove your own `emails_received` export provider first: UserKit throws a `LogicException` on a duplicate section key. Keep `false` when your application erases / exports the log tables itself or must retain the log. Literal boolean. |
| `subject_resolver` | `null` | Service id implementing `Log\SentMailPersonalDataSubjectResolverInterface` (the addresses of a user). `null` = the original user identifier when it is an e-mail, plus `getEmail()`. |

## brand

Since 1.4 (spec 220 S7). Used only by the optional `@NowoMailKit/email/branded_layout.html.twig` / `.txt.twig`
templates and `BrandedEmailHelper` ([USAGE — Branded e-mails](USAGE.md#branded-e-mails)); nothing changes for e-mails
that do not use them.

| Key | Default | Description |
| --- | --- | --- |
| `mode` | `auto` | `auto`: with `nowo-tech/brand-contracts` installed and a `Nowo\Brand\Contract\BrandProviderInterface` service (InstanceSettings 1.5 with `brand.enabled: true`, or the host's own), the layout takes the brand name and colours from it and the logo bytes from `BrandAssetReaderInterface`. `off`: the provider is ignored. A string `auto` / `off` is accepted as a shorthand. |
| `name` | `null` | Name printed by the layout without a provider (header, footer, logo `alt`, plain-text part). `null` prints none. Trimmed; refused when blank, longer than 80 characters or containing control, bidi or line-separator characters. |
| `colors.*` | UiKit `nowo-ui.css` defaults | Fallback colours without a provider: `primary`, `primary_contrast` (text on primary, for buttons), `text`, `muted`, `paper` (page background), `surface` (card), `border`. `#rrggbb` only, lower-cased. |
| `max_image_bytes` | `524288` | Largest brand image attached; a larger one is left out (logged). 1 KiB … 2 MiB. |
| `max_total_bytes` | `1048576` | Largest total of the brand images of one e-mail. 1 KiB … 5 MiB; must be ≥ `max_image_bytes`. |
| `max_image_side` | `4096` | Longest side, in pixels, read from the image header; a larger image is left out. 16 … 8192. |

## graph

Since 1.8 (Beacon spec 229). Opt-in: with `enabled: false` (default) no Graph service is registered and nothing
changes. Microsoft 365 setup: [MICROSOFT-GRAPH-SETUP.md](MICROSOFT-GRAPH-SETUP.md); code:
[USAGE — Microsoft Graph](USAGE.md#microsoft-graph-shared-mailbox).

| Key | Default | Description |
| --- | --- | --- |
| `enabled` | `false` | Registers `Graph\Send\GraphMailSender` (public), the token provider, the Graph client and the `microsoft+graph` mailer transport factory (`mailer.transport_factory` tag). Literal boolean. |
| `mailbox_provider` | `null` | Service id implementing `Graph\GraphMailboxProviderInterface` (tenant, client id, secret and mailbox per profile, e.g. from encrypted host settings). When set, `profiles` and `default_profile` are not used. |
| `default_profile` | `default` | Profile of `microsoft+graph://default` and of `GraphMailSender::send($mail)` without a profile. Must exist in `profiles` when no provider is set. |
| `profiles.<name>.tenant_id` | — | Entra tenant id (GUID) or verified domain. Required. |
| `profiles.<name>.client_id` | — | Application (client) id (GUID). Required. |
| `profiles.<name>.client_secret` | — | Client secret. Required. Use `%env(…)%` (resolved at runtime); never commit it. |
| `profiles.<name>.mailbox` | — | Shared mailbox address; the From of every message. Required. |
| `http_client` | `http_client` | HTTP client service. Keep the default (decorated by HttpLog when installed): the calls are counted under the outbound provider **you declare** for `graph.microsoft.com`, `login.microsoftonline.com` and the upload hosts (e.g. `microsoft_graph`). HttpLog never records bodies, query strings or headers. Every call uses `max_redirects: 0` and the timeout below. |
| `token_cache` | `cache.app` | PSR-6 pool of the access tokens, key = SHA-256 of tenant, client id and secret fingerprint; stored until 5 minutes before expiry (the expiry is also checked on read). Tokens are bearer credentials valid for about an hour: on a shared or persistent cache, use a pool with an encrypting marshaller (`SodiumMarshaller`) or `cache.adapter.array` per worker. |
| `timeout` | `30.0` | Idle deadline of one call (the total per call is 4×, the token request 2×). 1 … 300 s. |
| `upload_hosts` | `[outlook.office.com, outlook.office365.com]` | Hosts accepted in the upload session URL Graph returns (HTTPS, port 443, no user info, exact match, lower-cased). Any other URL is refused before a byte is sent (SSRF guard). |
| `limits.max_attachments` | `50` | Attachments of one e-mail (0 … 250). |
| `limits.max_attachment_bytes` | `36700160` (35 MiB) | Bytes of one attachment, at most 150 MiB (Graph). |
| `limits.max_message_bytes` | `36700160` (35 MiB) | Bytes of all attachments plus the bodies. Exchange's own message size limit (35 MB by default, `Set-Mailbox -MaxSendSize`) applies on top. |

Profile names: letters, digits, `_`, `.`, `-` (at most 64 characters). The container refuses to compile when
`enabled` is true without `mailbox_provider` and without a profile named like `default_profile`.

| Key | Default | Description |
| --- | --- | --- |
| `draft_cleanup.bus` / `draft_cleanup.delay_ms` | `null` / `5000` | 1.9: after an ambiguous send failure (timeout, 5xx, 429 / 503) whose message still reads as a draft, dispatch a delayed `Graph\Send\DeleteGraphDraft` on this bus (route it to a transport that supports delays); it deletes the message only if it is still a draft. Without a bus the state is read once more at once (a send Exchange completes after that read is still deleted: residual race). Never blocks the worker. |

### graph.inbound

Since 1.9 (Beacon spec 229 block B). Opt-in; needs `graph.enabled`, symfony/messenger and doctrine/doctrine-bundle
(DBAL), plus symfony/html-sanitizer and the PHP fileinfo extension. Usage: [USAGE — Inbound mail](USAGE.md#inbound-mail-from-a-graph-mailbox).

| Key | Default | Description |
| --- | --- | --- |
| `enabled` | `false` | Registers the webhook controller, the subscription manager, the delta sweeper, the processor, the Messenger handlers, the commands, the DBAL store and its schema listener. Literal boolean. |
| `handler` | — | Service id implementing `Graph\Inbound\GraphInboundHandlerInterface`. Required when enabled. |
| `profiles` / `profiles_provider` | `[]` / `null` | Mailbox profiles read: the list (empty = the mailbox provider's default profile), or your `GraphInboundProfilesProviderInterface` service (e.g. one mailbox per organization). |
| `bus` | `messenger.default_bus` | Bus of the fetch / sweep / renew messages. Route them to an async transport: the webhook must answer within 3 s. |
| `connection` | `null` | DBAL connection of the three inbound tables. MailKit never uses the host's entity manager for them. |
| `folder` | `inbox` | Well-known folder subscribed to and swept. |
| `webhook.path` | `/hooks/mail-kit/v1/graph` | Path of the notification route (container parameter `nowo_mail_kit.graph.webhook_path`). Import the loader (`type: nowo_mail_kit_graph_webhook`) and make the path public and stateless in the firewall. |
| `webhook.notification_url` | `null` | Absolute `https://` URL of that route as Graph reaches it (no user info, no fragment). `null`: no subscriptions, the sweep is the only reader. |
| `webhook.max_body_bytes` / `max_notifications` | `1048576` / `200` | Request caps (413); the body is read to the cap whatever the stream's chunk size. |
| `webhook.rate_limit` / `client_rate_limit` | `1200` / `60` | Per minute: requests with at least one valid notification (global bucket) / unverified requests per client IP, IPv6 per /64 (validation, malformed, rejected). Graph shares Microsoft's addresses with every tenant, so an exhausted client bucket never refuses a verifiable notification: it only narrows the request to a cheap path (the first unknown subscription ends it, else 429). At most 5 distinct rejected subscriptions are looked up per request; valid items next to them are accepted. |
| `webhook.allowed_ips` | `[]` | Optional source allow-list (addresses or CIDR, validated at compile time, e.g. Microsoft's published Graph change-notification ranges). With a reverse proxy set `framework.trusted_proxies` to **your proxies only**: trusting all addresses lets a client spoof `X-Forwarded-For`, choose its rate-limit bucket and pass this list. |
| `subscriptions.lifetime_minutes` / `renew_before_minutes` | `4320` / `1440` | Lifetime requested (≤ 10070) and renewal window. Run the renewal at least hourly. |
| `sweep.initial_window` | `P2D` | ISO 8601 duration (`PnD`, `PTnH`, `PnDTnH`) read back by the first sweep of a profile (an existing inbox is not imported). |
| `sweep.page_size` / `max_pages` | `50` / `20` | Page size (`Prefer: odata.maxpagesize`) and pages per run (the stored link resumes the next run). |
| `allow_remote_images` | `false` | Keep `http(s)` image sources in the sanitised HTML (lets senders track readers). Off: `cid:` images of the message only. Literal boolean. |
| `retention_days` | `30` | Idempotency records kept (purged after each sweep run). Keep it longer than any redelivery window. |
| `stale_claim_seconds` | `900` | A `processing` claim older than this (crashed worker) is processed again. |
| `limits.max_message_bytes` | `4194304` | Bytes of the Graph message JSON read (headers and body); a larger message fails with `too_large`. |
| `limits.max_attachments` / `max_attachment_bytes` / `max_total_attachment_bytes` | `20` / `26214400` / `52428800` | Attachments beyond these are reported as rejected (`count`, `size`, `total_size`), not read. |
| `limits.max_html_bytes` | `1048576` | HTML passed to the sanitizer (longer: cut). |
| `limits.allowed_types` | PDF, PNG, JPEG, GIF, WebP, plain text, CSV, Word / Excel / PowerPoint (legacy and OOXML), OpenDocument | Content types accepted **as detected from the bytes** (finfo), `fnmatch` patterns, lower-cased; any other type is rejected (`type`). `application/zip` is **not** in the default (owner decision 2026-11-12): add it explicitly; archives and legacy Office files are not scanned inside. Independently, dangerous extensions (`InboundFileTypes::DANGEROUS_EXTENSIONS`: `html`, `svg`, `js`, `php*`, `exe`, `bat`, `ps1`, `lnk`, macro-enabled Office…) are always rejected (`extension`). |

## doctrine

| Key | Default | Description |
| --- | --- | --- |
| `table_prefix` | `''` | Prefix of the bundle tables **and** their index names (REQ-ORM-002), applied by `TablePrefixListener` on `loadClassMetadata` to the bundle entities only. `app_` gives `app_mail_kit_sent_mail`, `app_mail_kit_sent_mail_body`, `app_idx_mail_kit_sent_mail_queued`, `app_idx_mail_kit_sent_mail_status`. The recorder (DBAL) uses the same names. |
| `entity_manager` | `null` | Entity manager of the log (resolved per call). |

## web_ui

| Key | Default | Description |
| --- | --- | --- |
| `enabled` | `true` | Controller, access listener, routes of the `nowo_mail_kit` loader and the "send a test e-mail" / Resend actions on the settings sections. With `false` the bundle needs neither SecurityBundle nor form CSRF. |
| `path_prefix` | `/admin/mail` | URL prefix of every admin route. |
| `layout_template` | `null` | Layout the pages extend; `null` uses `nowo_ui_kit.layouts.admin` (REQ-UI-003). It must render the UiKit canonical blocks. |
| `templates` | `{}` | Per-page overrides: `log_index`, `log_show` (see [TEMPLATES](TEMPLATES.md)). |
| `page_size` | `25` | Rows per log page (`per_page` query values 10, 25, 50, 100 and this one are accepted). |
| `export.enabled` | `true` | Route `nowo_mail_kit_log_export` (`GET <path_prefix>/log/export`) and the "Export CSV" action of the log list. Needs `log.enabled`. |
| `export.max_rows` | `10000` | Rows of one export at most (1 … 100 000). On MySQL / MariaDB `pdo_mysql` buffers the whole result in the client before the first row is written (a few KiB per row), so memory grows with this value. When more rows match, the file ends with a translated notice row ("Export truncated: the first N of M rows…"), the `X-Mail-Kit-Export-Truncated` response header is `1` and the list action reads "Export CSV (first N rows)". |
| `export.access_roles` | `[]` | Roles the export needs **on top of** the log access (`security.*`), any of them. Empty: whoever may open the log may export it. With roles set, anonymous access (`allow_unauthenticated`) cannot export. |
| `export.include_bodies` | `false` | Adds "Export CSV with bodies" (`?bodies=1`: text and HTML body columns). Refused at compile time unless `log.store_bodies: true`; without it `?bodies=1` is ignored. Each stored body part may be 1 MiB: the bodies are loaded in chunks of `export.body_batch_bytes`. |
| `export.body_batch_bytes` | `8388608` | Approximate body bytes (text + HTML length) loaded with one query by the export with bodies (1 MiB … 64 MiB); a row over it is loaded alone. The export then needs about this much memory plus one row on top of the request. |

Example: let support staff read the log but keep the CSV for the DPO role, capped at 5 000 rows.

```yaml
nowo_mail_kit:
    security:
        access_roles: [ROLE_SUPPORT, ROLE_ADMIN]
    web_ui:
        export:
            max_rows: 5000
            access_roles: [ROLE_DPO]
```

### Layout and CSS stack (UiKit)

The pages are UiKit scaffolds: the CSS stack, icon set and theme are configured **once** for every Nowo bundle in `nowo_ui_kit` (REQ-UI-003), not per bundle:

```yaml
nowo_ui_kit:
    css_framework: bootstrap5          # bootstrap5 | tailwind | foundation | custom | none …
    layouts:
        admin: 'admin/layout.html.twig'
```

Bundle pages add no CSS / JS of their own.

## security

| Key | Default | Description |
| --- | --- | --- |
| `access_roles` | `[ROLE_ADMIN]` | Any of these roles opens the UI. An empty list is refused at compile time unless `access_checker` is set or `allow_unauthenticated` is true. |
| `access_checker` | `null` | Service id implementing `MailKitAccessCheckerInterface` (`canAccess(?object $user): bool`). |
| `allow_unauthenticated` | `false` | **DEV / DEMO only.** Opens the UI without login; refused unless `kernel.debug` is true. |

The settings actions additionally require the Instance Settings access of their section (`Nowo\InstanceSettingsBundle\Security\AccessGuard::isGranted('mailer')`; `'resend'` before 1.3: its `security.access_roles` / `area_roles` / checker and `allow_unauthenticated`). Protect the prefix with a firewall **and** `access_control`:

```yaml
# config/packages/security.yaml
security:
    access_control:
        - { path: ^/admin/mail, roles: ROLE_ADMIN }
        - { path: ^/admin/settings, roles: ROLE_ADMIN }
```

## FormKit profile

The forms (log filters, CSRF-only actions) are FormKit types bound to the profile **`nowo_form_kit.profiles.mail_kit`** (REQ-TWIG-005 rule 11), prepended by the bundle with: translation domain `NowoMailKitBundle`, `auto_help: false`, `auto_placeholder: false`, no framework classes (the UiKit scaffolds paint the fields). Define the profile yourself to change it; the bundle then prepends nothing:

```yaml
nowo_form_kit:
    profiles:
        mail_kit:
            translation_domain: NowoMailKitBundle
            defaults: { attr: { class: 'form-control' } }
```

## Instance Settings

MailKit requires `NowoInstanceSettingsBundle` (and its DoctrineEncrypt key for the secret fields). The sections are tagged `nowo_instance_settings.section` with priorities 50 (`mailer`) and 49 (`resend`); restrict them with `nowo_instance_settings.security.area_roles: { mailer: [ROLE_SUPER_ADMIN], resend: [ROLE_SUPER_ADMIN] }`. Sealed exports carry the DSN and the API key only with "include secrets".

## Timeouts (REQ-RUNTIME-001)

| Layer | Default | Where |
| --- | --- | --- |
| DNS lookup of a DSN host | 2 s | `dsn.dns_timeout` |
| Resend key check | 10 s connect + total, no redirect | `resend.api_timeout` |
| Microsoft Graph / token calls (1.8) | 30 s idle per call (total 4×, token 2×), no redirect | `graph.timeout` |
| SMTP / provider delivery | Symfony mailer defaults (`timeout` DSN option, default `default_socket_timeout`) | add `?timeout=10` style options to the DSN when needed |
| PHP `max_execution_time` | must be **greater** than the above | php.ini / FrankenPHP `php_ini` |
| Proxy / Caddy write timeout | must be **greater** than PHP | demo `Caddyfile` |

The test e-mail and the key check run in the request: keep the operation deadlines below `max_execution_time`. Bulk mail belongs on Messenger (the worker resolves the DSN).

## Profiles

REQ-CFG-001 does not apply to the mailer: the bundle has one configuration tree. Variants (another DSN source) are a `mailer.dsn_provider` service, not named profiles. The Graph mailboxes (1.8) are named profiles in the REQ-CFG-001 shape under `graph`: `graph.default_profile` + `graph.profiles`.

## Container parameters and services

Parameters: `nowo_mail_kit.doctrine.table_prefix`, `.web_ui.enabled`, `.web_ui.path_prefix`, `.log.enabled`, `.log.retention_days`, `.resend.enabled`, `.security.allow_unauthenticated`.

Public services: `Nowo\MailKitBundle\Brand\BrandedEmailHelper` (1.4), `Nowo\MailKitBundle\Mailer\RuntimeTransport`, `Readiness\OutboundMailReadiness`, `Dsn\MailerDsnValidator`, `TestMail\TestMailSender`, `Log\SentMailRecorder` and `Log\SentMailRepository` (log on), `Resend\ResendApiChecker` (Resend on), `nowo_mail_kit.outbound_mail_ready_checker` (AuthKit installed), `Graph\Send\GraphMailSender` and the `Graph\GraphMailboxProviderInterface` alias (`graph.enabled`, 1.8). Interfaces: `Dsn\MailerDsnProviderInterface`, `Security\MailKitAccessCheckerInterface`, `Log\SentMailErasureInterface`, `Log\SentMailPersonalDataEraserInterface`, `Log\SentMailPersonalDataExporterInterface` and `Log\SentMailPersonalDataSubjectResolverInterface` (1.7). With UserKit: `Integration\UserKit\EmailsReceivedPersonalDataEraser` and `Integration\UserKit\EmailsReceivedPersonalDataExportProvider` (private, tagged).

## Fail-closed checks

The container refuses to compile when:

- `NowoInstanceSettingsBundle` is not registered, or the framework mailer is off (`mailer.transports` missing);
- the admin UI is on without SecurityBundle (unless `allow_unauthenticated`), or without form CSRF (`framework.csrf_protection`, `framework.form.csrf_protection`);
- `security.access_roles` is empty without `access_checker` / `allow_unauthenticated`;
- `security.allow_unauthenticated: true` outside `kernel.debug`;
- `dsn.allowed_schemes` contains `sendmail`, `sendmail+smtp`, `native` or `null`;
- a switch is an env placeholder, a prefix / path / interval / template id is invalid;
- `graph.inbound.enabled` is true without `graph.enabled`, without `graph.inbound.handler`, without symfony/messenger, symfony/html-sanitizer, the fileinfo extension or DoctrineBundle, `retention_days` does not exceed `sweep.initial_window` by more than a day, or an inbound option is invalid (profile names, folder, webhook path, notification URL, sweep window);
- `graph.enabled` is true without `graph.mailbox_provider` and without a profile named like `graph.default_profile`, or a profile name is invalid;
- `brand.name` is blank, too long or contains control / bidi characters, a `brand.colors` value is not `#rrggbb`, or `brand.max_image_bytes` exceeds `brand.max_total_bytes`.
