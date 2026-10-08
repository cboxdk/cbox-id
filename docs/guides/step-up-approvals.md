---
title: Step-up approvals
weight: 24
description: Make a key's dangerous calls wait for a person's approval in Cbox Authenticator, why a signed-in agent's critical calls always wait, and how to handle 202 approval_required and approval_pending in code.
---

# Step-up approvals

**Console pages:** AI agents › Agents (setting a key's policy) and AI agents › Approvals in
an environment console

A step-up approval holds one call until a person says yes on their phone. It lets you give
an agent a key with broad scopes while keeping the calls that mint credentials, change
sign-in or delete things behind a human.

This page is the how-to: setting the policy and handling a held call in your code. For
the concept see [Approvals](../core-concepts/approvals.md). For the person answering the
request see [Approvals](agent-approvals.md) and [Trusted devices](trusted-devices.md).

## When a call is held

| Credential | Held when | Who approves |
|---|---|---|
| A secret key (`cbid_env_…`) with an approval policy | The action is at or above the policy's danger, or is named in it | The person who created the key. For a key minted by another key, the person at the top of that chain. |
| A workspace key (`cbid_ws_…`) minted by a key with a policy | The same, with the policy it inherited | The person at the top of the minting chain |
| A token a person signed in with (an MCP client, `cbox login`) | Every **critical** action, always | That person |
| A console session | Never. The console asks for your password before dangerous changes instead. | — |
| An [Admin Portal](admin-portal.md) session | Never. The link is the approval. | — |

A key with no policy is never held; its scopes are its only limit. Every action states its
danger (`read`, `write`, `destructive` or `critical`), in the
[reference](../reference/_index.md) and in each MCP tool's description.

If a policy needs an approval and no person can be found behind the key, the call is
refused with `403 approval_unavailable` rather than run. Create keys from the console, so
there is an owner to ask.

## Set a key's policy

Only a **secret key created on AI agents › Agents** in the environment console (or with
`keys.create`) can be given a policy. Choose **New agent** and, under approvals, pick one:

| Choice | Holds |
|---|---|
| None | Nothing. The agent acts on its own, within its scopes. |
| Critical actions | Minting keys, rotating secrets, changing how people sign in. |
| Destructive and above | Also anything that deletes or revokes. |
| Every change | Every write. The agent reads freely and asks before it writes anything. |

You can also name actions one by one; a named action is held whatever its danger, reads
included. The presets start you off: **Read-only** holds nothing, **Support agent** holds
destructive and above, **Full admin** holds critical actions.

Over the API, the same is `require_approval` on `keys.create` (`POST /api/v1/keys`, scope `keys:write`):

```json
{
  "name": "Release bot",
  "scopes": ["apps:write", "webhooks:write"],
  "require_approval": { "min_danger": "critical", "actions": ["apps.delete"] }
}
```

`min_danger` is `write`, `destructive`, `critical` or null; `actions` lists up to 100
action names.

Things to know:

- **A policy is set when the key is created.** There is no action to change it later.
  Rotating the key (`keys.rotate`) carries it to the new key. To change it, mint a new key
  and revoke the old one.
- **A key a key mints is at least as supervised as its parent.** It gets the lower
  threshold of the two and the named actions of both, and its approvals go to the
  parent's person.
- **The workspace console's API keys page takes no policy**, for Secret or Workspace
  keys alike. A workspace key gets a policy only by inheriting one from the key that
  minted it.
- **Approvals cover actions only.** A few scopes are still served by endpoints that are
  not actions; the create page lists them, because calls to them run without asking.

## What the person sees

The request reaches the approver's Cbox Authenticator as "Approve an action", with a
sentence such as `Key "Release bot" in Production wants to run apps.delete · K7Q2`: the
key, the environment it acts in, and the action. The last four characters are the
**binding code**: the same code the agent was given, so the person can check they are
approving the request in front of them.

A request that would be refused anyway is refused **before** it is held: a webhook, hook,
log stream or SCIM URL the SSRF guard blocks, an id from another environment, a missing
owner, incomplete SSO settings. Nobody is asked to approve something that cannot run.

They can also approve on **AI agents › Approvals** in the environment console, which shows
the action, its danger, its arguments (secrets hidden) and the code. Approving a critical
action there asks for their password first. Any administrator of the environment can
**deny** a request; only the person it waits for can approve it.

Key owners' approvals are filed at the platform root, so their phone must be enrolled
there; a signed-in person's are filed in the environment they signed in to. See
[Trusted devices](trusted-devices.md).

## Handle it in code: REST

A held call answers `202 Accepted` with a `Retry-After` header:

```json
{
  "error": "approval_required",
  "message": "This action needs a person's approval. Approve it on the device that owns this credential, then repeat the request with the approval id.",
  "approval": {
    "id": "01K…",
    "status": "pending",
    "binding_code": "K7Q2",
    "expires_at": "2026-10-08T12:05:00+00:00",
    "poll_url": "https://<environment-host>/api/v1/action-approvals/01K…"
  }
}
```

1. **Show the person the binding code.** Print it in your CLI, post it in the chat the
   agent is working in, or whatever fits.
2. **Poll `poll_url`** no faster than `Retry-After` seconds. It answers
   `{"data": {"id": "…", "status": "pending"}}`, needs no scope, and only shows the
   credential its own approvals. The path depends on where the call was made:
   `/api/v1/action-approvals/{id}` on an environment, `/api/v1/workspace/…`,
   `/api/v1/me/…` or `/api/v1/platform/…` at the platform root.
3. **When `status` is `approved`, repeat the request** with the header
   `Cbox-Approval: <id>`, the same body and the same credential. Send the same
   `Idempotency-Key` too: the held `202` was never stored, so the repeat runs, and any
   later retry with that key gets the stored answer back instead of running twice.

| `status` | Meaning |
|---|---|
| `pending` | Nobody has answered yet. |
| `approved` | Approved and not yet used. Repeat the request now. |
| `denied` | The person said no. Stop. |
| `expired` | Nobody answered in time, or the approval was not used in time. Repeat the request **without** the header to ask again. |
| `consumed` | Used; the action ran. |

A repeat that cannot be run is refused:

| `error` | HTTP | Why |
|---|---|---|
| `approval_pending` | 409 | Not approved yet. Keep polling. |
| `approval_denied` | 403 | The person denied it. |
| `approval_expired` | 403 | It lapsed. Ask again. |
| `approval_mismatch` | 409 | Already used, or given for a different request. |
| `approval_invalid` | 403 | No approval with that id belongs to this credential. |

**An approval is for exactly one call**: the same action, the same validated input and
the same credential. Change one argument and it no longer matches. It can be spent once,
and it lapses after five minutes by default (`CBOX_ID_CIBA_TTL_SECONDS`, at most 15). A
`Cbox-Approval` header on a call that does not need approval is ignored.

```bash
# The first try is held
curl -i -X POST https://<environment-host>/api/v1/apps/$APP/secrets \
  -H "Authorization: Bearer $CBOX_ID_KEY" \
  -H "Idempotency-Key: rotate-2026-10-08"

# …the person approves code K7Q2; poll_url says "approved"…

curl -X POST https://<environment-host>/api/v1/apps/$APP/secrets \
  -H "Authorization: Bearer $CBOX_ID_KEY" \
  -H "Idempotency-Key: rotate-2026-10-08" \
  -H "Cbox-Approval: 01K…"
```

## Handle it in code: MCP

Over MCP a held call is not an error. The tool answers with:

```json
{
  "status": "approval_pending",
  "approval": { "id": "01K…", "binding_code": "K7Q2", "expires_at": "…" },
  "next": "Tell the person to approve the request showing code K7Q2 on their Cbox ID app. Poll `approval_status` with this approval id; once it is `approved`, call apps_secrets_rotate again with exactly the same arguments plus `approval_id` (and the same `idempotency_key`, if you sent one)."
}
```

The agent tells the person the code, polls the `approval_status` tool with `approval_id`
until it says `approved`, then calls the same tool again with the same arguments plus
`approval_id`. The server's instructions already tell MCP clients to do this. The
refusals are the same codes as REST. See [Agents and MCP](agents-and-mcp.md#critical-actions-wait-for-you).

## What is recorded

Every [Audit log](activity-log.md) entry the approved action writes carries the approval's
id and **who approved it**, so the row names both the key and the person who said yes.
An approval or denial given in the console is recorded on the workspace's Audit log as
`organization.action_approval_approved` or `organization.action_approval_denied`; one
given on the phone as `approval.approved` or `approval.denied`.

## Related

- [Approvals](../core-concepts/approvals.md) — the concept, and the other kind of approval.
- [Approvals](agent-approvals.md) — the approver's side.
- [Trusted devices](trusted-devices.md) — the phone that answers.
- [Agents and MCP](agents-and-mcp.md) — connecting the agent that gets held.
- [API keys](keys.md) — creating and revoking the keys a policy belongs to.
- [Keys and tokens](../core-concepts/keys-and-tokens.md) — which credential acts as whom.
