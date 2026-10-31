# Upgrading

## Table of contents

- [Unreleased](#unreleased)
- [To 1.0.0 (initial release)](#to-100-initial-release)
  - [Requirements](#requirements)
  - [Enable and configure](#enable-and-configure)
  - [Migrating from an in-app mailer (Beacon-style)](#migrating-from-an-in-app-mailer-beacon-style)
  - [Template contract](#template-contract)
  - [Breaking changes](#breaking-changes)

## Unreleased

Nothing yet beyond the initial 1.0.0 content below.

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
