---
title: Agents and MCP
weight: 23
description: Connect Claude Code, Cursor or another MCP client to one environment's management plane, with a management key or by signing yourself in, and what an agent can and cannot do there.
---

# Agents and MCP

**Endpoint:** `https://<environment-host>/mcp`

Every environment serves an [MCP](https://modelcontextprotocol.io) server on its own
host, next to its management API. An AI agent connected to it can run the same actions the
API offers: register APIs, manage webhooks, read the audit log, and so on. It goes through
the same checks, gets the same refusals and leaves the same activity log entries as the
API and the console. There is no separate permission model to learn.

This page is for the developer wiring an agent up. What each action does is in the guide
for its area, for example [APIs](apis.md).

## Two ways in

| | A management key | Signing in as yourself |
|---|---|---|
| What the agent holds | `cbid_env_…`, pasted into its config | An OAuth access token the client gets by sending you through sign-in |
| Who it acts as | The key | You, through that client |
| What it may do | The key's scopes | The scopes you granted **and** what you may do yourself here |
| Critical actions | Whatever the key's approval policy says | Always wait for your approval on your device |
| Best for | Automation that runs without a person: CI, a sync job | You, working with an agent at your desk |

## Option 1: sign in with OAuth

Add the server by its URL alone:

```bash
claude mcp add --transport http cbox-id https://<environment-host>/mcp
```

Then run `/mcp` in Claude Code and choose **Authenticate**. Your browser opens this
environment's sign-in page. Sign in, check what the consent screen lists, and choose
**Authorize**. Claude Code stores the token and refreshes it; you do not handle it.

What happens underneath, for the curious:

1. The first request has no token, so `/mcp` answers `401` and points at
   `/.well-known/oauth-protected-resource/mcp`.
2. The client reads that document and the issuer's metadata, then either registers itself
   at `/oauth/register` or uses a [client ID metadata document](#client-id-metadata-documents)
   as its `client_id`.
3. It sends you to `/oauth/authorize` with PKCE and `resource=https://<environment-host>/mcp`.
4. It redeems the code for a token whose audience is `https://<environment-host>/mcp`,
   good there and nowhere else.

### The consent screen

The consent screen lists what the client will be able to do as you. Read it before you
choose **Authorize**:

- **"This app registered itself."** Every MCP client that signs in this way registered
  itself. Nobody at your company reviewed it, so continue only if you started this sign-in
  yourself.
- **"Described by agent.example."** A client that uses a metadata document is shown with
  the host that published it. That host is the one thing about the client that has been
  checked; its name and logo are whatever that host wrote.
- **Scopes marked Critical.** Some actions mint or rotate credentials, change how people
  sign in, or put an endpoint in the sign-in path. A scope any of those actions needs is
  flagged. You can still grant it: every critical action waits for your approval anyway
  (see below).

The screen is shown every time for a self-registered client. It is never skipped.

### What a signed-in agent may do

Two limits apply, and the agent gets only what both allow:

1. **The scopes you granted.** A tool whose scope the token does not carry is not listed.
2. **What you may do yourself, here.** The agent acts as you in the organization you
   signed in to, with your role there:
   - An organization owner or admin can run the actions their own console offers for that
     organization: its apps, webhooks, inline hooks, log streams, branding, sign-in rules and
     audit log. Only that organization's, never another's, and never the environment's own
     (an environment-wide webhook, a first-party app).
   - On a customer's environment of the hosted platform, a customer's console is a smaller
     admin portal, and a signed-in agent gets only what it offers.
   - A member who is not an admin gets no tools.
   - Actions that belong to the **environment console** (registering APIs, minting
     management keys, custom domains, frontend keys) are not available to a signed-in
     person at all. Use a management key for those.

If you leave the organization or your role changes, the agent's next call reflects it.
If your account is deactivated, its token stops working.

`whoami` shows who the connection acts as, which organization and role it holds, and the
scopes the token carries. Ask the agent to call it when a tool you expected is missing.

### Critical actions wait for you

Every **critical** action a signed-in agent tries is held. The agent gets back:

```json
{
  "status": "approval_pending",
  "approval": { "id": "…", "binding_code": "K7Q2", "expires_at": "…" },
  "next": "Tell the person to approve the request showing code K7Q2 …"
}
```

You get a request on your devices that shows the same code, and on **Approvals** in your
console. Approve it there. The agent polls `approval_status`, then repeats the call with
`approval_id` and runs it once. An approval is for exactly that call: the same tool, the
same arguments and the same client. It cannot be spent on anything else, and it lapses
after five minutes.

## Option 2: a management key

A **management key** (`cbid_env_…`) for the environment works without anybody signing in.
Create one on **Developers › Keys** in the environment console, or on **Keys** in the
workspace console ([Keys](keys.md#management-keys)).

Give it only the scopes the agent needs. The scopes decide which tools the agent sees, so
an agent that only needs to read APIs should get `apis:read` and nothing else. Set an
expiry if the agent is for one piece of work, and set an approval policy if a person should
confirm its dangerous calls.

A key in an agent's config file is a long-lived secret on a laptop. Keep it out of
repositories (see the environment variable example below), and revoke it on the Keys page
when the agent no longer needs it.

```bash
claude mcp add --transport http cbox-id https://<environment-host>/mcp \
  --header "Authorization: Bearer cbid_env_…"
```

To share the setup with your team without sharing the key, put it in the project's
`.mcp.json` and read the key from an environment variable:

```json
{
  "mcpServers": {
    "cbox-id": {
      "type": "http",
      "url": "https://<environment-host>/mcp",
      "headers": { "Authorization": "Bearer ${CBOX_ID_MANAGEMENT_KEY}" }
    }
  }
}
```

A workspace key (`cbid_ws_…`) works the same way and sees the workspace's tools: projects,
environments, the team and keys.

## Connect Cursor or another client

Any client that speaks MCP's Streamable HTTP transport works. A client that supports MCP
authorization needs only the URL (`https://<environment-host>/mcp`) and signs you in as
above. Otherwise give it the header `Authorization: Bearer cbid_env_…`. In Cursor that is
`.cursor/mcp.json`:

```json
{
  "mcpServers": {
    "cbox-id": {
      "url": "https://<environment-host>/mcp",
      "headers": { "Authorization": "Bearer cbid_env_…" }
    }
  }
}
```

The server is stateless: no session, no cookies, and no server-to-client stream. Each
request is answered on its own.

## The `cbox` CLI

`cbox login` signs you in with the device flow against the environment's own issuer. It
reads `/.well-known/cbox-cli`, which names the CLI's client, the scopes to ask for (the
sign-in ones and every management scope) and the `resource` to name: the same `/mcp`
audience. You approve the code on the device page, which lists what the CLI may do as you
and flags the critical scopes. The token it gets works at `/mcp` and on the REST
environment API, as you, with the same two limits and the same approvals.

An operator provisions the CLI client once per environment with
`php artisan cbox-id:cli:client`. Run it again after an upgrade: it adds any new management
scopes to the existing client without replacing it.

The REST API answers a held critical call with `202 approval_required` and a `poll_url`.
Poll it, then repeat the call with the header `Cbox-Approval: <id>`.

## What tools you get

| Tool | What it is |
|---|---|
| `whoami` | Who this connection acts as: a key (its id, name and scopes) or a person (who, which client, which organization and role, the token's scopes), plus the environment and issuer. Check this first when a tool you expected is missing. |
| `list_actions` | A short list of every action this connection may run: tool name, summary, scope and danger. Cheaper to read than the full tool list. |
| `approval_status` | Where a held call's approval stands. |
| One tool per action | Named after the action, with dots as underscores: `apis.create` is `apis_create`. |

Each tool's input is the same as the matching API endpoint's, with values from the URL as
ordinary arguments. `PUT /apis/{id}/scopes/{key}` is `apis_scopes_define` with `id` and
`key`.

### Scopes decide what is listed

A tool appears only when the credential may run it. A key with `apis:read` sees
`apis_list` and `apis_get`, and not `apis_create`. Hiding is a convenience, not the
protection: every call is checked again before it runs, exactly as the API checks it.

### Danger, and asking before acting

Every action states how much harm it can do, and the tool says it twice: in its
description, and in the MCP annotations clients use to decide when to ask you first.

| Danger | Annotations | Example |
|---|---|---|
| read | read-only | `apis_list` |
| write | not read-only, not destructive | `apis_create` |
| destructive | destructive | `apis_delete` |
| critical | destructive | `webhooks_secret_rotate`, `keys_create` |

A tool is also marked idempotent when calling it twice has the same effect as once
(the API verb is `GET`, `PUT` or `DELETE`).

## Retries: `idempotency_key`

Every write tool takes an optional `idempotency_key` argument. It does what the
`Idempotency-Key` header does on the API:

- The first call runs, and its answer is kept for 24 hours.
- A retry with the same key and the same arguments gets that answer back, marked
  `"replayed": true`, and nothing runs again.
- The same key with different arguments is refused (`idempotency_key_reused`).
- A refusal is not kept, so a corrected retry runs.

Keys are per credential and shared with the API. For a signed-in person that is the
person and the client together, so two agents you signed in never share one.

Agents retry after timeouts, so tell yours to send a fresh `idempotency_key` with every
change it means to make once. The server's instructions already ask it to.

## What comes back

A successful call returns the API's response body as structured content, `{data, meta?}`
(`meta` carries the cursor on lists), after one line of plain text.

A call the action refuses is a tool error, not a protocol error, so the agent can read it
and correct itself:

```json
{ "error": "organization_not_found", "message": "No organization with that organization_id exists in this environment.", "field": "organization_id" }
```

The codes are the API's: `validation_failed` (with `errors`, by field), `not_found`,
`forbidden`, `invalid_api` and the rest.

## What is recorded

What an agent does is recorded on the [activity log](activity-log.md):

- with a key, as the key's act, the same entries the API writes;
- signed in, as **your** act, with the client you used recorded on every entry
  (`oauth_client_id`), so "you did it" and "your agent did it" can be told apart.

The log does not say whether the call came through MCP or the API.

## Limits

- **Rate limit:** 240 requests a minute per credential, in a bucket of its own so an agent
  does not use up the allowance of a sync job on the same key. Operators change it with
  `CBOX_ID_API_RATE_LIMIT_MCP`.
- **Tool search** (operators): `CBOX_ID_MCP_TOOL_SEARCH=true` replaces the per-action
  tools with laravel/mcp's `search_tools` and `execute_tools`, for clients that load
  every tool into the context. It is off by default because behind `execute_tools` a
  client can no longer tell a destructive call from a read, and so cannot ask you first.
- **Self-registered clients** (operators): registration is open in the `mcp` profile by
  default (`CBOX_ID_DCR_MODE`), capped at 20 an hour per address
  (`CBOX_ID_DCR_MAX_PER_IP_PER_HOUR`), and a client nobody has used for 30 days is
  removed (`CBOX_ID_PRUNE_UNUSED_DYNAMIC_CLIENTS`). `CBOX_ID_MCP_DYNAMIC_CLIENTS=false`
  closes `/mcp` to them. See [environment variables](../configuration/environment-variables.md#oauth--oidc-endpoint-policy).

## For client authors

### Discovery

A request without a valid credential is answered `401` with:

```
WWW-Authenticate: Bearer resource_metadata="https://<environment-host>/.well-known/oauth-protected-resource/mcp"
```

plus `error="invalid_token"` when a token was presented. That document
([RFC 9728](https://www.rfc-editor.org/rfc/rfc9728)) names the resource
(`https://<environment-host>/mcp`), the environment's issuer as its authorization server,
and the scopes the tools use.

A token is accepted when it is live, its `aud` names `https://<environment-host>/mcp`, its
`iss` is this environment's issuer, it stands for a person (not a client-credentials or a
support-session token) and, when it is DPoP-bound, the request carries a valid proof
(`Authorization: DPoP <token>`). A token for any other audience, including the issuer's
own, is refused.

### Registration

`POST /oauth/register` ([RFC 7591](https://www.rfc-editor.org/rfc/rfc7591)) in the `mcp`
profile takes a public client only:

```json
{
  "client_name": "My agent",
  "redirect_uris": ["http://127.0.0.1:33418/callback"],
  "grant_types": ["authorization_code", "refresh_token"],
  "token_endpoint_auth_method": "none"
}
```

Redirect URIs must be https or loopback http. A client secret, another grant or a
back-channel logout URI is refused with `invalid_client_metadata`. Leave `scope` out to be
registered for every `/mcp` scope plus `offline_access`.

### Client ID metadata documents

When the issuer's metadata says `client_id_metadata_document_supported: true`, use the
https URL of your client's metadata document as `client_id` and skip registration. The
document's `redirect_uris` are matched exactly, port included.
