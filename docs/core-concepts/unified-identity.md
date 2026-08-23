---
title: Unified identity
weight: 30
description: Why a customer's people are ordinary subjects in the platform-root environment instead of a second, parallel credential store.
---

# Unified identity

A customer's people are ordinary **subjects** in the platform-root environment
(`is_default` — "tenant 1"), and the customer itself is an **organization** in that
environment.

> **This page is a design record, and its story finished.** It was written while the
> account plane still existed and was being re-pointed at the subject stack — so it argues
> for a change, names what it deliberately kept, and lists what had not moved yet. All of
> it has moved: the account row, its member table, its role enum and its session are gone,
> and a customer is an organization whose people hold memberships of it. What remains
> worth reading is WHY, because the same argument is the reason not to grow a second
> credential store again. Where the text below says a thing "stays" or "does not go away",
> read it as what was true at the time; the current shape is
> [customers, projects & the platform plane](https://github.com/cboxdk/laravel-id/blob/main/docs/core-concepts/customers-and-projects.md)
> in the framework.

## The problem this solves

The platform authenticated people two different ways.

| | Tenant plane | Account plane (as it was) |
|---|---|---|
| Identity | `Identity\Models\User` (subject) | `Platform\Models\AccountMember` |
| Session bridge | `PlatformAuth` | `AccountAuth` — a full parallel implementation (later: no session of its own) |
| Sign-in | identifier-first, home-realm discovery | flat email + password |
| SSO | `Federation\Models\Connection` | none |
| Passkeys, MFA, magic links | yes | none |
| Password policy (`AuthPolicies`) | enforced | not enforced |
| Administrative password reset | yes | partial |

Everything in the right-hand column that says "none" is a feature the left-hand column
already has. Adding SSO to the management plane under the old model meant building
account-scoped connections, federated-identity linkage for `AccountMember`, and
home-realm discovery over account domains — and then doing the same again for passkeys,
MFA and the password policy. The identity stack would exist twice.

Self-hosted deployments already demonstrated the simpler shape: with no
`environments.base_domains` configured there is **no account door at all**. One host,
subject authentication only. The dual path existed solely to serve multi-account SaaS.

## The model

```
Operator plane            platform operators (unchanged — sits above account)
   │
Platform-root environment ("tenant 1", is_default)
   │
   ├── Organization "Acme"   ← an ACCOUNT
   │     └── Members          ← former AccountMembers, now ordinary subjects
   │
   └── Organization "Rival"  ← another ACCOUNT
```

- **Account** — unchanged in purpose: owns projects, environment allowance, plan,
  billing. Now paired 1:1 with an organization in tenant 1, which is where its people
  live.
- **Account member** — a subject in tenant 1 with a membership in the account's
  organization. Authenticates through the ordinary subject door.
- **Account role** — retained as a typed capability enum on the membership
  (`AccountRole`: owner / admin / developer / viewer / billing). It gates five
  capabilities across seven call sites; keeping it typed is less churn than dissolving it
  into RBAC, and keeps `canManageEnvironments()` and friends exhaustive.
- **Environment access** (`all_environments`, per-environment grants) — resource access
  scoped to the account's organization.

## What this buys

- **Account-layer SSO is an ordinary `Connection`** on the account's organization. No new
  framework code.
- Passkeys, MFA, magic links, the password policy and administrative password assignment
  all apply to account members automatically.
- **One sign-in door.** Identifier-first, then password or an SSO redirect, for everyone.
- **Self-hosted and SaaS converge.** Self-hosted becomes "tenant 1 is the only tenant"
  rather than a separate shape with a plane switched off.
- The IdP authenticates its own operators with its own product, so its own sign-in path
  is exercised continuously.

## The risk, stated plainly

`EnvironmentAdminAuth` documents the boundary this removes:

> the tenant admin is an account-layer identity, NOT a subject inside the environment

Making account members subjects widens the blast radius of a tenant-1 compromise: the
control plane's people now authenticate through an environment's auth configuration.

Two things make that acceptable rather than merely tolerable:

1. **The bridge already exists.** `EnvironmentAdminAuth` already carries account identity
   into environment administration through a platform-signed handoff. The separation was
   never absolute — it was a separation of *credential store*, not of *authority*.
2. **Policy cuts the right way.** Tenant 1 can hold a stricter
   [authentication policy](../security/_index.md) than any customer tenant — MFA
   required, SSO required — and the policy engine's tighten-only semantics mean no
   organization override can weaken it.

What must **not** regress: the handoff's watertight anti-bleed anchor. A session names
exactly one environment id and it is re-checked against the request's host-resolved
environment on every resolve, so a session minted for one environment administers nowhere
else — not even another environment the same person is entitled to administer, because
each one is authorized by its own one-time handoff. That property is independent of where
the identity comes from, and it survived the collapse of the admin session into the
subject session precisely because it is not an identity: it is a *selection*, of the same
kind as the console's organization picker and an operator's target environment.

## Decisions taken

- **One organization per account**, rather than modelling every account member directly
  in tenant 1 without an organization. Keeps `Account` meaningful, gives a natural home
  for account SSO connections and domain verification, and maps 1:1 so there is no
  ambiguity about which account a member belongs to.
- **`AccountRole` stays a typed enum** on the membership rather than becoming RBAC roles.
  Five capabilities, seven call sites — the churn of dissolving it is not repaid.
  *(It did not survive: with one row there is one role vocabulary, `MembershipRole`.)*
- **Clean cut, no data migration.** There are no external consumers, so the platform-root
  environment and its accounts are rebuilt rather than migrated. This is the one decision
  that stops being available the moment someone else runs this software.

## Consequences

- `AccountAuth` stops being a credential store and, in a second pass, stops being a
  session at all. The password is verified against the member's subject; the SSO mandate
  is `PlatformAuth::localSignInAllowedFor()`, the same method the tenant door calls; an
  administratively-issued temporary password expires on both doors alike.

  The account session was kept distinct at first, on the reasoning that the account host
  must not mint a credential for a plane it does not serve. That reasoning was about the
  HOST, and the host bulkheads (`plane:console` / `plane:issuer`) are what enforce it —
  so the distinct session bought nothing and cost every seam between the two stores: an
  operator with no membership could not sign in at all, a gate that asked the wrong store
  looped silently, and three separate places had to be asked "who is this?". There is one
  session. `AccountAuth` was the management plane's *view* of it, and membership a lookup
  off it — and once the view was all that remained, it was deleted too: `CurrentUser`
  already answered who is acting, and the middleware already refused an organization the
  subject is not a member of.

- The same collapse reaches `EnvironmentAdminAuth`. It held the administering subject's
  id under a key of its own, which was the same duplication; an admin session is now the
  ordinary subject session plus the environment anchor above. A consequence worth having:
  revoking a person's sessions — which a password reset now does — ends their admin
  console too, which a session assembled out of a raw id never could.
- The plane split stops being about *which credential store* and becomes purely about
  *which host serves which surface*. `PlaneResolver` answers each of those questions once,
  for both the route gate and the post-authentication landings, so the two can never
  disagree about where a login is allowed to land. There are four such questions, not one:
  the management plane (`plane:console`, the root alone), the console (`plane:console`, every
  host — the root is a tenant and its subjects sign in there), the issuer surface
  (`plane:issuer`, never the root) and the environment-admin door (`plane:environment`,
  never the root). The console and the issuer surface were a single plane named `subject`
  for a while, which is why the root had no `/login` at all.
- `AccountProvisioner` creates the account's organization in tenant 1 alongside the
  account, and the install flow bootstraps tenant 1 and the platform organization before
  the first account exists.
- The workspace sign-in becomes the subject sign-in, so it inherits identifier-first
  discovery, SSO, passkeys and magic links with no additional work.

## What is still on the member, not the subject

Two things deliberately did NOT move in the cutover, because moving them would have
weakened something while the rest was being unified:

- **Account-plane MFA and passkeys** (`AccountMemberMfa`, `AccountPasskeys`) still hang
  off the account member. Routing the workspace login at subject MFA instead would have
  orphaned every factor a member had already enrolled — a member with TOTP would stop
  being challenged for it. The account factor is therefore still enforced, and it is
  enforced on the NEW ways in too: SSO and magic-link landings hold a member with a
  confirmed factor at the challenge rather than treating a strong first factor as
  permission to skip the second one. Folding these onto the subject is a later batch,
  and it is a migration, not a switch.
- **The member's own password column.** It is still written, but it is read in exactly
  one situation: a member created before the deployment had a platform root, where there
  was nowhere for the subject to live. Everything else asks the subject.
