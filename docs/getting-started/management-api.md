---
title: Run your tenancy from your backend
weight: 22
description: The environment management API — create a customer's team with its owner, add members, invite with roles, grant roles (in one organization or everywhere for staff), register apps and APIs, revoke customer API keys and start support sessions, all with one management key.
---

# Run your tenancy from your backend

Your app has customers, each with a team. Cbox ID holds those teams as **organizations**
in your environment. The **environment management API** lets your own backend create and
run them: a team with its owner, its members, invitations that carry your app's roles,
role grants, and the rest of what a multi-tenant app needs.

The full contract is the OpenAPI document your environment serves at
`https://{your-environment-host}/api/v1/environment/openapi.yaml` (also linked as
**API reference** on the Keys page). This page is the tour.

## The key

Create a **management key** (`cbid_env_…`) on the Keys page, in the workspace console or
in the environment's own console ([Keys](../guides/keys.md)). It works on **one
environment's host** and nowhere else. Give it the scopes the job needs and no more:

| Scope | Lets the key |
|---|---|
| `organizations:read` / `:write` | list and read organizations / create, rename, archive, transfer ownership |
| `users:read` / `:write` | list and read users / create and deactivate them |
| `members:read` / `:write` | list an organization's members / add, re-tier and remove them |
| `invitations:read` / `:write` | list pending invitations / send, re-send and withdraw them |
| `roles:read` / `:write` | list roles and who holds them / grant and take them back |
| `apps:read` / `:write` | list apps and export blueprints / register apps |
| `apis:read` / `:write` | list APIs / register, change and delete them |
| `api_keys:read` / `:write` | list customers' API keys / revoke them |
| `support:write` | start a support session |

Send it as `Authorization: Bearer cbid_env_…` to `https://{your-environment-host}/api/v1/…`.
A key without the route's scope gets `403` with the missing scope named.

## A customer signs up

```http
POST /api/v1/users
{ "email": "ada@acme.example", "name": "Ada" }

POST /api/v1/organizations
{ "name": "Acme", "slug": "acme", "owner_user_id": "<ada's id>" }
```

The organization and its owner are made in one transaction. Without `owner_user_id` it
starts with no owner; give it one later with `POST /organizations/{id}/transfer-ownership`.
`parent_id` places it under another organization.

`slug` is optional — left out, it is derived from the name and made unique. **Send your
own if you retry**: a retried create then answers `422 slug_taken` instead of making a
second organization. There is no `Idempotency-Key` header; grants and deletes are
idempotent by design, and adding a member who already holds the same role answers `200`.

## The team

```http
POST   /api/v1/organizations/{id}/members            { "user_id": "…", "role": "member" }
PATCH  /api/v1/organizations/{id}/members/{userId}   { "role": "admin" }
DELETE /api/v1/organizations/{id}/members/{userId}
POST   /api/v1/organizations/{id}/transfer-ownership { "user_id": "…" }
```

A member's `role` is the built-in tier — `admin` or `member`. **Owner is never assigned**:
ownership moves with `transfer-ownership`, and the previous owner stays on as an admin.
Removing or demoting the only owner is refused with `409 last_owner`.

## Invitations that carry your app's roles

```http
POST /api/v1/organizations/{id}/invitations
{
  "email": "grace@acme.example",
  "role": "member",
  "roles": ["viewer"],
  "client_id": "<your app's client id>",
  "return_to": "https://app.example/welcome",
  "inviter_name": "Ada at Acme"
}
```

- `roles` are your app's manifest roles, by **key** when `client_id` names your app (or by
  id). They are granted the moment the invitation is accepted.
- `return_to` sends the person back into your app after they accept. It must be on one of
  your app's registered redirect-URI origins, and it is checked again when they accept.
- The person gets a mail, opens a page that says who invited them to what, and presses
  **Accept**. Opening the link does not accept it — mail scanners open every link.

