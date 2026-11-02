# Feature Specification: provider-dependent Resend settings fields

**Feature Branch**: `feat/1.3.0`  
**Created**: 2026-11-02  
**Status**: Active (1.3.0)  

**Package**: `nowo-tech/mail-kit-bundle`  
**Baseline**: [`../001-baseline/spec.md`](../001-baseline/spec.md)  
**Code inventory**: [`../001-baseline/code-inventory.md`](../001-baseline/code-inventory.md) (no new file)  
**Depends on**: `nowo-tech/instance-settings-bundle` ^1.3 (spec 003-provider-dependent-fields), `nowo-tech/ui-kit-bundle` ^2.23

## Summary

MailKit's only provider switch in settings is the `resend` section's `enabled` checkbox (the `mailer` section's
provider is part of its encrypted DSN). With InstanceSettings 1.3 `visible_when`, the Resend API key and sender rows
follow that switch and keep their values while it is off.

## User Scenarios

### US-1 — Rows follow the switch (P1)

**Given** "Send with Resend" unchecked, **Then** only the switch is shown; checking it shows the API key and the
Resend sender (no reload).

### US-2 — Values survive switching off (P1)

**Given** a stored Resend sender / key, **When** the admin unchecks the switch and saves, **Then** both keep their
stored values (not validated, not written, not cleared); checking the switch again shows them.

## Functional Requirements

- **FR-MAILVIS-001** `ResendSettingsSection::getFields()`: `api_key` and `from` `withVisibleWhen('enabled', [true])`;
  `enabled` always visible. `MailerSettingsSection` unchanged.
- **FR-MAILVIS-002** `MailSettings::missingFields()` already names `resend.api_key` only while the switch is on
  (consistent with the visible rows).
- **FR-MAILVIS-003** Requires `nowo-tech/instance-settings-bundle` ^1.3, `nowo-tech/ui-kit-bundle` ^2.23.

## Success Criteria

- Unit test `ResendVisibleWhenTest` (dependencies, visible fields per switch state, mailer section unchanged).
- Demo Playwright e2e: switch, save, switch back, value kept; axe in both states.
- 100% coverage kept; inventory 100% of `src/`; translation parity unchanged.
