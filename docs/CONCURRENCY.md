# Concurrency (REQ-RUNTIME-003)

How MailKit stays correct when the same rows are written at the same time: two workers, two web requests, a retried
request or a replayed Messenger message. It applies from **1.13.0**.

## Table of contents

- [Summary](#summary)
- [Write paths](#write-paths)
- [What MailKit does not store](#what-mailkit-does-not-store)
- [Conflict answer and codes](#conflict-answer-and-codes)
- [Host options](#host-options)
- [Savepoints inside your transaction](#savepoints-inside-your-transaction)
- [Failures that are not swallowed](#failures-that-are-not-swallowed)
- [Tests](#tests)
- [Residual risks](#residual-risks)

## Summary

- MailKit writes only through **DBAL**, on the connection you configure. It never flushes, clears or resets the host's
  entity manager. Each store resolves its connection on every call (FrankenPHP worker mode, kernel reset off).
- Each write unit is one statement, or one transaction through `nowo-tech/transaction-doctrine`
  (`DoctrineTransactionRunner::forConnection()`).
- Each write uses an explicit control: a unique key plus a caught duplicate for "only one" rules, an atomic
  `UPDATE … SET n = n + 1` for counters, a conditional `UPDATE` for takeovers. MailKit never reads a value and then
  writes it back. Key columns compare exactly (binary NO PAD collation on MySQL and MariaDB, REQ-SEC-006).
- A lock conflict on one of MailKit's own routes answers **409** with `X-Conflict-Code`. Host routes are never
  answered.

## Write paths

| Path | Transaction | Control | Conflict answer |
| --- | --- | --- | --- |
| Sent-mail log row (`SentMailRecorder::queued/sent/failed`) | One `INSERT` (plus the optional body row), or one `UPDATE … attempts = attempts + 1` by id; autocommit | New UUIDv7 primary key; atomic counter | Best effort: a failure is logged and the e-mail is not affected (see [Residual risks](#residual-risks)) |
| GDPR erasure of an address (`forgetAddress()` / `erase()`) | One transaction (`transactional()`), or the caller's | Exact re-check in PHP of every pre-filtered row; delete by id in batches of 500 | Exception to the caller (UserKit eraser, `nowo:mail-kit:forget-recipient`) |
| Log retention (`purgeBefore()`, `nowo:mail-kit:purge`) | One `DELETE` per keyset batch | Idempotent (delete by id) | Exception to the command; run it again |
| Graph inbound claim (`GraphInboundStore::claim()`) | One `INSERT`; inside a host transaction it runs in a savepoint, rolled back on any failure | Unique `message_key` plus a caught duplicate; stale takeover with a conditional `UPDATE … WHERE claimed_at < ?` | Messenger retries; the retry sees the claim |
| Graph inbound decision (`complete()`) | One `UPDATE`, in a savepoint inside a host transaction | By key; one retry on a lock conflict outside a transaction | Rethrown, and the claim is kept (no second hand-over to your handler) |
| Graph inbound release (`release()`) | One `DELETE`, in a savepoint inside a host transaction | By key; one retry on a lock conflict outside a transaction | Thrown (no longer swallowed); the processor logs it and rethrows your failure |
| Graph subscription swap (`saveSubscription()`) | **One transaction**: `DELETE` the profile's rows and `INSERT` the new one (1.13) | All or nothing | `TransientStorageFailure` after the rollback; Messenger or the command retries |
| Graph subscription renewal and removal | One `UPDATE` or `DELETE` by id | By id | Exception to Messenger or the command |
| Graph delta link (`saveDeltaLink()`) | `UPDATE`, else an `INSERT` (each in a savepoint inside a transaction, rolled back on any failure) | Unique `profile`, duplicate caught and followed by the `UPDATE` (1.13; it used to be a check-then-insert) | Exception to Messenger or the command |
| Graph inbound retention (`purge()`) | One `DELETE` | Idempotent | Exception to Messenger |
| Graph webhook (`GraphWebhookController`) | Writes nothing itself; it only dispatches messages | — | 409: `CONFLICT_001` when the first dispatch fails, `CONFLICT_002` when part of the batch was already queued |
| Tracking send row (`TrackingStore::createSend()`) | One `INSERT`, in a savepoint inside a host transaction | New random send id | A lock conflict is rethrown (nothing was sent yet); any other failure sends the e-mail untracked |
| Tracking link to the log row (`attachLog()`) and failed-send cleanup (`discard()`) | One `UPDATE` or `DELETE`, in a savepoint inside a host transaction | By send id | Inside a host transaction a lock conflict is rethrown; in autocommit it is logged (the e-mail already left) |
| Open counter (`recordOpen()`, pixel or `RecordMailOpen` handler) | One `UPDATE` | Atomic `open_count = open_count + 1` with the cap in the `WHERE` | Pixel: 409 `CONFLICT_001`; Messenger retries |
| Open event listeners (`MailOpenedEvent`, `MailMachineOpenedEvent`) | After the counter's commit | — | A listener's lock conflict is rethrown as a committed `ConcurrencyConflict`: pixel 409 `CONFLICT_002` |
| Tracking retention and GDPR (`purge()`, `eraseRecipient()`) | One `DELETE` per batch of 500 ids | Idempotent; exact re-check of the recipient id | Exception to the command or the eraser |
| Delegated mailbox status (`MailboxStore::insertMailbox/activate/pauseByHost/pauseGrant/bindAccount`, 2.5.0) | One statement, in a savepoint inside a host transaction | First row: caught duplicate; transitions: conditional `UPDATE … WHERE status = ?` (one winner, one event); account: conditional first write, then a read | Grant failure acknowledged (the pause commits); others to Messenger |
| Folder sweep lease and cursor (`acquireLease`, `requestRerun`, `advanceCursor`, `extendLease`, `releaseLease`, 2.5.0) | One statement each, in a savepoint inside a host transaction | Lease by a conditional `UPDATE` with a random owner; cursor compare-and-set on `cursor_version` and the owner; a 0-row extension confirmed by a read (MySQL changed-rows count, ZAT-R4) | A lost lease stops the run without writing; the fetches are idempotent |
| Delegated subscriptions (`saveSubscription` swap, renewal, deletion, 2.5.0) | Swap in **one transaction**; the rest one statement | All or nothing; by id | Exception to Messenger or the command |
| Delegated send (`claimSend`, `updateSend`, `takeOverSend`, 2.5.0 part 2) | One statement each; `send()` refuses an open transaction on the mailbox connection | Primary key (mailbox, key) plus a caught duplicate; every transition conditional on the claim token and state and bumps `row_version`; takeover of a stale row by a conditional `UPDATE` with a new token (one winner); a heartbeat right before every submit (ZAV-L1) | `MailboxSendInProgress` (map to 409 `CONFLICT_001`), `MailboxSendKeyConflict` (`CONFLICT_002`) |
| Send purge (`purgeSends`, `nowo:mail-kit:mailbox:purge`) | One `DELETE` per row of a batch | Settled states only, re-checked in the `WHERE` | Exception to the command |
| Delegated mailbox webhook (`MailboxGraphWebhookController`) | Writes nothing itself; it only dispatches messages | — | As the Graph webhook |
| Schema commands (`nowo:mail-kit:graph:schema`, `nowo:mail-kit:tracking:schema`, `nowo:mail-kit:mailbox:schema`) | DDL, one statement at a time | `CREATE` only for missing tables | Exception to the command |

`grep -rn "flush()" src/` finds nothing: MailKit has no ORM write.

## What MailKit does not store

- **Mailer settings** (DSN, sender, Resend key) are Instance Settings rows. They are saved by the Instance Settings
  bundle's form, through its own runner and conflict answer. MailKit only reads them (and validates a save through a
  constraint). Its concurrency is that bundle's verdict.
- **E-mail templates** are Twig files in your application or in the bundle. Nothing is stored in a database, so there
  is no template write path.
- **Graph tokens** go to your PSR-6 cache pool. A lost write only means one more token request.

## Conflict answer and codes

A **lock conflict** is a deadlock, a lock wait timeout or a serialization failure: MySQL / MariaDB 1213, 1205 and
1020; PostgreSQL 40001, 40P01 and 55P03. A `TransientStorageFailure` of a runner, and a lost savepoint whose commit
outcome is unknown, count too. Unique-constraint violations and lost connections are **never** conflicts. They stay
real errors, except where the table above catches an expected duplicate.

On MailKit's routes (admin pages and actions, the Graph webhook, the tracking pixel) `MailKitConflictListener`
answers:

| Code | When | Status | Headers |
| --- | --- | --- | --- |
| `CONFLICT_001` | Nothing of the request was committed: a retry is safe | 409 (or 503) | `X-Conflict-Code: CONFLICT_001`, `Retry-After` |
| `CONFLICT_002` | Part of the request was already committed: read again, do not repeat | 409 (or 503) | `X-Conflict-Code: CONFLICT_002`, no `Retry-After` |

"Part committed" is decided for the whole request:

- transaction-doctrine's request commit mark (`ConflictCode::forRequest()`, transaction-doctrine >= 1.6 with
  `commit_detection` on), so a commit made earlier in the same request by the host or another bundle counts;
- a Messenger `HandlerFailedException` whose envelope carries a `HandledStamp`, or any `DelayedMessageHandlingException`;
- a `ConcurrencyConflict` with `committed: true`: the webhook after a partly queued batch, or an open listener after the
  counted open;
- a lost savepoint.

The shapes of the answers:

- **Admin routes** become an `HttpException` with the status and the headers above, so your error page renders it and
  the headers are still sent.
- **The webhook and the pixel** answer `text/plain` with a generic message, `Cache-Control: no-store` and the headers.
- Other routes are not touched: the listener only acts when `_controller` is one of MailKit's classes.

## Host options

```yaml
nowo_mail_kit:
    conflict_response:
        status: 409        # or 503 for hosts that keep a Service Unavailable answer
        retry_after: 1     # seconds, CONFLICT_001 only (a runner's own hint wins)
```

To get `CONFLICT_002` for commits made earlier in the request, register `nowo-tech/transaction-doctrine`'s Symfony
bundle (`NowoTransactionDoctrineBundle`) with its commit detection on. Without it, the failure alone decides the code.

For a retrying runner around your own units, alias `TransactionRunnerInterface` to
`nowo-tech/doctrine-deadlock-retry-bundle`. MailKit's own runner makes one attempt and leaves the retry to the client
or to Messenger.

## Savepoints inside your transaction

When your transaction is open, every Graph inbound write runs in its own savepoint: the claim `INSERT` and its
stale-claim `UPDATE`, the delta-link `UPDATE` and `INSERT`, and the `complete()` / `release()` statements (the last
three since audit UE2-MK-1). The savepoint is
rolled back on **any** failure: a duplicate key (then caught), a lock wait timeout, a deadlock or a serialization
failure (then rethrown). Your PostgreSQL transaction therefore never stays aborted, and your next statement works.
On MySQL / MariaDB a deadlock rolls back your whole transaction anyway; the original failure is rethrown even when
the server already dropped the savepoint.

## Failures that are not swallowed

Since 1.13 (rule 8), a lock conflict propagates where a replay is safe:

- before the e-mail leaves: the tracking store, your tracking policy, your link rewriter, and the test e-mail action;
- inside your transaction: tracking writes in a savepoint;
- on the pixel and the webhook;
- in the Graph inbound release and decision writes.

Two places still log and continue, by contract, because the e-mail has already been sent and a replay would send it
twice:

- the sent-mail log write after delivery;
- `MailDeliveredEvent` and `MailDeliveryFailedEvent` listeners. Keep your listener's writes in their own transaction,
  or dispatch them to Messenger.

## Tests

- `tests/Integration/Concurrency/ConcurrencyRaceTest.php`: two real connections on MySQL 8.4, MariaDB 11.4 and
  PostgreSQL 16 / 17. Lock waits are capped at one second (`innodb_lock_wait_timeout`, `lock_timeout`). The tests
  cover:
  - two workers claiming the same message (a lock wait, then the duplicate);
  - two sweeps storing the delta link of a new profile (an injected interleaving, and inside a transaction on
    PostgreSQL);
  - a subscription swap under a held row lock (all or nothing), and one whose `INSERT` fails (the old subscription
    survives);
  - a lock timeout on the claim and delta-link `INSERT` inside the host's transaction (the next statement still
    works);
  - two pixels on the same send (409 `CONFLICT_001`, then both opens counted);
  - a listener conflict after the counted open (409 `CONFLICT_002`).

  Each test checks the stored rows and that the connection is still usable.
- `tests/Integration/Concurrency/MailboxConcurrencyRaceTest.php` and `MailboxSendRaceTest.php` (2.5.0): one sweeper
  per folder, no cursor lost update after a takeover, one grant transition, same-second lease extensions and send
  heartbeats on MySQL / MariaDB, one takeover winner against an open transaction, and two workers sending one key
  (one delivery, also after an ambiguous submit), with an injected `MockClock`.
- Unit tests with injected deadlocks, lock wait timeouts, serialization failures, lost savepoints, unique violations
  and lost connections: `StorageConflictsTest`, `MailKitConflictListenerTest`, `TrackingConflictsTest`,
  `GraphInboundStoreTest`, `GraphInboundProcessorTest`, `GraphWebhookControllerTest`.
- CI: the `Log on …` jobs run the log and the race tests on every database. The `Dependency floor` job runs the suites
  with the lowest `nowo-tech/transaction-*` versions that composer.json allows, and checks the demo lock.

## Residual risks

- **The sent-mail log write after delivery is best effort.** In autocommit, a lock conflict on the log row loses that
  row, and the e-mail is not affected. If you send mail **inside** your own database transaction, a deadlock on the
  log row can roll back your transaction on MySQL / MariaDB while MailKit only logs it. Send after your commit, or
  through Messenger (rule 9: no mail while holding locks).
- **A failed release keeps a claim.** If `release()` fails twice, the claim stays `processing`. Messenger's retry then
  skips the message as "already being processed", and it is handed over again only on a later delivery after
  `stale_claim_seconds`. The failure is logged at error level ("claim not released").
- **A failed decision write keeps a claim.** If `complete()` fails twice after your handler handled the message, the claim
  stays `processing`. A later delivery after `stale_claim_seconds` hands the message over again. This is rare, and
  your handler should be idempotent (rule 7).
- **Concurrent subscription swaps.** Two concurrent `ensure()` runs for one profile can each create a Graph
  subscription. One of them is stored. The other expires on Graph's side, and its notifications are refused as
  unknown.
- **A replayed open.** A Messenger retry of `RecordMailOpen` after a listener conflict counts the open again. Open
  counts are statistics, not billing data.
- **A resubmitted draft (2.5.0 send).** After an ambiguous submit, a draft that still reads as a draft after
  `send.stale_claim_seconds` is submitted again. This relies on Exchange delivering one draft item at most once (on the
  owner's manual checklist); worker clocks should be NTP-synced (staleness compares them with stored times).
- **No retry inside MailKit.** Its runner makes one attempt. Clients, Messenger or a retrying runner retry.
