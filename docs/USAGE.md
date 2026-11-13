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
- [GDPR erasure and export](#gdpr-erasure-and-export)
  - [Ports](#ports)
  - [Matching rule](#matching-rule)
  - [What is and is not erased](#what-is-and-is-not-erased)
  - [Transactions and the erased event](#transactions-and-the-erased-event)
  - [UserKit bridge](#userkit-bridge)
- [Reacting to changes and auditing](#reacting-to-changes-and-auditing)
- [Using it from a DDD / hexagonal application](#using-it-from-a-ddd--hexagonal-application)
- [Microsoft Graph (shared mailbox)](#microsoft-graph-shared-mailbox)
  - [How a Graph send works](#how-a-graph-send-works)
  - [Standard Symfony host: the mailer transport](#standard-symfony-host-the-mailer-transport)
  - [Getting the Graph id back: `GraphMailSender`](#getting-the-graph-id-back-graphmailsender)
  - [Threading and plus addressing](#threading-and-plus-addressing)
  - [Errors](#errors)
  - [DDD / hexagonal host: your own mailbox port](#ddd--hexagonal-host-your-own-mailbox-port)
  - [Worker mode](#worker-mode)
- [Inbound mail from a Graph mailbox](#inbound-mail-from-a-graph-mailbox)
  - [How a message comes in](#how-a-message-comes-in)
  - [Standard Symfony host](#standard-symfony-host)
  - [The normalised message](#the-normalised-message)
  - [DDD / hexagonal host](#ddd--hexagonal-host)
  - [Worker mode and limits](#worker-mode-and-limits)
- [Open and click tracking](#open-and-click-tracking)
  - [What happens to a tracked e-mail](#what-happens-to-a-tracked-e-mail)
  - [What is never tracked](#what-is-never-tracked)
  - [Standard Symfony host](#standard-symfony-host-1)
  - [Beacon example: policy and short-link rewriter](#beacon-example-policy-and-short-link-rewriter)
  - [The pixel endpoint](#the-pixel-endpoint)
  - [Reading the results](#reading-the-results)
  - [Retention, GDPR and worker mode](#retention-gdpr-and-worker-mode)
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
  therefore carries the image bytes (up to `brand.max_total_bytes`); see [UPGRADING](UPGRADING.md#to-150).
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
php bin/console nowo:mail-kit:log:forget alice@example.com     # her rows deleted (shared rows unlinked), address masked elsewhere (GDPR art. 17)
```

Schedule the purge (cron or Symfony Scheduler). `SentMailRecorder::forgetAddress()` (`['deleted' => n, 'unlinked' => n, 'redacted' => n]`) is the same erasure for an account-deletion flow; search and erasure use the normalised `recipient_index` column (each address NFC-normalised, lower-case, in its Unicode and punycode forms, separated by a control character) and work on SQLite, MySQL / MariaDB and PostgreSQL. For the export port, the exact matching rule, transactions and the UserKit bridge see [GDPR erasure and export](#gdpr-erasure-and-export).

**Failures.** A failed send throws `Nowo\MailKitBundle\Mailer\DeliveryFailedException` (a `TransportException`): a short message with a reference (`The mail provider answered HTTP 403 (reference 1a2b3c4d).` for API bridges, the scrubbed SMTP answer otherwise). Provider response bodies and credentials never reach the log row, the flash or the Messenger failure transport; the scrubbed detail is logged (`Mail delivery failed.`, `reference`, `detail`).

## GDPR erasure and export

Since 1.7 the sent-mail log has erasure and export ports, so an application never writes SQL on the
`mail_kit_sent_mail` tables. Both are plain PHP (no Doctrine, Symfony or HTTP types), public and always registered:
backed by `Log\SentMailRecorder` whenever Doctrine is registered (also with `log.enabled: false`), by no-op
implementations on a host without Doctrine.

### Ports

| Port | Method | Result |
| --- | --- | --- |
| `Log\SentMailErasureInterface` (1.6; shared rows unlinked since 1.7) | `forgetAddress(string $email)` | `['deleted' => n, 'unlinked' => n, 'redacted' => n]`, see [What is and is not erased](#what-is-and-is-not-erased). |
| `Log\SentMailPersonalDataEraserInterface` (1.7) | `erase(SentMailPersonalDataSubject $subject)` | `Log\SentMailErasureResult` (`deleted`, `unlinked`, `redacted`, `toArray()`, `total()`): the same erasure as `forgetAddress()`, with a validated subject. |
| `Log\SentMailPersonalDataExporterInterface` (1.7) | `export(SentMailPersonalDataSubject $subject)` | List of rows, oldest id first. |
| `Log\SentMailPersonalDataSubjectResolverInterface` (1.7, SPI) | `subjectsFor(object $user, ?string $originalIdentifier)` | The addresses of a host user (used by the UserKit bridge). |

`Log\SentMailPersonalDataSubject::fromEmail($email)` builds the subject. It refuses an empty value, a value without
`@`, control characters (NUL included), invalid UTF-8 and more than 255 characters; `tryFromEmail()` returns null
instead of throwing.

```php
use Nowo\MailKitBundle\Log\SentMailPersonalDataEraserInterface;
use Nowo\MailKitBundle\Log\SentMailPersonalDataExporterInterface;
use Nowo\MailKitBundle\Log\SentMailPersonalDataSubject;

$subject = SentMailPersonalDataSubject::fromEmail('alice@example.com');
$rows    = $exporter->export($subject);
$result  = $eraser->erase($subject);           // SentMailErasureResult: deleted 3, unlinked 2, redacted 1
// 1.6 port, same erasure: $erasure->forgetAddress('alice@example.com') returns ['deleted' => 3, 'unlinked' => 2, 'redacted' => 1]
```

One exported row:

```json
{"id": "0196…", "roles": ["recipient"], "status": "sent", "template": "welcome", "subject": "Welcome",
 "queued_at": "2026-11-11T10:00:00+00:00", "sent_at": "2026-11-11T10:00:01+00:00", "failed_at": null}
```

`roles` holds `recipient` (to, cc or bcc), `sender` or both. `template` is the `MailHeaders::tag()` category. The
export is envelope data only. It never includes the bodies: they may hold other people's data and masked secrets, and
they are stored only with `log.store_bodies`. It never includes the other recipients, the transport, the provider
message id or the error text either. Both operations are idempotent: a second erasure returns zero counts.

### Matching rule

The log normalises every address when it writes it: trimmed, Unicode NFC, lower-case, domain in Unicode, indexed in its
Unicode and punycode forms. The ports apply the same normalisation and nothing else, then compare exactly:

- `Alice@Example.com` and `alice@example.com` are **the same address** in the log (case is folded on write, local part
  included), so both select the same rows.
- `user@bücher.de` and `user@xn--bcher-kva.de` are the same address.
- `jose@example.com` and `josé@example.com` are **different addresses**. SQL only pre-filters (`recipient_index LIKE`,
  `sender IN`). Every candidate row is checked again in PHP with `===` on the index entries and the sender, so an
  accent-insensitive MySQL / MariaDB collation can never export or erase another person's rows. Deletes go by primary
  key in batches of 500 (`SentMailRecorder::PERSONAL_DATA_BATCH`).
- Masking in other rows' subjects and bodies is case-insensitive and accent-sensitive, and replaces whole addresses
  only: erasing `na@example.com` does not touch `ana@example.com`, `x.na@example.com` or `na@example.com.au`. The
  `redacted` count only counts rows that really changed.

### What is and is not erased

A message to several people is **one** log row that they share. Since 1.7 (behaviour change) erasing one address
does not take the row away from the others:

- **Unlinked** (`unlinked`): a row the address received (to, cc, bcc) or sent that still has **another recipient**.
  The row stays for the others: the address is removed from `recipients` and `recipient_index`, the sender becomes
  `[redacted]` when it was the address, and the address is masked in the subject, the delivery error (an SMTP bounce
  such as `550 <alice@…>`) and the stored body. The other
  recipients still export it with their own data; the erased address appears nowhere in it. A site whose
  `default_from` is a person's own mailbox keeps the log of what it sent to everybody else when that person is erased.
- **Deleted** (`deleted`): a row with **no other recipient left** (the address was the only recipient, or sent it to
  itself), with its stored body.
- **Masked** (`redacted`): the address in the subjects, delivery errors and stored bodies of the other rows (e.g. a message to `ops@`
  that mentions the person), and in the stored bodies of the unlinked rows.
- Not erased: names or other data of the person that are not the address itself (a display name in a subject, a
  phone number in a body). Use `log.store_bodies: false` (default) and the retention purge to keep that small.

### Transactions and the erased event

`erase()` and `forgetAddress()` write with DBAL on the log connection (`doctrine.entity_manager`) and never flushes an entity
manager:

- **No transaction open:** it opens its own, commits, then dispatches `Event\SentMailPersonalDataErasedEvent`
  (`email`, `deleted`, `unlinked`, `redacted`; also for a run that matched nothing) for your erasure audit trail.
  Listeners must not log the address verbatim.
- **Inside your transaction** (UserKit runs all erasers inside one): it joins it and dispatches **no event**, because
  your transaction may still roll back. The returned counts are the outcome.

With a separate entity manager / connection for the log (`doctrine.entity_manager`), the erasure is not part of the
transaction of your user's connection (UserKit's): it commits on its own. If a later eraser fails and UserKit rolls
back, the log part stays erased; run the erasure again (it is idempotent).

When only the body table is missing (mapped entities), the main rows are erased and committed first, then
`SentMailLogTableMissingException` is thrown, as in 1.6.

### UserKit bridge

Opt-in. With `nowo-tech/user-kit-bundle` (>= 1.12) installed, the log entities mapped (default) and
`personal_data.enabled: true` (default `false`), MailKit registers:

| Service | UserKit tag | Key | Priority |
| --- | --- | --- | --- |
| `Integration\UserKit\EmailsReceivedPersonalDataEraser` | `nowo_user_kit.personal_data.eraser` | `emails_received` | 110 |
| `Integration\UserKit\EmailsReceivedPersonalDataExportProvider` | `nowo_user_kit.personal_data.export_provider` | section `emails_received` | 0 |

The eraser runs before a host identity eraser at priority < 110, while the account still has its address. It calls
`SentMailPersonalDataEraserInterface::erase()` once for each distinct address of the user:

- the subjects of the resolver. The default resolver (`Log\DefaultSentMailPersonalDataSubjectResolver`) returns
  `ErasureRequest::identifier()` (the identifier before any eraser ran) when it is an e-mail address, plus the
  user's `getEmail()` when the user has one;
- `context['previous_email']`, when it is a non-empty address (pass it after an e-mail change).

The export section lists the rows of every address of the user, each row once. It uses the current
`getUserIdentifier()` and `getEmail()`. Set `personal_data.subject_resolver` to your own resolver service to change
which addresses belong to a user. Keep `personal_data.enabled: false` if your application erases and exports these
tables itself or must retain the log (GDPR art. 17(3)). UserKit throws a `LogicException` on two export sections with
the same key, so remove your own `emails_received` provider before you turn the bridge on.

## Reacting to changes and auditing

A save of the `mailer` section dispatches Instance Settings' `SettingsChangedEvent`; MailKit's listener resets the runtime transport and logs `Mail settings changed.` (notice) with the section, field names, origin and the redacted new transport — never a value. `Nowo\MailKitBundle\Dsn\MailerDsnAudit::redact($dsn)` / `label($dsn)` give you the same redacted view for your own audit trail.

## Using it from a DDD / hexagonal application

MailKit is an **infrastructure adapter** for Symfony Mailer: it decorates `mailer.mailer` and `mailer.transports` so
every send resolves the DSN and the sender at runtime. It also owns a small sent-mail log (two tables of its own, no
relation to your entities) and an admin UI. Your domain never needs a MailKit type.

**Keep the domain free of the bundle**

- Declare your own outbound port in the domain (for example `InvitationNotifier`) and implement it in your
  infrastructure layer with Symfony's `MailerInterface`. MailKit applies the runtime DSN and the From / Reply-To from
  the settings behind that interface.
- `MailHeaders::tag()` / `MailHeaders::redact()` take a Symfony `Message`: call them in the adapter, not in the domain.
- The log is written with DBAL, never through your unit of work: a send cannot flush or roll back your aggregates, and
  a log failure never breaks the delivery.

```php
// Infrastructure adapter for a domain port
final readonly class SymfonyInvitationNotifier implements InvitationNotifier
{
    public function __construct(private MailerInterface $mailer) {}

    public function notify(InvitationId $id, EmailAddress $to, string $link): void
    {
        $email = (new Email())->to((string) $to)->subject('You are invited')->text("Open: {$link}");
        MailHeaders::tag($email, 'invitation');
        MailHeaders::redact($email, $link);
        $this->mailer->send($email); // throws DeliveryFailedException (a TransportException) on failure
    }
}
```

**Ports you can implement**

| Interface | Purpose | Config |
| --- | --- | --- |
| `Nowo\MailKitBundle\Dsn\MailerDsnProviderInterface` | Where the DSN comes from (vault, per-tenant table…); `getDsn(): ?RuntimeDsn`, null = framework transport | `nowo_mail_kit.mailer.dsn_provider` |
| `Nowo\MailKitBundle\Sender\SenderIdentityProviderInterface` (1.12) | Default `From` / `Reply-To` of messages without one (tenant, brand, your config), framework-free; `defaultFrom(): ?MailAddress`, `defaultReplyTo(): ?MailAddress` | `nowo_mail_kit.mailer.sender_provider` |
| `Nowo\MailKitBundle\Sender\SenderProviderInterface` (1.5, **deprecated** since 1.12, removed in 2.0) | The same, with the Symfony Mime `Address`; `from(): ?Address`, `replyTo(): ?Address` | `nowo_mail_kit.mailer.sender_provider` |
| `Nowo\MailKitBundle\Security\MailKitAccessCheckerInterface` | Who opens the admin UI; `canAccess(?object $user): bool` | `nowo_mail_kit.security.access_checker` |
| `Nowo\Brand\Contract\BrandProviderInterface` / `BrandAssetReaderInterface` (optional) | Brand of the branded layout | `nowo_mail_kit.brand: auto` |

**Without Instance Settings and without the ORM (1.6)**

Standard path (default): register `NowoInstanceSettingsBundle`; the DSN, sender, test recipient and Resend key are
fields of its `mailer` section, edited at runtime. DDD path (opt-in): give MailKit its configuration through the two
ports and leave Instance Settings (and, with the log off, Doctrine) out of the kernel:

```yaml
# config/packages/nowo_mail_kit.yaml — config/bundles.php has no NowoInstanceSettingsBundle
nowo_mail_kit:
    mailer:
        dsn_provider: App\Infrastructure\Mail\VaultMailerDsnProvider       # MailerDsnProviderInterface
        sender_provider: App\Infrastructure\Mail\TenantSenderProvider      # SenderIdentityProviderInterface
    log:
        enabled: false   # no DoctrineBundle needed; keep it true to have the sent-mail log (needs the ORM)
```

The sender port is framework-free since 1.12: `SenderIdentityProviderInterface` takes and returns the bundle value
object `Sender\MailAddress`, so the host domain needs no Symfony Mime type. `nowo_mail_kit.mailer.sender_provider`
also still accepts a service implementing the deprecated `Sender\SenderProviderInterface` (Mime `Address`); MailKit
wraps whichever it gets.

```php
use Nowo\MailKitBundle\Sender\MailAddress;
use Nowo\MailKitBundle\Sender\SenderIdentityProviderInterface;

final readonly class TenantSenderProvider implements SenderIdentityProviderInterface
{
    public function __construct(private TenantContext $tenants)
    {
    }

    public function defaultFrom(): ?MailAddress
    {
        return new MailAddress($this->tenants->current()->mailFrom(), $this->tenants->current()->name());
    }

    public function defaultReplyTo(): ?MailAddress
    {
        return null;   // or MailAddress::fromString('Acme support <help@acme.example>')
    }
}
```

Both providers are read on every send, so per-tenant or per-request values work in a kept-alive worker (keep no
state in them). Without either provider the build fails and names the missing option; `resend.enabled` needs
Instance Settings. The settings actions are not registered (no settings page); the log pages, readiness, Twig
functions, the AuthKit adapter and `nowo:mail-kit:send-test` (sender from your port) keep working.

**Sending inside a transaction**

The mailer sends when you call it. Send after your unit of work commits — from a domain-event subscriber that runs
after commit, or through Messenger (`framework.messenger.routing` of `SendEmailMessage` to an async transport) — so a
rolled-back use case never sends an e-mail. MailKit never flushes or commits your EntityManager (the log uses DBAL).

**Reacting to deliveries (1.5)**

`RuntimeTransport` dispatches id-only PSR-14 events after each send, once the sent-mail log row is written:

- `Nowo\MailKitBundle\Event\MailDeliveredEvent`: `logId`, `tag`, `transport` (scheme label), `providerMessageId`,
  `size`, `occurredAt`.
- `Nowo\MailKitBundle\Event\MailDeliveryFailedEvent`: `logId`, `tag`, `transport`, `reference` (the one of the
  `DeliveryFailedException`), `occurredAt`.

They never carry addresses, subject, body, DSN or provider error text. To correlate them with your domain, tag the
message (`MailHeaders::tag($email, 'invitation')`) and keep your own mapping, or look the row up by `logId`. A delivery
is an external fact, so the events are not held back by a transaction you may have open. A listener that throws is
logged and never fails the send (no Messenger retry, no duplicate e-mail). Keep personal data out of the tag.
`logId` is null with the log off; when the log write failed it is the id of the queued row, if the message was queued.

Send alerts from these listeners **asynchronously** (dispatch a Messenger message): a mail sent synchronously from a
delivery listener is delivered and logged, but dispatches no delivery event of its own (one level only), so an alert
about a failing transport cannot recurse through the same transport.

**Services and value objects your adapters can use**

- `OutboundMailReadinessInterface::isOutboundMailReady()` / `status(): MailStatus` (1.6 port of
  `OutboundMailReadiness`; scalars only: source, redacted label, ready, error key, missing fields). AuthKit gets it
  automatically through `OutboundMailReadyCheckerInterface`.
- `SentMailErasureInterface::forgetAddress($email)` (`['deleted' => n, 'redacted' => n]`) and `purgeBefore()` (1.6)
  from your account-deletion / retention use case; always registered. With Doctrine it really erases, also with
  `log.enabled: false` (rows from when the log was on); it is a no-op only without Doctrine (nothing can be stored). A missing log table (wrong `doctrine.table_prefix` /
  entity manager, unmigrated schema) throws `SentMailLogTableMissingException` instead of reporting zero rows, except
  with `log.map_entities: false`.
- `RuntimeDsn`, `MailStatus`, `TestMailResult`, `SentMailFilters` are immutable value objects.

**Persistence**

`SentMail` and `SentMailBody` are bundle-owned, read-only mapped entities with no relations: no
`resolve_target_entities`, nothing to extend. Their mapping is added to the default ORM mapping list (also with
`log.enabled: false`, so existing tables are not dropped by your schema tooling; set `log.map_entities: false`, only
with the log off, to keep them out of an XML-only entity manager); with several entity managers, add the `NowoMailKitBundle` mapping to the manager you name
in `doctrine.entity_manager`.

**Known limitations**

- Instance Settings, DoctrineEncrypt and the ORM stay in composer `require` in 1.x (installed, not registered); 2.0
  moves them to `suggest`.
- `SentMailRepository` returns the `SentMail` entity (admin read model): wrap it in your own query port if your
  application layer must not depend on it.

## Microsoft Graph (shared mailbox)

Since 1.8 (opt-in, `graph.enabled: true`) MailKit sends from a Microsoft 365 **shared mailbox** through Microsoft
Graph with app-only credentials: the message stays in the mailbox's Sent Items, Outlook threads it with the
replies, and the caller gets the Graph id and the `internetMessageId` back. The Microsoft 365 side (app registration,
permissions, access policy, secret rotation) is in [MICROSOFT-GRAPH-SETUP.md](MICROSOFT-GRAPH-SETUP.md); the options
in [CONFIGURATION.md](CONFIGURATION.md#graph). Nothing changes while `graph.enabled` is false (the default).

### How a Graph send works

1. The mailbox of the profile comes from `GraphMailboxProviderInterface` (the configured `graph.profiles`, or your
   service). An app-only token is requested from `login.microsoftonline.com/{tenant}` (client credentials) and cached
   in `graph.token_cache` until five minutes before it expires.
2. The headers and bodies are rendered as MIME — with your `Message-ID`, `In-Reply-To`, `References` and `Reply-To` —
   and a **draft** is created from it (`POST /users/{mailbox}/messages`, base64 MIME, immutable ids). Graph's JSON
   `internetMessageHeaders` only take custom `x-` headers, which is why MailKit uses the MIME path: it is the one
   that keeps the RFC 5322 threading headers.
3. Bcc recipients are set on the draft; each attachment is added (below 2.9 MB in one call, from 2.9 MB with an
   upload session in 3.75 MiB chunks read from the stream).
4. The draft is sent (`POST …/send`) and saved in Sent Items with the same immutable id. A failure in step 3, or a
   definite refusal of the send (4xx), deletes the draft. An ambiguous send failure (network error, timeout, 5xx,
   429 / 503) reads `isDraft` first:
   - sent → success;
   - unknown → the draft is kept and the send fails with the non-retryable reason `ambiguous`;
   - still a draft, **with** `graph.draft_cleanup.bus` (1.9) → a delayed `DeleteGraphDraft` (default 5 s) is
     dispatched, which deletes the message later only if it is still a draft, and the send fails with `ambiguous`
     (not retried: Exchange may still send it); the worker never waits. Route `DeleteGraphDraft` to a transport that
     supports delays (Doctrine, Redis, AMQP; a `sync://` transport ignores the delay);
   - still a draft, **without** a bus (or when the dispatch fails) → `isDraft` is read once more at once: still a
     draft → deleted and the original (retryable) error rethrown; a send Exchange completes after that read is still
     deleted with its draft (residual race).

   The mailer transport turns `ambiguous` into a Messenger `UnrecoverableExceptionInterface`, so a queued e-mail is
   not sent twice.

The From address is always the mailbox (Graph sends as that mailbox); only its display name is yours.

### Standard Symfony host: the mailer transport

Configure the profile(s) and use the `microsoft+graph://<profile>` DSN (`default` = `graph.default_profile`). The
DSN carries no credentials.

```yaml
# config/packages/nowo_mail_kit.yaml
nowo_mail_kit:
    graph:
        enabled: true
        profiles:
            default:
                tenant_id: '%env(MAIL_KIT_GRAPH_TENANT_ID)%'
                client_id: '%env(MAIL_KIT_GRAPH_CLIENT_ID)%'
                client_secret: '%env(MAIL_KIT_GRAPH_CLIENT_SECRET)%'
                mailbox: projects@example.com

# config/packages/mailer.yaml — Graph as a named transport next to the usual one
framework:
    mailer:
        transports:
            main: '%env(MAILER_DSN)%'
            graph: 'microsoft+graph://default'
```

```php
use Nowo\MailKitBundle\Graph\Send\PlusAddress;
use Symfony\Component\Mime\Email;

$email = (new Email())
    ->to('client@example.net')
    ->replyTo(PlusAddress::compose('projects@example.com', 't-42'))
    ->subject('Re: Offer for task 42')
    ->text('…')
    ->attachFromPath('/path/to/offer.pdf');
$email->getHeaders()->addTextHeader('X-Transport', 'graph');
$email->getHeaders()->addIdHeader('In-Reply-To', 'previous-message-id@example.net');
$mailer->send($email); // or queue it: the Email is serializable
```

The transport maps To, Cc, Reply-To, subject, text, HTML, attachments (inline parts keep their `cid:`), and the
`Message-ID`, `In-Reply-To` and `References` headers; envelope recipients that are neither To nor Cc become Bcc.
Other custom headers are not sent. The `SentMessage` carries Graph's internetMessageId as message id (so the
sent-mail log and `MailDeliveredEvent` record it) and the Graph id in its debug output. `MAILER_DSN=microsoft+graph://default`
also works when Graph is your only transport. MailKit's runtime DSN from the settings does **not** accept
`microsoft+graph` (the scheme is not in `dsn.allowed_schemes`): Graph credentials never live in a DSN.

### Getting the Graph id back: `GraphMailSender`

When you store the sent message (to match replies later), call the sender directly. Its input is plain value objects:

```php
use Nowo\MailKitBundle\Graph\Send\GraphAttachment;
use Nowo\MailKitBundle\Graph\Send\GraphMailSender;
use Nowo\MailKitBundle\Graph\Send\GraphOutgoingMail;
use Nowo\MailKitBundle\Graph\Send\GraphRecipient;
use Nowo\MailKitBundle\Graph\Send\PlusAddress;

$result = $graphMailSender->send(new GraphOutgoingMail(
    to: [new GraphRecipient('client@example.net', 'Client')],
    subject: 'Offer for task 42',
    text: $text,
    html: $sanitisedHtml,
    cc: [],
    bcc: [],
    replyTo: [new GraphRecipient(PlusAddress::compose('projects@example.com', 't-42'))],
    attachments: [
        GraphAttachment::fromPath($path, 'offer.pdf', 'application/pdf'),   // read while uploading
        GraphAttachment::fromStream($stream, 'plan.png', 'image/png', inline: true, contentId: 'plan@task-42'),
    ],
    messageId: $ownId,            // e.g. "t42-7f3c…@example.com"; null = generated on the mailbox domain
    inReplyTo: $parentMessageId,  // without angle brackets (brackets are stripped)
    references: $threadIds,       // oldest first, the newest 50 are kept
    fromName: 'Projects',
), 'default');                    // profile; null = the provider's default

$result->graphId;            // immutable Graph id (Drafts → Sent Items keeps it)
$result->internetMessageId;  // "<t42-7f3c…@example.com>" as Graph reports it
$result->conversationId;     // Outlook conversation id (reply fallback)
```

`GraphAttachment` sources: `fromPath()` (opened only while uploading), `fromStream()` (a non-seekable stream is
copied once to `php://temp`, capped at 35 MiB unless you pass `$maxBytes` — pass your `graph.limits.max_attachment_bytes`), `fromString()`. File names are neutralised (no directory part, no control or
bidi characters, at most 200 characters); a content type that is not a plain `type/subtype` token becomes
`application/octet-stream`. The HTML is sent as you pass it: sanitise user-written HTML first. The value objects
hold streams and are not serializable; queue an ID and build the mail in the handler (or use the mailer transport,
whose `Email` is serializable).

Limits (`graph.limits`) are checked before any call: number of attachments, bytes per attachment, bytes per message.
The MIME part (headers and bodies, no attachments) is capped at 2.9 MB so its base64 request stays under Graph's
4 MB limit.

### Threading and plus addressing

- Give every message your own `messageId` and store it (and the returned `internetMessageId`); answer with
  `inReplyTo` = the parent and `references` = the thread. MailKit writes them into the MIME; if Exchange ever
  replaced the Message-ID, the result returns Graph's value and a notice is logged.
- `PlusAddress::compose('projects@example.com', 't-42')` gives `projects+t-42@example.com` (tags:
  `[A-Za-z0-9._=-]{1,64}`); `PlusAddress::tag($address, $mailbox)` reads the tag back from a recipient (for the
  inbound side).

### Errors

Everything fails with `Nowo\MailKitBundle\Graph\GraphException` (the transport rethrows it as a Mailer
`TransportException` with the same message): `reason` (`configuration`, `authentication`, `api`, `throttled`,
`transport`, `invalid_message`, `untrusted_url`, `ambiguous`), `status`, `graphCode`, `requestId`, `retryAfter`, and
`isRetryable()`. Messages contain only those — never a response body, a URL, a token, the secret or message content
— and no `previous` exception. Throttling (429 / 503) is not retried in the request: retry later (Messenger).

### DDD / hexagonal host: your own mailbox port

Keep the tenant, client id, secret and mailbox in your own (encrypted) settings and implement the port; profiles
can be any key you choose (an organization id…). MailKit calls it on every send and keeps nothing:

```php
use Nowo\MailKitBundle\Graph\GraphException;
use Nowo\MailKitBundle\Graph\GraphMailbox;
use Nowo\MailKitBundle\Graph\GraphMailboxProviderInterface;
use Nowo\MailKitBundle\Graph\GraphSecret;

final readonly class OrganizationMailboxes implements GraphMailboxProviderInterface
{
    public function __construct(private MailSettingsRepository $settings) {}

    public function mailbox(string $profile): GraphMailbox
    {
        $s = $this->settings->forOrganization($profile) ?? throw GraphException::configuration('Not configured.');

        return new GraphMailbox($profile, $s->tenantId, $s->clientId, new GraphSecret($s->decryptedSecret()), $s->mailbox);
    }

    public function defaultProfile(): string
    {
        return 'instance';
    }
}
```

```yaml
nowo_mail_kit:
    mailer: { dsn_provider: App\Mail\DsnProvider, sender_provider: App\Mail\SenderProvider }  # without Instance Settings
    log: { enabled: false }
    graph:
        enabled: true
        mailbox_provider: App\Mail\OrganizationMailboxes
```

The port, `GraphMailbox`, `GraphSecret`, `GraphOutgoingMail`, `GraphRecipient`, `GraphAttachment`, `GraphSendResult`
and `GraphException` use no Doctrine, HttpFoundation or Mime type. `GraphMailbox` validates the values (tenant GUID or
domain, client id GUID, mailbox address, profile `[A-Za-z0-9_.-]{1,64}`) so they cannot inject a path into the token
or Graph URLs; `GraphSecret` is never printed (`[redacted]`) and refuses serialization.

### Worker mode

The token provider, Graph client, sender and transport hold no tenant, token or secret in a property: the mailbox is
resolved and the token read from the cache on every send, so two organizations alternating on one FrankenPHP worker
(kernel reset false, no services resetter) each use their own mailbox and token. See
[FRANKENPHP-WORKER-AUDIT.md](FRANKENPHP-WORKER-AUDIT.md).

## Inbound mail from a Graph mailbox

Since 1.9 (opt-in, `graph.inbound.enabled`; needs `graph.enabled`, symfony/messenger and a DBAL connection) MailKit
reads the inbox of the Graph mailboxes and hands every new message, normalised, to **your**
`GraphInboundHandlerInterface`, which routes, quarantines or discards it. Microsoft 365 side (permissions, webhook
URL): [MICROSOFT-GRAPH-SETUP.md](MICROSOFT-GRAPH-SETUP.md#webhook-url-requirements-inbound-mailkit-19); options:
[CONFIGURATION.md](CONFIGURATION.md#graphinbound).

### How a message comes in

1. **Webhook** — Graph POSTs a change notification to `graph.inbound.webhook.path`. MailKit checks the subscription
   (stored, unexpired, of an inbound profile) and its `clientState` (constant time), then only dispatches
   `FetchGraphMessage(profile, graphId)` and answers 202. Lifecycle events dispatch `RenewGraphSubscriptions` with a
   reason and the subscription id (`reauthorize` → `POST /subscriptions/{id}/reauthorize`; `removed` → best-effort
   delete and recreate; both only when the id is the profile's current subscription) or `SweepGraphMailbox`
   (missed); unknown lifecycle values are logged and ignored.
2. **Delta sweep** — `nowo:mail-kit:graph:sweep` (or `SweepGraphMailbox`) walks the folder's delta query from the stored
   link and dispatches the same `FetchGraphMessage` for every new message: missed notifications and hosts without a
   public URL. The first run reads back `sweep.initial_window` only.
3. **Fetch** (your Messenger worker) — `GraphInboundProcessor` reads the message (capped), **claims** it by
   internetMessageId (a message seen by both paths is processed once), reads the attachments into capped temporary
   streams (type detected from the bytes), builds a `GraphInboundMessage` and calls your handler. The decision is
   stored with the claim; your exception releases the claim, so Messenger's retry processes the message again.

### Standard Symfony host

```yaml
# config/packages/nowo_mail_kit.yaml
nowo_mail_kit:
    graph:
        enabled: true
        profiles:
            default: { tenant_id: '%env(MAIL_KIT_GRAPH_TENANT_ID)%', client_id: '%env(MAIL_KIT_GRAPH_CLIENT_ID)%', client_secret: '%env(MAIL_KIT_GRAPH_CLIENT_SECRET)%', mailbox: projects@example.com }
        inbound:
            enabled: true
            handler: App\Mail\TaskMailInbound
            webhook:
                notification_url: 'https://app.example.com/hooks/mail-kit/v1/graph'

# config/packages/messenger.yaml — the webhook must answer fast: handle the fetch asynchronously
framework:
    messenger:
        routing:
            Nowo\MailKitBundle\Graph\Inbound\Message\FetchGraphMessage: async
            Nowo\MailKitBundle\Graph\Inbound\Message\SweepGraphMailbox: async
            Nowo\MailKitBundle\Graph\Inbound\Message\RenewGraphSubscriptions: async

# config/routes/nowo_mail_kit.yaml
nowo_mail_kit_graph_webhook:
    resource: .
    type: nowo_mail_kit_graph_webhook

# config/packages/security.yaml — public (Graph sends no credentials; MailKit checks the client state), stateless
security:
    access_control:
        - { path: '^/hooks/mail-kit/', roles: PUBLIC_ACCESS }
```

Offboarding a mailbox: drop its profile from `inboundProfiles()` (its notifications are refused at once) and run
`nowo:mail-kit:graph:subscriptions --profile=<name> --delete`.

Tables: `doctrine:migrations:diff` picks up `mail_kit_graph_subscription`, `mail_kit_graph_delta` and
`mail_kit_graph_inbound` (with `doctrine.table_prefix`) through a `postGenerateSchema` listener; without migrations,
`nowo:mail-kit:graph:schema` prints the SQL (`--force` runs it). Since 1.11.0 the key columns (`subscription_id`,
`profile`, `message_key`, `status`) get a binary NO PAD collation on MySQL / MariaDB instead of the host default, so
they compare byte for byte (REQ-SEC-006); tables created before 1.11.0 need the `ALTER` in
[`UPGRADING.md`](UPGRADING.md#to-1110). Schedule `nowo:mail-kit:graph:subscriptions`
hourly and `nowo:mail-kit:graph:sweep` every 5 minutes (cron, or Symfony Scheduler with
`RecurringMessage::every('1 hour', new RenewGraphSubscriptions())` and `RecurringMessage::every('5 minutes', new SweepGraphMailbox())`).

```php
use Nowo\MailKitBundle\Graph\Inbound\GraphInboundHandlerInterface;
use Nowo\MailKitBundle\Graph\Inbound\GraphInboundMessage;
use Nowo\MailKitBundle\Graph\Inbound\InboundDecision;

final class TaskMailInbound implements GraphInboundHandlerInterface
{
    public function handle(GraphInboundMessage $message): InboundDecision
    {
        $task = $this->tasks->byMailTag($message->plusTags[0] ?? null)
            ?? $this->tasks->byThread($message->inReplyTo, $message->references, $message->conversationId);
        if ($task === null) {
            return InboundDecision::quarantine('no_task');
        }
        if (!$task->hasContact($message->from?->address)) {
            return InboundDecision::quarantine('unknown_sender', $this->quarantine->store($task, $message)->id());
        }
        foreach ($message->attachments as $attachment) {
            if ($attachment->isAccepted()) {
                $this->files->store($task, $attachment->filename, $attachment->contentType, $attachment->stream()); // copy now
            }
        }

        return InboundDecision::route($this->thread->append($task, $message)->id());
    }
}
```

### The normalised message

| Field | Content |
| --- | --- |
| `from`, `sender`, `replyTo`, `to`, `cc` | `InboundAddress` (lower-cased address, display name without control / bidi characters, `tag` = plus-address tag of the receiving mailbox). |
| `plusTags` | Distinct tags of the mailbox found in To, Cc and the envelope headers Exchange may keep (`Delivered-To`, `X-Original-To`, …): a Bcc or a forward to `projects+t-42@…` still names its thread when such a header is present. |
| `subject`, `text` | Control characters removed; `text` is the text body, or the plain text of the sanitised HTML. |
| `html` | Sanitised (see below), or null for a text-only message. Still render it in a sandboxed / CSP-restricted view. |
| `internetMessageId`, `inReplyTo`, `references`, `conversationId` | Threading ids (no angle brackets; References oldest first, newest 100). |
| `authentication` | `AuthenticationResults` (`spf`, `dkim`, `dmarc`, `aligned()`) of the **first** `Authentication-Results` header, only for mail Exchange received from outside (`X-MS-Exchange-Organization-AuthAs: Anonymous`), else null verdicts. This assumes Exchange Online Protection strips `X-MS-Exchange-Organization-*` and its own `Authentication-Results` from incoming mail (header firewall); in hybrid / on-premises routing (mail entering through your own servers) the first header may be another hop's — treat verdicts as advisory there. A coarse spam signal for your quarantine rule — **not** a user authentication (neither is a plus-address tag: anyone can write to it). |
| `oversized` | The message was over `limits.max_message_bytes`: subject, sender and ids only — no body, headers, threading headers, verdicts or attachments, and no To / Cc when even the envelope is over 256 KiB. Quarantine it or fetch it yourself. |
| `forwarded` | Subject prefix (`Fwd:`, `RV:`, `WG:` …), an attached e-mail, `Resent-From` / `X-Forwarded-*` headers or a forward marker in the text. |
| `attachments` | `InboundAttachment`: neutralised `filename`, `safeFilename` (extension of the detected type — store and serve under this name), `declaredContentType` (informative), `contentType` **detected** with finfo, `size`, `inline` / `contentId` (for `cid:` images), `stream()` while your handler runs, or a `rejection` (`type`, `extension`, `size`, `count`, `total_size`, `unsupported`). Serve stored files with the detected type, `Content-Disposition: attachment` and `X-Content-Type-Options: nosniff`. |

HTML sanitising (symfony/html-sanitizer): safe elements and attributes only — no `script`, `style`, `iframe`, `form`,
event handlers or `style` attributes; links `http` / `https` / `mailto` with `rel="noopener noreferrer nofollow"`; no
relative URLs; images `cid:` (the message's inline parts) only, remote `http(s)` images only with
`allow_remote_images: true`. The input is cut to `limits.max_html_bytes`.

### DDD / hexagonal host

The ports are plain PHP: `GraphInboundHandlerInterface` (a `GraphInboundMessage` in, an `InboundDecision` out),
`GraphInboundProfilesProviderInterface` (the profiles to read, e.g. the organizations with a configured mailbox) and the
1.8 `GraphMailboxProviderInterface`. Without Instance Settings and with your own mailbox port:

```yaml
nowo_mail_kit:
    mailer: { dsn_provider: App\Mail\DsnProvider, sender_provider: App\Mail\SenderProvider }
    log: { enabled: false }
    graph:
        enabled: true
        mailbox_provider: App\Mail\OrganizationMailboxes
        inbound:
            enabled: true
            handler: App\Mail\TaskMailInbound
            profiles_provider: App\Mail\OrganizationInboundProfiles
            connection: default
            webhook: { notification_url: '%env(MAIL_KIT_GRAPH_WEBHOOK_URL)%' }
```

MailKit writes its three tables through DBAL on `connection` (never your entity manager, never a flush; a claim made
inside your open transaction uses a savepoint). Commit your own writes inside `handle()`.

### Worker mode and limits

Every inbound service is stateless: the mailbox, token and profile are resolved per message, attachment streams are
closed when the message is done, and nothing is kept between requests or messages (tested with 600 webhook requests
for two organizations in one kernel without the services resetter). Limits: `webhook.max_body_bytes`,
`max_notifications`, `rate_limit` (verified requests), `client_rate_limit` (unverified, per client IP),
`allowed_ips`; `limits.*` for the message and its attachments (`application/zip` must be added explicitly). Logs carry the profile, the decision
and counts — never addresses, subjects, bodies or file names.

## Open and click tracking

Since 1.10 (Beacon spec 230 block B). **Off by default** (`nowo_mail_kit.tracking.enabled: false`): nothing is
registered and every e-mail goes out exactly as in 1.9. Configuration: [CONFIGURATION — tracking](CONFIGURATION.md#tracking);
privacy and security notes: [SECURITY](SECURITY.md#e-mail-open-and-click-tracking-110).

### What happens to a tracked e-mail

Right before delivery (in the Messenger worker for queued mail), the runtime transport asks `Tracking\EmailTracker` for
a **tracked copy**; the original message is never changed (a Messenger retry re-delivers it untracked and gets a new send).

1. Your `EmailTrackingPolicyInterface` decides per template id and recipient (`TrackingCandidate`: template id, the only
   envelope recipient, the recipient id / tenant hints) and answers `TrackingDecision::none()` or
   `TrackingDecision::track($recipientId, $tenant, pixel: true, links: true)`.
2. A send row is stored (`<prefix>mail_kit_tracking_send`): an opaque **send id** (128 random bits), template id,
   recipient id, tenant, log id. No address, subject or body. `send_id`, `recipient_id` and `tenant` compare byte for
   byte: `TrackingIds` refuses an identifier with surrounding white space, control characters or invalid UTF-8, and
   since 1.11.0 the columns themselves carry a binary **NO PAD** collation on MySQL / MariaDB (`utf8mb4_0900_bin` on
   MySQL 8.0.17+, `utf8mb4_nopad_bin` on MariaDB), so `'bob'` never matches `'bob '`, `'Bob'` or `'bób'` even for rows
   written around the bundle. Tables created before 1.11.0 need the `ALTER` in
   [`UPGRADING.md`](UPGRADING.md#to-1110).
3. Every absolute `http(s)` link (`<a>`, `<area>`, Outlook VML `<v:roundrect>` buttons) that is not credential-bearing and
   that your allow-list accepts goes through your `EmailLinkRewriterInterface` (`TrackedLink`: send id, URL, label,
   1-based position, `essential` flag, template, recipient id, tenant). The text part gets the same tracking URLs.
4. A 1×1 transparent GIF `<img src="https://app.example.com/e/o/{token}.gif">` is added before `</body>`. The token is
   `base64url(send id . issued at . HMAC)`: it never contains the address.
5. `MailDeliveredEvent::$trackingId` carries the send id (null when untracked); a failed delivery forgets the send.

Tag the message so the policy knows what it is:

```php
use Nowo\MailKitBundle\Mailer\MailHeaders;

MailHeaders::template($email, 'issue_alert');                  // template id (falls back to MailHeaders::tag())
MailHeaders::trackingRecipient($email, $user->getId()->toRfc4122(), $organization->getSlug()); // hints for the policy
MailHeaders::noTracking($resetEmail);                         // never track this one (security mail)
```

These headers are internal: they are removed from the copy that leaves the application (also with tracking off), never
from your message object — a Messenger retry re-sends that object with its headers intact and is decided again.

### What is never tracked

No pixel, links untouched, **no row stored** when:

- the message has `X-Mail-Kit-No-Track`, more than one envelope recipient (To + Cc + Bcc), no HTML body, an HTML body
  larger than `max_html_bytes`, or already carries this application's pixel;
- its template id matches the built-in security patterns (`EmailTracker::SECURITY_TEMPLATES`: password / reset / magic /
  login / sign-in / one-time / OTP / code / verify / confirm / security / new device / e-mail change / invitation / 2FA /
  MFA / token — always applied) or `tracking.extra_never_track_templates`;
- it declares `MailHeaders::redact()` values (one-time links and codes) while `skip_redacted` is on (default);
- the policy says `none()` (security templates, opted-out recipients) or throws, or the row cannot be stored.

Inside a tracked e-mail a link is **never rewritten** when it is not absolute `http(s)` (`mailto:`, `tel:`, `#anchor`,
relative, `cid:`), carries user info, a credential-like query / fragment parameter (`token`, `code`, `otp`, `signature`,
`_hash`, `X-Amz-Signature`…), a credential-like path segment (`/reset-password`, `/login/…`, `/magic`, `/verify-email`,
`/invitations/…/accept`, `/oauth`…), contains a redaction value, is marked `data-mail-kit-track="off"`, is not on the
allow-list (`links.allowed_hosts` / `allowed_path_prefixes`, or your `TrackableLinkFilterInterface`), or when the rewriter
returns null, something that is not an absolute `http(s)` URL, or throws. Path parameters (`;jsessionid=…`) count like query
parameters.

**Token-bearing unsubscribe / one-click links** (`/unsubscribe?token=…`, `List-Unsubscribe` targets) are never
rewritten by the credential rule; if yours carry the token in the path without a credential word, mark them
`data-mail-kit-track="off"` or keep their route outside `links.allowed_path_prefixes`. List route prefixes of ordinary
content in the allow-list (`/issues`, `/projects`), never `/` or `*`. HTML comments (except Outlook conditional
blocks), `<script>` and `<style>` are not touched, and malformed HTML is kept byte for byte around the rewritten `href`s.

### Standard Symfony host

```yaml
# config/packages/nowo_mail_kit.yaml
nowo_mail_kit:
    tracking:
        enabled: true
        policy: App\Mail\MailTrackingPolicy
        public_base_url: '%env(APP_PUBLIC_URL)%'      # https://app.example.com (needs mailer.decorate: true, the default)
        secret: '%env(MAIL_KIT_TRACKING_SECRET)%'      # ≥ 32 bytes; or null to derive it from kernel.secret
        links:
            allowed_hosts: [app.example.com]           # nothing is rewritten until a host is listed
```

```yaml
# config/routes/nowo_mail_kit.yaml
nowo_mail_kit_tracking:
    resource: .
    type: nowo_mail_kit_tracking

# config/packages/security.yaml — before the main firewall: no session, no user, no cookie
security:
    firewalls:
        mail_kit_pixel:
            pattern: ^/e/o/
            security: false
```

Create the table with `doctrine:migrations:diff` (ORM hosts; schema listener) or `nowo:mail-kit:tracking:schema`
(prints the SQL; `--force` runs it). Schedule `nowo:mail-kit:tracking:purge` daily.

### Beacon example: policy and short-link rewriter

```php
namespace App\Mail;

use Nowo\MailKitBundle\Tracking\EmailTrackingPolicyInterface;
use Nowo\MailKitBundle\Tracking\TrackingCandidate;
use Nowo\MailKitBundle\Tracking\TrackingDecision;

final readonly class MailTrackingPolicy implements EmailTrackingPolicyInterface
{
    private const SECURITY = ['magic_link', 'password_reset', 'login_code', 'new_device', 'sign_in_alert', 'email_change', 'invitation_accept'];

    public function __construct(private UserNotificationPreferences $preferences) {}

    public function decide(TrackingCandidate $candidate): TrackingDecision
    {
        if ($candidate->templateId === null || in_array($candidate->templateId, self::SECURITY, true) || $candidate->recipientId === null) {
            return TrackingDecision::none();
        }
        // Resolved on every call (worker mode): the user may have opted out a second ago.
        if (!$this->preferences->tellSendersWhenIOpen($candidate->recipientId)) {
            return TrackingDecision::none();
        }

        return TrackingDecision::track($candidate->recipientId, $candidate->tenant);
    }
}
```

```php
namespace App\Mail;

use Nowo\MailKitBundle\Tracking\EmailLinkRewriterInterface;
use Nowo\MailKitBundle\Tracking\TrackedLink;

final readonly class ShortLinkEmailRewriter implements EmailLinkRewriterInterface
{
    public function __construct(private ShortLinks $shortLinks) {}

    public function rewrite(TrackedLink $link): ?string
    {
        // Kind "email": records send id, label and position on click, then 302s to $link->url.
        // An essential link (unsubscribe, preferences, legal) must never be blocked, expired or rate-limited.
        return $this->shortLinks->createForEmail(
            target: $link->url,
            tenant: $link->tenant,
            sendId: $link->sendId,
            label: $link->label,
            position: $link->position,
            neverBlock: $link->essential,
        )->publicUrl();
    }
}
```

```yaml
# Beacon: config/packages/nowo_mail_kit.yaml
nowo_mail_kit:
    tracking:
        enabled: true
        policy: App\Mail\MailTrackingPolicy
        link_rewriter: App\Mail\ShortLinkEmailRewriter
        recipient_resolver: App\Mail\TrackingRecipients    # GDPR bridge: the user's recipient ids
        public_base_url: '%env(BEACON_PUBLIC_URL)%'
        secret: '%env(MAIL_KIT_TRACKING_SECRET)%'
        retention: P90D
        bus: messenger.bus.default                       # optional: route RecordMailOpen to an async transport
        links:
            allowed_hosts: ['%env(BEACON_PUBLIC_HOST)%']
            allowed_path_prefixes: [/issues, /projects, /account/notifications, /legal]
    personal_data:
        enabled: true                                    # email_tracking eraser + export section (UserKit)
```

```php
// When sending (spec 133 templates):
MailHeaders::template($email, $template->id());
MailHeaders::trackingRecipient($email, $user->getId()->toRfc4122(), $organization->getSlug());
```

A DDD host wires exactly the same ports: they are plain PHP (interfaces and value objects, no Doctrine, Symfony or
HTTP types); the store is bundle-owned (DBAL, its own table) and never touches the host's entity manager.

### The pixel endpoint

`GET|HEAD <path_prefix>/{token}.gif` (route `nowo_mail_kit_tracking_open`, `_stateless`) answers **the same 43-byte GIF**
for valid, expired, forged and unknown tokens, with `Cache-Control: no-store`, `Referrer-Policy: no-referrer`,
`X-Robots-Tag: noindex`; an internal failure is logged and still answers the GIF. For a valid token it counts one
open with a single indexed `UPDATE … WHERE send_id = ?` (no session, no ORM, no read), or only dispatches
`Tracking\Message\RecordMailOpen` with `tracking.bus`. `HEAD` never counts.

An open is a **machine open** (Apple Mail Privacy Protection, image proxies, link scanners) when the user agent is empty
or matches `machine_opens.user_agents`, or the client address is in `machine_opens.ip_ranges` (default Apple
`17.0.0.0/8`); machine opens are counted apart and never count as "opened". Only the truncated address (IPv4 /24,
IPv6 /48) and the user-agent family (`OpenClassifier::FAMILIES`) of the last open are stored.

### Reading the results

```php
use Nowo\MailKitBundle\Event\MailDeliveredEvent;
use Nowo\MailKitBundle\Event\MailOpenedEvent;
use Nowo\MailKitBundle\Tracking\EmailTrackingReaderInterface;

#[AsEventListener]
public function onDelivered(MailDeliveredEvent $event): void
{
    // Link your e-mail log row to $event->trackingId (null when untracked).
}

$stats = $reader->find($sendId);           // OpenStats: opens, first / last, machineOpens, …, or null
$many  = $reader->findMany($sendIds);      // keyed by send id (an e-mail log page)
```

`MailOpenedEvent` / `MailMachineOpenedEvent` (send id and time only) are dispatched after each counted open of a known
send. Clicks are recorded by your link rewriter's redirect (e.g. the short-link bundle), not by MailKit. Each send counts at most
`tracking.max_opens_per_send` opens (default 1000). `TrackedLink::$label` is the link's visible text as written by the
e-mail's author (entities decoded, tags stripped, at most 100 characters): escape it wherever you display it, and keep it
under the same retention as the click rows.

### Retention, GDPR and worker mode

- `nowo:mail-kit:tracking:purge` deletes sends older than `tracking.retention` (default `P90D`); tokens older than
  that are refused anyway.
- `EmailTrackingPersonalDataInterface::exportRecipient()` / `eraseRecipient()` export / erase every row of a recipient
  id (exact, binary match, re-checked in PHP). With `personal_data.enabled` and UserKit, the `email_tracking` eraser and
  export section call them with the ids of your `TrackingRecipientResolverInterface` (default: `getId()`). Sends tracked
  without a recipient id cannot be attributed: only the purge removes them.
- **Opt-out**: the policy stops new tracking at once; call `EmailTrackingPersonalDataInterface::eraseRecipient()` from
  your opt-out handler too when turning tracking off must also remove what was already recorded (and delete the
  recipient's click rows in your short-link store).
- **Recipient id**: return one from the policy for every tracked send (recommended: refuse to track without one), so
  export and erasure can find the rows.
- **Client address**: set `framework.trusted_proxies` to your proxies only; the truncated address and the machine-open
  ranges use `Request::getClientIp()`.
- Every tracking service is immutable (FrankenPHP worker mode, kernel reset off): the policy, rewriter and filter are
  asked on every send, the store resolves its connection per call, nothing is memoised. Tested with 1 000 pixel
  requests for two tenants in one kept-alive kernel without the services resetter (flat memory, p95 < 20 ms).

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

Tables: `<prefix>mail_kit_sent_mail` (indexes `<prefix>idx_mail_kit_sent_mail_queued`, `<prefix>idx_mail_kit_sent_mail_status`) and `<prefix>mail_kit_sent_mail_body`; with `tracking.enabled` (1.10) also `<prefix>mail_kit_tracking_send` (DBAL, added by a `postGenerateSchema` listener, or printed by `nowo:mail-kit:tracking:schema`). The Instance Settings table holds the settings themselves.
