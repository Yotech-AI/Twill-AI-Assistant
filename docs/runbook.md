# MCP endpoint — operator runbook

Lets Claude Cowork create CMS content from outside the admin, over
`POST /mcp/twill`.

## Safety guarantees

These are enforced in code, not configuration. Nobody can switch them off from a
settings screen.

- Everything written is a **draft**. There is no way to publish.
- **Nothing can be deleted.** No delete tool exists.
- A client that cannot be attributed to a Twill user is **refused**, so MCP-written
  content never appears in the CMS with no author.
- An OAuth client that is not on the allow-list is **refused**, even if a Twill
  admin approved it. Self-registration alone grants nothing.
- Plain HTTP is refused outside local/testing.

Editors review and publish drafts exactly as they would a colleague's.

## Everyday commands

```bash
php artisan mcp:doctor                          # is it healthy?
php artisan mcp:client-list                     # who can connect
php artisan mcp:client-list --pending           # ...plus clients that self-registered
php artisan mcp:client-create "Claude Cowork"   # new connector + OAuth credentials
php artisan mcp:client-revoke 1                 # kill a connector's access now
php artisan mcp:client-revoke 1 --delete        # ...and remove it entirely
```

`mcp:client-create` prints the client secret **once**. Store it in a password
manager.

## How auth works

The endpoint uses **OAuth 2.1 via Passport**, not a fixed bearer token. That is
what the MCP specification documents, and it is the only scheme Claude's custom
connector dialog offers — it has no field for an `Authorization` header.

Two identities are involved, and keeping them straight explains most of this file:

| Identity | What it is |
|---|---|
| The **approver** | A real Twill admin who signs in and approves the connector. The access token belongs to them. |
| The **attribution user** | A password-less Twill user named after the connector. `ActAsTwillUser` swaps this in per request, so drafts are credited to "Claude Cowork" and not to the admin who happened to click Approve. |

The approval screen sits behind the **CMS** login, not the customer login
(`config/passport.php` sets `guard` to `twill_users`).

## Connecting Cowork (one-time)

1. Run `php artisan mcp:client-create "Claude Cowork"` on the server.
2. Give the connector administrator the three values it prints — URL, client id,
   client secret.
3. In Cowork, add a custom connector with that URL, putting the id and secret
   under Advanced settings.
4. A Twill admin signs in when redirected and approves the connector.

**If Cowork reports a `redirect_uri` mismatch**, the default callback baked into
`mcp:client-create` is wrong for your account. Re-run it with the URI Cowork
reports: `--redirect=https://…`.

**If Cowork registers itself instead** (leaving the id and secret blank uses
dynamic client registration), its requests are refused until you allow-list it:

```bash
php artisan mcp:client-list --pending
php artisan mcp:client-create "Claude Cowork" --oauth-client=<id>
```

That refusal is deliberate. Passport's registration endpoint is public, so
self-registration on its own must not grant access.

## If credentials leak

```bash
php artisan mcp:client-revoke <id>
```

Revocation is immediate — Passport checks the revoked flag on every request, so
the connector's next call gets a 401 rather than working until expiry. Add
`--delete` to revoke the OAuth client too, so it cannot be re-approved. Then
create a fresh connector and re-add it. Anyone completing the approval flow can
create drafts, so treat the credentials like a password. They cannot publish or
delete.

## Is it Cowork, or is it us?

Run `php artisan mcp:doctor` first. It reports the endpoint, its middleware, whether
all eight tools resolve, and every client with its token count and attribution.

| Symptom | Likely cause |
|---|---|
| `401` on every call | Token expired, revoked, or the connector was never approved |
| `403` mentioning a registered connector | The OAuth client self-registered and is not allow-listed — see above |
| `403` mentioning attribution | Connector has no linked Twill user — re-create it |
| `403` mentioning HTTPS | Connector pointed at `http://`, or a proxy not forwarding the scheme |
| Approval page 404s or redirects to the customer login | `passport.guard` is not `twill_users` |
| Token requests fail after a deploy | `passport:keys` never ran on that server — see below |
| `429` | Rate limit (30/min). Expected under bursty use; back off |
| Tool errors naming a block | Almost always a **stale queue worker** — see below |
| Client reports a timeout | Request exceeded Claude's 300s limit — see limits below |
| `mcp:doctor` says endpoint NOT REGISTERED | `routes/ai.php` missing, or cached routes are stale (`route:clear`) |

## The stale worker trap

`queue:work` caches code in memory. After **any** deploy or code change:

```bash
php artisan queue:restart
```

Skip it and the agent silently runs old tools, which surfaces as inexplicable
"unknown block" errors while the admin looks perfectly fine. This caused a
production incident on the easy-to-spain install this integration came from.
`twill-ai:doctor` reports whether every configured block resolves *in the running
process*, which is how you tell a stale worker from a genuinely missing block.

In development use `composer dev` (it runs `queue:listen`, which reloads per job).

## The Passport keys trap

Passport signs tokens with a keypair in `storage/oauth-*.key`. Those files are
generated per environment and **gitignored**, so a freshly provisioned server has
none, and every token request fails until:

```bash
php artisan passport:keys
```

Run it once per environment and make sure the files survive deploys — a release
directory that is wiped each deploy will silently break the connector every time.
Alternatively set `PASSPORT_PRIVATE_KEY` / `PASSPORT_PUBLIC_KEY` in the
environment, which is usually better for containerised deploys.

`mcp:doctor` reports whether the keys are readable, so check it first after any
infrastructure change.

## Platform limits worth knowing

- **~150,000 characters** per tool result on Claude surfaces. `get_content` returns a
  full block tree and can approach this on large pages.
- **300 seconds** per request. Multi-locale generation can take longer; our own config
  allows 600s. When it overruns, the write still completes server-side and Cowork's
  retry returns the entry the first attempt created rather than duplicating it —
  that is what `external_ref` is for.
- Anthropic calls from `160.79.104.0/21` if you need a firewall allowlist.

## Logs

Every content write is logged as `[mcp] create_content` / `[mcp] update_content`
with the client id, module, entry id and outcome. Reads are not logged.
