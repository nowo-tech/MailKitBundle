# FrankenPHP worker mode audit (kernel not reset between requests)

| Field | Value |
|-------|-------|
| Package | `nowo-tech/mail-kit-bundle` (`symfony-bundle`) |
| Audited revision | `1.0.0` (unreleased, branch `feat/1.0.0`); boundaries re-audited for 1.7.1 (REQ-RUNTIME-002, 2026-11-11); e-mail tracking added in 1.10.0 (2026-11-12) |
| Audit date | 2026-10-31 (Messenger / console boundaries: 2026-11-11) |
| Method | Manual review of every file under `src/`; PHPStan `ruleset-classic` + `ruleset-worker-strict` + `ruleset-hardening`; Igor (`composer igor`); unit tests of the transport cache and reset; demo run in worker mode |
| **Verdict** | ✅ **Viable under scenario B** (1.7.1) — the mutable state is the transport built from the last DSN (keyed by the DSN, so a changed DSN is picked up on the next send even without a reset), the route loader's load-once flag, and (with `resend.enabled`) the memo of the 1.2 `resend` rows, forgotten at every request, message and command boundary since 1.7.1 (before: only by `kernel.reset`); no request, user or message data survives a unit of work |

## Table of contents

- [Execution model assumed](#execution-model-assumed)
- [Summary](#summary)
- [Services reviewed](#services-reviewed)
- [Findings](#findings)
- [Boundary events](#boundary-events)
- [Worker tests](#worker-tests)
- [Host rule: entity managers](#host-rule-entity-managers)
- [Usage recommendations in worker mode](#usage-recommendations-in-worker-mode)
- [Re-audit triggers](#re-audit-triggers)

## Execution model assumed

FrankenPHP worker mode boots the kernel once per worker and serves many requests with the same container. Two
scenarios are evaluated:

- **A — kernel not rebooted, `services_resetter` still runs.**
- **B — no reset at all:** any state kept in a service survives into the next request.

A bundle safe under **B** is safe under **A**, classic mode and PHP-FPM.

**Precondition, precisely.** `FRANKENPHP_RESET_KERNEL=false` alone does not mean scenario B: with the stock Symfony kernel, `Kernel::handle()` → `boot()` still calls `services_resetter->reset()` before every request after the first. Scenario B happens where the resetter does not run: a host that removes, replaces or bypasses `services_resetter` (or drives `http_kernel` directly), Messenger consumers started with `messenger:consume --no-reset`, and long-running console processes. This bundle is correct in all of them.

## Summary

| Area | Status | Notes |
|------|--------|-------|
| Mutable state in shared services | ✅ | `RuntimeTransport`: transport + DSN it was built from, last validated DSN + result; route loader load-once flag (cache warm-up); `LegacyResendSettings` memo of the 1.2 `resend` rows (cleared at every boundary, W-01) |
| Static properties / `static` locals | ✅ | None |
| `ResetInterface` / `kernel.reset` | ✅ | `RuntimeTransport::reset()` (tag `kernel.reset`, also called on `SettingsChangedEvent` for the mail sections) — correct without it too (scenario B: the cache is keyed by the DSN read on every send) |
| Request / user captured in services | ✅ | `AccessGuard` reads `RequestStack::getMainRequest()` and the token per call; the rate-limit key is computed per request |
| Superglobals, `putenv`, `ini_set`, `setlocale`, timezone | ✅ | None |
| Doctrine | ✅ | Entity manager / connection resolved per call from the registry; log writes through DBAL (no identity map growth), reads through read-only entities |
| Resources held open | ✅ | An SMTP transport may keep its connection between sends (Symfony behaviour, ping threshold); dropped on reset |
| Memory growth across requests | ✅ | One cached transport at most; nothing appended per request |
| Branded e-mails (1.4) | ✅ | `BrandedEmailHelper` is `readonly` with no memo: the brand provider and asset reader are asked on every `apply()` / `embed()`, image bytes live only in the e-mail being built |
| E-mail tracking (1.10) | ✅ | `EmailTracker`, `HtmlLinkTracker`, `CredentialLinkDetector`, `ConfiguredTrackableLinkFilter`, `TrackingTokenSigner`, `OpenClassifier`, `OpenRecorder`, `TrackingPixelController`, `RecordMailOpenHandler` are `readonly` (immutable configuration, keys derived once at construction); `TrackingStore` resolves its connection per call and keeps nothing; the host policy / rewriter / filter are asked on every send; the tracked copy, its send id and the link map live only in the call |
| Microsoft Graph (1.8) | ✅ | `GraphTokenProvider`, `GraphApiClient`, `GraphMailSender`, `ConfiguredGraphMailboxProvider`, `GraphTransport(Factory)` are immutable: the mailbox comes from the port and the token from the PSR-6 pool on every send; attachment files are opened and closed within the send |
| Output, headers, `exit` | ✅ | Responses / console output only |
| PHPStan FrankenPHP rulesets / Igor | ✅ | 0 errors / 0 KO (annotated: bundle extension cache, compile-time DI calls, route loader flag) |

## Services reviewed

| Service | Mutable state | Scenario A | Scenario B |
|---------|---------------|------------|------------|
| `LegacyResendSettings` (only with `resend.enabled`) | memo of the 1.2 `resend` rows (enabled, API key, sender); `kernel.reset` + `LegacyResendSettingsResetListener` at every boundary (1.7.1) | ✅ | ✅ (1.7.1) |
| `LegacyResendSettingsResetListener` | none (`readonly`; iterator of `IGNORE_ON_UNINITIALIZED_REFERENCE` references) | ✅ | ✅ |
| `RuntimeTransport` | transport + its DSN, last checked DSN + error; reset by `kernel.reset` and `SettingsChangedEvent` | ✅ | ✅ (DSN compared on every send) |
| `MailKitRouteLoader` | `loaded` flag (cache warm-up only) | ✅ | ✅ |
| `MailKitMailer`, `SenderPolicy`, `MailSettings`, `SettingsMailerDsnProvider`, `MailerDsnValidator`, `DsnHostGuard`, `OutboundMailReadiness` | none (`readonly`; settings read per call) | ✅ | ✅ |
| `SenderProviderGate` (1.12), `MimeAddressMapper` (static) | none (the host sender port is asked on every call) | ✅ | ✅ |
| `SentMailRecorder`, `SentMailRepository`, `TablePrefixListener` | none (`readonly`) | ✅ | ✅ |
| `TestMailSender`, `TestMailGuard` (limiter state lives in `cache.app`), `ResendApiChecker` | none | ✅ | ✅ |
| `MailKitController`, `AdminPages`, `MailKitPages`, `SettingsActions`, form types, sections | none (forms and view models built per call) | ✅ | ✅ |
| `AccessGuard`, `MailKitAccessListener`, access checkers, `SettingsChangedListener`, Twig extension, commands | none | ✅ | ✅ |
| `GraphWebhookController`, `GraphInboundProcessor`, `GraphMessageNormalizer`, `InboundHtmlSanitizer`, `GraphDeltaSweeper`, `GraphSubscriptionManager`, `GraphInboundMessageHandler`, `GraphInboundStore`, `ConfiguredInboundProfiles`, inbound commands (1.9, `graph.inbound.enabled`) | none (readonly; the sanitizer holds an immutable config; the store resolves its connection per call; attachment streams are closed at the end of each message; the rate limiter state lives in `cache.app`) | ✅ | ✅ |
| `GraphWebhookRouteLoader` | `loaded` flag (cache warm-up only) | ✅ | ✅ |
| `EmailTracker`, `HtmlLinkTracker`, `CredentialLinkDetector`, `ConfiguredTrackableLinkFilter`, `NullEmailLinkRewriter`, `TrackingTokenSigner`, `OpenClassifier`, `OpenRecorder`, `TrackingPixelController`, `RecordMailOpenHandler`, `TrackingStore`, `TrackingSchemaListener`, tracking commands, UserKit `email_tracking` bridge (1.10, `tracking.enabled`) | none (`readonly` or no properties beyond injected configuration; per-send data — tracked copy, send id, link map, open — is local to the call; the store resolves its connection per call; no memo, no static) | ✅ | ✅ |
| `TrackingRouteLoader` (1.10) | `loaded` flag (cache warm-up only) | ✅ | ✅ |
| `GraphTokenProvider`, `GraphApiClient`, `GraphMailSender`, `ConfiguredGraphMailboxProvider`, `GraphTransportFactory`, `GraphTransport` (1.8, `graph.enabled`) | none (readonly; tokens in the PSR-6 pool keyed per tenant / client / secret; mailbox resolved per send; files opened and closed per attachment) | ✅ | ✅ |

## Findings

### W-01 — Legacy Resend rows memoised until `kernel.reset` (Low, fixed in 1.7.1)

- **Where:** `src/Settings/LegacyResendSettings.php` (`values()` memo, `??=`), read by `MailSettings` (provider, Resend key and sender while `mailer.provider` is not stored), `MailerSettingsSection` and `LegacyResendSettingsTypeExtension`. 1.3.0 to 1.7.0.
- **Worker impact:** under scenario B a worker kept the rows it read first after another worker migrated, purged or edited them, so it could send with an old sender or a revoked key until it restarted. Only on hosts upgraded from 1.2 with the rows still stored.
- **Status:** Resolved in 1.7.1 — `src/EventListener/LegacyResendSettingsResetListener.php` forgets the memo at every boundary (see [Boundary events](#boundary-events)). Dropping the memo was not chosen: one send reads the rows several times (`provider()`, `resendActive()`, `resendApiKey()`, `resendFrom()`). Tests: `tests/Integration/Settings/LegacyResendBoundariesTest.php`, `tests/Unit/EventListener/LegacyResendSettingsResetListenerTest.php`, `tests/Unit/Settings/LegacyResendSettingsTest.php`.

No other worker-mode findings under scenario **B**. The DSN is read from Instance Settings on every send (memoized per main
request by Instance Settings itself), so a change saved in one worker is used by every other worker on its next message;
the cached transport is rebuilt because its DSN no longer matches.

## Usage recommendations in worker mode

- Do not cache `MailSettings` / DSN values in your own services' constructors; read them where you send.
- Messenger workers: the stock `messenger:consume` runs `kernel.reset` between messages (`RuntimeTransport` is reset);
  with `--no-reset` the bundle stays correct on its own (the transport is keyed by the DSN, the legacy memo is
  forgotten per message), and the worker resolves the DSN at delivery time. The DSN itself comes from Instance
  Settings, whose own memo must also be cleared per message (see the Instance Settings worker audit).

## Boundary events

`LegacyResendSettingsResetListener` (registered only with `resend.enabled: true`; `kernel.event_listener` tags on event names, `NowoMailKitExtension::LEGACY_RESEND_RESET_EVENTS`):

| Boundary | Event | Priority |
|---|---|---|
| HTTP main request start | `kernel.request` (main only; sub-requests keep the memo of their main request) | 4096 |
| HTTP end | `kernel.terminate` | -1024 |
| Messenger message start | `Symfony\Component\Messenger\Event\WorkerMessageReceivedEvent` | 4096 |
| Messenger message end | `WorkerMessageHandledEvent`, `WorkerMessageFailedEvent` | -1024 |
| Console command start / end | `console.command` / `console.terminate` | 4096 / -1024 |

## Worker tests

- `tests/Integration/Settings/LegacyResendBoundariesTest.php` (1.7.1) boots **one** kernel and never calls `services_resetter`: 500 HTTP requests through the `http_kernel` service (not `Kernel::handle()`; each with a sub-request), 500 messages through a real `Symfony\Component\Messenger\Worker` on the kernel's dispatcher without `ResetServicesListener` (as `--no-reset`; every 7th handler fails), and 500 console `Application::run()` calls. Before each unit another worker alternates the legacy sender between tenant A's and tenant B's address; each unit must read the stored one. Memory margin: less than 1 MiB growth after a 50-unit warm-up. Without the 1.7.1 listener all three paths fail.
- `tests/Integration/Ddd/HostProvidersKernelTest.php::testKeptAliveKernelServesManyTenantsWithoutLeakingState`: 600 requests through `http_kernel` for two tenants (host DSN / sender ports), sender of each tenant checked, memory under 2 MiB.
- `tests/Integration/Graph/GraphKernelTest.php::testDddHostWithItsOwnMailboxPortAndManyRequestsInOneWorker` (1.8): one kernel, no services resetter, 600 requests through `http_kernel` alternating two organizations (two tenants, two apps, two mailboxes) on the Graph sender with a host mailbox port; each request must create its draft in its own mailbox with its own tenant's token and Reply-To (the simulated Graph answers 401 otherwise), exactly one token per tenant is requested over the run, memory under 2 MiB after a 100-request warm-up.
- `tests/Integration/Graph/GraphInboundKernelTest.php::testDddHostServesManyNotificationsInOneWorker` (1.9): one kernel, no services resetter, 600 webhook requests through `http_kernel` alternating two organizations (two tenants, two mailboxes), each with a valid notification plus a forged one for the other organization; Messenger handles the fetch synchronously; each request must hand exactly its own organization's message (read from its own mailbox with its own token, its own plus-address tag) to the host handler; one token per tenant over the run; memory under 2 MiB after a 100-request warm-up.
- `tests/Integration/Tracking/TrackingKernelTest.php::testDddHostServesOneThousandPixelsInOneWorker` (1.10): one kernel, no services resetter, a strict DDD host (no Instance Settings, no log, host DSN / sender / tracking policy / link rewriter ports); 1 000 iterations alternating two tenants, each sending one tracked e-mail through the mailer and fetching its pixel through `http_kernel`; every send carries its own tenant's recipient id (no cross-tenant row), 1 000 sends with exactly one open each (334 machine opens from the Apple range), memory under 2 MiB after a 200-iteration warm-up (Symfony's test-only `mailer.message_logger_listener` is reset per send), pixel p95 under 20 ms.
- `reset()` is tested directly: `tests/Unit/Settings/LegacyResendSettingsTest.php`, `tests/Unit/EventListener/LegacyResendSettingsResetListenerTest.php`.

Static checks: `composer igor` (107 service files in 1.8, 0 KO, 0 WARN) and PHPStan with the FrankenPHP rulesets pass.

## Host rule: entity managers

A host that disables or bypasses the services resetter must also clear its **own** entity managers at every boundary (main request start or end, each Messenger message, each console command); otherwise the identity map serves stale rows (for example a downgraded permission or a soft-deleted item) across requests (REQ-RUNTIME-002 rule 7). MailKit writes its log through DBAL and reads Instance Settings rows with `HINT_REFRESH`, so it does not depend on the identity map itself.

## Re-audit triggers

Re-run this audit when a change adds a property to a service, a cache, an event listener that keeps data, a
persistent connection, or any read of `$_SERVER` / `$_ENV` / static state at runtime.
