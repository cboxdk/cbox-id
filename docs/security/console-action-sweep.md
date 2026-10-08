---
title: Cross-tenant id sweep
weight: 30
description: Every action that takes an id answers 404 to somebody else's — how that is guaranteed, how it is tested, and what the sweep has found.
---

# Cross-tenant id sweep

An id in a URL is the caller's claim, never a fact. Every change and every read the
platform offers is an **action** (`app/Actions`, run by `App\Platform\Actions\ActionRunner`),
and the same action serves the console, the REST API and MCP. So the question "can somebody
reach another tenant's thing by naming its id?" is asked once, of the actions, rather than
once per controller, endpoint and tool.

The answer the platform gives is **404** — the answer an id that never existed gets. Not
403, which confirms the thing exists and is somebody else's. Not 200, which is the breach.

## The guarantee

Three fences, from the outside in. Each one alone answers a foreign id with nothing.

1. **The environment.** Every environment-owned model carries the framework's
   `EnvironmentScope`: a query in one environment cannot see another's rows. It is
   deny-by-default — with no environment resolved it matches nothing — and it is the hard
   outer boundary. Another environment's id therefore resolves to nothing in the lookup
   itself, and the action answers `ActionRefused::notFound()`.
2. **The organization, for a confined principal.** A principal says whether it acts with
   the environment's authority or inside one organization:
   `Principal::confinedToOrganization()`. The organization console, a person's signed-in
   token (`DelegatedTokenPrincipal`) and an Admin Portal session are confined; a management
   key and the environment console are not. Every action that reaches into organizations asks
   that one method — `OrganizationTarget`, `IntegrationReach`, `EnterpriseReach`,
   `RoleAuthority`, `TenantRoster`, the app and audit-log lookups — never the class of the
   principal, so a new confined principal is confined everywhere the day it says so.
   Another organization's id answers exactly like an unknown one: 404 when the URL names
   it, the same `organization_not_found` field error when the body does. Only the
   *missing* organization ("the environment's default every tenant inherits") is a 403,
   because there is no id in that request to keep secret.
3. **The parent, for a nested id.** `/apps/{id}/secrets/{secret_id}`,
   `/organizations/{organization_id}/members/{user_id}`, `/roles/{id}/permissions/{permission_id}`:
   the child is looked up *inside* the parent, in the query, so the caller's own parent with
   somebody else's child is not found either.

The lookup carries the fence in the **query** — `whereKey($id)->where('organization_id', …)`
or a builder that already has it — rather than fetching by id and comparing afterwards in
PHP. A fetch-then-compare leaks through timing and error shape even when it blocks the
write, and it is only as safe as the comparison somebody remembered to write.

## The test that holds it

`tests/Feature/Actions/CrossTenantIdSweepTest.php` walks **the registry**, not a list. Every
action on every plane whose path has an id is swept, so an action added tomorrow is swept
the day it lands:

- **Fixtures are built the way a customer builds them** — through the create actions
  (`tests/Support/CrossTenantSweep.php::world()`), one of every resource an action names by
  id. A world is built for the caller and another for the stranger.
- **Each id field is swapped for the stranger's on its own**, the others left the caller's,
  and then all of them at once. `/organizations/{mine}/members/{theirs}` is the request that
  finds a lookup fenced on its parent and loose on its child.
- **A control runs after each action** with every id the caller's own, and must *not* answer
  404 — otherwise a fixture pointing at nothing would make every 404 a pass for the wrong
  reason. Each action runs in a savepoint rolled back afterwards, so what one deletes the
  next still has.
- **A path field the sweep cannot map fails the test by name**, so a new kind of id cannot
  slip past it.

The passes:

