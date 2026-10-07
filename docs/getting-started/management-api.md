---
title: Run your tenancy from your backend
weight: 22
description: The environment management API, and the workspace API above it — create a customer's team with its owner, add members, invite with roles, grant roles (in one organization or everywhere for staff), register apps and APIs, revoke customer API keys and start support sessions, all with one management key.
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
| `users:erase` | **critical** — erase a person for good (GDPR Art. 17); not implied by `users:write` |
| `members:read` / `:write` | list an organization's members / add, re-tier and remove them |
| `invitations:read` / `:write` | list pending invitations / send, re-send and withdraw them |
| `roles:read` / `:write` | list roles and who holds them / grant and take them back |
| `apps:read` / `:write` | list apps and export blueprints / register apps |
| `apis:read` / `:write` | list APIs / register, change and delete them |
| `api_keys:read` / `:write` | list member API keys / revoke them |
| `support:write` | start a support session |
| `webhooks:read` / `:write` | list webhook endpoints / register, repoint, pause, resume, re-key and delete them |
| `hooks:read` / `:write` | list inline hooks / register, pause, activate and remove them |
| `log_streams:read` / `:write` | list audit log streams / create, disable, resume and delete them |
| `events:read` | read the environment's domain events with a cursor |
| `audit:read` | read the environment's audit trail with a cursor |
| `signin:read` / `:write` | read / change sign-in rules, self-service sign-up, social providers and the legacy login |
| `frontend_keys:read` / `:write` | list publishable keys / create them, change their origins, revoke them |
| `saml_apps:read` / `:write` | list SAML applications / register, change and remove them |
| `branding:read` / `:write` | read / change the sign-in theme and white-label branding |
| `domains:read` / `:write` | read / add, verify and remove this environment's custom domain |

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
second organization. Grants and deletes are idempotent by design, and adding a member who
already holds the same role answers `200`.

Endpoints whose reference lists the `Idempotency-Key` header take one on a write. Send any
unique string (a UUID); a retry with the same key and the same body gets the first answer
back, marked `Idempotent-Replayed: true`, for 24 hours. The same key on a different body is
`422 idempotency_key_reused`. More endpoints take it as their areas move onto the shared
action layer.

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
`redirect_uris` — in production. The response carries the new `client_secret` **once**;
a retry with the same `Idempotency-Key` gets the same app back with `client_secret: null`.

Everything the console does to an app, the API does too — the console runs the same
actions. `{id}` is the app's id or its `client_id`:

| Endpoint | What it does |
|---|---|
| `GET /apps/{id}` | The app, with its settings. Never a secret. |
| `PATCH /apps/{id}` | Rename it, replace `redirect_uris` or `post_logout_redirect_uris`. What you leave out is unchanged. |
| `DELETE /apps/{id}` | Delete it and every secret it holds. |
| `PUT /apps/{id}/scopes` | The complete scope set (`{"scopes": [...]}`; `[]` removes them all), from its next token. |
| `GET /apps/{id}/secrets` | Its live secrets: ids, last characters, dates. |
| `POST /apps/{id}/secrets` | Rotate: a new `client_secret`, once. `grace_seconds` is required — how long the current ones keep working; `0` stops them now. |
| `DELETE /apps/{id}/secrets/{secret_id}` | Revoke one now. Never the last live one (`last_live_secret`) — rotate instead. |
| `PUT /apps/{id}/manifest` | Set or clear (`null`) `manifest_url`. Does not fetch it. |
| `POST /apps/{id}/manifest/sync` | Fetch the manifest now and sync its roles (`manifest_sync_failed` says why it could not). |
| `PUT /apps/{id}/settings/token-lifetime` | `access_token_ttl` in seconds, or `null` for the default. |
| `PUT /apps/{id}/settings/token-exchange` | `enabled`: RFC 8693 token exchange, confidential apps only. |
| `PUT /apps/{id}/settings/backchannel-logout` | `uri` (HTTPS) and `session_required`, or `null` to stop. |
| `PUT /apps/{id}/settings/api-key-prefix` | `prefix` (`tax_live`) lets the app's users create keys; `null` stops new ones. |

A management key cannot give an app `vault.manage` or `decisions:read`
(`422 scope_not_grantable`) — grant those in the console; an edit that keeps one an
administrator granted is fine. Copying an app into another environment
(`POST /apps/{id}/copy`) is the console's: a key belongs to one environment, so it
exports the blueprint here and registers it with the other environment's key.

