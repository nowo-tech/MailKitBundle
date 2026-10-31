# Templates

Pages of MailKitBundle on the UiKit page contract (REQ-UI-003, UiKit 2.17+). The admin UI (`web_ui.path_prefix`,
default `/admin/mail`, enabled with `web_ui.enabled: true`) has two page templates, the sent-mail log list and detail.
`Nowo\MailKitBundle\Ui\AdminPages` builds the UiKit view models (`Nowo\UiKitBundle\View\*`) for the controller;
`Nowo\MailKitBundle\Ui\MailKitPages` resolves the layout (`web_ui.layout_template`, null = `nowo_ui_kit.layouts.admin`)
and the per-page overrides (`web_ui.templates.<page>`). Contract test: `tests/Integration/Contract/PageContractTest.php`
(UiKit `PageContractTestCase`, strict missing-translation rule). Twig namespace: `@NowoMailKitBundle` (views in
`src/Resources/views/`).

The mailer / Resend **settings** pages are Instance Settings pages (`@NowoInstanceSettingsBundle/settings/section.html.twig`):
MailKit contributes the sections (fields, labels in the `NowoMailKitBundle` domain) and their extra actions
(`Nowo\UiKitBundle\View\Action` with a FormKit CSRF-only `FormView`, rendered by UiKit's `_action_post` partial after
the Save button).

## Table of contents

- [Pages](#pages)
- [Settings actions](#settings-actions)
- [Personal data in the pages](#personal-data-in-the-pages)
- [Unique pages](#unique-pages)
- [Per-page overrides](#per-page-overrides)
- [Overriding templates (REQ-TWIG-001)](#overriding-templates-req-twig-001)
- [Bundle partials and assets](#bundle-partials-and-assets)
- [Translations](#translations)
- [Allowed exceptions](#allowed-exceptions)

## Pages

Every view model passes `bundle: 'NowoMailKitBundle'` (theme exception `nowo_ui_kit.theme.bundles.NowoMailKitBundle`)
and `layout: MailKitPages::layout()`. Labels are `NowoMailKitBundle` translation keys (`MailKitPages::label()`).

| Page (`web_ui.templates` id) | Template | Scaffold | View model | Data blocks filled |
| --- | --- | --- | --- | --- |
| Log list (`log_index`) | `log/index.html.twig` | `page/list` | `ListPage` from `AdminPages::listPage()`: header `log.index.title` + `log.index.intro_ready` / `intro_not_ready` (`%count%`, redacted `%transport%`, `%source%`); columns queued at, status, subject, to (priority 2), tag, transport (priority 3); GET filters (`SentMailFilterType`: `q`, `status`, `period`; FilterFields with widths; submit `log.action.filter`; clear link when filtered); row action "View" (`Action::view`); `Pagination` (`web_ui.page_size`); `EmptyState` `log.index.empty` / `empty_filtered`; root `data-testid="mail-kit-log"` | `nowo_ui_list_row` — status badge (`badge_success` / `badge_danger` / `badge_info` slots, `data-testid="mail-kit-status"`), subject linked to the detail, recipients joined, tag and transport in the `code` slot |
| Log entry (`log_show`) | `log/show.html.twig` | `page/show` | `ShowPage` from `AdminPages::showPage()`: header `log.show.title`, actions "Back to the list" and — with a stored HTML body — "Open the HTML body" (sandboxed response, new tab); breadcrumbs; sections `envelope` (subject, from, to, cc, bcc, tag) and `delivery` (status, transport, provider id, size, attempts, queued / sent / failed at, error); root `data-testid="mail-kit-log-show"` | `nowo_ui_show_after` → block `nowo_mail_kit_text_body` (stored text body in the `code_block` slot, escaped) |

Other variables passed by the controller: `filters` (`Log\SentMailFilters`, list), `entry` (`Entity\SentMail`) and
`body` (`Entity\SentMailBody` or null, detail).

## Settings actions

| Section | Action (`data-testid`) | Route | CSRF token id |
| --- | --- | --- | --- |
| `mailer` | "Send a test e-mail" (`nowo_mail_kit_test`) | `POST nowo_mail_kit_test` | `nowo_mail_kit_test` (field `csrf_only[_token]`) |
| `resend` | "Check the API key" (`nowo_mail_kit_resend_check`) | `POST nowo_mail_kit_resend_check` | `nowo_mail_kit_resend_check` |
| `resend` | "Send a test e-mail with Resend" (`nowo_mail_kit_resend_test`) | `POST nowo_mail_kit_resend_test` | `nowo_mail_kit_resend_test` |

The handlers redirect back to the section page with a flash (UiKit `_flashes`).

## Personal data in the pages

- The log pages show envelope data (subject, addresses) to users who pass the access checker; the responses are
  `private, no-store`.
- Bodies appear only with `log.store_bodies: true`: the text body escaped in the detail page, the HTML body only in
  its own response with a sandboxing CSP (`default-src 'none'; … sandbox`), never inline in the admin page.
- No DSN, password or API key is ever passed to a template (transport labels are `scheme://host[:port]`).

## Unique pages

- (none) — both pages extend UiKit scaffolds. The HTML body endpoint is a raw response, not a page.

## Per-page overrides

`web_ui.templates.log_index` / `web_ui.templates.log_show` point at a host template that extends the bundle page and
overrides one block:

```twig
{# templates/admin/mail_kit/log_show.html.twig — web_ui.templates.log_show #}
{% extends '@!NowoMailKitBundle/log/show.html.twig' %}

{% block nowo_mail_kit_text_body %}{% endblock %}   {# never show bodies, even when stored #}
```

## Overriding templates (REQ-TWIG-001)

See [USAGE.md — Overriding templates](USAGE.md#overriding-templates-req-twig-001) for the freeze rule, the recovery
procedure and the `<subpath>` table (`log/index.html.twig`, `log/show.html.twig`). Prefer `nowo_ui_kit.layouts.admin`,
the UiKit theme and `web_ui.templates.<page>` over full-file forks.

## Bundle partials and assets

None: the pages use UiKit partials only (list / show scaffolds, filters, pagination, flashes) and add no CSS / JS (no
`nowo_ui_styles` / `nowo_ui_scripts` override, no asset package).

## Translations

`NowoMailKitBundle` (`settings.*`, `dsn.*`, `sender.*`, `test_email.*`, `resend.*`, `log.*`) in `en`, `es`, `fr`, `de`,
`it`, `pt`, `nl`. The contract test loads the bundle catalogues with the strict missing-translation rule.

## Allowed exceptions

- (none)
