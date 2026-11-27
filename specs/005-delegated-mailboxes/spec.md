# Feature Specification: Delegated mailboxes (personal Outlook, then Gmail)

> **Note (superseded text):** where this spec promises Gmail sync, push or `gmail.readonly`, FR-019 (send-only, owner decision D-20261124-09, shipped in 2.6.0) overrides it.

**Feature Branch**: `feat/2.5.0-delegated-mailboxes` (2.5.0), `feat/2.6.0-gmail` (2.6.0)  
**Created**: 2026-11-23  
**Status**: Accepted 2026-11-23 (owner: Q-MB-1 – Q-MB-22 as recommended); 2.5.0 part 1 (sync, attachments) and part 2 (send) built 2026-11-24; Gmail reduced to send-only (D-20261124-09, phase B2) and built 2026-11-25  

**Package**: `nowo-tech/mail-kit-bundle`  
**Baseline**: [`../001-baseline/spec.md`](../001-baseline/spec.md)  
**Design**: [`../../docs/design/DELEGATED_MAILBOXES.md`](../../docs/design/DELEGATED_MAILBOXES.md)  
**Requested by**: symfony-beacon spec 255 "Mail inbox" (D-20261123-14, T001), spec 236, spec 254 block B  
**Depends on**: nothing new for 2.5.0 (`symfony/html-sanitizer`, `symfony/messenger`, `doctrine/dbal`,
`nowo-tech/transaction-doctrine` as today); 2.6.0 adds `firebase/php-jwt` to `suggest` (Gmail push only) *(superseded by FR-019, send-only, owner decision D-20261124-09: Gmail has no sync or push and 2.6.0 adds no dependency)*

## Summary

MailKit syncs the folders a person follows in their own mailbox (Microsoft Graph delegated in 2.5.0, Gmail API in
2.6.0), hands each message to the host through a sink port, fetches attachments on demand and sends new messages,
replies, replies to all and forwards from that mailbox. Owner decision Q-254-15 = (b): MailKit has **no OAuth flow,
consent page or callback**; it reads current access tokens through a host token port. The host owns message storage,
encryption and retention. Everything is off by default (`mailboxes.enabled: false`) and the 1.9 shared-mailbox
pipeline is unchanged.

## User Scenarios

### US-1 — A followed folder reaches the host once (P1)

**Given** an active Outlook mailbox following Inbox, **When** a message arrives and Graph notifies twice while the
5-minute sweep also sees it, **Then** the sink receives it as a `SyncedMessage` (sanitised HTML, thread key, attachment
references, no attachment content) and every later delivery answers `Duplicate`.

### US-2 — Excluded mail is never stored (P1)

**Given** the host's exclusion policy excludes a sender domain, **When** a message from that domain arrives, **Then**
the sink is never called for it, its body is not normalised, logged or kept, and the policy got the rule id to count.

### US-3 — Reply from the platform (P1)

**Given** a stored message, **When** the host sends a reply with an idempotency key and two attachments, **Then** the
reply leaves from the mailbox's address in the provider's thread, `send()` returns the provider ids and thread key,
the sent copy reaches the sink, and a second call with the same key sends nothing and returns the same ids.

### US-4 — A dead grant stops everything once (P1)

**Given** the person revoked the app, **When** the next sweep, renewal or fetch gets a grant error, **Then** the mailbox
is paused, exactly one `MailboxGrantFailedEvent` is dispatched (even with several workers), queued work for it is
dropped, nothing retries, and `resume()` after a reconnect continues from the stored cursor.

### US-5 — A lost cursor resyncs without duplicates (P2)

**Given** an expired delta token / history id, **When** the sweep runs, **Then** it restarts from the resync window,
dispatches `MailboxResyncedEvent`, and already stored messages answer `Duplicate`.

### US-6 — Gmail behind the same ports (P2, 2.6.0; superseded by US-7)

**Given** `gmail.enabled: true` and a verified Pub/Sub push, **When** Gmail publishes a change, **Then** only a JWT
signed by Google for the configured audience and service account triggers a history sweep, and the host receives the
same `SyncedMessage` shape as for Outlook.