`/api/v1/apis` registers an API (resource server): its `identifier` becomes the token's
`aud`, and its scopes are unique across the environment. `PATCH` takes the complete scope
set; `PUT /apis/{id}/scopes/{key}` adds or changes one scope and
`DELETE /apis/{id}/scopes/{key}` removes one, without resending the rest. Only the
environment registers APIs; no customer surface can.

## Member API keys

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
who acted and why. The response's `scopes` are exactly what the session's tokens carry:
once one belongs to a registered API the tokens are for that API, so a scope no API
registered (`apps.manifest`, say) is not among them. Scopes of two APIs are refused with
`422 invalid_target` before anything starts.

## Webhooks, hooks, log streams and the trail

`/api/v1/webhooks`, `/api/v1/hooks` and `/api/v1/log-streams` are the console's Webhooks,
Inline hooks and Log streaming pages. A new one names its owner out loud: an
`organization_id`, or `"environment_wide": true` for one that carries **every**
organization's traffic. Sending neither is `422 owner_required`.

```http
POST /api/v1/webhooks
Idempotency-Key: 3f0c…
{ "url": "https://app.example/hooks/cbox", "event_types": ["user.created"], "organization_id": "…" }
```

The answer carries the signing `secret` **once** — and so does
`POST /webhooks/{id}/rotate`, which replaces it at once. A retry with the same
`Idempotency-Key` gets the same answer with `"secret": null`: the secret is never kept for
replays. Lost it? Rotate. `PATCH /webhooks/{id}` repoints or resubscribes, `POST …/pause`
and `…/resume` stop and start deliveries, `DELETE` removes it. A hook is paused or
activated with `PATCH /hooks/{id}` `{"active": false}`, and a stream with
`PATCH /log-streams/{id}` `{"enabled": false}` — the state you want, so a retry is safe. A
URL that resolves to a private address is `422 unsafe_url`.

To read instead of being pushed: `GET /api/v1/events` lists the environment's domain
events — the facts webhooks deliver — oldest first. Keep the last `id` and pass it as
`after` to get only what is new; `types[]=user.created` narrows it. `GET /api/v1/audit-log`
reads the audit trail the same way, narrowed by `action`, `actor_type` or
`organization_id`. Both are strictly this environment's.
## Sign-in, branding and the domain

How people sign in is on the API too, each behind its own scope, and each the same action
the console runs — the same rules, refusals and activity-log entries.

- **Sign-in rules** (`signin:read` / `signin:write`): `GET /api/v1/sign-in/policy` reads the
  environment baseline, or with `?organization_id=` what governs one organization and
  whether it inherits. `PATCH` changes only the rules you send (`min_length`, `mfa`,
  `sso`, …) — at the baseline, or as an organization's override, which may only tighten
  it: a looser rule is `422 loosens_environment_baseline`, naming every floor.
  `DELETE /sign-in/policy/organizations/{id}` drops an override. Requiring SSO signs out
  every password session it governs.
- **Self-service sign-up**: `PUT /api/v1/sign-in/self-service-signup` with `enabled`.
- **Social sign-in**: `POST /api/v1/sign-in/social-providers` with `organization_id`,
  `provider` (`google`, `github`, `apple`, …), `client_id`, `client_secret` and the
  provider's `parameters`. The response gives the `callback_uri` to register with the
  provider; the secret is never returned. `DELETE /sign-in/social-providers/{id}` removes one.
- **Legacy login** (`signin:*`): `GET /api/v1/legacy-login`, then
  `POST /legacy-login/probe` with your own address, then `/approve` (or `/revoke`).
  Approving sends every not-yet-migrated person's password to the declared URL.
- **Frontend keys** (`frontend_keys:*`): `/api/v1/frontend-keys` creates publishable keys
  with their allowed `origins`; `PUT /frontend-keys/{id}/origins` replaces the list.
- **SAML apps** (`saml_apps:*`): `/api/v1/saml-apps` registers the applications people
  sign in to with their account here. The `certificate` is write-only (`has_certificate`).
  `organization_id` makes one organization's app: only its active members are signed in
  to it and everybody else is refused (`saml_idp.assertion_refused` on the audit trail).
  Null — the default, and what every app registered before this field was — is
  environment-wide: anybody with an account in the environment can sign in to it.
