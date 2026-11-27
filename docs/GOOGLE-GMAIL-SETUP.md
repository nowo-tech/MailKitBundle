# Google Cloud setup for send-only Gmail mailboxes (owner guide)

MailKit 2.6.0 sends e-mail from a person's own **Gmail** (or Google Workspace) address with a delegated OAuth grant
the host application obtains. The grant is **send-only** (owner decision D-20261124-09): MailKit never reads the
mailbox, so the only Gmail scope is `gmail.send`. This guide covers the Google Cloud steps done once per application.
Bundle configuration is in [CONFIGURATION.md](CONFIGURATION.md#mailboxesgmail-260-send-only), code in
[USAGE.md](USAGE.md#send-only-gmail-mailboxes-260).

All identifiers below (`app.example.com`, project and client ids) are placeholders. Never commit a real client id,
client secret, refresh or access token, or the address of a test account to a repository: keep them in environment
variables, a secret store or your application's encrypted settings.

## Table of contents

- [What you need](#what-you-need)
- [1. Create or pick the Google Cloud project](#1-create-or-pick-the-google-cloud-project)
- [2. Enable the Gmail API](#2-enable-the-gmail-api)
- [3. Configure the OAuth consent screen](#3-configure-the-oauth-consent-screen)
- [4. Create the OAuth client](#4-create-the-oauth-client)
- [5. The authorization request](#5-the-authorization-request)
- [6. Configure the application](#6-configure-the-application)
- [Verification and publishing](#verification-and-publishing)
- [Limits](#limits)
- [Troubleshooting](#troubleshooting)

## What you need

- A Google Cloud project you administer (one per application and environment is simplest).
- The public HTTPS origin of the application (for the redirect URI), e.g. `https://app.example.com`.
- The application's OAuth connection code (MailKit runs no OAuth flow; Beacon reuses its PKCE helpers).

## 1. Create or pick the Google Cloud project

Google Cloud console → *IAM & Admin* → *Create project*. Note the project id; nothing of it goes into MailKit.

## 2. Enable the Gmail API

*APIs & Services* → *Library* → *Gmail API* → *Enable*. Without it every send answers 403 `accessNotConfigured`
(MailKit reports `MailboxSendFailed` `refused:accessNotConfigured`; the mailbox is not paused, since the problem is the
project, not the person). The key of such a send ends `failed` for good: once the API is enabled, send the message
again with a **new** idempotency key.

No Pub/Sub topic, service account or push subscription is needed: send-only mailboxes have no inbox and no watch.

## 3. Configure the OAuth consent screen

*Google Auth Platform* (formerly *OAuth consent screen*):

- **Audience**: *External* (any Google account) or *Internal* (only your Workspace organisation; no verification).
- **Branding**: application name, support e-mail, logo, the application's home page, privacy policy and terms URLs,
  and the **authorised domain** of the redirect URI (`example.com`).
- **Data access** (scopes): add exactly
  - `openid`
  - `.../auth/userinfo.email` (requested as `email`)
  - `https://www.googleapis.com/auth/gmail.send` (*sensitive* scope)

  Do **not** add `gmail.readonly`, `gmail.modify`, `gmail.compose`, `gmail.metadata` or `https://mail.google.com/`:
  they are *restricted* scopes that need a yearly CASA security assessment, and MailKit never uses them.
- **Test users** (while the app is in *Testing*): the Google accounts allowed to connect before verification. Keep
  their addresses in the console, never in a repository.

## 4. Create the OAuth client

*Clients* → *Create client* → *Web application*:

- **Authorised redirect URI**: the host's callback, exactly as the application sends it, e.g.
  `https://app.example.com/connect/google/mailbox/callback` (HTTPS, no wildcard, no fragment). Add one per environment.
- No JavaScript origin is needed for a server-side code exchange.

Copy the client id and the client secret into the application's secret store (`%env(...)%` or encrypted settings).
Rotate the secret from the same page; keep the old one until every environment uses the new one.

## 5. The authorization request

The host builds (placeholders shown):

```text
https://accounts.google.com/o/oauth2/v2/auth
  ?client_id=<client id>
  &redirect_uri=https://app.example.com/connect/google/mailbox/callback
  &response_type=code
  &scope=openid%20email%20https%3A%2F%2Fwww.googleapis.com%2Fauth%2Fgmail.send
  &access_type=offline
  &prompt=consent
  &include_granted_scopes=false
  &state=<single-use random value bound to the session>
  &code_challenge=<BASE64URL(SHA-256(code_verifier))>
  &code_challenge_method=S256
```

- **PKCE S256** always (also with a confidential client); the `code_verifier` stays server-side with the `state`.
- **`state`**: at least 128 random bits, bound to the signed-in person, compared in constant time, deleted on first use
  and expired after a few minutes; a callback with an unknown or reused `state` is refused.
- `include_granted_scopes=false` keeps earlier grants of the same client from widening this token.
- Exchange the code at `https://oauth2.googleapis.com/token` with the `code_verifier`; check that the returned `scope`
  contains `gmail.send` and nothing broader before storing the refresh token (encrypted). MailKit logs a warning when a
  token declares a Gmail scope beyond `gmail.send`.
- Read the address with the `id_token` or the userinfo endpoint and store it as the mailbox address
  (`MailboxSettings`) **only when `email_verified` is true**. If you use the `id_token`, validate it: `aud` equals your
  client id, `iss` is `https://accounts.google.com` (or `accounts.google.com`) and `exp` is in the future (the TLS
  token response lets you skip the signature, not these claims). Store the `sub` with the grant and refuse a reconnect
  whose `sub` differs from the mailbox's (connect it as a new mailbox instead).
- Refresh with `grant_type=refresh_token`; an `invalid_grant` answer means the grant is gone: throw
  `MailboxGrantFailure(GrantFailureReason::Revoked)` from your token port and ask the person to reconnect.
- On disconnect, revoke at `https://oauth2.googleapis.com/revoke` and call `MailboxSyncInterface::disconnect()`.

## 6. Configure the application

```yaml
nowo_mail_kit:
    mailboxes:
        enabled: true
        token_provider: App\Mail\MailboxTokens   # returns MailboxAccessToken with the granted scopes
        directory: App\Mail\MailboxDirectory     # MailboxProvider::Gmail, no folders
        sink: App\Mail\MailboxSink
        gmail:
            enabled: true
```

The HTTP client must **not retry** by itself: with FrameworkBundle `retry_failed` on the client MailKit uses, a
`messages.send` that Gmail accepted but answered 502 / 503 would be POSTed again outside MailKit's idempotency claim,
so the build fails (`MailboxHttpRetryPass`). Give `mailboxes.http_client` a scoped client without `retry_failed` if
your default client retries.

MailKit calls only `https://openidconnect.googleapis.com/v1/userinfo` (account check) and
`https://gmail.googleapis.com/upload/gmail/v1/users/me/messages/send` (one call per message). Declare both hosts as the
Google provider in HttpLog's outbound metering if you use it; the token endpoint is the host's own call.

## Verification and publishing

- *Internal* apps (Workspace only) need no verification.
- *External* apps in *Testing* work for the listed test users only, and Google expires their refresh tokens after
  7 days: reconnecting is expected while testing.
- Before production, *Publish app* and submit the **OAuth verification** for the sensitive `gmail.send` scope (scope
  justification, a demo video of the connect and send flow, the privacy policy). No CASA assessment is required as long
  as no restricted scope is requested.

## Limits

- 35 MB per message after encoding (attachments grow by about a third); MailKit refuses a larger message before
  calling Google (`MailboxSendFailed` `too_large`), from the sizes alone when the attachments cannot fit (nothing is
  rendered), else once rendered (rendering peaks at about five times the message size in memory: keep
  `memory_limit` well above that for large attachments).
- Gmail's per-user sending limits (Google Workspace and consumer accounts differ) answer 429 / 403
  `dailyLimitExceeded` / `userRateLimitExceeded`: MailKit releases the key (nothing was sent) and throws a retryable
  `MailboxProviderFailure` with `retryAfter`.
- `messages.send` costs 100 quota units per call; the units reach `MailboxCallObserverInterface` (`ProviderCall::$quotaUnits`).

## Troubleshooting

| Symptom | Cause |
| --- | --- |
| Mailbox paused `scope_missing` | The token's scopes lack `gmail.send` (the person unticked it on the consent screen, or the token port returns no scopes). Reconnect; pass Google's `scope` field into `MailboxAccessToken`. |
| Mailbox paused `account_mismatch` | The token belongs to another Google account than the directory's address, or the address is not verified. |
| Mailbox paused `revoked` | Google refused the token twice (revoked in the Google account, password change, test-user token expired). Reconnect, then `resume()`. |
| `MailboxSendFailed` `refused:accessNotConfigured` | The Gmail API is not enabled in the project (step 2). |
| Mailbox paused `consent_missing` | A Workspace administrator blocked the app or the Gmail API (`domainPolicy`). |
| `MailboxSendOutcomeUnknown` | Google gave no definite answer (timeout, 5xx). Gmail may have sent it; check the person's *Sent* folder by hand. MailKit never sends that key again. |