Re-send with `POST …/invitations/{invitationId}/resend` (a fresh link, and a new
invitation id — the old one stops working) and withdraw with `DELETE`.

## Roles, and your staff

```http
GET    /api/v1/roles?client_id=<your app>
PUT    /api/v1/organizations/{id}/members/{userId}/roles/viewer?client_id=<your app>
DELETE /api/v1/organizations/{id}/members/{userId}/roles/viewer?client_id=<your app>
PUT    /api/v1/users/{id}/environment-roles/support?client_id=<your app>
```

A role is named by id, or by your manifest **key** with `?client_id=`.

**This key acts with the environment's authority.** It is your backend, not a customer's
administrator, so it can grant a **staff role** — one your manifest marks
`"tenant_assignable": false`, such as "Support" — inside one organization, or everywhere
with `/users/{id}/environment-roles/…`. An organization's own administrators still cannot:
their console, their invitations and their directory mappings refuse staff roles, and an
invitation from this API refuses them too (`422 role_not_assignable`) — grant a staff role
after the person has joined.

Every grant is checked against the environment's [role conflicts](../guides/role-conflicts.md)
(`409 role_conflict`).

## Apps and APIs

`POST /api/v1/apps` registers an app. Send the short form (`name`, `type`: `web`, `spa`,
`cli`, `service` or `agent`), or a **blueprint**: `GET /api/v1/apps/{id}/blueprint` in
staging, then `POST /api/v1/apps` with it as `blueprint` — plus production's
`redirect_uris` — in production. The response carries the new `client_secret` **once**.

`/api/v1/apis` registers an API (resource server): its `identifier` becomes the token's
`aud`, and its scopes are unique across the environment. `PATCH` takes the complete scope
set. Only the environment registers APIs; no customer surface can.

## Your customers' API keys

When your API accepts keys your customers make (`tax_live_…`), your API verifies each one
with `POST /oauth/api-keys/verify`, authenticated as your app, and gets back the holder,
organization, tier and permissions — or `{"active": false}`. From the management API,
`GET /api/v1/organizations/{id}/api-keys` lists an organization's keys and
`DELETE /api/v1/api-keys/{id}` revokes one.

## Support sessions

```http
POST /api/v1/support-sessions
{
  "actor_user_id": "<your support agent>",
  "user_id": "<the customer's user>",
  "organization_id": "…",
  "client_id": "<your app>",
  "reason": "Ticket 4411",
  "ttl_minutes": 30,
  "redirect_uri": "https://app.example/callback",
  "code_challenge": "<PKCE S256 challenge>"
}
```

Your agent must hold your app's `support:impersonate` permission **everywhere** (a staff
role granted with `environment-roles`). The response carries the first authorization
`code`; your app redeems it at `/oauth/token` with the PKCE verifier. Every token names the
agent in `act`, there is never a refresh token, and nothing lives past the session (at
most an hour). The customer's activity log and webhooks (`support_session.started`) say
who acted and why.

## Errors

Every failure is `{ "error": "<code>", "message": "<sentence>" }`; a validation failure
adds a field-keyed `errors` map. Switch on `error`: `not_found`, `validation_failed`,
`slug_taken`, `user_not_found`, `already_member`, `last_owner`, `not_a_member`,
`already_owner`, `role_not_assignable`, `role_conflict`, `not_pending`, `too_soon`,
`mail_failed`, `invalid_api`, `not_permitted`, and the rest listed per operation in the
OpenAPI document.

## The activity log

Everything the key does is recorded with the key as the actor (`actor_type: service`),
shown as *Management key "…"* in the console, and every entry it caused carries
`context.environment_api_key`. Where the platform records a person as the actor — the
outgoing owner of a transferred organization — the key is on the entry beside them.

## Related

- [Keys](../guides/keys.md) — creating, expiring and revoking the management key.
- [Members and invitations](../guides/members.md) — the same operations in the console.
- [Integrate your app](integrate-your-app.md) — the app side: sign-in and tokens.