- **Erase a person** (`users:erase`): `POST /api/v1/users/{id}/erase` runs the GDPR Art. 17
  erasure in one transaction and returns its receipt — what each store removed, in
  numbers, with no personal data. `409 last_owner` for the only owner of an organization
  (transfer ownership first). See [Compliance › Erasure](../security/compliance.md#erasure-gdpr-art-17)
  for exactly what is erased and what is kept.
- **Branding** (`branding:*`): `PUT /api/v1/branding/appearance` (the hosted sign-in theme;
  an unreadable palette is refused) and `PUT /api/v1/branding/whitelabel` — the environment
  default, or one organization's with `organization_id`.
- **Custom domain** (`domains:*`): `POST /api/v1/domains` returns the DNS TXT record,
  `POST /domains/verify` promotes the domain once it is visible (`422 dns_not_propagated`
  until then), `DELETE /domains` removes it. Only ever the environment the key belongs to.
## The workspace API: stand an environment up

Everything above runs inside one environment, with that environment's key on its host.
**Above** your environments is your workspace — its projects, environments, team and keys —
and the **workspace API** at `/api/v1/workspace` runs it with a **workspace key**
(`cbid_ws_…`, Keys › Workspace keys in the console). Its contract is served at
`/api/v1/workspace/openapi.yaml`.

A workspace key carries a **role** (admin, developer, member or viewer), which bounds it
exactly as it bounds a person in the console, and optionally **scopes** that narrow it
further. A key with no scopes is bounded by its role alone.

| Scope | Lets the key | Role must be able to |
|---|---|---|
| `workspace:read` | read the workspace, its projects and environments | — |
| `projects:write` | create, rename, suspend and reactivate projects | manage environments |
| `environments:write` | create environments, optionally with a first key | manage environments |
| `team:read` | list members and pending invitations | read members |
| `team:write` | invite, re-role, scope and remove members | manage members |
| `keys:write` | mint and revoke environment keys and workspace keys | manage environments (and manage members, for workspace keys) |
| `settings:write` | rename the workspace | manage members |

This is enough for an agent holding one workspace key to stand a product up end to end:

```http
POST /api/v1/workspace/projects
{ "name": "Billing", "environment_limit": 2 }

POST /api/v1/workspace/environments
{
  "name": "Production",
  "project_id": "<the project's id>",
  "initial_key": { "name": "Bootstrap", "scopes": ["apps:write", "apis:write"] }
}
```

The second answer carries the environment — its `issuer` is its host — and its first
management key **once**, as `initial_key.token`. From there the agent uses that key on the
environment's host, exactly as this page describes: register apps and APIs, create
organizations. Retry either call with the same `Idempotency-Key` and you get the first
answer back; a replay never carries a key's value (`initial_key.token` is `null`), so a
caller that lost it revokes the key and mints another with
`POST /api/v1/workspace/environments/{id}/keys`.

A key can mint workspace keys (`POST /api/v1/workspace/keys`), but **never a wider one**:
its role at most, its scopes at most (inherited when you send none), expiring no later than
it does. Revoking a key revokes every key it minted. Handing the workspace to someone else
(`transfer-ownership`) is the owner's act, in the console; a key is refused with
`403 owner_only`. Everything a workspace key does is on the workspace's activity log with
the key as the actor.

## Errors

Every failure is `{ "error": "<code>", "message": "<sentence>" }`; a validation failure
adds a field-keyed `errors` map. Switch on `error`: `not_found`, `validation_failed`,
`slug_taken`, `user_not_found`, `already_member`, `last_owner`, `not_a_member`,
`already_owner`, `role_not_assignable`, `role_conflict`, `not_pending`, `too_soon`,
`mail_failed`, `invalid_api`, `not_permitted`, `owner_required`, `unsafe_url`, and the rest
listed per operation in the OpenAPI document.
`mail_failed`, `invalid_api`, `not_permitted`, `invalid_client_metadata`,
`scope_not_grantable`, `public_client`, `last_live_secret`, `secret_not_live`,
`api_key_prefix_taken`, `no_manifest_url`, `manifest_sync_failed`, and the rest listed per
operation in the OpenAPI document.

## The activity log

Everything the key does is recorded with the key as the actor (`actor_type: service`),
shown as *Management key "…"* in the console, and every entry it caused carries
`context.environment_api_key`. Where the platform records a person as the actor — the
outgoing owner of a transferred organization — the key is on the entry beside them.

## Related

- [Keys](../guides/keys.md) — creating, expiring and revoking the management key.
- [Webhooks](../guides/webhooks.md) — verifying what a registered endpoint receives.
- [Members and invitations](../guides/members.md) — the same operations in the console.
- [Integrate your app](integrate-your-app.md) — the app side: sign-in and tokens.