### US-7 — Send from a Gmail address, nothing read (P1, phase B2)

**Given** a person connected Gmail with `openid email gmail.send` only, **When** the host sends a new message with an
idempotency key, **Then** it leaves from that address once, `send()` returns the Gmail ids and thread key, a retry with
the key replays them, a timeout or 5xx is reported unknown and never resent, and the mailbox shows as send-only (no
inbox, nothing synced).

## Requirements

- **FR-001** Host ports (bundle VOs only, REQ-ARCH-001 rule 3): `MailboxTokenProviderInterface`,
  `MailboxDirectoryInterface`, `MailboxMessageSinkInterface` (required), `MailboxExclusionPolicyInterface`,
  `MailboxCallObserverInterface` (optional). Signatures: design §5.1.
- **FR-002** Services: `MailboxSyncInterface` (start, pause, resume, sweep, status, disconnect),
  `MailboxSenderInterface`, `MailboxAttachmentReaderInterface`. Design §5.2.
- **FR-003** MailKit never runs an OAuth flow, never stores or caches an access or refresh token, and calls only `/me`
  paths with a delegated token. The account behind the token is checked against the directory's address at start,
  resume and every renewal. Scopes the host requests (Q-MB-2, Q-MB-17): Graph `Mail.ReadWrite`, `Mail.Send`,
  `offline_access`, `User.Read`; Gmail `gmail.readonly`, `gmail.send` (never `gmail.modify`). *Superseded by FR-019 (send-only, owner decision D-20261124-09): Gmail requests exactly `openid email gmail.send`; `gmail.readonly` is not requested.* No `Mail.*.Shared`
  scopes in 2.5.0 (Q-MB-15). Personal Outlook.com accounts are supported, sweep-only if their subscriptions or
  immutable ids fail the first build check (Q-MB-21).
- **FR-004** Sync: Graph subscription per followed folder plus a per-folder delta sweep (2.5.0); Gmail watch plus a
  per-mailbox history cursor (2.6.0); a scheduled sweep every 5 minutes; idempotent by provider message id through the
  sink contract only, no MailKit claim table (Q-MB-4). First sweep and resync window `P7D` by default, per mailbox up
  to `P90D` (Q-MB-11). Messages deleted or moved out of a followed folder are reported through the sink's `removed()`
  (Q-MB-5).
- **FR-005** Exclusion runs before the sink on every path, and before the fetch when the provider's change page gives
  sender and folder; otherwise after the single full fetch (no extra headers-only call); an excluded body is never
  normalised, logged or stored (Q-MB-3).
- **FR-006** Thread key: provider thread id, else root of `References`, else `In-Reply-To`, else own `Message-ID`;
  header ids always exposed; joins across providers by header ids are the host's (Q-MB-10). Design §12.
- **FR-007** Attachments are fetched on demand by provider ids into capped temp streams; MailKit stores no content.
  Attached e-mails and OneDrive reference attachments are listed, not downloadable, in 2.5.0 (Q-MB-22).
- **FR-008** Send: new, reply, reply all, forward from the mailbox address, with attachments and a **mandatory**
  idempotency key (Q-MB-20). Graph uses **draft + send** (`createReply` / `createReplyAll` / `createForward`, MIME draft
  for new; not `sendMail`) (Q-MB-1); the host's body replaces the draft body Outlook generates (Q-MB-7). `send()`
  returns the ids at once and the full sent copy reaches the sink even when Sent Items is not followed (Q-MB-6).
  Design §6.5, §9.4.
