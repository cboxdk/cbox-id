---
title: Approvals
weight: 16
description: When a person has to say yes before something runs — a key's approval policy, critical actions from a delegated token, and apps or agents asking to act as somebody — and how the yes is given.
---

# Approvals

An **approval** is a person saying yes, on a device they hold, before something runs. Cbox
ID asks for one in two situations, and both end up on the same surfaces: the person's
trusted device (the Cbox Authenticator app) and **Approvals** in their console.

## 1. An action held for a person

A call to an [action](actions.md) is held when the credential running it says a person must
approve it first:

- **A key with an approval policy.** Whoever creates a secret key on **AI agents › Agents**
  (or with `keys.create`, its `require_approval` field) may set its **approval policy**:
  every action at or above a danger (`critical`, `destructive`, or every `write`), plus any
  actions named one by one. A key with no policy needs no approval; its scopes are its
  limit. A key minted *by* a key, organization keys included, inherits its parent's policy,
  so an agent cannot shed its supervision by minting itself a fresh key.
- **A token a person delegated.** Every `critical` action tried with a delegated token
  (an MCP sign-in, `cbox login`) waits for that person, whatever its scopes.

The person asked is the one behind the credential: the key's owner, or the person who
signed in. A key whose chain of minting ends in no person is refused rather than let
through.

What the caller sees:

| Door | Held | Then |
|---|---|---|
| REST | `202` with `error: approval_required` and `approval: { id, binding_code, expires_at, poll_url }` | poll `poll_url` (respect `Retry-After`), then repeat the same request with the same `Idempotency-Key` and a `Cbox-Approval: <id>` header |
| MCP | `status: approval_pending` with the same approval | poll the `approval_status` tool, then repeat the call with `approval_id` |
| CLI | prints the binding code and polls until it is decided | repeats the request with the same `Idempotency-Key` once approved |

**An approval is for exactly that call**: the same action, the same input and the same
credential. It runs once, cannot be spent on anything else, and lapses after a few minutes.
The **binding code** is shown both to the caller and on the approving device, so the person
can check they are approving the request in front of them.

## 2. An app or agent asking to act as somebody

An AI agent application, or any app registered for the backchannel flow (OpenID Connect
CIBA), can ask a person for permission to act as them when it cannot put a screen in front
of them: it runs on a server, in a terminal, in a chat. The person gets the request on
their device and on **Approvals**, with the app's name, what it asks to do and a binding
code. Nothing happens until they approve.

An environment's administrators see every pending request in the environment on **AI
agents › Approvals**, so one that looks like abuse can be denied.

## Where people approve

- **On a trusted device.** The Cbox Authenticator app answers approvals with the device's
  own key. See [Trusted devices](../guides/trusted-devices.md).
- **In the console.** **Approvals** lists the requests to act as you; approving there works
  too. It is the whole path for anyone without the app: see
  [Approvals without the mobile app](../guides/approvals-without-the-app.md) for what your
  agent has to tell them.

Every decision, approve or deny, is recorded on the [Audit log](../guides/activity-log.md).

## 3. A standing approval for a machine's deployments

Some changes are approved once and for all by the one person entitled to: an organization's
**owner** can say that one machine client's deployments to one non-production environment of
an app (Cbox Infra's CI deploys) run without a person's click. It is a **standing approval**,
off until the owner turns it on, and never possible for a production environment.

- **Granted by the owner only**, from the app, which calls
  [`organizations.standing_approvals.grant`](../reference/actions-environment.md#organizations.standing_approvals.grant)
  naming them; Cbox ID checks they are the organization's active owner, that the client is a
  machine client (client credentials) of the organization, and that the environment's kind is
  not `production`.
- **Used through the token exchange.** When that client proposes a deployment, the app
  exchanges the client's own token (RFC 8693) for an approval token bound to the plan
  (RFC 9396 `authorization_details`, type `cbox_infra_change`). It is the token a person's
  approval is, with the client as its subject and the entry marked
  `"approval": "standing_grant"` with the grant's id, the environment and its kind. Cbox ID
  checks the grant on every exchange; the app's engine checks the kind again before it runs.
- **Seen and turned off** under the organization's **Settings › Standing approvals** in the
  console, or with
  [`organizations.standing_approvals.revoke`](../reference/actions-environment.md#organizations.standing_approvals.revoke).
  From then on the client's deployments wait for a person again.

Every grant, every token issued under one and every revocation is on the
[Audit log](../guides/activity-log.md): `standing_approval.granted`, `standing_approval.used`,
`standing_approval.revoked`.

## Related

- [Step-up approvals](../guides/step-up-approvals.md): setting a key's approval policy, and handling `approval_required` in code.
- [Approvals](../guides/agent-approvals.md): the approver's side — what to check before saying yes.
- [Agents and MCP](../guides/agents-and-mcp.md#critical-actions-wait-for-you): the agent's side.
