# Architecture

## Table of contents

- [Overview](#overview)
- [Send flow](#send-flow)
- [Admin flow](#admin-flow)
- [Components](#components)
- [Container wiring](#container-wiring)
- [Worker safety](#worker-safety)
- [Extension points](#extension-points)

## Overview

```
Instance Settings ◄── MailerSettingsSection (fields, provider visible_when, #[MailerDsn], #[DeliverableSender], ResendApiKeyAccepted, actions)
      │ getSecret('mailer.dsn'), getString('mailer.from') …
      ▼
MailSettings ──► SettingsMailerDsnProvider (MailerDsnProviderInterface) ──► RuntimeTransport ◄── MailKitMailer
      │                                                                     (decorates          (decorates
      └──► SenderPolicy (From / Reply-To)                                    mailer.transports)  mailer.mailer)
                                                                                 │
               MailerDsnValidator (schemes, DsnHostGuard → OutboundUrlGuard rules, factory) ◄┘
                                                                                 │ sent / failed
                                                                                 ▼
                                    SentMailRecorder (DBAL) ──► <prefix>mail_kit_sent_mail(_body) ◄── SentMailRepository (ORM)
OutboundMailReadiness ──► Twig functions, AuthKit OutboundMailReadyCheckerInterface adapter
```

## Send flow

1. Application code calls `MailerInterface::send()` → `MailKitMailer` applies the runtime sender and (with a message bus)
   records a `queued` row whose id travels in `X-Mail-Kit-Log-Id`.
2. The framework `Mailer` sends now or dispatches `SendEmailMessage`; both end in `mailer.transports` =
   `RuntimeTransport`.
3. `RuntimeTransport` asks the provider for a DSN: none (or an `X-Transport` header) → framework transport; otherwise it
   reuses the transport built for that DSN or validates the DSN and builds one with `mailer.transport_factory`.
4. Internal headers are stripped, the message is sent, and the outcome (`sent` with provider id and size, or `failed`
   with a scrubbed error) updates the queued row or inserts one.

## Admin flow

1. Settings: Instance Settings renders the `mailer` section (provider-dependent rows with `resend.enabled`); values are validated by MailKit's constraints on
   save / import; `SettingsChangedEvent` → `SettingsChangedListener` resets the transport and audits (redacted).
2. Actions: the section's UiKit `Action`s post a FormKit CSRF-only form to `MailKitController` (`/actions/*`), which
   checks `AccessGuard::isSectionGranted()` (MailKit checker + Instance Settings section access), the token and the rate
   limit, then runs `TestMailSender` / `ResendApiChecker` and redirects back with a flash.
3. Log: `MailKitAccessListener` guards the prefix; `MailKitController::index()` / `show()` build UiKit view models with
   `AdminPages` from `SentMailRepository`.

## Components

| Component | Role |
| --- | --- |
| `Settings\MailerSettingsSection`, `MailSettings`, `LegacyResendSettings` | Settings contract, typed view, 1.2 `resend` rows until migrated |
| `Validator\ResendApiKeyAccepted` | Save-time Resend key check (MK-L1) |
| `Dsn\MailerDsnProviderInterface`, `SettingsMailerDsnProvider`, `RuntimeDsn`, `DsnSource` | Where the DSN comes from |
| `Dsn\MailerDsnValidator`, `DsnHostGuard`, `MailerDsnAudit` | Validation, SSRF guard, redaction |
| `Validator\MailerDsn`, `DeliverableSender` (+ validators) | Field constraints |
| `Sender\SenderAddress`, `SenderPolicy` | Sender rules and defaults |
| `Sender\SenderIdentityProviderInterface`, `Sender\MailAddress` | Framework-free sender port and its value object (1.12); `Sender\MimeAddressMapper` and `Sender\SenderProviderGate` adapt it to the deprecated Mime-typed port |
| `Mailer\RuntimeTransport`, `MailKitMailer`, `MailHeaders` | Runtime delivery and internal headers |
| `Log\SentMailRecorder`, `SentMailRepository`, `SentMailFilters`, `SentMailStatus`, `ErrorScrubber`, `Entity\*` | Sent-mail log |
| `TestMail\TestMailSender`, `TestMailGuard`, `TestMailResult`, `TestMailOutcome` | Test e-mail |
| `Resend\ResendApiChecker`, `ResendCheckResult` | Resend key check |
| `Readiness\OutboundMailReadiness`, `MailStatus`, `Integration\AuthKit\MailKitOutboundMailReadyChecker`, `Twig\MailKitTwigExtension` | Readiness |
| `Controller\MailKitController`, `Routing\MailKitRouteLoader`, `Ui\AdminPages`, `MailKitPages`, `SettingsActions`, `Form\SentMailFilterType` | Admin UI |
| `Security\*`, `EventListener\MailKitAccessListener` | Access |
| `Brand\BrandedEmailHelper`, `BrandedEmailImage`, `BrandedEmailText`, `Twig\MailKitBrandTwigExtension`, `email/branded_layout.*.twig` | Optional branded e-mails (1.4, spec 220 S7): brand from `nowo-tech/brand-contracts`, logo as an inline CID part |
| `Graph\GraphMailboxProviderInterface`, `ConfiguredGraphMailboxProvider`, `GraphMailbox`, `GraphSecret`, `GraphTokenProvider`, `GraphApiClient`, `GraphException` | Microsoft Graph (1.8, opt-in): mailbox port, app-only token (PSR-6 cache), Graph v1.0 client (token only to graph.microsoft.com, upload host allow-list, no redirects) |
| `Graph\Send\GraphMailSender`, `GraphOutgoingMail`, `GraphRecipient`, `GraphAttachment`, `AttachmentFilename`, `GraphSendLimits`, `GraphSendResult`, `PlusAddress`, `Graph\Transport\GraphTransport(Factory)` | Graph send: MIME draft with threading headers → Bcc → attachments / upload sessions → send; `microsoft+graph://<profile>` mailer transport |
| `Graph\Inbound\*` (1.9, opt-in) | Inbound: `Webhook\GraphWebhookController` + route loader (verify, enqueue), `GraphSubscriptionManager`, `GraphDeltaSweeper`, `GraphInboundProcessor` (fetch, claim, attachments, normalise, host decision), `GraphMessageNormalizer`, `InboundHtmlSanitizer`, `Store\GraphInboundStore` (DBAL tables) + schema listener, `Message\*` (Messenger), `Command\*`; ports `GraphInboundHandlerInterface`, `GraphInboundProfilesProviderInterface`; DTO `GraphInboundMessage`, `InboundAddress`, `InboundAttachment`, `AuthenticationResults`, `InboundDecision` / `InboundAction` |
| `Tracking\*` (1.10, opt-in) | Open / click tracking: `EmailTracker` (called by `RuntimeTransport` before delivery; tracked copy), `HtmlLinkTracker` (tolerant href / pixel rewriting), `CredentialLinkDetector`, `TrackingTokenSigner` (HMAC pixel tokens), `OpenClassifier` (machine opens, IP truncation, UA family), `OpenRecorder`, `Http\TrackingPixelController` + route loader, `Store\TrackingStore` (DBAL table) + schema listener, `Message\RecordMailOpen(Handler)`, `Command\*`; ports `EmailTrackingPolicyInterface`, `EmailLinkRewriterInterface`, `TrackableLinkFilterInterface`, `TrackingRecipientResolverInterface`, `EmailTrackingReaderInterface`, `EmailTrackingPersonalDataInterface`; VOs `TrackingCandidate`, `TrackingDecision`, `TrackedLink`, `OpenStats` |
| `Command\*` | CLI (purge, forget, send-test) |
| `DependencyInjection\*`, `Doctrine\TablePrefixListener` | Wiring, table prefix |

## Container wiring

Services are registered explicitly by `NowoMailKitExtension` (no autoconfiguration of bundle classes). `mailer.decorate`
adds the two decorators; `MailerIntegrationPass` (before optimization) checks Instance Settings and the framework mailer
and tells `MailKitMailer` whether the mailer has a bus. `CsrfProtectionPass` and `UnauthenticatedAccessPass` fail closed
for the admin UI; `TwigPathsPass` registers `@NowoMailKitBundle`. The extension prepends the Doctrine mapping, the
FormKit profile `mail_kit` and, with AuthKit, `nowo_auth_kit.outbound_mail_ready_checker`.

## Worker safety

See [FRANKENPHP-WORKER-AUDIT.md](FRANKENPHP-WORKER-AUDIT.md): one cached transport keyed by DSN, reset on `kernel.reset`
and settings changes; every other service is stateless and reads the settings per call.

## Extension points

- `nowo_mail_kit.mailer.dsn_provider` — your `MailerDsnProviderInterface`.
- `nowo_mail_kit.security.access_checker` — your `MailKitAccessCheckerInterface`.
- `MailHeaders::tag()` / `redact()` — log category and body masking per message.
- `nowo_mail_kit.graph.mailbox_provider` — your `Graph\GraphMailboxProviderInterface` (1.8).
- `nowo_mail_kit.graph.inbound.handler` / `profiles_provider` — your `Graph\Inbound\GraphInboundHandlerInterface` and `GraphInboundProfilesProviderInterface` (1.9).
- `nowo_mail_kit.tracking.policy` / `link_rewriter` / `link_filter` / `recipient_resolver` — your `Tracking\EmailTrackingPolicyInterface`, `EmailLinkRewriterInterface`, `TrackableLinkFilterInterface` and `TrackingRecipientResolverInterface` (1.10).
- `web_ui.templates.*` — page overrides (extend + one block).
- Instance Settings `area_roles` for the `mailer` section.
