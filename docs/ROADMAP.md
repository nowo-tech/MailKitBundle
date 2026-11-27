# Roadmap

## Table of contents

- [1.0 (this release)](#10-this-release)
- [1.1 (unreleased)](#11-unreleased)
- [Phase 2 — branded, editable e-mail templates](#phase-2--branded-editable-e-mail-templates)
- [Later candidates](#later-candidates)
- [Requested for CrmKit (Beacon spec 254, 2026-11-23)](#requested-for-crmkit-beacon-spec-254-2026-11-23)

## 1.0 (this release)

Runtime mailer on top of Instance Settings: `mailer` section (Resend provider fields since 1.3), runtime DSN with fallback (also for
Messenger), DSN / sender validation with an SSRF host guard, redacted audit, test e-mail and Resend key check, sent-mail
log with retention and erasure, readiness (Twig, AuthKit adapter). Extracted from Symfony Beacon (kit #2, phase 1).

## 1.1 (unreleased)

- CSV export of the sent-mail log (streamed, capped, formula-injection safe through ControllerKit's
  `CsvStreamedResponse`) and server-side sortable log columns with UiKit 2.20 `Listing\SortRequest` — both requested
  by Symfony Beacon (its e-mail log, spec 188). No local sort helper: the request side is UiKit's, only the key → DQL
  column whitelist lives in `SentMailRepository`.
- Possible follow-up: an async export (Messenger + download link) for logs much larger than `web_ui.export.max_rows`.

## Phase 2 — branded, editable e-mail templates

Out of scope for 1.0 (Beacon kit #3, `src/Shared/EmailTemplate/**`):

- `EmailTemplate` entity (key + locale) with table prefix; template **definitions as tagged services** (key,
  placeholders, default subject / HTML / text) instead of a hard-coded catalog.
- HTML + text renderer with a branded layout and an HTML sanitizer; a `BrandProviderInterface` (name, colours, logo,
  base URI) — optionally fed by UiKit appearance.
- Admin list / edit / preview / reset (UiKit scaffolds, FormKit forms, editor widget), "send me a test" through the
  existing test e-mail guard and rate limit.
- Ready-made AuthKit notifiers (magic link, password reset, new device) that use the templates and
  `MailHeaders::redact()` for one-time links.
- The sent-mail log `tag` column already carries the template key.

## Later candidates

- Graph inbound: rich-notification (encrypted resource data) mode to skip the fetch call; other inbound providers
  (IMAP, Postmark) behind the same `GraphInboundMessage`-style port.
- Optional automatic purge (Scheduler message) instead of a cron line.
- Provider webhooks (bounces, complaints) updating the log status.
- `nowo-tech/runtime-env-bundle` DSN provider shipped as an optional integration.

## Requested for CrmKit (Beacon spec 254, 2026-11-23)

Beacon spec 254 "CRM HubSpot parity" (owner decision D-20261123-11) and the owner's reuse rule of 2026-11-23 ("if a
nowo bundle already does, or nearly does, a CRM capability, use it or extend it") send these pieces here instead of
building CRM-only copies in CrmKitBundle. Each piece is opt-in, generic (no CRM dependency) and gets its own contract
revision before it is built. Versions are planned next minors and move if this bundle releases something else first.
Plan and reuse matrix: [CrmKitBundle docs/ROADMAP.md](https://github.com/nowo-tech/CrmKitBundle/blob/main/docs/ROADMAP.md#reuse-matrix).

| Version | Piece | Used by |
| --- | --- | --- |
| 2.3.0 | **ICS invites**: an invite part on outgoing mail (`text/calendar`, `METHOD:REQUEST` / `CANCEL`, stable UID, SEQUENCE, organiser and attendees), plain-text fallback | CrmKit A-1 meetings, I booking; usable by TaskBoard and WorkingTime |
| 2.4.0 | **Editable templates** (this roadmap's Phase 2) plus what the CRM needs: personal and shared scopes through a host owner port, snippets, locale, and a closed placeholder resolver registry by service tag (unknown placeholders shown as text, never evaluated) | CrmKit A-2 (`contact.*`, `company.*`, `deal.*`, `me.*` placeholders) |
| 2.5.0 | **Personal Outlook mailboxes** (widened by Beacon spec 255 "Mail inbox", D-20261123-14): Graph delegated sync per followed folder (subscription + delta sweep, idempotent by provider message id), host storage through a sink port, host exclusion port before storage (sender, domain, List-Id, folder), thread key, attachments on demand, sanitised HTML (remote images blocked), send (new, reply, reply all, forward) with idempotency and the sent copy, one grant-failure event + pause, a call observer for API metering. Consumes a **token port** (`MailboxTokenProviderInterface`: current access token per mailbox, refreshed by the host). MailKit has **no OAuth flow, consent page or callback** of its own: the host keeps one connection per person and provider, obtained through TimeTrack's PKCE helpers with the extra mail scopes and encrypted with DoctrineEncrypt (Q-254-15, Decision 2026-11-23: (b)). Design: [design/DELEGATED_MAILBOXES.md](design/DELEGATED_MAILBOXES.md), contract: [specs/005](../specs/005-delegated-mailboxes/spec.md), owner decisions Q-MB-1 – Q-MB-22 (accepted 2026-11-23) | CrmKit B (spec 236 kit side), Beacon spec 255 |
| 2.5.x | `MailboxSendSettledEvent`: dispatched when a background reconcile settles a delegated send (sent, failed or unknown), so the host gets a push instead of retrying `send()` with the key (owner answer Q2 of the 2.5.0 send PR, 2026-11-24) | Beacon spec 255 |
| 2.6.0 | **Gmail, send-only (phase B2)** behind the same token port and send path (no OAuth flow or callback in MailKit; Q-254-15). Owner decision D-20261124-09: scopes exactly `openid email gmail.send`; no `gmail.readonly`, so no CASA assessment, no inbox, no sync, no sent copy; new messages only. `mailboxes.gmail.enabled: false` by default. Built in PR `feat/b2-gmail-send-only` (2.6.0) | CrmKit B, Beacon spec 255 |
| later (not planned) | Gmail **sync** (`users.watch` + Pub/Sub push, `history.list`, `gmail.readonly`): only if the owner reverses D-20261124-09 (restricted scope: yearly CASA assessment). The design's section 7.2 / 10.2 stays as the reference | — |
| 2.7.0 | **`sending_domains`**: a domain per tenant, SPF / DKIM / DMARC verification (Resend domains API on Resend, DNS checks otherwise), sends from an unverified or failing domain refused with the reason | CrmKit F (circulars, sequences, FR-064) |

Reused as they are (no change): tracking 1.10 (pixel, `MailOpenedEvent`, `MailMachineOpenedEvent`,
`EmailTrackingPolicyInterface`), Graph inbound 1.9 with plus-address tags and `GraphInboundHandlerInterface` (ticket
address), the queue and the sent-mail log.
