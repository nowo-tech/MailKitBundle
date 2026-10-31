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
- [Readiness and AuthKit](#readiness-and-authkit)
- [Sent-mail log](#sent-mail-log)
  - [Admin pages](#admin-pages)
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

MailKit contributes the section **`mailer`** to [Instance Settings](https://github.com/nowo-tech/InstanceSettingsBundle) (`/admin/settings/sections/mailer` by default) and, with `resend.enabled: true`, the section **`resend`**.

### Fields

| Key | Type | Default | Notes |
| --- | --- | --- | --- |
| `mailer.dsn` | secret (encrypted) | — | Validated ([DSN validation](#dsn-validation)); blank keeps the stored value, "Remove the saved value" clears it (back to `MAILER_DSN`). |
| `mailer.from` | e-mail | `sender.default_from` | Default From; must be on a public domain unless `sender.require_public_domain: false`. |
| `mailer.from_name` | string (≤ 120) | `sender.default_from_name` | Display name of the From. |
| `mailer.reply_to` | e-mail | — | Default Reply-To. |
| `mailer.test_recipient` | e-mail | — | Recipient of the test e-mails (else the signed-in user's address). |
| `resend.enabled` | bool | `false` | Send through Resend while a key is stored. |
| `resend.api_key` | secret (encrypted) | — | Resend API key (`re_…`). |
| `resend.from` | e-mail | — | Sender while Resend is active (else `mailer.from`). |

The section actions receive Instance Settings' `SectionActionContext` (section, request, user).

Read them like any setting (`InstanceSettingsInterface::getString('mailer.from')`), or through the typed `Nowo\MailKitBundle\Settings\MailSettings` service (`from()`, `replyTo()`, `dsn()`, `resendActive()`…). The section names and keys are a public contract: they are never renamed.

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

1. the Resend key (`resend+api://<key>@default`) when the `resend` section exists, is switched on and holds a key;
2. the `mailer.dsn` secret;
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

`MailKitMailer` and `RuntimeTransport` add `From` (`mailer.from` with `mailer.from_name`, or `resend.from` while Resend is active) and `Reply-To` (`mailer.reply_to`) **only when the message has none**:

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
        enabled: true      # adds the "resend" section; composer require symfony/resend-mailer
        api_timeout: 10
```

The section shows "Check the API key" (`GET https://api.resend.com/domains`, bounded by `api_timeout`, no redirects; lists the domains and their status, recognises sending-only keys) and "Send a test e-mail with Resend" (uses the stored key even while the switch is off). Programmatic check: `Nowo\MailKitBundle\Resend\ResendApiChecker::check($key)`.

## Readiness and AuthKit

```php
$readiness->isOutboundMailReady();   // bool
$status = $readiness->status();      // MailStatus: source (settings|resend|custom|fallback), label (scheme://host), ready, error
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

- `GET /admin/mail/log` — UiKit list: search (subject or recipient, ≥ 3 characters), status, period (24 h / 7 d / 30 d / all), `web_ui.page_size` rows per page, the current transport in the header.
- `GET /admin/mail/log/{id}` — envelope and delivery details; the stored text body (escaped).
- `GET /admin/mail/log/{id}/body` — stored HTML body as its own response with `Content-Security-Policy: default-src 'none'; …; sandbox` (no script, no remote loads, no forms), `nosniff`, `no-store` (only with `log.store_bodies`).

### Retention and erasure

```bash
php bin/console nowo:mail-kit:log:purge                       # rows older than log.retention_days (keyset batches)
php bin/console nowo:mail-kit:log:purge --older-than-days=7
php bin/console nowo:mail-kit:log:forget alice@example.com     # rows she sent or received deleted, address masked elsewhere (GDPR art. 17)
```

Schedule the purge (cron or Symfony Scheduler). `SentMailRecorder::forgetAddress()` (`['deleted' => n, 'redacted' => n]`) is the same erasure for an account-deletion flow; search and erasure use the normalised `recipient_index` column (each address NFC-normalised, lower-case, in its Unicode and punycode forms, separated by a control character) and work on SQLite, MySQL / MariaDB and PostgreSQL.

**Failures.** A failed send throws `Nowo\MailKitBundle\Mailer\DeliveryFailedException` (a `TransportException`): a short message with a reference (`The mail provider answered HTTP 403 (reference 1a2b3c4d).` for API bridges, the scrubbed SMTP answer otherwise). Provider response bodies and credentials never reach the log row, the flash or the Messenger failure transport; the scrubbed detail is logged (`Mail delivery failed.`, `reference`, `detail`).

## Reacting to changes and auditing

A save of the `mailer` / `resend` section dispatches Instance Settings' `SettingsChangedEvent`; MailKit's listener resets the runtime transport and logs `Mail settings changed.` (notice) with the section, field names, origin and the redacted new transport — never a value. `Nowo\MailKitBundle\Dsn\MailerDsnAudit::redact($dsn)` / `label($dsn)` give you the same redacted view for your own audit trail.

## Admin UI

The routes come only from the `nowo_mail_kit` loader (REQ-SF-004 rule 7):

| Route | Method | Path (default prefix) |
| --- | --- | --- |
| `nowo_mail_kit_log` | GET | `/admin/mail/log` |
| `nowo_mail_kit_log_show` | GET | `/admin/mail/log/{id}` |
| `nowo_mail_kit_log_body` | GET | `/admin/mail/log/{id}/body` (with `log.store_bodies`) |
| `nowo_mail_kit_test` | POST | `/admin/mail/actions/test` |
| `nowo_mail_kit_resend_check` | POST | `/admin/mail/actions/resend/check` (with `resend.enabled`) |
| `nowo_mail_kit_resend_test` | POST | `/admin/mail/actions/resend/test` (with `resend.enabled`) |

Every request under the prefix passes `MailKitAccessCheckerInterface` (`security.access_roles`, or your `security.access_checker`); the actions also need the Instance Settings access of their section and a valid CSRF token. Add a link to the log in your admin menu (`path('nowo_mail_kit_log')`).

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
