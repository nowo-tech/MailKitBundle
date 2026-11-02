# Feature Specification: Provider choice and provider-dependent mailer fields

**Feature Branch**: `feat/1.3.0-provider-fields`  
**Created**: 2026-11-02  
**Status**: Active (1.3.0, unreleased)  

**Package**: `nowo-tech/mail-kit-bundle`  
**Baseline**: [`../001-baseline/spec.md`](../001-baseline/spec.md)  
**Code inventory**: [`../001-baseline/code-inventory.md`](../001-baseline/code-inventory.md)  
**Depends on**: `nowo-tech/instance-settings-bundle` ^1.3.1 (`SettingField::withVisibleWhen()`), `nowo-tech/ui-kit-bundle`
^2.23 (`visible-when` peer)

## Summary

User decision 2026-11-02 (reverses the 1.2 "Resend fields always visible" decision): the mail settings page shows only
the fields of the selected provider, like SmsKit 1.3. With `resend.enabled`, the `mailer` section gets an explicit
`provider` choice (`dsn` / `resend`) and the Resend fields move into it as provider-dependent rows; the 1.2 `resend`
section is removed and its rows are migrated. A Resend key is checked with Resend before a save can put it live
(audit MK-L1).

Design choice: the Resend fields move into the `mailer` section (option a) rather than a provider choice inside the
`resend` section (option b), because `visible_when` only works within one section: with option b the DSN row could not
be hidden while Resend is selected, so the page would still show both providers.

## User Scenarios

### US-1 — Only the selected provider's fields (P1)

**Given** `resend.enabled: true`, **When** the admin opens the mailer page, **Then** a Provider row offers "SMTP or
another mailer DSN" and "Resend API"; the DSN row is shown for `dsn`, the Resend key and sender rows for `resend`; the
sender, sender name, Reply-To and test recipient always. Switching back and forth keeps the hidden provider's values.
Without `resend.enabled` the page is the 1.2 page.

### US-2 — A wrong Resend key never goes live (P1, MK-L1)

**Given** a working DSN delivery, **When** the admin selects Resend and saves a key Resend rejects (or no key at all),
**Then** the save is refused with the scrubbed Resend error, nothing is stored and the DSN keeps sending. A key Resend
accepts is saved and used on the next message. Rotating the key while Resend is live checks the new key; a save that
keeps the live key makes no call.

### US-3 — Upgrading a 1.2 install (P1)

**Given** 1.2 rows `resend.enabled = true`, `resend.api_key`, `resend.from`, **When** 1.3 is deployed, **Then** mail
keeps going through Resend (the rows are read while `mailer.provider` is not stored) and the mailer page shows a
notice; **When** the operator runs `nowo:instance-settings:seed-from-fallbacks --section=mailer`, **Then** the values
are stored as `mailer.provider`, `mailer.resend_api_key`, `mailer.resend_from` and the old rows are no longer read.
Before the seed the page shows Resend pre-selected (old key as stored, old sender pre-filled), and saving it unchanged
stores the same values and keeps Resend live without a new key check. **When** the operator runs
`nowo:mail-kit:purge-legacy-resend`, **Then** the old rows are deleted only if every value was copied (otherwise it
lists them and deletes nothing).

## Functional Requirements

- **FR-PRV-001** With `resend.enabled`, `MailerSettingsSection` yields `provider` (choice, default `dsn`, required),
  `dsn` (`visible_when provider in [dsn]`), `resend_api_key` (secret, `[resend]`), `resend_from` (e-mail, `[resend]`),
  then `from`, `from_name`, `reply_to`, `test_recipient` (always). Intro `settings.mailer.intro_providers`. Actions:
  mailer test + Resend check + Resend test. Without it: the 1.2 fields, no `visible_when`.
- **FR-PRV-002** `MailSettings::provider()`; `dsn()` / `resendActive()` / `from()` follow the selected provider;
  `missingFields()` names the selected provider's empty field only (`mailer.dsn` or `mailer.resend_api_key`). Every 1.2
  public method is kept.
- **FR-PRV-003** `ResendApiKeyAccepted` (admin form option of `resend_api_key`): with the submitted provider `resend`,
  checks the typed key, or the stored key when the stored provider is not `resend`, with `ResendApiChecker`; violation
  `resend.save.rejected` / `resend.save.key_required` / `resend.save.rate_limited` (shared test e-mail limiter); no call
  when Resend stays live with the same key or outside a form; the key is never part of the violation.
- **FR-PRV-004** `LegacyResendSettings` reads the 1.2 `resend` rows from the repository (memoized, `kernel.reset`);
  `MailSettings` uses them while `mailer.provider` is not stored; `MailerSettingsSection::getFallbacks()` exposes them
  as `provider` / `resend_api_key` / `resend_from` fallbacks (before `bootstrap.resend_api_key`); notice
  `settings.mailer.notice_legacy_resend` while pending. `LegacyResendSettingsTypeExtension` pre-selects / pre-fills
  the mailer form from them and submits the kept old key; `ResendApiKeyAccepted` does not re-check a key equal to the
  live one.
- **FR-PRV-007** `nowo:mail-kit:purge-legacy-resend [--dry-run] [--force]`: deletes the `resend` rows only once
  `mailer.provider` is stored and the old key / sender equal the mailer values; otherwise exit 1 listing the rows;
  idempotent; names only.
- **FR-PRV-005** `ResendSettingsSection` is a deprecated holder of `NAME = 'resend'`; the Resend actions guard and
  redirect on the `mailer` section; `SettingsChangedListener` reacts to `mailer` only.
- **FR-PRV-006** Docs (README, USAGE, CONFIGURATION, SECURITY, UPGRADING, CHANGELOG), 7 locales, demo with the Resend
  provider (offline key check stand-in) and the `visible-when` peer.

## Success Criteria

- Unit tests: section fields and `visible_when`, `MailSettings` per provider and legacy fallback, legacy reader,
  validator branches; kernel tests: page attributes, check-before-save (rejected / missing / accepted / switch back),
  1.2 rows read then seeded, notices.
- Playwright: switch provider → only that provider's rows → rejected key not saved → accepted key saved → switch back →
  values kept; axe clean in both states.
- 100% coverage, PHPStan, CS, Rector, Igor, translation parity.
