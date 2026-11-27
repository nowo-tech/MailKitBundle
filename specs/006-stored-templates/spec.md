# Feature Specification: Stored e-mail templates and snippets (2.7.0)

**Feature Branch**: `feat/2.7.0-templates`  
**Created**: 2026-11-27  
**Status**: Active (target 2.7.0, unreleased)  

**Package**: `nowo-tech/mail-kit-bundle`  
**Baseline**: [`../001-baseline/spec.md`](../001-baseline/spec.md)  
**Contract**: [`docs/TEMPLATES.md` — Stored e-mail templates](../../docs/TEMPLATES.md#stored-e-mail-templates-270) (C1–C12)  
**Requested by**: CrmKit A-2 (Beacon spec 254 T003, FR-015 / FR-016); owner reuse rule 2026-11-23; Q-254 answers as
recommended. **Depends on**: symfony/html-sanitizer and doctrine/doctrine-bundle when enabled.

## Summary

This roadmap's "Phase 2" store, widened for the CRM: editable templates and snippets per tenant and locale, in a
personal (owner only) or shared (tenant) scope, rendered by pure substitution through a closed registry of tagged
placeholder providers. Generic (no CRM dependency), opt-in (`templates.enabled: false`), API-first (no admin UI).
CrmKit A-2 adds its `contact.*`, `company.*`, `deal.*`, `me.*` provider, the consent purpose per template (its own
table keyed by the template id) and the *Write e-mail* insert.

## User Scenarios

### US-1 — A salesperson keeps personal templates (P1)

**Given** a host that grants `Personal`, **When** Ana creates, edits and deletes her template `follow-up` (es), **Then**
only Ana lists, reads and renders it; Bob of the same tenant and Ana in another tenant get "not found"; a second
`follow-up` (es) of Ana is refused (`TemplateKeyTaken`), the same key in `es_ES`, for Bob or in another tenant is fine.

### US-2 — Managers keep the tenant's shared templates (P1)

**Given** `ManageShared` for managers and `UseShared` for members, **When** a manager saves a shared template, **Then**
every member lists, reads and renders it, only managers change or delete it (`TemplateAccessDenied` otherwise), and two
managers editing version 1 produce one winner and one `TemplateVersionConflict`.

### US-3 — Rendering never runs code (P1)

**Given** a template with `{{ contact.first_name }}`, `{{ me.company }}`, `{{ unknown.x }}`, `{% if %}` and `{{ 7*7 }}`,
**When** it is rendered for contact 42, **Then** known placeholders get their provider's value (HTML-escaped in the
HTML body, plain in subject and text), everything else stays as literal text and is listed as unresolved, a value
that looks like a placeholder is printed as is, and the HTML is sanitised again.

### US-4 — Nothing is allowed until the host says so (P1)

**Given** templates enabled and no `templates.permission`, **When** anyone lists or creates, **Then** lists are empty and
writes raise `TemplateAccessDenied`.

### US-5 — A person's data goes with them (P2)

**Given** Ana's personal rows and shared rows she wrote, **When** her data is exported and erased, **Then** the export
lists her personal rows with content and the shared ones without content; the erasure deletes her personal rows (one
`TemplateDeletedEvent` each) and clears her as author of shared rows, which stay; a second erasure changes nothing.

## Requirements

- **FR-TPL-001** `templates` configuration (enabled, permission, owner_resolver, connection, max_body_bytes,
  max_output_bytes, max_placeholders, max_per_scope); off: nothing registered; `max_body_bytes ≤ max_output_bytes`.
- **FR-TPL-002** One DBAL table `<prefix>mail_kit_template` with the unique key (tenant, kind, scope, owner, key,
  locale), exact collations on key columns (REQ-SEC-006 declaration), MEDIUMTEXT bodies on MySQL / MariaDB; ORM
  `postGenerateSchema` listener and `nowo:mail-kit:templates:schema [--force]`.
- **FR-TPL-003** `TemplateDraft` grammar: key, locale, name, subject (template only; no control / bidi / line-break
  characters), at least one body, valid UTF-8.
- **FR-TPL-004** Host ports `TemplatePermissionInterface` (default deny-all) and `TemplateOwnerResolverInterface`;
  every call takes a `TemplateActor(tenant, owner)`.
- **FR-TPL-005** Fixed access rules: tenant in every query; personal rows owner-only; invisible = `TemplateNotFound`;
  visible but not manageable = `TemplateAccessDenied`.
- **FR-TPL-006** `TemplateLibraryInterface`: list (visible rows, capped), get, findByKey (locale, then language),
  create, update and delete with optimistic versions; HTML sanitised on save with https images only (`RemoteImages::HttpsOnly`,
  owner answer Q2); size, placeholder and per-scope limits.
- **FR-TPL-007** Closed placeholder registry: `PlaceholderProviderInterface`, autoconfigured tag, build-time check of
  namespaces (`TemplatePlaceholderPass`).
- **FR-TPL-008** `PlaceholderResolver`: grammar, one provider call per namespace, one-pass substitution, unknown as
  literal text, value cleaning and escaping per part, occurrence cap.
- **FR-TPL-009** `TemplateRendererInterface`: render, renderDraft, placeholders; output cap; HTML re-sanitised; text
  derived from HTML when empty; `RenderedTemplate::applyTo(Email)`.
- **FR-TPL-010** `TemplateDeletedEvent` on delete and GDPR erasure.
- **FR-TPL-011** GDPR: `TemplatePersonalDataInterface` (export, erase; exact, idempotent, one transaction) and the
  UserKit `email_templates` eraser / export section (owner resolver required).
- **FR-TPL-012** REQ-RUNTIME-003: caught duplicate keys, compare-and-set versions, savepoints inside host
  transactions; stateless services (worker mode).

## Success Criteria

- **SC-001** Race tests on MySQL 8.4, MariaDB 11 and PostgreSQL 16 / 17: one winner per key and per version; the
  loser's host transaction stays usable.
- **SC-002** Worker-mode kernel test: 400 renders for two tenants and two people in one kept-alive kernel without the
  services resetter, no state bleed, flat memory.
- **SC-003** 100% statement coverage, PHPStan, CS, PHPDoc gates green.

## Out of scope (C12)

Admin UI; `{{ snippet.key }}` references at render time; tagged template definitions with defaults / reset; AuthKit
notifiers; consent purpose or categories in MailKit; hard quotas.