- **FR-009** HTML sanitised with MailKit's existing `symfony/html-sanitizer` configuration; remote images `blocked`
  by default (URL kept in `data-mail-kit-remote-src` for the host's image proxy, Q-MB-8), no `style` attribute or
  element (Q-MB-9), no script or event handler.
- **FR-010** Webhooks: Graph `validationToken` echo and per-subscription `clientState` (SHA-256, constant time); Gmail
  Pub/Sub OIDC JWT verified with `firebase/php-jwt` (issuer, audience, service account, expiry) and subscription name,
  no secret in the URL (Q-MB-16). Both enqueue only.
- **FR-011** Subscriptions / watches renewed before expiry by a scheduled command; lifecycle events handled.
- **FR-012** Concurrency (REQ-RUNTIME-003): folder lease with `rerun` coalescing, cursor compare-and-set, unique first
  rows with caught duplicates, conditional status transitions, send claim with stale takeover, savepoints inside a host
  transaction, 409 `CONFLICT_001` / `CONFLICT_002` on MailKit's routes. Design §9.
- **FR-013** Grant failure: one conditional transition to `PausedGrant`, one `MailboxGrantFailedEvent` after commit,
  unrecoverable Messenger failure, no retry until `resume()`; only grant, consent and account errors pause. Transient
  errors (429, 5xx, timeouts) retry with backoff (Q-MB-12).
- **FR-014** Metering: `MailboxCallObserverInterface::called(ProviderCall)` after every provider call (mailbox id,
  operation label, status, duration, bytes, retry-after, quota units; never URLs, ids of messages, addresses or
  bodies) **and** the `mailboxes.http_client` option for HttpLog (Q-MB-13).
- **FR-015** GDPR: MailKit's tables hold no address, subject, body, token or recipient; `disconnect()` removes remote
  subscriptions / watch and every MailKit row of the mailbox; delegated sends are not written to the sent-mail log
  (Q-MB-14).
- **FR-016** Worker mode (kernel reset off): no per-mailbox state in services; only JWKS cached (PSR-6).
- **FR-017** Gmail (2.6.0) is off by default (`gmail.enabled: false`, no extra "verified" flag) and needs topic and push
  settings to build; Beacon's Google phase-2 checklist (verification + CASA) gates production (Q-MB-18). *Superseded by FR-019 (send-only, owner decision D-20261124-09): no topic, push or CASA; only `gmail.send` verification is needed.*
- **FR-018** Versions (Q-MB-19): 2.5.0 = ports + Graph (sync and attachments, then send: two PRs, one tag); 2.6.0 =
  Gmail. *Amended by D-20261124-09*: Gmail is phase B2, send-only (FR-019), after the 2.5.0 tag.
- **FR-019** (phase B2, D-20261124-09) Gmail is **send-only**: scopes exactly `openid email gmail.send`; no
  `gmail.readonly`, watch, push, history cursor, sync, attachment download or sent copy (FR-003, FR-004, FR-007,
  FR-010, FR-011 and the Gmail half of FR-008 do not apply to Gmail). Account check through the OpenID userinfo
  (verified address, bound `sub`). New messages only, one `messages.send` per key; an unconfirmed answer is `unknown`
  and never resent; 4xx grant / scope / policy answers pause and release the key. `MailboxSettings` of a Gmail mailbox
  has no folders; `MailboxProvider::isSendOnly()` and the `mailbox.provider.*` translations let the host's UI say
  plainly that there is no inbox. Off by default (`mailboxes.gmail.enabled`).

## Success Criteria

- **SC-001** Race tests on MySQL 8.4, MariaDB 11.4, PostgreSQL 16 / 17 prove: one sweeper per folder, no cursor lost
  update, one event per grant failure, one send per idempotency key.
- **SC-002** No token, body, address or provider URL in logs, exceptions or MailKit tables (test with a log spy).
- **SC-003** At most two provider calls per synced message without attachments (page share + fetch).
- **SC-004** The public surface passes the REQ-ARCH-001 grep (no Symfony component, Doctrine or HTTP type in ports, VOs
  and events); a DDD host kernel with in-memory ports boots and syncs.
- **SC-005** 100 % of the new code covered; REQ-SEC-004 audit before each tag.

## Out of scope

OAuth flows and token storage (host); message storage, search, retention and encryption (host); shared mailboxes
through `Mail.*.Shared` (Q-MB-15); delegated sends in the sent-mail log (Q-MB-14; if ever added, the 2026-11-11 unlink
rule applies); IMAP; rich notifications with resource data; editing, moving or deleting provider
mail.
