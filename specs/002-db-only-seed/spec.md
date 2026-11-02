# Feature Specification: DB-only mode (bootstrap seed, missing-settings warning)

**Feature Branch**: `feat/db-only-seed`  
**Created**: 2026-11-02  
**Status**: Active (1.2.0)  

**Package**: `nowo-tech/mail-kit-bundle`  
**Baseline**: [`../001-baseline/spec.md`](../001-baseline/spec.md)  
**Code inventory**: [`../001-baseline/code-inventory.md`](../001-baseline/code-inventory.md)  
**Depends on**: `nowo-tech/instance-settings-bundle` ^1.2 (spec 002-seed-from-fallbacks)

## Summary

The mailer DSN and the Resend key can live only in the encrypted settings. MailKit cannot read `framework.mailer.dsn`,
so two config keys carry the one-time bootstrap values to `nowo:instance-settings:seed-from-fallbacks`; the settings
pages and the readiness status report empty required settings.

## User Scenarios

### US-1 — Seed MAILER_DSN / RESEND_API_KEY once (P1)

**Given** `bootstrap.mailer_dsn: '%env(MAILER_DSN)%'` (and `bootstrap.resend_api_key`), **When** the host runs the
seed, **Then** the values are stored encrypted where the secrets are empty, a DSN failing the settings validation is
skipped (`invalid`, `--strict` exits 1), the Resend switch is not touched, and the output lists field names only.

### US-2 — Missing settings warning (P1)

**Given** no stored DSN (and `fallback_counts_as_ready: false`) or Resend switched on without a key, **When** the
`mailer` / `resend` page renders, **Then** a warning names the empty fields; `OutboundMailReadiness` reports the same
names for health pages.

## Functional Requirements

- **FR-DBO-001** Configuration `bootstrap.mailer_dsn`, `bootstrap.resend_api_key` (scalar, default null), never used
  to send.
- **FR-DBO-002** `MailerSettingsSection` / `ResendSettingsSection` implement `SectionFallbackProviderInterface`
  (secret fallbacks `dsn`, `api_key`) and `SettingsSectionNoticesInterface` (`settings.notice_missing`, `%fields%`),
  the readiness resolved lazily through a service closure.
- **FR-DBO-003** `MailSettings::missingFields()`, `OutboundMailReadiness::missingFields()` / `missingFieldsOf()`,
  `MailStatus::$missingFields` (names only; `mailer.dsn` omitted when the framework transport counts as ready; nothing
  with a custom DSN provider).
- **FR-DBO-004** Docs: DB-only mode (USAGE), `bootstrap` (CONFIGURATION), seed order (UPGRADING).

## Success Criteria

- Unit tests for fallbacks, report and notices; kernel tests: env-mapped bootstrap values seeded encrypted and never
  printed, idempotent, invalid DSN refused under `--strict`, warnings rendered.
- 100% coverage gate and translation parity kept; inventory 100% of `src/`.
