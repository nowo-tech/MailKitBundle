# Upgrading

## Table of contents

- [Unreleased](#unreleased)
  - [To 1.3.0](#to-130)
- [To 1.2.0](#to-120)
  - [To 1.2.0](#to-120-1)
- [To 1.1.1](#to-111)
- [To 1.1.0](#to-110)
- [To 1.0.0 (initial release)](#to-100-initial-release)
  - [Requirements](#requirements)
  - [Enable and configure](#enable-and-configure)
  - [Migrating from an in-app mailer (Beacon-style)](#migrating-from-an-in-app-mailer-beacon-style)
  - [Template contract](#template-contract)
  - [Breaking changes](#breaking-changes)

## Unreleased

### To 1.3.0

From **1.2.x**. The bundle now requires `nowo-tech/instance-settings-bundle` ^1.3.1 and `nowo-tech/ui-kit-bundle`
^2.23:

```bash
composer update nowo-tech/mail-kit-bundle nowo-tech/instance-settings-bundle nowo-tech/ui-kit-bundle --with-dependencies
```

**Only installs with `resend.enabled: true` are affected.** Without it the `mailer` page, its keys and the behaviour
are unchanged.

What changed with `resend.enabled: true`:

- The `resend` settings section is gone. Its fields moved into the `mailer` section, which gets an explicit provider
  choice and shows only the selected provider's rows (InstanceSettings 1.3 `visible_when`):

  | 1.2 key | 1.3 key |
  | --- | --- |
  | `resend.enabled` (bool) | `mailer.provider` (`resend` when it was on, else `dsn`) |
  | `resend.api_key` (secret) | `mailer.resend_api_key` (secret) |
  | `resend.from` | `mailer.resend_from` |

  `mailer.dsn`, `mailer.from`, `mailer.from_name`, `mailer.reply_to`, `mailer.test_recipient` keep their keys.
- `bootstrap.resend_api_key` now seeds `mailer.resend_api_key`.
- "Check the API key" / "Send a test e-mail with Resend" are on the mailer page; their routes, names and CSRF token ids
  are unchanged. They now require the Instance Settings access of the `mailer` section (was `resend`).
- Saving the mailer page with `provider = resend` checks the key that would go live against the Resend API and refuses
  the save when Resend rejects it (audit MK-L1). The check makes one outbound HTTPS call per such save and shares
  `test_email.rate_limit`.
- `MailSettings::missingFields()` reports the selected provider only (`mailer.dsn` or `mailer.resend_api_key`); the
  1.2 names `resend.api_key` are no longer reported.

**Migrate the stored rows** (once, after deploying 1.3):

```bash
php bin/console nowo:instance-settings:seed-from-fallbacks --section=mailer --dry-run
php bin/console nowo:instance-settings:seed-from-fallbacks --section=mailer --strict
```

The mailer section exposes the old `resend` rows as its fallbacks, so the existing InstanceSettings seeder copies them
(insert-only: a value already stored in the mailer section wins; field names only in the output; also callable from a
non-transactional Doctrine migration, as in [To 1.2.0](#to-120)). Until then nothing breaks: with no
`mailer.provider` stored, `MailSettings` reads the old rows (Resend stays live when it was) and the mailer page shows a
notice. **Do not save the mailer page before the seed**: its provider row shows the default `dsn` until the rows are
copied, and saving it would store `dsn` and switch the delivery away from Resend.

After the seed the old `resend` rows are no longer read. They stay in the Instance Settings table (the encrypted 1.2
key included); delete them when convenient, e.g. in a Doctrine migration:
`DELETE FROM <prefix>instance_setting_value WHERE section = 'resend'`.

Host code:

- `MailSettings` keeps every public method (`dsn()`, `resendActive()`, `resendApiKey()`, `resendDsn()`, `from()`,
  `resendFrom()`, `mailerFrom()`, `replyTo()`, `testRecipient()`, `missingFields()`), now backed by the new keys;
  `provider()` and `legacyResendPending()` are new. Its constructor got an optional trailing argument.
- Code reading `resend.*` keys through `InstanceSettingsInterface` must switch to the `mailer.*` keys above (reading an
  unregistered section throws `UnknownSettingException`).
- `ResendSettingsSection` is now a deprecated holder of the `NAME` constant (`'resend'`), no longer a section service.
- Instance Settings `security.area_roles` for `resend` no longer matter; the `mailer` roles cover Resend.
- Scripts calling `seed-from-fallbacks --section=resend` must drop that option (unknown section).
- Register the UiKit `visible-when` Stimulus peer so the provider rows hide in the browser (InstanceSettings 1.3
  [UPGRADING](https://github.com/nowo-tech/InstanceSettingsBundle/blob/main/docs/UPGRADING.md)); without it every row
  stays visible and the server rules still apply.

## To 1.2.0

From **1.1.1**:

```bash
composer update nowo-tech/mail-kit-bundle
```

### To 1.2.0

From **1.1.x**. The bundle now requires `nowo-tech/instance-settings-bundle` ^1.2:

```bash
composer update nowo-tech/mail-kit-bundle nowo-tech/instance-settings-bundle --with-dependencies
```

No BC break; new, optional:

- `bootstrap.mailer_dsn` / `bootstrap.resend_api_key` (default null): one-time bootstrap values for
  `nowo:instance-settings:seed-from-fallbacks` (DB-only mode).
- Settings page warnings and `MailStatus::$missingFields` / `OutboundMailReadiness::missingFields()` for health pages.
  `MailStatus` and `OutboundMailReadiness` got an optional trailing constructor argument (named arguments unaffected).

To go DB-only, **in this order**: (1) upgrade and map `bootstrap.mailer_dsn: '%env(default::MAILER_DSN)%'` (and
`bootstrap.resend_api_key`) while the env vars still exist; (2) run
`php bin/console nowo:instance-settings:seed-from-fallbacks --section=mailer --section=resend` (1.2; since 1.3 `--section=mailer` only) (try `--dry-run`
first); (3) only then remove the `bootstrap` keys and the env vars, and set `framework.mailer.dsn` to a literal such as
`null://null`. Removing them first would seed nothing. From a Doctrine migration, call the seeder from a
non-transactional migration (see InstanceSettings
[USAGE](https://github.com/nowo-tech/InstanceSettingsBundle/blob/main/docs/USAGE.md#calling-the-seeder-from-a-migration)).

## To 1.1.1

From **1.1.0**:

```bash
composer update nowo-tech/mail-kit-bundle
```

- `nowo-tech/controller-kit-bundle` is now required at `^2.3.1` (`composer update nowo-tech/controller-kit-bundle`).
  Exported cells with a formula trigger after an embedded `;`, `,`, tab, `|` or line break now read `Hi; =x` instead
  of `Hi;=x`, and the response sends `X-Content-Type-Options: nosniff`.
- **Notable behaviour change:** `web_ui.export.max_rows` accepts at most **100 000** (was 1 000 000). A configuration
  above it no longer compiles; lower it, or narrow the filters of the export.
- New optional `web_ui.export.body_batch_bytes` (default 8 MiB, 1 MiB … 64 MiB): approximate body bytes loaded per
  query by the export with bodies. Nothing to change unless you want a different memory bound.

## To 1.1.0

From **1.0.0**:

```bash
composer update nowo-tech/mail-kit-bundle
```

No code change is required. Review two defaults:

- The log list now offers **"Export CSV"** to whoever can open it (`web_ui.export.enabled: true`). To keep it for a
  narrower role, set `web_ui.export.access_roles: [ROLE_…]`; to remove it, `web_ui.export.enabled: false`. Bodies are
  never exported unless you also set `web_ui.export.include_bodies: true` (needs `log.store_bodies`).
- New requirements: `nowo-tech/ui-kit-bundle` `^2.20` and `nowo-tech/controller-kit-bundle` `^2.3` (only its
  `Http\CsvStreamedResponse` / `CsvSafeWriter` classes are used; registering `NowoControllerKitBundle` is not needed).
- A template override of `log/index.html.twig` keeps working; the table headers now sort (UiKit `sorting`) and the
  filter form carries two hidden fields (`sort`, `dir`), rendered by `form_end` if your override does not.

## To 1.0.0 (initial release)

### Requirements

- PHP `>=8.2 <8.6`, Symfony 7.4 / 8.0 / 8.1, Doctrine ORM 3.3+, `symfony/mailer` with `framework.mailer` configured.
- `nowo-tech/instance-settings-bundle` ^1.0 registered (and DoctrineEncrypt configured for its secrets): the container
  refuses to compile without it.

### Enable and configure

1. `composer require nowo-tech/mail-kit-bundle` (Flex registers the bundle and copies the recipe).
2. Import the routes (opt-in) — `nowo_mail_kit` and `nowo_instance_settings` loaders.
3. Generate a migration for `mail_kit_sent_mail` / `mail_kit_sent_mail_body` (with your `doctrine.table_prefix`).
4. Add `access_control` rules for `/admin/mail` and `/admin/settings`.
5. Schedule `nowo:mail-kit:log:purge`.

### Migrating from an in-app mailer (Beacon-style)

Apps that kept the mailer DSN, sender and Resend key in their own settings entity and wrapped the mailer themselves:

1. **Move the values** into the Instance Settings rows (once, in a migration or a command):
   `$settings->save('mailer', ['dsn' => $old->getMailerDsn(), 'from' => $old->getMailerFrom()])` and, with
   `resend.enabled: true`, `$settings->save('resend', ['enabled' => $old->isResendEnabled(), 'api_key' => $old->getResendApiKey(), 'from' => $old->getResendFrom()])`.
   Then drop the old columns.
2. **Delete the wrappers**: the configured `MailerInterface` alias, the transport used by
   `mailer.messenger.message_handler`, the DSN validator / audit / sender helpers, the Resend checker, the settings
   controllers, forms and templates — MailKit's decorators cover both the sync and the Messenger path.
3. **Callers** of `sendNow()` / `sendSample()` use `Nowo\MailKitBundle\Mailer\RuntimeTransport::send()` (synchronous) or
   `TestMailSender`; `getFromAddress()` → `MailSettings::from()`; `isMagicLoginAvailable()` / `canDeliver()` →
   `OutboundMailReadiness::isOutboundMailReady()`; `MailerDsnAudit::redact()` keeps its shape (`scheme`, `host`, plus
   `port`).
4. **Log**: the old e-mail log table can be dropped or migrated into `mail_kit_sent_mail` (same columns, `template` →
   `tag`); the tag header is `X-Mail-Kit-Tag` (`MailHeaders::tag()`), the redaction header `X-Mail-Kit-Redact`
   (`MailHeaders::redact()`). Bodies are no longer stored unless `log.store_bodies: true`.
5. **Test environment**: MailKit does not swap SMTP for `null://` in `APP_ENV=test`; store no DSN in test databases (the
   framework test DSN is used) or set `mailer.decorate: false` in `when@test`.

### Template contract

First release: pages `log/index.html.twig` (list scaffold, block `nowo_ui_list_row`) and `log/show.html.twig` (show
scaffold, blocks `nowo_ui_show_after` / `nowo_mail_kit_text_body`), view models from `Ui\AdminPages`. Later changes to
blocks, view-model fields or theme slots will be listed here under "Template contract".

### Breaking changes

None (initial release).
