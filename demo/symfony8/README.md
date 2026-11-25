# Mail Kit Bundle – Symfony 8 demo

Symfony 8.1 application on FrankenPHP (worker mode by default), MySQL and Mailpit showing `nowo-tech/mail-kit-bundle`:

- the `mailer` settings section contributed to Instance Settings (DSN encrypted at rest, sender, Reply-To, test
  recipient) with the "Send a test e-mail" action;
- the runtime DSN (`smtp://mailpit:1025`, allowed through `dsn.allowed_hosts`) replacing `MAILER_DSN=null://null`;
- a home page that sends through the plain `MailerInterface` and shows `nowo_mail_kit_status()`;
- the sent-mail log (`/admin/mail/log`) with seeded messages, one failed delivery and stored bodies (`store_bodies`
  is on in the demo only).

## Setup

```bash
make up      # containers + composer install, prints "Demo started at: http://localhost:8085"
make setup   # key, schema (doctrine:schema:update — demo only, use migrations in an application), seed
```

Mailpit: http://localhost:8087.

## Commands

- `php bin/console app:demo:seed` – seed the settings and the log (idempotent)
- `php bin/console nowo:mail-kit:send-test qa@example.com` – synchronous test e-mail
- `php bin/console nowo:mail-kit:log:purge --older-than-days=7` – retention purge
- `make test` – `lint:container` + `lint:twig` + the PHPUnit suite of the Outlook harness (`make test-harness` runs only the suite)
- `make test-e2e` / `make demo-screenshots` – Playwright (REQ-DEMO-013)

## Outlook manual test harness (demo only)

Console harness for the manual Outlook.com check of the delegated mailboxes (2.5.0) against a **dedicated test** personal
account. It lives in the demo only (`src/Outlook`, `src/Command/Outlook`, `config/packages/nowo_mail_kit_mailboxes.yaml`);
nothing of it ships in the bundle.

- **Token**: a JSON file in a scratch directory **outside every repository**, mounted at `/outlook-scratch`. The
  harness refuses a file inside the demo, the bundle checkout or any git work tree, never prints the token, and
  refreshes an expired one with the stored refresh token, writing the new pair back to the same file (mode 0600).
  Accepted content: the raw Microsoft token response (`access_token`, `refresh_token`, `expires_in`, `scope`) or the
  same with `expires_at`. `make outlook-login` writes it with a device-code login.
- **Entra app**: multi-tenant + personal accounts, "Allow public client flows" on, delegated `Mail.ReadWrite`,
  `Mail.Send`, `offline_access`, `User.Read`. The client id is not a secret; there is no client secret.
- **Sweep only, no worker, no webhook**: `graph.webhook.notification_url` is null, so MailKit creates no Graph
  subscription and every followed folder is synced by the sweep (`status` shows "subscription none (sweep only)").
  The Messenger bus has no transport, so every MailKit message (`SyncMailboxFolder`, `FetchMailboxMessage`,
  `DeliverSentCopy`, `ReconcileMailboxSend`) runs inside the command that dispatched it; delays are ignored.
  `app:outlook:send` therefore asks once more for the sent copy after `--sent-copy-wait` seconds (default 15).
- **Sink**: envelopes only (no bodies) in `demo_outlook_message`, unique on (mailbox, provider id); `dups` counts
  later `store()` calls that answered `Duplicate`.

```bash
cd demo/symfony8                                        # MailKit checkout at main (dev-main)
mkdir -m 700 -p "$HOME/.mailkit-outlook-scratch"
export MAILKIT_OUTLOOK_SCRATCH_DIR="$HOME/.mailkit-outlook-scratch"   # absolute, outside any repository
export MAILKIT_OUTLOOK_CLIENT_ID=<client-id>        # login + refresh
# optional: MAILKIT_OUTLOOK_TENANT=consumers (default)  MAILKIT_OUTLOOK_TOKEN_NAME=token.json (default)

make outlook-up                                         # demo + scratch mount + schema
make outlook-login                                      # or copy the token JSON to $MAILKIT_OUTLOOK_SCRATCH_DIR/token.json
make outlook-register ADDRESS=you@example.com FOLDERS='inbox archive'   # start(): account check + first sweep
make outlook-sync ARGS=-v                               # one sweep pass (inline); -v shows MailKit's notices/warnings
make outlook-list                                       # ARGS=--full-ids for ids usable with --reply-to
make outlook-status                                     # nowo:mail-kit:mailbox:status
```

Guide checks:

| Check | Commands | Expected |
| --- | --- | --- |
| Received once, immutable id | send a mail to the account, `make outlook-sync`, `make outlook-list`; move it Inbox → Archive in Outlook, sync, list | one row; after the move the **same** provider id with folder `archive`, no removal mark |
| Folder shape recognised | `make outlook-sync ARGS=-v 2>&1 \| grep -i 'does not recognise'` | no output |
| Deleted vs moved | delete one for good (Deleted Items → delete), move another to an unfollowed folder, sync, list | `deleted from inbox` / `moved_out from inbox` (an Outlook "Delete" only moves to Deleted Items: `moved_out`) |
| Send new + reply with > 3 MB | `make outlook-send ARGS='--key=outlook-test-new-0001 --to=<other> --attach-mb=4'`; reply: `make outlook-send ARGS='--key=outlook-test-reply-0001 --reply-to=<id> --to=<sender> --attach-mb=4'` | `replayed: false`, one copy each, the reply in the same conversation, no "outside the allowed hosts" |
| Same key twice | run the same `make outlook-send ARGS='…'` again (same key, same options) | `replayed: true (nothing sent again)`, still one copy |
| Sent copy once | Sent Items not followed: output of the send; followed (`FOLDERS='inbox sentitems'`): `make outlook-sync`, `make outlook-list` | `origin sent_copy`; followed: one row, `dups` ≥ 1 |
| Double POST /send (ZAV-L1) | `make outlook-double-send ARGS='--to=<other>'` | `PASS` (202, `isDraft: false`, then 4xx) and one copy; `FAIL` = do not tag |
| Ambiguous submit | `make outlook-send ARGS='--key=outlook-test-ambig-0001 --to=<other> --attach-mb=20'`, cut the **host** network while it runs (not `docker network disconnect`: the database shares that network); restore; after 300 s run it again | first: exit 2 `MailboxSendInProgress (reconciling: yes)`; then `replayed: true`, one copy |

Clean up: `make outlook-down` and delete the scratch token file (`rm "$MAILKIT_OUTLOOK_SCRATCH_DIR/token.json"`). The test
account and its Entra app may be kept as a permanent test mailbox; its refresh token then lives only in the host's
local secrets (never in a repository).
