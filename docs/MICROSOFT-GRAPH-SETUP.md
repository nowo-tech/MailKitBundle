# Microsoft 365 setup for Graph sending (owner guide)

MailKit 1.8 can send e-mail from a **shared mailbox** of your Microsoft 365 tenant through Microsoft Graph, with
**app-only** credentials (no user signs in). This guide covers the steps a Microsoft 365 administrator does once per
mailbox. Bundle configuration is in [CONFIGURATION.md](CONFIGURATION.md#graph), code in
[USAGE.md](USAGE.md#microsoft-graph-shared-mailbox).

All identifiers below (`contoso.example`, GUIDs, addresses) are placeholders. Never commit a real tenant id, client
secret or mailbox to a repository: keep them in environment variables, a secret store or your application's
encrypted settings.

## Table of contents

- [What you need](#what-you-need)
- [1. Create the shared mailbox](#1-create-the-shared-mailbox)
- [2. Register the app in Microsoft Entra ID](#2-register-the-app-in-microsoft-entra-id)
- [3. Grant the application permissions](#3-grant-the-application-permissions)
- [4. Create the client secret](#4-create-the-client-secret)
- [5. Restrict the app to the mailbox (application access policy)](#5-restrict-the-app-to-the-mailbox-application-access-policy)
- [6. Plus addressing](#6-plus-addressing)
- [7. Configure the application](#7-configure-the-application)
- [Secret rotation](#secret-rotation)
- [Size limits](#size-limits)
- [Network and HttpLog](#network-and-httplog)
- [Webhook URL requirements (inbound, MailKit 1.9)](#webhook-url-requirements-inbound-mailkit-19)
- [Troubleshooting](#troubleshooting)

## What you need

- A Microsoft 365 tenant with Exchange Online.
- An administrator who can register apps and grant admin consent in Microsoft Entra ID (Application Administrator +
  Privileged Role Administrator, or Global Administrator), and run Exchange Online PowerShell (Exchange Administrator).
- The `ExchangeOnlineManagement` PowerShell module (`Install-Module ExchangeOnlineManagement`).

## 1. Create the shared mailbox

Exchange admin center → **Recipients** → **Mailboxes** → **Add a shared mailbox**, for example
`projects@contoso.example`. A shared mailbox needs no licence (up to 50 GB). Give the people who read it in Outlook
**Full access** and **Send as** there; the app does not need them.

## 2. Register the app in Microsoft Entra ID

Entra admin center → **Identity** → **Applications** → **App registrations** → **New registration**:

- Name: e.g. `MailKit – projects mailbox` (one app per application and environment is easiest to audit).
- Supported account types: **Accounts in this organizational directory only** (single tenant).
- Redirect URI: none (client credentials do not use one).

On the app's **Overview**, note the **Application (client) ID** and the **Directory (tenant) ID** (both GUIDs).
MailKit also accepts a verified domain (`contoso.example`) as tenant id.

## 3. Grant the application permissions

App → **API permissions** → **Add a permission** → **Microsoft Graph** → **Application permissions**:

| Permission | Why |
| --- | --- |
| `Mail.Send` | Send the draft. |
| `Mail.ReadWrite` | Create the draft (MIME with the threading headers), add attachments and upload sessions, delete a draft after a failed send. The inbound pipeline (1.9) also reads the mailbox with it. |

Then **Grant admin consent for <tenant>**. Remove any delegated permission the wizard added (e.g. `User.Read`); the
app needs none.

Application permissions apply to **every mailbox in the tenant** until you restrict them in step 5. Do not skip it.

## 4. Create the client secret

App → **Certificates & secrets** → **Client secrets** → **New client secret**:

- Description: e.g. `mailkit-prod-2026-11`.
- Expires: the shortest period your operations can follow (6 or 12 months; 24 months at most).
- Copy the secret **Value** (not the *Secret ID*) once — it is not shown again — straight into your secret store
  (environment variable, vault, or the application's encrypted settings). Never paste it into a ticket, chat,
  commit or log.

## 5. Restrict the app to the mailbox (application access policy)

Exchange application access policies limit an app with `Mail.*` application permissions to the members of one
mail-enabled security group:

```powershell
Connect-ExchangeOnline -UserPrincipalName admin@contoso.example

# A mail-enabled security group that holds the mailbox(es) the app may use.
New-DistributionGroup -Name "MailKit Graph mailboxes" -Alias mailkit-graph `
    -Type Security -PrimarySmtpAddress mailkit-graph@contoso.example
Add-DistributionGroupMember -Identity mailkit-graph@contoso.example -Member projects@contoso.example
# Hide the group from the address book (optional).
Set-DistributionGroup -Identity mailkit-graph@contoso.example -HiddenFromAddressListsEnabled $true

# Restrict the app (Application (client) ID from step 2) to that group.
New-ApplicationAccessPolicy -AppId 00000000-0000-0000-0000-000000000000 `
    -PolicyScopeGroupId mailkit-graph@contoso.example -AccessRight RestrictAccess `
    -Description "MailKit: projects mailbox only"

# Check: Granted for the mailbox, Denied for anybody else.
Test-ApplicationAccessPolicy -Identity projects@contoso.example -AppId 00000000-0000-0000-0000-000000000000
Test-ApplicationAccessPolicy -Identity someone@contoso.example -AppId 00000000-0000-0000-0000-000000000000
```

A new or changed policy can take 30 minutes or more to apply. Until then Graph may still answer `403
ErrorAccessDenied` for the mailbox (or, before the policy exists, allow every mailbox).

Microsoft now recommends **RBAC for Applications in Exchange Online** as the successor of application access
policies (`New-ManagementScope` + `New-ManagementRoleAssignment -App … -Role "Application Mail.Send"` /
`"Application Mail.ReadWrite"`, with the Entra permissions of step 3 removed). Either model works with MailKit; use
one, and test it with a mailbox outside the scope.

## 6. Plus addressing

The Reply-To of a message can be a plus address of the mailbox (`projects+t-42@contoso.example`, built with
`PlusAddress::compose()`), so a reply names its thread. Exchange Online has plus addressing on by default; check it:

```powershell
Get-OrganizationConfig | Format-List AllowPlusAddressInRecipients
Set-OrganizationConfig -AllowPlusAddressInRecipients $true   # only when it is False
```

## 7. Configure the application

Store the three values (tenant id, client id, secret) and the mailbox address, then enable Graph in MailKit
([CONFIGURATION.md](CONFIGURATION.md#graph)):

```yaml
# config/packages/nowo_mail_kit.yaml
nowo_mail_kit:
    graph:
        enabled: true
        profiles:
            default:
                tenant_id: '%env(MAIL_KIT_GRAPH_TENANT_ID)%'
                client_id: '%env(MAIL_KIT_GRAPH_CLIENT_ID)%'
                client_secret: '%env(MAIL_KIT_GRAPH_CLIENT_SECRET)%'
                mailbox: '%env(MAIL_KIT_GRAPH_MAILBOX)%'
```

Applications that keep these values in their own (encrypted) settings implement `GraphMailboxProviderInterface`
instead ([USAGE.md](USAGE.md#ddd--hexagonal-host-your-own-mailbox-port)).

Send one message to yourself and check that it appears in the mailbox's **Sent Items** and in your inbox with the
expected From, Reply-To and subject.

## Secret rotation

1. Some weeks before the secret expires, create a **second** client secret (step 4). Both secrets are valid at once.
2. Put the new value in your secret store and deploy / restart the workers. MailKit's token cache key includes a
   fingerprint of the secret, so the next send requests a token with the new secret at once.
3. Send a test message.
4. Delete the old secret in Entra. Tokens already issued with it stay valid until they expire (about 60–90 minutes);
   deleting a secret does not revoke them. For an incident, also restrict the app (step 5) or disable it
   (Enterprise applications → the app → Properties → *Enabled for users to sign in?* = No), then rotate.
5. Put a reminder (or an Entra / Azure Monitor alert on credential expiry) before the next expiry date.

## Size limits

- Exchange Online's default message size limit is **35 MB** (`Get-Mailbox projects@… | fl MaxSendSize`); raise it
  with `Set-Mailbox -MaxSendSize` (up to 150 MB) when you need larger messages, then raise
  `nowo_mail_kit.graph.limits` accordingly.
- Graph attachments go up to 150 MB through upload sessions; MailKit switches to an upload session at 2.9 MB per
  attachment.

## Network and HttpLog

MailKit calls these hosts over HTTPS (outbound only, no redirects followed):

| Host | Use |
| --- | --- |
| `login.microsoftonline.com` | Token endpoint (client credentials). Carries the client secret in the request body. |
| `graph.microsoft.com` | Graph API (drafts, attachments, send). Carries the bearer token. |
| `outlook.office.com`, `outlook.office365.com` | Attachment upload sessions (pre-authenticated URL; no token sent). |

Allow them in an egress firewall or proxy. With HttpLog, add them to the `microsoft_graph` outbound provider and keep
request / response body capture **off** for that provider (the token request body holds the secret; upload URLs hold
a short-lived token in the query string, which HttpLog never records):

```yaml
nowo_http_log:
    outbound:
        providers:
            microsoft_graph:
                hosts: [graph.microsoft.com, login.microsoftonline.com, outlook.office.com, outlook.office365.com]
                path_depth: 2
```

## Webhook URL requirements (inbound, MailKit 1.9)

The inbound pipeline (change notifications on the mailbox, planned for MailKit 1.9) needs a notification URL that
Microsoft Graph can reach:

- public **HTTPS** with a certificate from a publicly trusted CA (no self-signed certificate, no IP literal);
- reachable without authentication (no SSO / basic auth in front of the route; MailKit verifies the notification's
  `clientState` secret instead), not blocked by a WAF for Microsoft's addresses;
- answers the subscription **validation request** (a `validationToken` query parameter) within **10 seconds** and
  every notification within **3 seconds** (MailKit only queues work in the request);
- a stable hostname: subscriptions last at most about 7 days for messages and are renewed by a scheduled command.

Hosts without a public URL (local development) rely on the periodic delta sweep instead.

## Troubleshooting

| Symptom | Likely cause |
| --- | --- |
| `token request failed with HTTP 400/401 (invalid_client)` | Wrong or expired secret, secret ID pasted instead of the value, wrong client id. |
| `token request failed with HTTP 400 (invalid_request)` / `unauthorized_client` | Wrong tenant id, or the app lives in another tenant. |
| `create draft failed with HTTP 403 (ErrorAccessDenied)` | Admin consent missing, the mailbox is outside the access policy / management scope, or the policy is still propagating. |
| `create draft failed with HTTP 404 (ErrorInvalidUser / ResourceNotFound)` | The mailbox address is wrong or has no Exchange Online mailbox. |
| `send failed with HTTP 403 (ErrorSendAsDenied)` | `Mail.Send` missing or restricted. |
| `failed with HTTP 429` | Graph throttling: MailKit reports `retryAfter`; let Messenger retry later. |
| Messages land as **Drafts** and stay there | The send call failed after the draft was created and the draft could not be deleted; check the logs (profile, operation, request-id). |

Every error carries the Graph `request-id` when Graph sent one; Microsoft support needs it.
