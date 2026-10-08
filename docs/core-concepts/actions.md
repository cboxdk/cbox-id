---
title: Actions
weight: 12
description: One action, four doors — every change in Cbox ID is one action, reached the same way from the console, the REST API, MCP and the cbox CLI.
---

# Actions

**One action, four doors.** Every change you can make in Cbox ID, and every read behind a
console page, is one **action**: `apps.create`, `organizations.portal_links.create`,
`keys.workspace.revoke`. The console, the REST API, the MCP server and the `cbox` CLI are
four doors to the same action. They run the same code, with the same checks, and leave the
same audit entry.

So anything a person can do in the console, an agent can do over MCP and a script can do
over REST, and the answer to "can my agent do X?" is always "is X an action, and does its
credential carry the scope?".

## What an action declares

Each action states, once:

| | Example | Used by |
|---|---|---|
| **Name** | `apps.secrets.rotate` | the MCP tool (`apps_secrets_rotate`), the CLI command (`cbox id apps secrets rotate`), `x-action` in OpenAPI |
| **Summary** | "Rotate an app's client secret…" | the tool description an agent reads, the API reference |
| **Plane** | environment | which host and credential reach it — see [Planes and hosts](planes-and-hosts.md) |
| **REST route** | `POST /api/v1/apps/{id}/secrets` | the API |
| **Scope** | `apps:write` | what a key or token must carry |
| **Danger** | `critical` | approvals, MCP annotations, the CLI's "are you sure" |
| **Input** | typed fields | validation at every door, the JSON Schema of the tool, the request body |

Nothing about an action's route, scope or danger is written anywhere else, so no door can
drift from another. The [actions reference](../reference/_index.md) is generated from the
same declarations.

## What happens when one runs

Whichever door it came through, every call goes through the same steps, in this order:

1. **Who is asking.** A person in the console, a key, or a token a person delegated.
2. **May they.** The credential carries the action's scope, and the person or key's role
   allows it. A lookup outside the caller's environment or organization is `not_found`,
   never somebody else's record.
3. **Is the input valid.** One set of rules, so a refusal is the same `validation_failed`
   with the same field names everywhere.
4. **Does a person need to approve it first.** If the key's approval policy, or the
   delegated token's rule for critical actions, says so, the call is held and the caller
   gets an approval to wait for. See [Approvals](approvals.md).
5. **Run it**, inside one database transaction: it happens completely or not at all.
6. **Record it** on the [Audit log](../guides/activity-log.md), with the real actor: the
   key over REST, the person in the console.

## Danger

Every action states how much harm it can do in the wrong hands:

| Danger | Means | For example |
|---|---|---|
| `read` | reads only | `apps.list`, `audit.list` |
| `write` | a change another write can undo | `apps.update`, `roles.create` |
| `destructive` | removes or revokes; undoing it means recreating it | `webhooks.delete`, `api_keys.revoke` |
| `critical` | mints credentials, takes over access, or changes how people sign in | `apps.secrets.rotate`, `keys.workspace.create` |

An MCP client is told a destructive or critical tool is destructive, so it asks you before
calling it. The CLI asks before running one unless you pass `--yes`. An approval policy keys
on it.

## Retries are safe

Every write takes an idempotency key: the `Idempotency-Key` header over REST, an
`idempotency_key` argument over MCP, and one the CLI sends for you. A retry with the same
key and the same input gets the first answer back, marked `Idempotent-Replayed: true`, for
24 hours, instead of making the change twice. A secret the first answer carried (a key's
value) is replaced by `null` in the replay: it is shown once.

## What comes back

Over REST, success is `{ "data": … }`, with a sibling `meta` on lists. A refusal is
`{ "error": "<code>", "message": "…" }`, plus a field-keyed `errors` map for validation.
Over MCP the same shapes come back as a tool result or a tool error the agent can read and
correct. A held call is `202 approval_required` over REST and `approval_pending` over MCP.

## What is not an action

Ceremonies a person must perform themselves stay in the browser: typing a password,
enrolling a passkey or an authenticator, signing in, giving OAuth consent, and the steps of
the hosted Admin Portal. Everything else the console does is an action.

## Related

- [Actions reference](../reference/_index.md): every action, with its route, scope, danger, tool and command.
- [Agents and MCP](../guides/agents-and-mcp.md): connecting an agent.
- [Run your tenancy from your backend](../getting-started/management-api.md): the environment API, by task.
