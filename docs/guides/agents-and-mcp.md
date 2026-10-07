---
title: Agents and MCP
weight: 23
description: Connect Claude Code, Cursor or another MCP client to one environment's management plane, and what an agent can and cannot do there.
---

# Agents and MCP

**Endpoint:** `https://<environment-host>/mcp`

Every environment serves an [MCP](https://modelcontextprotocol.io) server on its own
host, next to its management API. An AI agent connected to it can do what a
[management key](keys.md#management-keys) can do through the API: list and register
APIs, change their scopes, and so on. It goes through the same checks, gets the same
refusals and leaves the same activity log entries as the API and the console. There is no
separate permission model to learn.

This page is for the developer wiring an agent up. What each action does is in the guide
for its area, for example [APIs](apis.md).

## Before you start: a management key

Today `/mcp` accepts one credential: a **management key** (`cbid_env_…`) for that
environment. Create one on **Developers › Keys** in the environment console, or on
**Keys** in the workspace console ([Keys](keys.md#management-keys)).

Give it only the scopes the agent needs. The scopes decide which tools the agent sees, so
an agent that only needs to read APIs should get `apis:read` and nothing else. Set an
expiry if the agent is for one piece of work.

A key in an agent's config file is a long-lived secret on a laptop. Keep it out of
repositories (see the environment variable example below), and revoke it on the Keys page
when the agent no longer needs it.

> **Coming next: sign in with OAuth.** MCP clients can sign a person in instead of
> holding a key, and the server already publishes the document they discover it from
> (below). Until that ships, a management key is the only way in, and the agent acts as
> that key, not as you.

## Connect Claude Code

```bash
claude mcp add --transport http cbox-id https://<environment-host>/mcp \
  --header "Authorization: Bearer cbid_env_…"
```

Then run `/mcp` in Claude Code to check it connected, and ask it to call `whoami`.

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

## Connect Cursor or another client

Any client that speaks MCP's Streamable HTTP transport and lets you set a request header
works. You need two things: the URL (`https://<environment-host>/mcp`) and the header
`Authorization: Bearer cbid_env_…`. In Cursor that is `.cursor/mcp.json`:

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

## What tools you get

| Tool | What it is |
|---|---|
| `whoami` | Who this connection acts as: the key's id and name, the environment, the issuer and the key's scopes. Check this first when a tool you expected is missing. |
| `list_actions` | A short list of every action this key may run: tool name, summary, scope and danger. Cheaper to read than the full tool list. |
| One tool per action | Named after the action, with dots as underscores: `apis.create` is `apis_create`. |

Today the actions are the APIs area: `apis_list`, `apis_get`, `apis_create`,
`apis_update`, `apis_delete`, `apis_scopes_define` and `apis_scopes_remove`. More areas
become actions over time, and each one appears here as a tool on its own. Nothing about
the MCP server has to change for that.

Each tool's input is the same as the matching API endpoint's, with values from the URL as
ordinary arguments. `PUT /apis/{id}/scopes/{key}` is `apis_scopes_define` with `id` and
`key`.

### Scopes decide what is listed

A tool appears only when the key holds its scope. A key with `apis:read` sees `apis_list`
and `apis_get`, and not `apis_create`. Hiding is a convenience, not the protection: every
call is checked again before it runs, exactly as the API checks it.

### Danger, and asking before acting

Every action states how much harm it can do, and the tool says it twice: in its
description, and in the MCP annotations clients use to decide when to ask you first.

| Danger | Annotations | Example |
|---|---|---|
| read | read-only | `apis_list` |
| write | not read-only, not destructive | `apis_create` |
| destructive | destructive | `apis_delete` |
| critical | destructive | minting or rotating credentials (no such action yet) |

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

Keys are per management key and shared with the API. A request the API answered under
`Idempotency-Key: abc` is replayed to an MCP call with `idempotency_key: "abc"` and the
same arguments.

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

What an agent does is recorded on the [activity log](activity-log.md) as the management
key's act, the same entries the API writes. The log does not say which door the key
came through.

## Limits

- **Rate limit:** 240 requests a minute per key, in a bucket of its own so an agent does
  not use up the allowance of a sync job on the same key. Operators change it with
  `CBOX_ID_API_RATE_LIMIT_MCP`.
- **Tool search** (operators): `CBOX_ID_MCP_TOOL_SEARCH=true` replaces the per-action
  tools with laravel/mcp's `search_tools` and `execute_tools`, for clients that load
  every tool into the context. It is off by default because behind `execute_tools` a
  client can no longer tell a destructive call from a read, and so cannot ask you first.

## For client authors: discovery

A request without a valid key is answered `401` with:

```
WWW-Authenticate: Bearer resource_metadata="https://<environment-host>/.well-known/oauth-protected-resource/mcp"
```

That document ([RFC 9728](https://www.rfc-editor.org/rfc/rfc9728)) names the resource
(`https://<environment-host>/mcp`), the environment's issuer as its authorization server,
and the scopes the tools use. OAuth access tokens from that issuer are not accepted at
`/mcp` yet; when they are, a client that follows this document will need no other change.
