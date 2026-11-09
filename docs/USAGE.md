# Usage

## Table of contents

- [How a message is sent](#how-a-message-is-sent)
- [The mailer settings section](#the-mailer-settings-section)
  - [Fields](#fields)
  - [DSN validation](#dsn-validation)
  - [Sender rules](#sender-rules)
- [Runtime DSN and fallback](#runtime-dsn-and-fallback)
  - [Your own DSN provider](#your-own-dsn-provider)
  - [Named transports](#named-transports)
  - [Messenger (async mail)](#messenger-async-mail)
- [Sender and Reply-To](#sender-and-reply-to)
- [Tags and redaction](#tags-and-redaction)
- [Test e-mail](#test-e-mail)
- [Resend](#resend)
  - [Migrating the 1.2 `resend` section](#migrating-the-12-resend-section)
- [DB-only mode](#db-only-mode)
- [Branded e-mails](#branded-e-mails)
- [Readiness and AuthKit](#readiness-and-authkit)
- [Sent-mail log](#sent-mail-log)
  - [Admin pages](#admin-pages)
  - [Sorting](#sorting)
  - [CSV export](#csv-export)
  - [Retention and erasure](#retention-and-erasure)
- [Reacting to changes and auditing](#reacting-to-changes-and-auditing)
- [Admin UI](#admin-ui)
  - [Forms (FormKit)](#forms-formkit)
  - [Overriding templates (REQ-TWIG-001)](#overriding-templates-req-twig-001)
- [Translations](#translations)
- [Doctrine schema and migrations](#doctrine-schema-and-migrations)

## How a message is sent

```text
MailerInterface::send()                         (your code, AuthKit, Notifier…)
  └─ MailKitMailer                              decorates mailer.mailer
       • From / Reply-To from the settings when missing
       • "queued" log row (only when the mailer has a message bus)
       └─ framework Mailer ──(Messenger, optional)──┐
                                                    ▼
       RuntimeTransport                         decorates mailer.transports (also used by the Messenger handler)
       • DSN = MailerDsnProviderInterface::getDsn() on every send (settings by default)
       • none stored → framework transport (MAILER_DSN)
       • stored DSN validated, transport built once per DSN, reset on kernel.reset / SettingsChangedEvent
       • internal headers removed, outcome logged (sent / failed)
```

Nothing changes in your code: keep injecting `Symfony\Component\Mailer\MailerInterface`.

## The mailer settings section

MailKit contributes the section **`mailer`** to [Instance Settings](https://github.com/nowo-tech/InstanceSettingsBundle) (`/admin/settings/sections/mailer` by default). With `resend.enabled: true` (since 1.3) the same section offers a **provider** choice — `dsn` (SMTP or any Symfony mailer DSN) or `resend` (Resend API) — and shows only the selected provider's fields (InstanceSettings 1.3 `visible_when`, UiKit `visible-when` peer). Before 1.3 Resend had its own `resend` section: see [Migrating the 1.2 `resend` section](#migrating-the-12-resend-section).

### Fields

| Key | Type | Default | Notes |
| --- | --- | --- | --- |
| `mailer.dsn` | secret (encrypted) | — | Validated ([DSN validation](#dsn-validation)); blank keeps the stored value, "Remove the saved value" clears it (back to `MAILER_DSN`). |
| `mailer.from` | e-mail | `sender.default_from` | Default From; must be on a public domain unless `sender.require_public_domain: false`. |
| `mailer.from_name` | string (≤ 120) | `sender.default_from_name` | Display name of the From. |
| `mailer.reply_to` | e-mail | — | Default Reply-To. |
| `mailer.test_recipient` | e-mail | — | Recipient of the test e-mails (else the signed-in user's address). |
| `mailer.provider` | choice `dsn` / `resend` | `dsn` | Only with `resend.enabled: true` (1.3). Selects the delivery; the other provider's rows are hidden and keep their stored values. |
| `mailer.resend_api_key` | secret (encrypted) | — | Only with `resend.enabled: true`, shown for `resend` (1.3; was `resend.api_key`). Checked with Resend before it goes live ([Resend](#resend)). |
| `mailer.resend_from` | e-mail | — | Only with `resend.enabled: true`, shown for `resend` (1.3; was `resend.from`). Sender while Resend is active (else `mailer.from`). |

With the provider choice, `mailer.dsn` is shown only for `dsn`; `from`, `from_name`, `reply_to` and `test_recipient` are
shown for both. A hidden row is neither validated nor written by a form save (Instance Settings 1.3), so switching back
restores the previous provider exactly as it was. Without `resend.enabled` the page is the 1.2 page (no provider row).
The host must register the UiKit `visible-when` Stimulus peer (see the
[InstanceSettings USAGE](https://github.com/nowo-tech/InstanceSettingsBundle/blob/main/docs/USAGE.md)); without
JavaScript every row stays visible and the server rules still apply.

The section actions receive Instance Settings' `SectionActionContext` (section, request, user).

Read them like any setting (`InstanceSettingsInterface::getString('mailer.from')`), or through the typed `Nowo\MailKitBundle\Settings\MailSettings` service (`provider()`, `from()`, `replyTo()`, `dsn()`, `resendActive()`…). The section names and keys are a public contract: they are never renamed.

### DSN validation

`Nowo\MailKitBundle\Dsn\MailerDsnValidator` (also the `#[MailerDsn]` constraint of the field) accepts a DSN when:

1. the scheme is in `dsn.allowed_schemes` (SMTP and the API / SMTP bridges of Mailgun, Postmark, SendGrid, SES, Brevo, Resend, Mailjet, MailPace, Scaleway by default) — `sendmail`, `native` (they run host processes) and `null` (drops every message) are refused even if listed;
2. it parses (`Symfony\Component\Mailer\Transport\Dsn`);
3. its **host** passes the SSRF guard ([`DsnHostGuard`](../src/Dsn/DsnHostGuard.php), the rules of nowo-tech/outbound-url-guard-bundle): hosts in `dsn.allowed_hosts` pass; cloud-metadata names and addresses never pass; with `dsn.allow_private_hosts: false` (default) `localhost`, `*.local`, `*.localhost`, `*.internal`, loopback / private / link-local / CGNAT / reserved IPs are refused; with `dsn.resolve_dns: true` (default) a host name must resolve (bounded lookup, `dsn.dns_timeout`) and every address must be public. Provider DSNs whose host is `default` (`resend+api://KEY@default`) target the provider endpoint and are not resolved;
4. its query options are safe: `region` only from the bridge allow-list (Mailgun `us` / `eu`, SendGrid `global` / `eu`, Scaleway `fr-par` / `nl-ams` / `pl-waw`, SES AWS region syntax; refused elsewhere), no other option value with `@ / ? # : \`, whitespace or control characters (credential options such as `session_token` excepted) — otherwise `dsn.option_not_allowed`;
5. a transport factory supports it (otherwise `dsn.unsupported`: install the bridge, e.g. `composer require symfony/resend-mailer`), and the **endpoint the built transport targets** is a provider host or passes the host guard. Building the transport does not connect.

Error keys (`NowoMailKitBundle` domain): `dsn.invalid`, `dsn.null_transport`, `dsn.scheme_not_allowed`, `dsn.option_not_allowed`, `dsn.insecure_tls` (`verify_peer=0` without `dsn.allow_insecure_tls`), `dsn.host_not_allowed`, `dsn.host_unresolvable`, `dsn.unsupported`. Single-label host names (`mailpit`) are refused unless listed in `dsn.allowed_hosts`. The DSN is never part of a message.

```php
$error = $validator->validate('smtp://user:pass@10.0.0.5:25');   // 'dsn.host_not_allowed'
$validator->isDeliverable('smtp://user:pass@smtp.example.com');  // true
```

An internal relay or a demo catcher:

```yaml
nowo_mail_kit:
    dsn:
        allowed_hosts: [mailpit, relay.corp.example]
```

### Sender rules

`Nowo\MailKitBundle\Sender\SenderAddress::isDeliverable()` (and the `#[DeliverableSender]` constraint): a valid address (optionally `Name <address>`) whose domain has a dot and does not end in `.local`, `.localhost`, `.internal` or `.`. Providers reject or spam-folder anything else.

## Runtime DSN and fallback

The DSN of every send is, in order:

1. the Resend key (`resend+api://<key>@default`) when `mailer.provider` is `resend` and a key is stored;
2. the `mailer.dsn` secret (also while `resend` is selected without a key);
3. nothing → the **framework transport** (`framework.mailer.dsn`, usually `MAILER_DSN`) — keep it as the bootstrap value (`null://null` is fine until a DSN is stored).

A stored DSN that no longer passes validation (for example after tightening `allowed_schemes`) makes the send **fail** with a `TransportException` naming the error key — MailKit never falls back silently to another transport.

### Your own DSN provider

Implement `Nowo\MailKitBundle\Dsn\MailerDsnProviderInterface` (a vault, `nowo-tech/runtime-env-bundle`, a per-tenant table…) and point the config at it:

```php
final readonly class VaultDsnProvider implements MailerDsnProviderInterface
{
    public function __construct(private VaultClient $vault) {}

    public function getDsn(): ?RuntimeDsn
    {
        $dsn = $this->vault->read('mail/dsn');              // read per call: no constructor caching (worker mode)

        return $dsn === null ? null : new RuntimeDsn($dsn); // null = framework transport
    }
}
```

```yaml
nowo_mail_kit:
    mailer:
        dsn_provider: App\Mail\VaultDsnProvider
```

Its DSNs are validated like stored ones.

### Named transports

A message with an `X-Transport` header (Symfony named transports, `framework.mailer.transports`) keeps its framework transport; the runtime DSN is not used for it.

### Messenger (async mail)

With `framework.messenger` routing `Symfony\Component\Mailer\Messenger\SendEmailMessage` to an async transport, the framework handler sends through `mailer.transports` — the decorated `RuntimeTransport` — so the **worker** resolves the DSN at delivery time. MailKit records a `queued` row before dispatching and the worker resolves it (`sent` / `failed`, attempts incremented on retries). Retries and the failure transport are your Messenger configuration; MailKit adds no handler of its own. Test e-mails are always sent synchronously.

## Sender and Reply-To

`MailKitMailer` and `RuntimeTransport` add `From` (`mailer.from` with `mailer.from_name`, or `mailer.resend_from` while Resend is active) and `Reply-To` (`mailer.reply_to`) **only when the message has none**:

```php
$mailer->send((new Email())->to('alice@example.com')->subject('Hi')->text('…'));        // From from the settings
$mailer->send((new Email())->from('billing@example.com')->to('…')->subject('Invoice')); // From kept
```

## Tags and redaction

```php
use Nowo\MailKitBundle\Mailer\MailHeaders;

$email = (new Email())->to($user->getEmail())->subject('Your sign-in link')->html($html);
MailHeaders::tag($email, 'magic_link');      // category in the log (≤ 64 characters)
MailHeaders::redact($email, $oneTimeUrl);    // masked as [redacted] in stored bodies
$mailer->send($email);
```

Both headers (and the internal log id) are removed before the message leaves the application.

## Test e-mail

- **Admin UI:** "Send a test e-mail" next to Save on the mailer section. POST with a FormKit CSRF-only form; the user must pass the MailKit access checker **and** the Instance Settings access of the section (Instance Settings `AccessGuard`); `test_email.rate_limit` per `test_email.rate_interval` per user (client address for anonymous demo access). The flash shows the transport's own error (credentials scrubbed).
- **CLI:** `php bin/console nowo:mail-kit:send-test [to] [--resend]` (exit code 1 on failure).
- **PHP:** `Nowo\MailKitBundle\TestMail\TestMailSender::sendMailer(?string $to, ?object $user)` → `TestMailResult` (`outcome`, `recipient`, `error`).

Recipient: the argument, else `mailer.test_recipient`, else the user's `getEmail()` (or an identifier that is an address). The message is sent synchronously through the current configuration, tagged `mail_kit_test`.

## Resend

```yaml
nowo_mail_kit:
    resend:
        enabled: true      # adds the provider choice to the mailer section; composer require symfony/resend-mailer
        api_timeout: 10
```

Select **Resend API** as the provider, enter the key and save. **The key is checked before it goes live** (since 1.3):
a save with `provider = resend` that would put a key live — a newly typed key, or the stored key when switching from
the DSN provider — first calls `GET https://api.resend.com/domains` with that key; when Resend rejects it (or the
call fails) the save is refused with *"Resend rejected this API key, so nothing was saved: …"* and the current
delivery keeps working. Selecting Resend with no key at all is refused too. Saving while Resend is already live with
the key unchanged makes no call. The checks share the per-user limit of the test e-mails (`test_email.rate_limit`).
This applies to the admin form only (`InstanceSettingsInterface::save()`, imports and the seed are not checked).

The mailer page also shows "Check the API key" (checks the **stored** key whichever provider is selected; lists the
domains and their status, recognises sending-only keys) and "Send a test e-mail with Resend" (uses the stored key even
while the DSN provider is selected). The key is never echoed back. Programmatic check:
`Nowo\MailKitBundle\Resend\ResendApiChecker::check($key)`.

### Migrating the 1.2 `resend` section

MailKit 1.0–1.2 stored Resend in its own section (`resend.enabled`, `resend.api_key`, `resend.from`). Since 1.3 that
section is no longer registered. Until the rows are copied, `MailSettings` keeps reading them (no stored
`mailer.provider` + `resend.enabled = true` → Resend stays live) and the mailer page shows what is live: Resend is
pre-selected when the old switch was on, the old key counts as stored ("leave blank to keep") and the old sender
pre-fills the Resend sender. Saving the page as it is stores `mailer.provider = resend`, the key and the sender, so
Resend stays live (the old key is the live key: it is not checked again). A notice names the migration meanwhile.
To migrate without opening the page, copy the rows once:

```bash
php bin/console nowo:instance-settings:seed-from-fallbacks --section=mailer --dry-run
php bin/console nowo:instance-settings:seed-from-fallbacks --section=mailer --strict
```

The seed stores `mailer.provider` (`resend` when `resend.enabled` was on, else `dsn`), `mailer.resend_api_key` and
`mailer.resend_from` from the old rows, only where nothing is stored yet (idempotent, field names only in the output).
The old `resend` rows stay in the table, unread once `mailer.provider` is stored. Delete them (the encrypted 1.2 key
included) once copied:

```bash
php bin/console nowo:mail-kit:purge-legacy-resend --dry-run
php bin/console nowo:mail-kit:purge-legacy-resend
```

The purge refuses (exit 1, nothing deleted) while `mailer.provider` is not stored, and when an old key or sender differs
from `mailer.resend_api_key` / `mailer.resend_from` (not copied, e.g. a mailer value stored before the seed); it lists
the row names, never a value. `--force` deletes anyway (the mailer values win). Running it again is a no-op. See
[UPGRADING](UPGRADING.md#to-130).

## DB-only mode

Since 1.2 (with `nowo-tech/instance-settings-bundle` ^1.2) the mailer DSN and the Resend key can live **only in the
database** (the `mailer` section, encrypted): the framework transport then needs no secret, e.g.
`framework.mailer.dsn: 'null://null'` as a literal, and no `MAILER_DSN` / `RESEND_API_KEY` env var.

MailKit cannot read `framework.mailer.dsn`, so the one-time bootstrap goes through two config keys that are read
**only** by the seed (never to send):

```yaml
# config/packages/nowo_mail_kit.yaml — temporary, while migrating
nowo_mail_kit:
    bootstrap:
        mailer_dsn: '%env(default::MAILER_DSN)%'
        resend_api_key: '%env(default::RESEND_API_KEY)%'   # with resend.enabled: true
```

Always use `default::` (the sections are built on every settings read, so a plain `%env(MAILER_DSN)%` would break those
reads once the env var is removed), and never put a literal secret under `bootstrap` (it would be compiled into the
container and shown by `debug:config`).

Order:

1. Upgrade to MailKit 1.2 / InstanceSettings 1.2 and add the `bootstrap` keys while the env vars still exist.
2. `php bin/console nowo:instance-settings:seed-from-fallbacks --section=mailer --dry-run`, then
   without `--dry-run` (add `--strict` to fail on a refused value). Each value is stored only when the secret is empty;
   the output lists field names only. A DSN that fails the settings validation (`null://`, `sendmail`, a private host
   not in `dsn.allowed_hosts`…) is skipped with reason `invalid`. `bootstrap.resend_api_key` is seeded into
   `mailer.resend_api_key`; the **provider** is not seeded from it: select Resend on the mailer page (the key is then
   checked before it goes live).
3. Remove the `bootstrap` keys and the env vars right away, and set the framework DSN to a literal (`null://null`).
   Seed **once**: a secret an admin clears later would be seeded again while `bootstrap.*` is still mapped.

**Missing settings warning.** The `mailer` page warns about the selected provider only: no DSN stored for `dsn`
(unless `mailer.fallback_counts_as_ready: true`), no key for `resend`. Health / status pages read the same report from
`OutboundMailReadiness::status()->missingFields` (`['mailer.dsn']` or `['mailer.resend_api_key']`,
names only) or `OutboundMailReadiness::missingFields()`. With a custom `mailer.dsn_provider` nothing is reported.

## Branded e-mails

Since 1.4 (spec 220 S7). An optional layout for the e-mails your application sends, with the brand name, colours and
logo of the project. Nothing changes for e-mails that do not use it.

```twig
{# templates/email/welcome.html.twig #}
{% extends '@NowoMailKit/email/branded_layout.html.twig' %}
{% block content %}<p>Hello {{ user_name }}</p>{% endblock %}

{# templates/email/welcome.txt.twig #}
{% extends '@NowoMailKit/email/branded_layout.txt.twig' %}
{% block content %}Hello {{ user_name }}{% endblock %}
```

```php
use Nowo\MailKitBundle\Brand\BrandedEmailHelper;
use Symfony\Bridge\Twig\Mime\TemplatedEmail;

public function __construct(private MailerInterface $mailer, private BrandedEmailHelper $brand) {}

$email = (new TemplatedEmail())
    ->to($user->getEmail())
    ->subject('Welcome')
    ->htmlTemplate('email/welcome.html.twig')
    ->textTemplate('email/welcome.txt.twig')
    ->context(['user_name' => $user->getName()]);

$this->mailer->send($this->brand->apply($email));
```

Where the brand comes from:

- **With a brand provider** (`nowo-tech/brand-contracts` installed and a `BrandProviderInterface` service, for example
  InstanceSettings 1.5 with `nowo_instance_settings.brand.enabled: true`, and `nowo_mail_kit.brand: auto`, the
  default): `apply()` puts the brand name and the light colours into the context entry `nowo_mail_brand`, and attaches
  the `logo_light` image (or, without a usable logo, the `mark`) as an **inline CID part** read through
  `BrandAssetReaderInterface` (owner decision Q-O6: no remote image, works offline, no tracking-pixel effect).
- **Without a provider**, with `brand: off`, or when the provider fails: `apply()` returns the e-mail untouched and the
  layout prints `nowo_mail_kit.brand.name` and `brand.colors` ([CONFIGURATION](CONFIGURATION.md#brand)), with no
  image. The rendered e-mail is the same, byte for byte, whether or not `apply()` was called.

The layout (table layout, inline styles, 600 px wide) has the blocks `html_lang` (default `en`), `title`, `preheader`,
`header` (logo, or mark + name, or name), `content` and `footer` (name). Every block can read `mail_brand`: `name`,
`colors.primary`, `colors.primary_contrast`, `colors.text`, `colors.muted`, `colors.paper`, `colors.surface`,
`colors.border` (all `#rrggbb`), `logo` and `mark` (`src`, `width`, `height` or null). A button in your content:

```twig
<a href="{{ url }}" style="display:inline-block;padding:10px 16px;border-radius:6px;background-color:{{ mail_brand.colors.primary }};color:{{ mail_brand.colors.primary_contrast }};text-decoration:none;">Open</a>
```

Other brand images in your own templates: `embed()` attaches `mark`, `logo_light` or a host-declared
`extra.email_<id>` slot and returns its `cid:` reference (null when it is unavailable):

```php
$mascot = $this->brand->embed($email, 'extra.email_mascot');
$email->context(['mascot' => $mascot] + $email->getContext());
```

```twig
{% if mascot %}<img src="{{ mascot }}" alt="" width="120">{% endif %}
```

Rules (see [SECURITY](SECURITY.md#threat-model-and-mitigations)):

- Only PNG, JPEG and WebP are attached, decided from the bytes (magic bytes and image header agreeing), never from the
  asset's declared type. SVG is never attached, not even a committed default asset: give e-mails a PNG or JPEG logo.
  WebP is not shown by every client (older Outlook).
- Each image ≤ `brand.max_image_bytes`, all brand images of one e-mail ≤ `brand.max_total_bytes`, sides ≤
  `brand.max_image_side`; a larger image is left out.
- Nothing is fetched from the asset URL; the bytes come only from the reader. A reader failure means no logo, and the
  send never fails because of the brand (every failure is logged with the slot and a hash prefix).
- Display sizes: the logo 40 px high (at most 240 px wide), the mark 40 × 40, extra images at their size up to 600 px.
- Only the light palette is used (mail clients' dark modes recolour on their own); `color-scheme: light` is declared.
- The helper is stateless: the provider is asked on every call, so a FrankenPHP or Messenger worker keeps no brand
  between messages. Call `apply()` where the e-mail is built: the brand view (plain scalars) and the image parts
  travel with the message, so a Messenger worker that renders it later prints the same brand. A queued message
  therefore carries the image bytes (up to `brand.max_total_bytes`); see [UPGRADING](UPGRADING.md#unreleased).
- The helper reuses and counts only the parts it attached itself (`BrandedEmailPart`): an attachment your application
  added under a `nowo-brand-…` name is never taken for the logo, and the brand part then gets a unique name.
- `apply()` attaches the logo even if the template does not print it: use it with templates that extend the layout.

## Readiness and AuthKit

```php
$readiness->isOutboundMailReady();   // bool
$status = $readiness->status();      // MailStatus: source (settings|resend|custom|fallback), label (scheme://host), ready, error, missingFields (1.2)
```

```twig
{% if nowo_mail_kit_outbound_mail_ready() %}…{% endif %}
{{ nowo_mail_kit_status().label }}   {# smtp://smtp.example.com:587 — never credentials #}
```

Ready means a runtime DSN is stored and valid (checked without DNS: the readiness check runs on anonymous pages; the full check runs on save and before sending). With nothing stored, the framework transport counts only with `mailer.fallback_counts_as_ready: true` (the framework transports cannot be inspected).

**AuthKit:** when `nowo-tech/auth-kit-bundle` is installed, MailKit registers `nowo_mail_kit.outbound_mail_ready_checker` (`MailKitOutboundMailReadyChecker`, implements `Nowo\AuthKitBundle\Mailer\OutboundMailReadyCheckerInterface`) and prepends `nowo_auth_kit.outbound_mail_ready_checker` to it, so magic-login and password-reset links appear only when mail can be delivered. Set your own id in `nowo_auth_kit.outbound_mail_ready_checker` to override it.

## Sent-mail log

One row per message in `<prefix>mail_kit_sent_mail`: status (`queued`, `sent`, `failed`), tag, subject, first sender, recipients (to / cc / bcc, lowercase), transport scheme (`default` = framework transport), provider message id, size, attempts, error (credentials scrubbed), timestamps. Writes go through DBAL and never through your unit of work; a database error is logged as a warning and never breaks the delivery.

**Bodies** are stored in `<prefix>mail_kit_sent_mail_body` only with `log.store_bodies: true` (personal data: keep it off unless you need it), capped at 1 MiB each, with the `MailHeaders::redact()` values masked.

Switch the log off with `log.enabled: false` (no table writes, no log pages, no purge commands).

### Admin pages

- `GET /admin/mail/log` — UiKit list: search (subject or recipient, ≥ 3 characters), status, period (24 h / 7 d / 30 d / all), sortable columns, `web_ui.page_size` rows per page, the current transport and the "Export CSV" action in the header.
- `GET /admin/mail/log/export` — CSV of the current filters and sort (see [CSV export](#csv-export)).
- `GET /admin/mail/log/{id}` — envelope and delivery details; the stored text body (escaped).
- `GET /admin/mail/log/{id}/body` — stored HTML body as its own response with `Content-Security-Policy: default-src 'none'; …; sandbox` (no script, no remote loads, no forms), `nosniff`, `no-store` (only with `log.store_bodies`).

### Sorting

The date, status, subject, tag and transport headers sort the list on the server (UiKit `Listing\SortRequest`,
`?sort=<key>&dir=asc|desc`). The keys are a whitelist — `queuedAt`, `status`, `subject`, `tag`, `transport`
(`SentMailRepository::SORT_FIELDS`, the same keys as the column `sortKey`s); an unknown key falls back to the default
(newest first) and an unknown direction to the column's first-click direction (`desc` for the date), never an error.
Ties are broken by the date and then the row id in the same direction, so pages never overlap. The pagination links,
the filter form (hidden `sort` / `dir` fields) and the export link keep the sort; a click on a header goes back to page
1 and keeps the filters. Recipients are a list and do not sort.

```php
// Your own listing of the log, same rules:
$sort = \Nowo\MailKitBundle\Ui\AdminPages::sort($request);  // SortRequest over the sortable columns
$rows = $repository->findFiltered($filters, 25, 0, $sort->field, $sort->direction); // unknown key => InvalidArgumentException
```

### CSV export

"Export CSV" on the log list downloads `sent-mail-<YYYYmmdd-His>.csv` with the **current filters and sort**:

```text
Date,Status,Tag,From,To,Cc,Bcc,Subject,Error,Reference
2026-10-31T09:12:44+00:00,sent,invoice,app@example.com,alice@example.com,,,Invoice 42,,0102abc@provider
```

- Columns: date (ISO 8601), status (`queued` / `sent` / `failed`), tag, from, to / cc / bcc (comma-separated), subject,
  error (credentials scrubbed again with `ErrorScrubber`), reference (provider message id). The header row is
  translated (`log.export.column.*`), UTF-8 with a BOM (Excel).
- Written through ControllerKit's `Http\CsvStreamedResponse` / `CsvSafeWriter`: a cell starting with `=`, `+`, `-`,
  `@`, a tab or a carriage return is prefixed with `'`, and a trigger after an embedded `;`, `,`, tab, `|` or line
  break gets a space (CSV / formula injection, also when Excel reads the file with `;`), so a subject such as
  `=HYPERLINK(…)` or `Hi;=cmd|…` is shown as text by the common spreadsheets. Streamed row by row (rows detached after use), `Cache-Control:
  no-store, private`, `Content-Disposition: attachment`, `X-Robots-Tag: noindex`.
- At most `web_ui.export.max_rows` (10 000, configurable up to 100 000) rows; a longer result ends with a notice row, `X-Mail-Kit-Export-Truncated:
  1` and the action label "Export CSV (first N rows)" — narrow the filters to export the rest.
- **Bodies are never exported** unless `log.store_bodies: true` **and** `web_ui.export.include_bodies: true` **and**
  the request asks for them (`?bodies=1`, the "Export CSV with bodies" action): two more columns, text and HTML body,
  loaded in chunks of about `web_ui.export.body_batch_bytes` (8 MiB) so the memory stays bounded.
- Access: the same checker as the list (`security.*`) plus, when set, `web_ui.export.access_roles`. Disable it with
  `web_ui.export.enabled: false`.
- GET, not a POST form: the export is a read with no side effect, like the list filters (REQ-TWIG-005 asks for a
  FormKit CSRF form only when a page submits data; a link carries none). A cross-site link can at most make an admin's
  own browser download a file it could already open; the response is never cached.

**GDPR.** A CSV leaves the application's retention and erasure: rows purged by `nowo:mail-kit:log:purge` or erased by
`nowo:mail-kit:log:forget` stay in files already downloaded. Restrict the export to the roles that need it
(`web_ui.export.access_roles`), keep bodies out of it, and treat the files as personal data (storage, retention,
deletion on request) — see [SECURITY](SECURITY.md#personal-data-gdpr).

### Retention and erasure

```bash
php bin/console nowo:mail-kit:log:purge                       # rows older than log.retention_days (keyset batches)
php bin/console nowo:mail-kit:log:purge --older-than-days=7
php bin/console nowo:mail-kit:log:forget alice@example.com     # rows she sent or received deleted, address masked elsewhere (GDPR art. 17)
```

Schedule the purge (cron or Symfony Scheduler). `SentMailRecorder::forgetAddress()` (`['deleted' => n, 'redacted' => n]`) is the same erasure for an account-deletion flow; search and erasure use the normalised `recipient_index` column (each address NFC-normalised, lower-case, in its Unicode and punycode forms, separated by a control character) and work on SQLite, MySQL / MariaDB and PostgreSQL.

**Failures.** A failed send throws `Nowo\MailKitBundle\Mailer\DeliveryFailedException` (a `TransportException`): a short message with a reference (`The mail provider answered HTTP 403 (reference 1a2b3c4d).` for API bridges, the scrubbed SMTP answer otherwise). Provider response bodies and credentials never reach the log row, the flash or the Messenger failure transport; the scrubbed detail is logged (`Mail delivery failed.`, `reference`, `detail`).

## Reacting to changes and auditing

A save of the `mailer` section dispatches Instance Settings' `SettingsChangedEvent`; MailKit's listener resets the runtime transport and logs `Mail settings changed.` (notice) with the section, field names, origin and the redacted new transport — never a value. `Nowo\MailKitBundle\Dsn\MailerDsnAudit::redact($dsn)` / `label($dsn)` give you the same redacted view for your own audit trail.

## Admin UI

The routes come only from the `nowo_mail_kit` loader (REQ-SF-004 rule 7):

| Route | Method | Path (default prefix) |
| --- | --- | --- |
| `nowo_mail_kit_log` | GET | `/admin/mail/log` |
| `nowo_mail_kit_log_show` | GET | `/admin/mail/log/{id}` |
| `nowo_mail_kit_log_body` | GET | `/admin/mail/log/{id}/body` (with `log.store_bodies`) |
| `nowo_mail_kit_log_export` | GET | `/admin/mail/log/export` (with `web_ui.export.enabled`) |
| `nowo_mail_kit_test` | POST | `/admin/mail/actions/test` |
| `nowo_mail_kit_resend_check` | POST | `/admin/mail/actions/resend/check` (with `resend.enabled`) |
| `nowo_mail_kit_resend_test` | POST | `/admin/mail/actions/resend/test` (with `resend.enabled`) |

Every request under the prefix passes `MailKitAccessCheckerInterface` (`security.access_roles`, or your `security.access_checker`); the actions also need the Instance Settings access of their section and a valid CSRF token; the export also needs `web_ui.export.access_roles` when set. Add a link to the log in your admin menu (`path('nowo_mail_kit_log')`).

### Forms (FormKit)

The filter form (`SentMailFilterType`, GET, no CSRF) and the CSRF-only action forms are FormKit types rendered with `form_start` / `form_end` (REQ-TWIG-005) — no raw `<form>` / `<input>` in the bundle templates; keep it that way in overrides. The profile is `nowo_form_kit.profiles.mail_kit` ([CONFIGURATION](CONFIGURATION.md#formkit-profile)).

### Overriding templates (REQ-TWIG-001)

Restyle through `nowo_ui_kit.theme` (slots, tokens, scaffolds) first — that covers every bundle at once ([TEMPLATES](TEMPLATES.md)). When a page needs bundle-specific markup, **do not copy** the template: point `web_ui.templates.<page>` at a thin template that extends the original and overrides one block, so bundle upgrades still reach you:

```yaml
nowo_mail_kit:
    web_ui:
        templates:
            log_index: 'admin/mail_kit/log_index.html.twig'
```

```twig
{# templates/admin/mail_kit/log_index.html.twig #}
{% extends '@!NowoMailKitBundle/log/index.html.twig' %}
{% block nowo_ui_list_row %}{{ parent() }}{% endblock %}
```

`templates/bundles/NowoMailKitBundle/<path>` overrides still work (Symfony convention) but freeze the file: you must re-apply every upstream change by hand. If you already have one, diff it against the new version on each upgrade, or replace it with a `web_ui.templates` override. Recovery: delete the copy, clear the cache, re-apply only the block you needed.

| Template (subpath) | Page id | Scaffold | Data blocks |
| --- | --- | --- | --- |
| `log/index.html.twig` | `log_index` | `@NowoUiKit/page/list.html.twig` | `nowo_ui_list_row` (and every list block) |
| `log/show.html.twig` | `log_show` | `@NowoUiKit/page/show.html.twig` | `nowo_ui_show_sections`, `nowo_ui_show_after`, `nowo_mail_kit_text_body` |

The settings pages themselves belong to Instance Settings (`@NowoInstanceSettingsBundle/settings/section.html.twig`).

## Translations

Domain **`NowoMailKitBundle`** (en, es, fr, de, it, pt, nl): section labels and help, DSN / sender errors, test e-mail outcomes and body, Resend messages, log pages. Override any key in `translations/NowoMailKitBundle.<locale>.yaml` of your app.

## Doctrine schema and migrations

The entities `Nowo\MailKitBundle\Entity\SentMail` and `SentMailBody` are mapped automatically (attribute mapping, `NowoMailKit` alias). Generate a migration in your app:

```bash
php bin/console doctrine:migrations:diff
php bin/console doctrine:migrations:migrate
```

Tables: `<prefix>mail_kit_sent_mail` (indexes `<prefix>idx_mail_kit_sent_mail_queued`, `<prefix>idx_mail_kit_sent_mail_status`) and `<prefix>mail_kit_sent_mail_body`. The Instance Settings table holds the settings themselves.