| Pass | Caller | Stranger |
|---|---|---|
| Environment plane | an environment key holding every scope | the same world in another environment |
| Environment plane, confined | the organization console, a signed-in token and an Admin Portal session, each confined to one organization | another organization in the same environment |
| Environment plane, over REST | an environment key, every read | another environment — checks the wire renders the API's `not_found` envelope |
| Workspace plane | a workspace key | another workspace's environments, projects, members, invitations and keys |
| Account plane | a person's token | another person's sessions, passkeys, devices and app keys, and an organization they do not belong to |
| Platform plane | an operator | an organization named under an environment it is not in |

### What is not swept, and why

Listed in the test (`SWEEP_UNSWEPT`), checked for staleness, each with its reason:

- **Names, not ids.** `PUT /apis/{id}/scopes/{key}` and `PUT /audit-logs/schemas/{action}`
  *define* the thing their name names — there is no foreign one. A scope key under an API,
  a vault grant named by the client it is for, a social provider (`github`) and an app's
  `client_id` on a person's own account are names too: each sits under a parent the URL
  already fences, or is keyed to the person acting. The parent is swept; the name is never
  swapped (`CrossTenantSweep::NAMES`).
- **The platform plane's single ids.** An operator administers every environment,
  workspace and operator of the deployment; nothing there is somebody else's to them. What
  *can* be foreign is a pair — an organization named under an environment it is not in —
  and that is swept.
- **A signed-in token's critical actions.** They wait for the person's approval before they
  run, and so before any lookup; the organization console runs the same lookup unheld and is
  swept.

## Findings

The first registry-driven run (October 2026) found, and this change fixed:

1. **A person's signed-in token held the environment's authority over roles and
   permissions.** `RoleAuthority::of()` asked "is this the organization console?" instead of
   `confinedToOrganization()`, so an organization owner's token could update, re-permission
   and delete every organization's roles and permissions in the environment. It now asks the
   principal. **Cross-tenant write — the one finding of that severity.**
2. **A confined principal got 403 for another organization's id** across invitations,
   members, the organization itself, portal links and the sign-in policy
   (`OrganizationTarget::check()`). It now answers as for an unknown organization.
3. **Another organization's role answered 422 "not offered"** to a confined principal on
   `members.roles.grant` / `members.roles.revoke`, confirming a peer's private role exists. Now 404.
4. **Removing an unknown permission from a role answered 200** (`roles.permissions.revoke`),
   and for a confined principal any permission in the environment was looked up. It is now
   looked up among the permissions the caller can see, and an unknown one is a 404.
5. **Another app's client secret answered 422 `secret_not_live`** on
   `apps.secrets.revoke`. The secret is now looked up among the app's own first: 404.
6. **An organization the person is not a member of answered 422 `not_a_member`** on
   `account.api_keys.create`. Now 404.
7. **A workspace key got 403 `owner_only` for another workspace's member** on
   `team.transfer_ownership`: the refusal ran before the lookup. The member is resolved
   first now, so a foreign one is a 404 and the key's own member still gets the 403.

`EnvironmentDomainController` also stopped picking the selected environment out of a loaded
list (`->firstWhere()`); it is resolved in a query fenced on the environments the person may
reach. `PortalSsoController`'s remaining `firstWhere` selects a static setup guide by
provider name, not a record by id.

## Earlier sweeps

Before the action layer, this document was a hand-run inventory of console controller
methods (and before that, of Volt component methods), graded scoped / weak / unscoped. Every
finding from those runs was closed — `manageableTarget()` resolving a workspace member
across every account, `Connections::byId()` settling ownership in PHP,
`GroupRoleMappings::map()` committing a foreign role id, the environment approvals console
approving as the wrong subject. They are in this file's history. What replaced the
inventory is the test above: a list somebody re-runs goes stale; a test that walks the
registry does not.

## Standing rule

An action that takes an id resolves it **inside** the caller's reach, in the query, and
answers a miss with `ActionRefused::notFound()`. A refusal that does not depend on the id —
"a key can never do this" — comes *after* the lookup, so a foreign id is a 404 there too.
