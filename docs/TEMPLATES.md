# Templates

Pages of MailKitBundle on the UiKit page contract (REQ-UI-003, UiKit 2.17+), and since 2.7.0 the contract of the
stored e-mail templates ([Stored e-mail templates (2.7.0)](#stored-e-mail-templates-270)), which has no page. The admin UI (`web_ui.path_prefix`,
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
- [E-mail layout (1.4)](#e-mail-layout-14)
- [Stored e-mail templates (2.7.0)](#stored-e-mail-templates-270)
  - [Contract](#contract)
  - [Public API](#public-api)
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

## E-mail layout (1.4)

`email/branded_layout.html.twig` and `email/branded_layout.txt.twig` (spec 220 S7) are layouts for the host's own
e-mails, not pages: they are not on the UiKit page contract and print no translated text (the host's `content` block
carries the copy). Extend them as `@NowoMailKit/email/branded_layout.html.twig` (or `@NowoMailKitBundle/...`); the
`templates/bundles/NowoMailKitBundle/email/` override path works as for the pages. Blocks: `html_lang`, `title`,
`preheader`, `header`, `content`, `footer`; the sanitised brand view is `mail_brand` in every block (Twig function
`nowo_mail_kit_brand()`, filter `nowo_mail_kit_plain_text`). See [USAGE — Branded e-mails](USAGE.md#branded-e-mails).

The colours are inline `style` values by necessity (e-mail clients ignore `<style>` blocks and CSS variables); they are
never literals of the layout but the `mail_brand.colors.*` values (`#rrggbb`, from the brand or `brand.colors`).

## Stored e-mail templates (2.7.0)

Not a page: an API. Since 2.7.0 MailKit stores editable e-mail **templates** and **snippets** and renders them with a
closed placeholder registry. It is this roadmap's "Phase 2" plus the CrmKit A-2 request (Beacon spec 254 T003,
FR-015 / FR-016, owner reuse rule 2026-11-23: the template store, `TemplateScope` and snippets live in MailKit; CrmKit
adds its `contact.*`, `company.*`, `deal.*`, `me.*` placeholder provider, the consent purpose per template and the
*Write e-mail* insert). Opt-in: `templates.enabled: false` (default) registers no table, service, command or route.

### Contract

Written before the code (ROADMAP: each requested piece gets a contract revision before it is built); the code follows
it. Owner decisions in force: reuse rule 2026-11-23, Q-254 answers as recommended, templates generic (no CRM
dependency) and opt-in.

**C1 — Scope of 2.7.0.** API-first: a store, a library service, a renderer, a GDPR port, a schema command and the
UserKit bridge. **No admin UI** in 2.7.0: Beacon builds its own screens (CrmKit A-2 owns the *Templates* list and the
insert dialog); a MailKit UiKit page is a later candidate. No Twig, no tagged *definitions* of system templates (the
Phase 2 "default subject / HTML / text per key" catalogue and the AuthKit notifiers stay later).

**C2 — Data.** One table `<prefix>mail_kit_template` (DBAL, created by the ORM `postGenerateSchema` listener for
`doctrine:migrations:diff`, or by `nowo:mail-kit:templates:schema`, which prints the CREATE statement in the dialect
of the connection: MySQL / MariaDB, PostgreSQL, SQLite). A row is a `TemplateKind` (`template`: subject + body;
`snippet`: body fragment, no subject) with:

| Field | Rule |
| --- | --- |
| `id` | UUID v7, set by MailKit |
| `tenant` | host tenant reference (`[A-Za-z0-9_.:@-]{1,128}`), part of every query |
| `scope` | `TemplateScope`: `personal` (owner only) or `shared` (the tenant); never changes |
| `owner_id` | owner of a personal row, `''` for shared |
| `template_key` | `[a-z0-9][a-z0-9._-]{0,63}`; unique per tenant, kind, scope, owner and locale |
| `locale` | `xx`, `xxx` or `xx_XX` |
| `name` | 1–190 characters, no control characters |
| `subject` | template: 1–255 characters, no control / bidi / line-break characters (no header injection); snippet: empty |
| `body_html` | sanitised on save; ≤ `max_body_bytes` before sanitising |
| `body_text` | as written; ≤ `max_body_bytes`; empty = derived from the HTML when rendered |
| `version` | optimistic, starts at 1, +1 on every update |
| `created_by` / `updated_by` | author references; cleared by a GDPR erasure |
| `created_at` / `updated_at` | UNIX seconds |

Key columns (`id`, `tenant`, `owner_id`, `template_key`, `locale`, `created_by`, `updated_by`) get the binary NO PAD
collation on MySQL / MariaDB and are declared to MigrationsKit (REQ-SEC-006). Bodies are `MEDIUMTEXT` on MySQL /
MariaDB, `TEXT` elsewhere.

**C3 — Ports (host-implemented, deny by default).**

- `Template\Port\TemplatePermissionInterface::isGranted(TemplatePermission, TemplateActor): bool`
  (`templates.permission`). `TemplatePermission::Personal` = create / edit / delete / list / render one's own personal
  rows; `UseShared` = list / read / render the tenant's shared rows; `ManageShared` = create / edit / delete them (it
  also allows reading them). Without a host service MailKit uses `DenyAllTemplatePermission`. Beacon maps
  `ManageShared` to `crm.manage`, `UseShared` / `Personal` to its CRM members.
- `Template\Port\TemplateOwnerResolverInterface::ownerIdsFor(object $user): list<string>`
  (`templates.owner_resolver`): the owner references of a user for the UserKit bridge; required when
  `personal_data.enabled` and UserKit are on.
- Tenant and owner come from the **caller** as a `TemplateActor(tenant, owner)` on every call, built by the host from
  its security context (or, in a worker running a sequence step, from the job). MailKit never derives them from input
  and never holds a "current" actor (worker mode). The permission port receives the actor and **must** check that
  the person belongs to `actor.tenant` (owner answer Q1, 2026-11-27: the actor stays caller-passed, so this check is
  the host adapter's job; MailKit cannot verify membership).

**C4 — Fixed access rules (MailKit, not overridable).** Every query names the tenant. A personal row is visible only
to its owner and only while `Personal` is granted; a shared row with `UseShared` or `ManageShared`. A row the actor
may not see — another tenant's, another person's personal row, a missing id — raises `TemplateNotFound` (map to 404);
a visible row the actor may not change raises `TemplateAccessDenied` (403). Lists contain only visible rows (empty
when nothing is granted). There is no admin or support override for personal rows (owner answer Q3): only their owner
reads them; the GDPR export is the only other way out.

**C5 — Placeholders: closed registry.** Grammar `{{ namespace.name }}` (up to 8 spaces inside the braces),
namespace `[a-z][a-z0-9_]{0,31}`, name `[a-z0-9_]{1,40}`; anything else is text. Providers implement
`Template\Placeholder\PlaceholderProviderInterface` (autoconfigured, tag `nowo_mail_kit.template_placeholder_provider`);
`placeholderNamespace()` is read at build time; the build fails on a duplicate namespace, an invalid one or a tagged
class that does not implement the interface. Resolution is **pure substitution**: one pass per part, each provider
asked once per render with the distinct names of its namespace and a `PlaceholderContext` (actor, locale, the host's
subject references such as `['contact' => '42']`); no Twig, no expression, no recursion (a value that looks like a
placeholder is printed as text). A placeholder without a value — unknown namespace, unknown name, a non-string or
invalid UTF-8 value — **stays as its literal text** and is listed in `RenderedTemplate::$unresolved`. MailKit ships
**no** provider in 2.7.0: `me.*` is part of CrmKit's provider (its grammar `\A(contact|company|deal|me)\.[a-z_]{1,40}\z`)
and a MailKit `me.*` would collide with it.

**C6 — Escaping and limits.** Values: control and bidi override / isolate characters removed; in the HTML body
HTML-escaped (`ENT_QUOTES | ENT_SUBSTITUTE | ENT_HTML5`), then the whole HTML is sanitised again; in the text body
plain (line breaks and tabs kept); in the subject plain with line breaks turned into spaces, cut at 998 characters.
Limits: `max_body_bytes` per stored body, `max_placeholders` occurrences per template, `max_output_bytes` per rendered
body (larger renders raise `InvalidTemplate('output')`), `max_per_scope` rows per tenant, kind, scope and owner (a soft
cap checked before the insert; concurrent creates may pass it together, bounded by the number of concurrent requests;
owner answer Q4: kept soft). Providers check that the actor may see what
they return (CrmKit: may view the contact).

**C7 — Sanitiser.** MailKit's `MailHtmlSanitizer` (symfony/html-sanitizer, required with `templates.enabled`) in the
`https_only` remote-image mode (`RemoteImages::HttpsOnly`, owner answer Q2, 2026-11-27), applied on save **and** again
on every render: W3C safe elements and attributes, no script / style / event handler / `style` attribute, links
`http` / `https` / `mailto` only (no relative URLs). Images: absolute `https` sources and `cid:` references to the
message's own inline parts (for instance the brand logo `BrandedEmailHelper` attaches) are kept; `http`, `data:` and
relative sources are removed (the `<img>` stays without `src`), and so are `srcset`, `poster`, `background`, `style`
and the other URL-bearing media attributes, the `source` / `track` / `link` / `style` elements and audio / video. A
placeholder inside an attribute value (`href="{{ x.y }}"`) is not a valid URL and is dropped on save: placeholders
belong in text.

**C8 — Rendering.** `TemplateRendererInterface::render(actor, id, subjects)` and `renderDraft(actor, draft, subjects)`
(preview; needs the manage permission of the draft's scope) return a `RenderedTemplate` (subject, html, text,
unresolved). `RenderedTemplate::applyTo(Email $email, bool $tag = true)` sets the parts on a Symfony `Email` for
MailKit's usual send path (`MailerInterface`, the delegated `MailboxSenderInterface` via its HTML / text, CrmKit's
`CrmMailSenderInterface`), and with `$tag` the sent-mail log tag and the tracking template id become the template key.

**C9 — Concurrency (REQ-RUNTIME-003).** Inserts are one statement with a caught duplicate key (`TemplateKeyTaken`,
never check-then-insert); updates and deletes are compare-and-set on `version` (plus tenant, scope and owner);
0 rows → `TemplateNotFound` if the row is gone, else `TemplateVersionConflict` (409). Inside an open host transaction
each statement runs in a savepoint rolled back on any failure (a duplicate key or a lock timeout leaves the host's
transaction usable); the GDPR erasure (several statements) runs in one transaction-doctrine unit. A lock wait on a key
another open transaction is inserting surfaces as the usual transient storage conflict (CONFLICT_001). Every service is
stateless (FrankenPHP worker mode, kernel reset false).

**C10 — Events.** `TemplateDeletedEvent(templateId, tenant, kind, scope)` after a delete and for each personal row a
GDPR erasure deletes, dispatched synchronously (inside the host's transaction when one is open), so a host side table
keyed by the template id (CrmKit's consent purpose) is cleaned in the same transaction.

**C11 — GDPR.** `TemplatePersonalDataInterface::exportOwner(owner)` lists the owner's personal rows with their content
and the shared rows the owner created or last changed (key, name and dates only: the content belongs to the tenant);
`eraseOwner(owner)` deletes the personal rows and clears the owner as `created_by` / `updated_by` of shared rows
(anonymised authorship; the shared row stays for the tenant). Exact match re-checked in PHP, across all tenants
(owner references must be unique host-wide), idempotent. UserKit bridge `email_templates` (eraser priority 110 and
export section) with `personal_data.enabled`.

**C12 — Not in 2.7.0 (later candidates).** An admin UI; render-time snippet references (`{{ snippet.key }}`: snippets
are inserted by the editor today); template definitions as tagged services with defaults and "reset"; per-template
categories / consent purpose (CrmKit keeps it in its own table keyed by the template id); hard quotas.

### Public API

| Type | Name |
| --- | --- |
| Services (public aliases) | `Template\Service\TemplateLibraryInterface` (`list`, `get`, `findByKey`, `create`, `update`, `delete`), `TemplateRendererInterface` (`render`, `renderDraft`, `placeholders`), `TemplatePersonalDataInterface` (`exportOwner`, `eraseOwner`) |
| Ports | `Template\Port\TemplatePermissionInterface`, `TemplateOwnerResolverInterface`; default `DenyAllTemplatePermission` |
| Extension point | `Template\Placeholder\PlaceholderProviderInterface`, `PlaceholderDefinition`, `PlaceholderContext` |
| Values | `Template\Value\TemplateActor`, `TemplateDraft`, `StoredTemplate`, `TemplateSummary`, `RenderedTemplate`, `TemplateErasureResult`, enums `TemplateKind`, `TemplateScope`, `TemplatePermission` |
| Exceptions | marker `Template\Exception\TemplateException`; `TemplateNotFound`, `TemplateAccessDenied`, `TemplateVersionConflict`, `TemplateKeyTaken`, `InvalidTemplate` (`$field`) |
| Event | `Template\Event\TemplateDeletedEvent` |
| Command | `nowo:mail-kit:templates:schema [--force]` |

Configuration: [CONFIGURATION — templates](CONFIGURATION.md#templates); usage: [USAGE — Stored e-mail templates](USAGE.md#stored-e-mail-templates-270);
spec: [specs/006](../specs/006-stored-templates/spec.md).

## Translations

`NowoMailKitBundle` (`settings.*`, `dsn.*`, `sender.*`, `test_email.*`, `resend.*`, `log.*`) in `en`, `es`, `fr`, `de`,
`it`, `pt`, `nl`. The contract test loads the bundle catalogues with the strict missing-translation rule.

## Allowed exceptions

- (none)
