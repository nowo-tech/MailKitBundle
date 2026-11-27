# Delegated mailboxes (Outlook and Gmail): design

Status 2026-11-25: **accepted; 2.5.0 part 1 (ports, sync, attachments) and part 2 (send) built; Gmail reduced to
send-only (phase B2, owner decision D-20261124-09) and built.** The owner accepted every recommendation (Q-MB-1 – Q-MB-22 as
recommended, 2026-11-23). For symfony-beacon spec `255-mail-inbox` (owner decision D-20261123-14,
task T001), spec `236` (personal Microsoft 365 mailboxes) and spec `254` block B (CRM mailboxes, FR-020 – FR-023). It
widens the roadmap rows 2.5.0 "Personal Outlook mailboxes" and 2.6.0 "Gmail provider". The contract revision is
[`specs/005-delegated-mailboxes/spec.md`](../../specs/005-delegated-mailboxes/spec.md). Nothing here is code yet; the
owner's decisions are in [section 19](#19-questions-for-the-owner-q-mb) and the build follows them.

## Table of contents

- [1. Summary](#1-summary)
- [2. Fixed points](#2-fixed-points)
- [3. Version split](#3-version-split)
- [4. Shape](#4-shape)
- [5. Ports, services, value objects and events](#5-ports-services-value-objects-and-events)
- [6. Data flow](#6-data-flow)
- [7. Webhook validation](#7-webhook-validation)
- [8. Subscription and watch renewal](#8-subscription-and-watch-renewal)
- [9. Cursors and concurrency (REQ-RUNTIME-003)](#9-cursors-and-concurrency-req-runtime-003)
- [10. Scopes per provider](#10-scopes-per-provider)
- [11. HTML sanitiser](#11-html-sanitiser)
- [12. Thread key](#12-thread-key)
- [13. Grant failure and pause](#13-grant-failure-and-pause)
- [14. Metering hook (Beacon spec 223)](#14-metering-hook-beacon-spec-223)
- [15. Configuration](#15-configuration)
- [16. Tables](#16-tables)
- [17. Threat model](#17-threat-model)
- [18. Personal data (GDPR)](#18-personal-data-gdpr)
- [19. Questions for the owner (Q-MB)](#19-questions-for-the-owner-q-mb)
- [20. Test plan](#20-test-plan)
- [21. Beacon side (for reference)](#21-beacon-side-for-reference)
- [22. Phase B2: Gmail send-only (D-20261124-09)](#22-phase-b2-gmail-send-only-d-20261124-09)

## 1. Summary

MailKit gains a **delegated mailbox** layer: it syncs the folders a person follows in their own Outlook (Microsoft
Graph, delegated permissions) or Gmail (Gmail API) mailbox, hands every message to the host through a port, fetches
attachments on demand and sends replies, forwards and new messages from that mailbox. It reuses what 1.8 / 1.9
already ship (Graph client with its SSRF guard, webhook verification, delta sweep, normaliser, inbound HTML sanitiser,
draft-and-send pipeline, conflict answers) and adds:

- a **token port**: MailKit never runs an OAuth flow; it asks the host for the current access token of a mailbox;
- a **mailbox directory port** (which mailboxes, which folders, paused or not) and a **message sink port** (the host
  stores; MailKit stores no message content);
- an **exclusion port** called before the sink (sender, domain, List-Id, folder);
- per-folder **subscriptions** and **cursors** with leases, so two workers and duplicate notifications are safe;
- one **grant-failure** transition per mailbox: one event, the mailbox paused, no retry loop;
- a **call observer** port so the host meters every provider call per mailbox.

The 1.9 shared-mailbox inbound pipeline (`graph.inbound`, app-only) stays as it is.

## 2. Fixed points

- **Q-254-15 = (b), owner 2026-11-23**: MailKit has **no OAuth flow, consent page or callback**. The host owns the
  connection (Beacon obtains it through TimeTrack's PKCE helpers, keeps the refresh token encrypted with
  DoctrineEncrypt) and MailKit reads current access tokens through `MailboxTokenProviderInterface`.
- **Reuse before new bundles** (owner 2026-11-23): everything lives in MailKit; no new package. The Graph pieces of 1.8
  / 1.9 are reused, not copied.
- **Gmail stays off** until Google's OAuth verification and the CASA assessment are through (restricted scopes).
  *Superseded 2026-11-24 by D-20261124-09*: Gmail is **send-only** (scopes exactly `openid email gmail.send`, a
  sensitive scope: verification, no CASA). No `gmail.readonly`, no watch, no Pub/Sub push, no history cursor, no sync,
  no attachment download, no sent copy. See [section 22](#22-phase-b2-gmail-send-only-d-20261124-09).
- **The host owns storage, encryption and retention** of messages (Beacon spec 255 FR-003, FR-006). MailKit keeps only
  sync state: cursors, subscriptions, the mailbox status and send idempotency rows.
- **MailKit never deletes or edits provider mail.** The only provider writes are the drafts it creates to send, the
  send itself, and subscriptions / watches.
- House rules: REQ-ARCH-001 rule 3 strict (ports take and return bundle VOs only: no Symfony component, Doctrine or
  HTTP type), REQ-RUNTIME-003 (concurrency), FrankenPHP worker mode with kernel reset off (no per-mailbox state in
  services).

## 3. Version split

2.3.0 (ICS invites) and 2.4.0 (editable templates) are planned first. If another release lands before, the numbers
move up; the order does not change. Each version gets a REQ-SEC-004 audit before its tag.

| Version | Content | Notes |
| --- | --- | --- |
| **2.5.0** Outlook | Provider-neutral ports, VOs, events, tables and commands (sections 5, 15, 16); Graph delegated provider: per-folder subscriptions on a new webhook route, per-folder delta sweep, fetch and normalise, exclusion, sink, thread key, on-demand attachments, sanitiser modes (section 11), send (new, reply, reply all, forward) with idempotency and the sent copy, grant-failure pause, call observer, disconnect | Two PRs (sync + attachments, then send), tagged together as one minor (Q-MB-19). Needs nothing new in composer. |
| ~~**2.6.0** Gmail~~ (superseded by phase B2, send-only) | Gmail provider behind the same ports: `users.watch` / `users.stop` with Pub/Sub push (OIDC JWT verified), `history.list` cursor per mailbox, `messages.get` normalised to the same VO, attachments, `messages.send` with threading and idempotency, Gmail quota units in the observer | `gmail.enabled: false` by default; `firebase/php-jwt` in `suggest` (Q-MB-16). Built and tested with fakes before Google's review; switched on in Beacon after it. |

Later candidates (not in either version): app-only shared mailboxes behind the same sink (Q-MB-15), rich
notifications with encrypted resource data, IMAP.

## 4. Shape

```
Host (Beacon)                                   MailKit (2.5.0 / 2.6.0)                         Provider
─────────────                                   ───────────────────────                         ────────
MailboxTokenProviderInterface ◄── access token ── Mailbox\Graph\GraphMailboxClient ──────────► graph.microsoft.com /me/…
MailboxDirectoryInterface ◄── folders, paused ─── Mailbox\Gmail\GmailMailboxClient ──────────► gmail.googleapis.com /users/me/…
                                                         ▲
                                   Webhooks (public) ────┤  /hooks/mail-kit/v1/mailbox/graph  ◄── Graph change notifications
                                                         │  /hooks/mail-kit/v1/mailbox/gmail  ◄── Pub/Sub push (OIDC JWT)
                                                         ▼
                                   Messenger: SyncMailboxFolder · FetchMailboxMessage · DeliverSentCopy · RenewMailboxSubscriptions
                                                         │
                                   MailboxSyncEngine: lease → page → fetch → exclusion → sanitise → sink → cursor CAS
                                                         │
MailboxExclusionPolicyInterface ◄── envelope ────────────┤
MailboxMessageSinkInterface ◄── SyncedMessage ───────────┤      DBAL: mail_kit_mailbox, _cursor, _subscription, _send
MailboxCallObserverInterface ◄── ProviderCall ───────────┘
Events (after commit): MailboxGrantFailedEvent, MailboxResyncedEvent

Host calls: MailboxSyncInterface (start, pause, resume, disconnect, sweep, status)
            MailboxSenderInterface (send) · MailboxAttachmentReaderInterface (open)
```

Namespaces (new code, layered per REQ-ARCH-001): `Nowo\MailKitBundle\Mailbox\Port` (host ports), `…\Mailbox\Service`
(interfaces the host calls), `…\Mailbox\Value` (VOs and enums), `…\Mailbox\Event`, `…\Mailbox\Graph`,
`…\Mailbox\Gmail`, `…\Mailbox\Infrastructure` (stores, Messenger, webhooks, commands).

Graph reuse: `GraphApiClient` gets an internal token source abstraction so the same client (fixed base URL, no
redirects, 401 → one retry with a fresh token, errors without bodies) serves app-only profiles and delegated mailboxes.
The delegated provider calls only `/me/…` paths: the token decides the mailbox, so a host can never point a delegated
token at another person's mailbox by passing a wrong address.

## 5. Ports, services, value objects and events

All VOs are `final readonly`, validated in the constructor, framework-free. Secrets are `#[SensitiveParameter]`, shown
as `[redacted]` by `__debugInfo()` and refuse `__serialize()`.

### 5.1 Host ports (`Mailbox\Port`)

```php
interface MailboxTokenProviderInterface
{
    /**
     * Current access token of the mailbox, refreshed by the host when needed. Called per provider call batch;
     * never cached by MailKit beyond one Messenger message (worker mode).
     *
     * @throws MailboxGrantFailure    the grant is gone (revoked, refresh token expired, consent withdrawn, unknown mailbox)
     * @throws MailboxTokenUnavailable transient (the host's token endpoint is down): retried by Messenger
     */
    public function accessToken(MailboxId $mailbox): MailboxAccessToken;

    /** The provider answered 401 to this token: drop it so the next accessToken() refreshes. */
    public function rejected(MailboxId $mailbox, string $tokenFingerprint): void;
}

interface MailboxDirectoryInterface
{
    /** Settings of one mailbox, or null when the host no longer knows it (MailKit then stops and cleans its rows). */
    public function mailbox(MailboxId $mailbox): ?MailboxSettings;

    /** @return iterable<MailboxId> mailboxes to keep in sync (renewal and sweep schedules) */
    public function activeMailboxes(MailboxProvider $provider): iterable;

    /** Gmail push names the account by address only. */
    public function findByAddress(MailboxProvider $provider, string $address): ?MailboxId;
}

interface MailboxExclusionPolicyInterface
{
    /** Called before the sink (and, where the provider gives the fields, before the message is fetched). */
    public function decide(MessageEnvelope $envelope): ExclusionDecision;
}

interface MailboxMessageSinkInterface
{
    /**
     * Stores one message. Must be idempotent by (mailbox id, provider message id): a second call answers Duplicate
     * (unique key + caught violation on the host side, REQ-RUNTIME-003 rule 4). Runs in the host's own transaction.
     */
    public function store(SyncedMessage $message): StoreOutcome;

    /** The message left a followed folder or was deleted in the mailbox (Q-MB-5). The host decides what to do. */
    public function removed(MailboxId $mailbox, ProviderMessageId $message, FolderRef $folder): void;
}

interface MailboxCallObserverInterface
{
    /** After every provider call. Must not throw (an exception is logged and ignored); write asynchronously. */
    public function called(ProviderCall $call): void;
}
```

Defaults: no exclusion policy = keep everything; no observer = `NullMailboxCallObserver`. The token provider, the
directory and the sink are required when `mailboxes.enabled: true` (the build fails otherwise).

### 5.2 Services the host calls (`Mailbox\Service`, interfaces + default implementations)

```php
interface MailboxSyncInterface
{
    /** Checks the account behind the token matches the settings, creates subscriptions / the watch, schedules the first sweep. */
    public function start(MailboxId $mailbox): MailboxSyncStatus;
    public function pause(MailboxId $mailbox): MailboxSyncStatus;                  // host pause (FR-001 "pause")
    public function resume(MailboxId $mailbox): MailboxSyncStatus;                 // after a reconnect or a host pause
    public function sweep(MailboxId $mailbox, ?FolderRef $folder = null): void;    // enqueues; never blocks the request
    public function status(MailboxId $mailbox): MailboxSyncStatus;
    /** Stops the watch / deletes subscriptions (best effort) and deletes every MailKit row of the mailbox. */
    public function disconnect(MailboxId $mailbox): DisconnectResult;
}

interface MailboxSenderInterface
{
    /** Sends from the mailbox's own address. Idempotent by $mail->idempotencyKey. */
    public function send(MailboxId $mailbox, OutgoingMailboxMail $mail): SentMessage;
}

interface MailboxAttachmentReaderInterface
{
    /** Streams one attachment into a capped temp stream; MailKit keeps nothing. */
    public function open(MailboxId $mailbox, ProviderMessageId $message, ProviderAttachmentId $attachment, int $maxBytes): FetchedAttachment;
}
```

### 5.3 Value objects and enums (`Mailbox\Value`)

| VO | Fields and rules |
| --- | --- |
| `MailboxId` | Host's opaque id, `[A-Za-z0-9_.:-]{1,128}`, compared exactly |
| `MailboxProvider` (enum) | `Graph`, `Gmail` |
| `MailboxSettings` | `MailboxId $id`, `MailboxProvider $provider`, `string $address` (lower-cased, validated), `list<FolderRef> $folders` (1 – `max_folders`), `bool $paused`, `?string $initialWindow` (ISO 8601, ≤ `sync.max_initial_window`) |
| `FolderRef` | `string $key` (well-known name such as `inbox`, `sentitems`, or a provider folder / label id, validated against a strict pattern); `isWellKnown()` |
| `MailboxAccessToken` | `#[SensitiveParameter] string $value`, `DateTimeImmutable $expiresAt`, `list<string> $scopes`; `fingerprint()` = first 12 hex of SHA-256; redacted dumps, not serialisable |
| `ProviderMessageId`, `ProviderAttachmentId` | Provider ids, length- and charset-checked (Graph immutable ids, Gmail hex ids) |
| `MessageEnvelope` | `MailboxId`, `FolderRef`, `ProviderMessageId`, `?MailAddress $from`, `?MailAddress $sender`, `?string $listId`, `?string $subject`, `?DateTimeImmutable $receivedAt`, `bool $complete` (false when only the delta / history fields were known) |
| `ExclusionDecision` | `keep()` / `exclude(string $ruleId)` (`ruleId` ≤ 64 characters, for the host's own per-rule counter) |
| `SyncedMessage` | `MailboxId`, `MailboxProvider`, `FolderRef`, `ProviderMessageId $id`, `?string $providerThreadId`, `ThreadKey $threadKey`, `string $internetMessageId`, `?string $inReplyTo`, `list<string> $references`, `?MailAddress $from`, `?MailAddress $sender`, `list<MailAddress> $replyTo`, `$to`, `$cc`, `$bcc` (sent items only), `string $subject`, `string $snippet` (≤ 255 characters), `string $text`, `?string $html` (sanitised, section 11), `int $remoteImagesBlocked`, `?DateTimeImmutable $receivedAt`, `?DateTimeImmutable $sentAt`, `bool $fromMailbox`, `?string $listId`, `list<AttachmentRef> $attachments`, `AuthenticationResults $authentication`, `bool $oversized`, `MessageOrigin $origin` (`Sync`, `Resync`, `SentCopy`) |
| `AttachmentRef` | `ProviderAttachmentId`, neutralised filename, declared content type, size, `inline`, `?contentId` (no content) |
| `FetchedAttachment` | `resource $stream` (php://temp, rewound), `int $size`, detected content type (finfo), neutralised filename |
| `ThreadKey` | `string $value` = `provider:<thread id>` or `msgid:<root Message-ID>`; `ThreadKey::for()` (section 12) |
| `StoreOutcome` (enum) | `Stored`, `Duplicate` |
| `OutgoingMailboxMail` | `OutgoingKind $kind` (`New`, `Reply`, `ReplyAll`, `Forward`), `?ProviderMessageId $inResponseTo` (required unless `New`), `list<MailAddress> $to, $cc, $bcc`, `?string $subject` (null = provider's prefix + original subject), `string $text`, `?string $html`, `list<OutgoingAttachment> $attachments`, `string $idempotencyKey` (`[A-Za-z0-9_.:-]{16,128}`, Q-MB-20) |
| `OutgoingAttachment` | `fromPath()` / `fromStream()` / `fromString()` with filename, content type, inline, content id: the 1.8 `GraphAttachment` rules, provider-neutral |
| `SentMessage` | `ProviderMessageId $id`, `string $internetMessageId`, `?string $providerThreadId`, `ThreadKey $threadKey`, `DateTimeImmutable $sentAt`, `bool $replayed` (true when the key was already sent) |
| `MailboxSyncStatus` | `MailboxStatus $status` (`Active`, `PausedByHost`, `PausedGrant`, `Unknown`), `?GrantFailureReason $reason`, per folder: last complete sweep, subscription expiry |
| `GrantFailureReason` (enum) | `Revoked`, `ConsentMissing`, `AccountMismatch`, `MailboxNotFound`, `ScopeMissing` |
| `ProviderCall` | `MailboxProvider`, `MailboxId`, `string $operation` (closed list, section 14), `int $status` (0 = transport error), `int $durationMs`, `int $bytes`, `?int $retryAfter`, `int $quotaUnits` (Gmail), `int $attempt` |
| `DisconnectResult` | `int $subscriptionsDeleted`, `bool $remoteCleanupFailed`, `int $rowsDeleted` |

`MailAddress` is the existing framework-free `Sender\MailAddress` (1.12); `AuthenticationResults` is the existing 1.9
VO. Neither is copied.

### 5.4 Events (`Mailbox\Event`, dispatched after commit, ids only)

- `MailboxGrantFailedEvent(MailboxId, MailboxProvider, GrantFailureReason)`: exactly once per transition to
  `PausedGrant` (section 13).
- `MailboxResyncedEvent(MailboxId, FolderRef, string $reason)`: a lost cursor restarted from the resync window
  (section 9); the host may tell the person that older mail can show up again (it does not: the sink deduplicates).

## 6. Data flow

### 6.1 Start

1. The host connects the account (its own OAuth + PKCE), stores the tokens, then calls `start($mailboxId)`.
2. MailKit reads `MailboxSettings`, takes a token, reads the account (`GET /me?$select=id,mail,userPrincipalName` /
   Gmail `users.getProfile`) and compares the address with the settings. A mismatch is a grant failure
   `AccountMismatch` (a host bug that would file Ana's mail under Pedro's mailbox). The SHA-256 of the provider
   account id is kept in `mail_kit_mailbox.account_hash` and checked again on every renewal.
3. Graph: one subscription per followed folder (`/me/mailFolders('{id}')/messages`, `changeType: created`, immutable
   ids). Gmail: one `users.watch` for the mailbox (followed labels, `labelFilterBehavior: include`), its `historyId`
   saved as the cursor.
4. One `SyncMailboxFolder` per folder (Gmail: per mailbox) for the initial window.

### 6.2 Notification → fetch → store

1. The webhook verifies (section 7) and only enqueues: Graph → `FetchMailboxMessage(mailbox, folder, id)`; Gmail →
   `SyncMailboxFolder(mailbox, '*')` (the push carries only a history id, used as a hint, never as a cursor).
2. `FetchMailboxMessage`: status check (paused → drop), token, `GET /me/messages/{id}` with `$select` of the envelope,
   `internetMessageHeaders`, body and attachment metadata (one call; Gmail: `messages.get format=full`).
3. Exclusion: `decide(envelope)`. `Exclude` → the body is dropped in memory, never normalised, logged or stored.
   Where the delta / history page already gives the sender and folder (Graph delta `$select=from,parentFolderId`),
   the policy runs first with `complete: false` and an excluded message is not fetched at all (Q-MB-3).
4. Normalise (1.9 `GraphMessageNormalizer` rules: headers, threading ids, plus tags, authentication results, caps),
   sanitise (section 11), build `SyncedMessage`, call `store()`. `Duplicate` is a normal outcome.

### 6.3 Sweep

`SyncMailboxFolder` takes the folder lease (section 9), follows the cursor page by page (Graph `deltaLink` /
`nextLink` checked by the 1.9 link guard, adapted to `/me/mailFolders/…/messages/delta`; Gmail `history.list` with
`historyTypes=messageAdded,labelAdded`), dispatches `FetchMailboxMessage` per new id, reports removals (`@removed` /
`messageDeleted` / `labelRemoved` of a followed label) to `removed()`, and advances the cursor only after the page's
messages are dispatched (at least once; the sink deduplicates). A scheduled `nowo:mail-kit:mailbox:sweep` (every
5 minutes) enqueues a sweep for every active mailbox, so a lost notification costs at most one interval.

### 6.4 Attachments on demand

`open()` checks the mailbox status, takes a token and streams `GET /me/messages/{id}/attachments/{aid}/$value` (Gmail:
`messages.attachments.get`, base64url decoded in chunks) into `php://temp`, capped at `$maxBytes` (≤
`limits.max_attachment_bytes`), with the finfo type and the 1.9 filename neutraliser. Item attachments (attached
e-mails) and reference attachments (OneDrive links) are listed with their kind and are not downloadable in 2.5.0.

### 6.5 Send

1. Claim the idempotency key (`mail_kit_mailbox_send`, unique key; section 9.4). An already sent key returns the stored
   `SentMessage` with `replayed: true`.
2. Graph: `New` → MIME draft (1.8 path: threading headers, Bcc, attachments, upload sessions over 2.9 MB) on
   `/me/messages`; `Reply` / `ReplyAll` / `Forward` → `createReply` / `createReplyAll` / `createForward` on the
   original (Graph sets the threading headers, the conversation and, for a forward, the original attachments), then
   `PATCH` the recipients and the body (Q-MB-7), add attachments. The draft id is saved on the claim row, then
   `POST /me/messages/{id}/send`. Gmail: one RFC 5322 message (Symfony Mime internally) with `In-Reply-To` /
   `References` from the original and `threadId`, `messages.send`.
3. The claim row becomes `sent` with the ids; `SentMessage` is returned at once.
4. `DeliverSentCopy` (Messenger, short delay, three tries) fetches the sent item (Graph immutable ids survive the move
   to Sent Items) and calls `store()` with `origin: SentCopy`, whether or not Sent Items is followed (Q-MB-6).

### 6.6 Pause, resume, disconnect

A paused mailbox (`PausedByHost` or `PausedGrant`) is skipped by every handler and the webhook acknowledges its
notifications without work. `resume()` re-checks the account, recreates missing subscriptions / the watch and sweeps
from the stored cursor (resync if it expired). `disconnect()` deletes the remote subscriptions (Gmail `users.stop`)
with the current token when it still works, then deletes every MailKit row of the mailbox; a subscription that could
not be deleted expires on the provider's side (≤ 7 days) and its notifications are refused as unknown.

## 7. Webhook validation

### 7.1 Graph (2.5.0, route `/hooks/mail-kit/v1/mailbox/graph`, separate from the 1.9 route)

The 1.9 controller logic is reused with its own store lookup:

- **Validation request**: the `validationToken` query parameter is echoed as `text/plain` within 10 s, only when it is
  1 – 1024 printable ASCII characters (`nosniff`, `default-src 'none'`).
- **Notifications**: each item must name a stored, unexpired subscription of an **active** mailbox and carry its
  `clientState`: a random 256-bit value per subscription, stored as SHA-256 only, compared with `hash_equals` (also
  for unknown ids). The mailbox comes from the stored subscription row, **never** from the payload's `resource`.
  The resource id is format-checked; replays are no-ops (idempotent fetch).
- **Lifecycle events** (`lifecycleNotificationUrl` = the same route): `reauthorizationRequired` → renew;
  `subscriptionRemoved` → recreate; `missed` → sweep the folder.
- Body / batch caps, a per-IP bucket for unverified traffic and a bucket for verified traffic, optional
  `allowed_ips` (as 1.9). The route only enqueues and answers 202 within 3 s.

### 7.2 Gmail (2.6.0, route `/hooks/mail-kit/v1/mailbox/gmail`)

Gmail publishes to a Cloud Pub/Sub topic; a **push subscription with authentication** calls the route with
`Authorization: Bearer <Google-signed OIDC JWT>`:

- Verify the JWT: RS256 against Google's JWKS (`https://www.googleapis.com/oauth2/v3/certs`, cached in a PSR-6 pool for
  the `Cache-Control` max-age, refetched once on an unknown `kid`), `iss` = `https://accounts.google.com` or
  `accounts.google.com`, `aud` = `gmail.push.audience`, `email` = `gmail.push.service_account` with
  `email_verified: true`, `exp` / `iat` with 60 s leeway. Library: `firebase/php-jwt` (Q-MB-16).
- Check the body's `subscription` equals `gmail.push.subscription`; decode `message.data` (base64 JSON
  `{emailAddress, historyId}`, capped); look the address up with `findByAddress()`; unknown or paused → acknowledge
  (204) without work, so Pub/Sub does not redeliver forever.
- Only `SyncMailboxFolder` is enqueued; the `historyId` is a hint (a higher one than the cursor triggers the sweep), never
  stored as the cursor. Answer 204 within Pub/Sub's ack deadline; a 409 conflict answer makes Pub/Sub redeliver.
- No shared secret in the URL (it would end in access logs).

## 8. Subscription and watch renewal

- Graph: message subscriptions live at most about 7 days (10 080 minutes, as Microsoft documents for Outlook
  messages; re-checked in the first build task). `nowo:mail-kit:mailbox:subscriptions` (hourly) renews every
  subscription that expires within `graph.renew_before` (default `P1D`) with `PATCH expirationDateTime`, recreates a
  missing one, re-checks the account hash, and deletes subscriptions of folders the host no longer follows. A
  `reauthorizationRequired` event renews at once.
- Gmail: `users.watch` expires after 7 days; Google recommends calling it again daily. The same command calls `watch`
  for every active mailbox whose watch expires within `gmail.renew_before` (default `P6D`, so daily in practice).
- A renewal that fails with a grant error pauses the mailbox (section 13); a transient error is retried at the next
  run; a missed renewal is caught by the 5-minute sweep (no message is lost, only latency).
- Swaps (delete the old row, insert the new one) run in one transaction, as 1.13 does for the shared mailbox.

## 9. Cursors and concurrency (REQ-RUNTIME-003)

MailKit writes only through DBAL on the configured connection, never touches the host's entity manager, uses
`nowo-tech/transaction-doctrine` for multi-statement units and savepoints inside a host transaction (as the 1.9 store),
and maps lock conflicts on its routes to 409 `CONFLICT_001` / `CONFLICT_002` with `X-Conflict-Code`.

### 9.1 One sweeper per folder: lease

`mail_kit_mailbox_cursor` has `lease_owner` (random per run) and `lease_until`. A sweep takes the lease with one
conditional `UPDATE … SET lease_owner = ?, lease_until = ? WHERE mailbox_id = ? AND folder_key = ? AND (lease_until IS
NULL OR lease_until < ?)`. Zero rows → another worker holds it: the sweep sets `rerun = 1` (atomic `UPDATE`) and ends.
The holder clears `rerun` when it starts and, when it finishes with `rerun = 1`, enqueues one more sweep. So a
notification that arrives during a sweep is never lost and two workers never page the same cursor.

### 9.2 Cursor writes: compare-and-set

Each page advances the cursor with `UPDATE … SET cursor = ?, cursor_version = cursor_version + 1 WHERE … AND
cursor_version = ? AND lease_owner = ?`. Zero rows → the lease expired and another worker took over: this worker
stops without writing (its dispatched fetches are idempotent). The first row of a mailbox folder is inserted with a
unique key and a caught duplicate (never check-then-insert).

### 9.3 Duplicates and order

- Duplicate notifications, a notification plus a sweep, a Messenger retry, two workers: each ends in a second
  `store()` call that answers `Duplicate` (host unique key). MailKit keeps **no claim table** for delegated mailboxes
  (Q-MB-4): the 1.9 claim exists because MailKit hands the message over with a decision; here the host's unique key is
  the single control.
- Messages are stored in any order; the thread key and dates order them on the host side.

### 9.4 Send idempotency

`mail_kit_mailbox_send.idempotency_key` is unique. `send()` inserts the claim (`claimed`), catches the duplicate and
reads the row: `sent` → return the stored ids (`replayed: true`); `claimed` / `drafted` younger than
`send.stale_claim_seconds` → 409 `CONFLICT_001` (in flight); older → take it over with a conditional `UPDATE … WHERE
claimed_at < ?`, then: a stored draft id that Graph still reports as a draft is sent; one that is no longer a draft
was sent (immutable id) and is marked `sent`. As built (2.5.0 part 2) the states are `drafting`, `sending`, `sent`,
`released` (abandoned before the submit; taken over at once), `failed` and `unknown`, and the key is unique per
mailbox; see `Mailbox\Engine\MailboxSender`. Gmail has no draft step: the Message-ID is derived from the key, and a
takeover first searches `rfc822msgid:` in the mailbox before sending again. A failed send keeps the row `failed` with
the provider's reason (the host keeps its draft, Beacon FR-041). Rows are purged after `send.retention` (`P7D`).

### 9.5 Lost cursor → resync

Graph `410` (`syncStateNotFound`, `resyncRequired`) or Gmail `404` on `history.list` (the start history id is too
old): the cursor is deleted in the lease holder's CAS, `MailboxResyncedEvent` is dispatched after commit, and the next
page restarts from `sync.resync_window` (default `P7D`; Graph `$filter=receivedDateTime ge …`, Gmail `messages.list`
with `after:` and the followed labels, after reading a fresh `historyId` with `users.getProfile`). Everything already
stored answers `Duplicate`.

### 9.6 Status transitions

`mail_kit_mailbox.status` changes only through conditional updates (`WHERE status = 'active'` for a grant failure,
`WHERE status IN (…)` for resume), each in one statement, so exactly one worker wins a transition and dispatches its
event after commit.

## 10. Scopes per provider

What the providers' documentation states at the time of writing; each line marked *to verify* is checked against the
live documentation and a test tenant / Google project in the first build task of its version.

### 10.1 Microsoft Graph (delegated)

| Scope | Why | Notes |
| --- | --- | --- |
| `Mail.ReadWrite` | Read messages and attachments, delta, subscriptions; create the drafts of the send path (`createReply`, MIME draft, upload sessions) | Beacon spec 236 already requests it. Without drafts (`sendMail` / `reply` actions) `Mail.Read` would do, but no ids, no idempotent retry and no large attachments (Q-MB-1, Q-MB-2) |
| `Mail.Send` | `POST /me/messages/{id}/send` | |
| `offline_access` | Refresh token, used by the host only | MailKit never sees it |
| `User.Read` | `GET /me` for the account check (section 6.1) | Could be replaced by reading the token's `oid` claim, but MailKit treats tokens as opaque |

Not requested: `Mail.Read.Shared`, `Mail.Send.Shared` (Q-MB-15), `MailboxSettings.*`. The `common` tenant serves
work / school and personal Microsoft accounts; change notifications and immutable ids on personal (Outlook.com)
accounts: *to verify* (Q-MB-21). Throttling: Microsoft documents per-mailbox limits for Outlook resources (about
10 000 requests per 10 minutes and 4 concurrent requests per app and mailbox): *to verify*; MailKit runs one sweeper
per folder and honours `Retry-After` through a Messenger `DelayStamp`.

### 10.2 Gmail

| Scope | Why | Google classification |
| --- | --- | --- |
| `https://www.googleapis.com/auth/gmail.readonly` | `users.watch`, `history.list`, `messages.get`, `messages.list`, `attachments.get`, `users.getProfile` | **Restricted**: OAuth verification + yearly CASA assessment. **Not requested** (superseded by section 22, D-20261124-09: send-only) |
| `https://www.googleapis.com/auth/gmail.send` | `messages.send` | Sensitive (verification, no CASA): *to verify* in Google's console |

Not requested: `gmail.modify` (would cover both but allows label and trash changes MailKit never makes),
`gmail.compose` (drafts are not needed for Gmail), `gmail.metadata` (no body; also cannot be combined with `q`
searches). Pub/Sub: the topic grants `roles/pubsub.publisher` to `gmail-api-push@system.gserviceaccount.com`; the push
subscription uses a dedicated service account (no key file in MailKit). Quota: Google documents per-user quota units
(`messages.get` 5, `history.list` 2, `messages.send` 100, `watch` 100; 250 units per user per second): *to verify*;
the units go to the call observer.

## 11. HTML sanitiser

No new dependency. MailKit already ships `Graph\Inbound\InboundHtmlSanitizer` (1.9) on `symfony/html-sanitizer`:
W3C safe elements, no `script`, `style`, `iframe`, `form`, event handlers or `style` attributes, links `http`,
`https`, `mailto` only with `rel="noopener noreferrer nofollow"`, no relative URLs, `cid:` images kept through a
placeholder host, input capped. Wiki's `WikiHtmlSanitizer` is the same library tuned for editor output (it allows a
`text-align` style); UiKit, ChatKit and Milkdown have no server-side HTML sanitiser. The mailbox layer moves the 1.9
class to a provider-neutral `Mailbox\Html\MailHtmlSanitizer` (the 1.9 class stays as a thin subclass, no BC break) and
adds one mode:

| `html.remote_images` | Effect |
| --- | --- |
| `blocked` (default) | `<img src="https://…">` loses its `src`; the URL (absolute `https`, no user info, ≤ 2 048 characters, re-validated) is kept in `data-mail-kit-remote-src` so the host's *Show images* can load it, preferably through its own image proxy (Q-MB-8). `SyncedMessage::$remoteImagesBlocked` counts them. |
| `dropped` | The image is removed with its URL (1.9 default behaviour). |
| `allowed` | `http(s)` sources kept (tracking pixels reach the sender). Not for Beacon. |

Why it is safe for a strict CSP: the output has no inline script, no event handler, no `style` attribute or element
(so `style-src` without `'unsafe-inline'` holds; Q-MB-9) and no URL that loads without a click. `cid:` images need the
host to resolve them (an inline `AttachmentRef` with the same content id, served from its own origin). Links stay
plain `href`s; the host adds `target` / its own click-through warning.

## 12. Thread key

`ThreadKey::for(SyncedMessage)`:

1. the provider thread id when present: Graph `conversationId` → `provider:graph:<id>`, Gmail `threadId` →
   `provider:gmail:<id>`;
2. otherwise the root of `References` (first entry) → `msgid:<id>`;
3. otherwise `In-Reply-To` → `msgid:<id>`;
4. otherwise the message's own `Message-ID` → `msgid:<id>` (a new thread).

`SyncedMessage` also carries `internetMessageId`, `inReplyTo` and `references`, so the host can join a message to an
existing thread by header ids when the provider thread differs: the same conversation seen from an Outlook and a Gmail
mailbox, or a reply whose subject change made Outlook open a new conversation. That join needs the host's storage and
is the host's (Q-MB-10).

## 13. Grant failure and pause

| Signal | Meaning | Action |
| --- | --- | --- |
| `MailboxGrantFailure` from the token port | Host says the grant is gone | Pause with its reason |
| 401 after one `rejected()` + fresh token | Token not accepted | Pause `Revoked` |
| Graph 403 `ErrorAccessDenied` / consent errors; Gmail 403 `insufficientPermissions` | Consent or scope missing | Pause `ConsentMissing` / `ScopeMissing` |
| Graph 404 `MailboxNotEnabledForRESTAPI` / `ErrorInvalidUser`; Gmail 400 `failedPrecondition` (Gmail disabled) | No mailbox behind the account | Pause `MailboxNotFound` |
| Account check mismatch (section 6.1) | Host mapped a token to the wrong mailbox | Pause `AccountMismatch` |
| 429, 5xx, timeouts, `MailboxTokenUnavailable` | Transient | Messenger retry with `Retry-After` / backoff; never a pause |

Pause = the conditional `UPDATE … WHERE status = 'active'` (section 9.6); only the winner dispatches
`MailboxGrantFailedEvent` after commit (one bell notice in Beacon). The failing Messenger message ends with an
unrecoverable exception (no retry), every queued message of the mailbox is dropped on its status check, the scheduled
sweep and renewal skip it, and its notifications are acknowledged without work. Nothing retries until the host calls
`resume()`.

## 14. Metering hook (Beacon spec 223)

Two complementary pieces:

1. **`mailboxes.http_client`** (default `http_client`): HttpLog's outbound decorator sees every call and counts it
   under the provider the host declares for `graph.microsoft.com` / `gmail.googleapis.com` (no bodies, query strings or
   headers recorded). Token endpoints are the host's, so they are metered there.
2. **`MailboxCallObserverInterface::called(ProviderCall)`**: after every provider call, with the mailbox id (Beacon maps
   it to the person and organisation), a fixed operation label (`account`, `subscribe`, `renew`, `unsubscribe`,
   `watch`, `stop`, `delta`, `history`, `list`, `get_message`, `get_attachment`, `create_draft`, `update_draft`,
   `upload`, `send`), status, duration, bytes, `Retry-After`, Gmail quota units and the attempt number. Never a URL,
   id of a message, address or body. An observer exception is logged and ignored (metering never breaks sync); the
   host should write asynchronously.

## 15. Configuration

```yaml
nowo_mail_kit:
    mailboxes:
        enabled: false                 # registers ports, services, Messenger handlers, commands, routes
        token_provider: ~              # required: MailboxTokenProviderInterface service id
        directory: ~                   # required: MailboxDirectoryInterface
        sink: ~                        # required: MailboxMessageSinkInterface
        exclusion_policy: ~            # optional: MailboxExclusionPolicyInterface (default: keep all)
        call_observer: ~               # optional: MailboxCallObserverInterface
        http_client: http_client
        connection: ~                  # DBAL connection (default connection when null)
        max_folders: 10
        sync:
            initial_window: P7D        # first sweep; a mailbox may ask for less or more up to max_initial_window
            max_initial_window: P90D
            resync_window: P7D
            page_size: 50
            max_pages_per_run: 20
            lease_seconds: 120
        limits:
            max_message_bytes: 26214400
            max_html_bytes: 1048576
            max_attachment_bytes: 26214400   # ceiling for open($maxBytes)
        html:
            remote_images: blocked     # blocked | dropped | allowed
        send:
            stale_claim_seconds: 300  # as built: must exceed 8 × mailboxes.timeout
            retention: P7D
            max_recipients: 100
        graph:
            enabled: false
            renew_before: P1D
            webhook:
                path: /hooks/mail-kit/v1/mailbox/graph
                notification_url: ~    # public https URL; null = sweep only
                allowed_ips: []
                rate_limit: { limit: 600, interval: '1 minute' }
                client_rate_limit: { limit: 60, interval: '1 minute' }
        gmail:
            enabled: false             # off until Google's verification + CASA (Beacon switches it on)
            topic: ~                   # projects/<project>/topics/<topic>
            renew_before: P6D
            push:
                path: /hooks/mail-kit/v1/mailbox/gmail
                audience: ~            # the push subscription's audience
                service_account: ~     # the push subscription's service account e-mail
                subscription: ~        # projects/<project>/subscriptions/<name>
                jwks_cache: cache.app
```

Build-time checks: `enabled` needs the three required ports; `graph.enabled` / `gmail.enabled` need `enabled`;
`gmail.enabled` needs `topic` and the three `push` values and `firebase/php-jwt`; windows are ISO 8601 durations
with `resync_window` ≤ `max_initial_window`.

Commands: `nowo:mail-kit:mailbox:schema`, `…:mailbox:subscriptions` (hourly), `…:mailbox:sweep` (every 5 minutes),
`…:mailbox:purge` (daily, send rows), `…:mailbox:status <id>` (no personal data in the output).

## 16. Tables

DBAL, table prefix, exact-key columns (REQ-SEC-006) for every id; created by the `postGenerateSchema` listener or the
schema command, as the 1.9 tables.

| Table | Columns | Keys |
| --- | --- | --- |
| `mail_kit_mailbox` | `mailbox_id`, `provider`, `status`, `reason`, `account_hash` (SHA-256), `updated_at` | PK `mailbox_id` |
| `mail_kit_mailbox_cursor` | `mailbox_id`, `folder_key`, `provider_folder_id`, `cursor` (delta link or history id), `cursor_version`, `lease_owner`, `lease_until`, `rerun`, `last_complete_at` | PK (`mailbox_id`, `folder_key`) |
| `mail_kit_mailbox_subscription` | `subscription_id`, `mailbox_id`, `folder_key`, `client_state_hash`, `expires_at`, `created_at` | PK `subscription_id`; unique (`mailbox_id`, `folder_key`) |
| `mail_kit_mailbox_send` | `mailbox_id`, `idempotency_key`, `payload_hash`, `status`, `claim_token`, `provider_message_id` (the draft's immutable id, which the sent item keeps), `internet_message_id`, `thread_id`, `reason`, `attempts`, `row_version`, `claimed_at`, `updated_at`, `sent_at` (as built in 2.5.0 part 2) | PK (`mailbox_id`, `idempotency_key`); indexes (`updated_at`), (`mailbox_id`, `provider_message_id`) |

No table holds an address, a subject, a body, a token or a recipient. A delta link carries an opaque provider token
that is useless without the bearer token; it is never logged.

## 17. Threat model

| Threat | Mitigation |
| --- | --- |
| Access token disclosure | `MailboxAccessToken` redacted and not serialisable; tokens only in the `Authorization` header to `graph.microsoft.com` / `gmail.googleapis.com` (and never to upload hosts); never cached by MailKit, logged, or put in exceptions (fixed messages with status, provider code and request id) |
| Cross-mailbox confusion (host maps a token to the wrong mailbox) | `/me` paths only; account check at start, resume and every renewal (`AccountMismatch` pause); the sink always gets the `MailboxId` from MailKit's own rows, never from a webhook payload |
| Forged or replayed notifications | Graph: per-subscription 256-bit `clientState` (hash, constant-time) + stored subscription of an active mailbox; Gmail: verified OIDC JWT (issuer, audience, service account, expiry) + subscription name; both only enqueue idempotent work; caps and rate limits (section 7) |
| SSRF / token leak through provider links | Base URLs fixed; `nextLink` / `deltaLink` followed only on `https://graph.microsoft.com/v1.0/me/mailFolders/…/messages/delta`; no redirects; Gmail paths built from validated ids only |
| Hostile HTML | Sanitiser (section 11), capped input; remote content never loads without a click |
| Hostile attachments | On demand only, capped temp streams, finfo type, neutralised names; MailKit never renders or stores them |
| Header injection on send | 1.8 rules: addresses without CR / LF / `<>`, subjects and names stripped of control characters, ids validated, Mime builds the headers |
| Duplicate or lost sends | Idempotency key with claim, takeover and draft-state check (section 9.4) |
| Exclusion bypass | The policy runs before the sink on every path (sync, resync, sent copy is exempt only for the mailbox's own sends); a policy exception fails the message (Messenger retry), never stores it |
| Retry storm on a dead grant | One transition, unrecoverable message, status checks everywhere (section 13) |
| Notification floods / quota exhaustion | Enqueue-only endpoints, buckets, lease + `rerun` coalescing, page and run caps, `Retry-After` honoured |
| Data left behind after disconnect | `disconnect()` deletes remote subscriptions / watch and all MailKit rows; host deletes its stored mail (Beacon FR-001) |
| Worker-mode state bleed | No per-mailbox state in services; immutable sanitiser config; JWKS and nothing else cached (PSR-6) |

## 18. Personal data (GDPR)

- **MailKit stores no message content and no address** for delegated mailboxes (section 16); the host is the
  controller of the stored mail, its encryption, export, erasure and retention (Beacon FR-006, FR-051).
- **Disconnect** (`disconnect()`) is the erasure of a mailbox on MailKit's side; erasing a person = the host
  disconnects each of their mailboxes. MailKit's personal-data bridge declares no export for this layer.
- **Third parties** (senders, recipients): only in the host's storage; the exclusion port lets a person keep senders,
  domains and lists out before anything is stored.
- **Sent-mail log**: delegated sends are **not** written to MailKit's sent-mail log (the host stores the thread; a
  second copy of the same personal data would need its own retention and erasure) (Q-MB-14). If the owner wants them
  logged, the existing unlink rule (owner, 2026-11-11) applies: erasing an address removes it from shared rows, a row is
  deleted only when no other recipient remains, and the sender address is masked, not deleted.
- **Send rows** keep ids only and are purged after 7 days.

## 19. Questions for the owner (Q-MB)

All 22 decided by the owner on 2026-11-23, each as recommended.

| # | Question | Decision |
| --- | --- | --- |
| Q-MB-1 | Graph send path: draft + send (`createReply` / `createReplyAll` / `createForward`, MIME draft for new) or the one-shot `sendMail` / `reply` actions Beacon's spec names? | Decided 2026-11-23: **Draft + send**: it returns the ids of the sent copy, makes retries idempotent (draft state check) and supports attachments up to 150 MB through upload sessions; `sendMail` answers 202 with no id |
| Q-MB-2 | Graph scopes: `Mail.ReadWrite`, `Mail.Send`, `offline_access`, `User.Read`? | Decided 2026-11-23: **Yes** (same as spec 236); `Mail.Read` alone cannot create drafts (Q-MB-1) |
| Q-MB-3 | When does the exclusion policy run? | Decided 2026-11-23: **Before any fetch** when the delta page gives sender and folder (Graph), otherwise after the **single** full fetch, with the body dropped in memory; no second "headers first" call (it doubles API calls and Gmail quota) |
| Q-MB-4 | Idempotency: rely on the host sink's unique key (no MailKit claim table)? | Decided 2026-11-23: **Yes**: one control, no extra write per message; the sink contract requires `Duplicate` |
| Q-MB-5 | Report messages deleted or moved out of a followed folder in the mailbox? | Decided 2026-11-23: **Report** through `removed()`; Beacon keeps its copy and may mark it "deleted in mailbox" (it is a register) |
| Q-MB-6 | Sent copy: return ids at once and deliver the full copy through the sink, even when Sent Items is not followed? | Decided 2026-11-23: **Yes** |
| Q-MB-7 | Reply / forward body: the host's body replaces the draft body Outlook generates (with its quote)? | Decided 2026-11-23: **Yes, the host's body wins** (Beacon composes the quote; same behaviour as Gmail, which has no server-side quote) |
| Q-MB-8 | Remote images: keep the blocked URL in `data-mail-kit-remote-src` for *Show images*? | Decided 2026-11-23: **Yes, mode `blocked` by default**; Beacon loads them through its own image proxy so the CSP stays `img-src 'self'` |
| Q-MB-9 | Inline `style` attributes: drop all, or allow a closed list of properties? | Decided 2026-11-23: **Drop all in 2.5.0** (a strict `style-src` would block them anyway); revisit with a sandboxed render if fidelity is a problem |
| Q-MB-10 | Thread key: provider thread id first, header fallback; cross-provider joins by header ids left to the host? | Decided 2026-11-23: **Yes** |
| Q-MB-11 | First sweep and resync window? | Decided 2026-11-23: **`P7D` default, per mailbox up to `P90D`** (Beacon can offer 30 days on connect) |
| Q-MB-12 | Grant-failure signals (section 13): pause only on grant / consent / account errors, transient errors retried? | Decided 2026-11-23: **Yes**; one event per transition, resume only by the host |
| Q-MB-13 | Metering: observer port with the mailbox id **and** the `http_client` option for HttpLog? | Decided 2026-11-23: **Both** |
| Q-MB-14 | Write delegated sends to MailKit's sent-mail log? | Decided 2026-11-23: **No** (host stores the thread); if yes, the 2026-11-11 unlink rule applies |
| Q-MB-15 | Shared mailboxes (soporte@) through delegated `Mail.*.Shared` scopes in 2.5.0? | Decided 2026-11-23: **No**: in 2.5.0 the shared address is connected as its own account; a later minor can put the 1.9 app-only profiles behind the same sink |
| Q-MB-16 | Gmail push authentication: OIDC JWT verified with `firebase/php-jwt` (already used by WalletQrBundle, `suggest` only)? | Decided 2026-11-23: **Yes**, plus the subscription name check; no secret in the URL |
| Q-MB-17 | Gmail scopes: `gmail.readonly` + `gmail.send`, not `gmail.modify`? | Decided 2026-11-23: **Yes** (least privilege; MailKit never changes labels) |
| Q-MB-18 | Gmail gate: `gmail.enabled: false` by default and no extra "verified" flag (Google's testing mode allows test users before verification)? | Decided 2026-11-23: **Yes**; Beacon's Google phase-2 checklist gates production |
| Q-MB-19 | Version split: 2.5.0 Outlook (sync, attachments, send; two PRs, one tag), 2.6.0 Gmail? | Decided 2026-11-23: **Yes** |
| Q-MB-20 | Make the send idempotency key mandatory? | Decided 2026-11-23: **Yes** (a double click or a Messenger retry must never send twice) |
| Q-MB-21 | Personal Outlook.com accounts in 2.5.0? | Decided 2026-11-23: **Yes if** subscriptions and immutable ids work for them in the first build task; otherwise sweep-only for those accounts, documented |
| Q-MB-22 | Item attachments (attached e-mails) and OneDrive reference attachments in 2.5.0? | Decided 2026-11-23: **Listed, not downloadable** in 2.5.0; a later minor if Beacon asks |

## 20. Test plan

- **No real token or account** (secrets rule): HTTP fixtures with fake tokens built at runtime; Graph and Gmail
  answers recorded against throwaway data only.
- Unit: every VO's validation; sanitiser modes (blocked URL kept and re-validated, `javascript:` / `data:` refused,
  `cid:` kept); thread key order; grant-failure classification table; JWT verification (wrong issuer, audience,
  service account, expired, unknown `kid`, `alg: none`); link guard on `/me` delta links.
- Integration (kernel): webhook validation echo, forged `clientState`, unknown subscription, paused mailbox, lifecycle
  events; Gmail push with a test JWKS; DDD host kernel with in-memory ports (no ORM, no Instance Settings).
- Concurrency (`ConcurrencyRaceTest`, MySQL 8.4, MariaDB 11.4, PostgreSQL 16 / 17): two sweepers on one folder (lease,
  `rerun`), cursor CAS after lease takeover, two `send()` with one key (one send, one replay or 409), status
  transition raced by two workers (one event), first cursor row inserted by two workers, all inside a host
  transaction too.
- Worker mode: two mailboxes handled in one process alternately, no state bleed.
- Load: 1 000 messages through the engine with an in-memory sink: provider calls per message ≤ 2 (fetch + optional
  attachment list), no DB write per message on MailKit's side except per page.

## 21. Beacon side (for reference)

Beacon implements the four ports: tokens from its encrypted connection (spec 236 FR-003), the directory from its
mailbox records (followed folders, pause), the sink in `App\Mail` (encrypted bodies, threads, retention, unique key on
mailbox + provider id), the exclusion policy from the person's rules (with per-rule counters), and the observer into
spec 223's counters. It listens to `MailboxGrantFailedEvent` for the bell notice, calls `disconnect()` on FR-001
disconnect and on erasure, routes the Messenger messages to an async transport, schedules the two commands, and exposes
the two webhook routes as public stateless routes outside its HTTP log.

## 22. Phase B2: Gmail send-only (D-20261124-09)

Owner decision D-20261124-09 (2026-11-24) replaces the 2.6.0 Gmail sync: Gmail is **send-only**. Outlook keeps sync and
send (2.5.0). Q-MB-1 – Q-MB-22 stand; Q-MB-16 (Pub/Sub JWT), Q-MB-17 (`gmail.readonly`) and the Gmail half of
Q-MB-6 (sent copy) no longer apply.

| Item | Detail |
| --- | --- |
| Scopes | Exactly `openid email https://www.googleapis.com/auth/gmail.send` (`Mailbox\Gmail\GmailScopes::REQUESTED`). No restricted scope, so no CASA assessment; `gmail.send` is sensitive (OAuth verification for production). |
| OAuth | Still the host's (Q-254-15 = b): PKCE S256, single-use `state`, encrypted refresh token, refresh on `rejected()`. The token port returns the granted scopes; a token without `gmail.send` pauses `ScopeMissing`. |
| Account check | OpenID userinfo (`https://openidconnect.googleapis.com/v1/userinfo`): `sub` is the bound account id (SHA-256 stored), `email` counts only with `email_verified: true`. At start, resume and before every send; another account pauses `AccountMismatch`. |
| Mailbox | `MailboxSettings` with `MailboxProvider::Gmail` and **no folders**. Start / resume check and bind the account only: no cursor, subscription, watch, sweep, renewal, fetch, attachment download or sent copy. `MailboxProvider::isSendOnly()` / `MailboxSyncStatus::isSendOnly()` and the `mailbox.provider.*` translations let the host say so. |
| Send | New messages only (a reply / forward needs the original). One RFC 5322 message built by Symfony Mime (Bcc header, sanitised HTML, remote images dropped, Message-ID derived from the key, ≤ 35 MB) and one `POST https://gmail.googleapis.com/upload/gmail/v1/users/me/messages/send?uploadType=media` (100 quota units). |
| Idempotency | The 2.5.0 send rows and claim. No draft, so the answer alone decides: 200 + id → `sent`; 401 → one retry with a fresh token; grant / scope / policy / rate-limit 4xx → `released` (nothing sent; a grant failure pauses); other 4xx → `failed`; timeout, 5xx or 2xx without an id → `unknown`, never resent. A stale `sending` row (a crashed worker) is settled `unknown` by one caller. |
| Errors that pause | 401 twice → `Revoked`; 403 `insufficientPermissions` / `ACCESS_TOKEN_SCOPE_INSUFFICIENT` → `ScopeMissing`; 403 `domainPolicy` → `ConsentMissing`; 400 `failedPrecondition` (Gmail not enabled) → `MailboxNotFound`. 403 `accessNotConfigured` (API off in the project) is a refusal, not a pause. |
| Data | MailKit stores the status row (account id hash) and the send rows (ids only, purged after `send.retention`). Nothing is logged with a body, token or address. |
| Config | `mailboxes.gmail.enabled: false` by default; no topic, push or JWKS settings. |
