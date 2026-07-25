---
title: Unified account identity
weight: 30
description: Why account members become ordinary subjects in the platform-root environment instead of a second, parallel credential store.
---

# Unified account identity

Account members stop being a separate credential store and become ordinary **subjects**
in the platform-root environment (`is_default` — "tenant 1"). Each **account** is
represented as an **organization** inside that environment.

The account itself does not go away: it remains the ownership and billing aggregate that
owns projects, holds the environment allowance, and anchors the plan. What it loses is
its own parallel way of authenticating people.

## The problem this solves

The platform authenticated people two different ways.

| | Tenant plane | Account plane (before) |
|---|---|---|
| Identity | `Identity\Models\User` (subject) | `Platform\Models\AccountMember` |
| Session bridge | `PlatformAuth` | `AccountAuth` — a full parallel implementation |
| Sign-in | identifier-first, home-realm discovery | flat email + password |
| SSO | `Federation\Models\Connection` | none |
| Passkeys, MFA, magic links | yes | none |
| Password policy (`AuthPolicies`) | enforced | not enforced |
| Administrative password reset | yes | partial |

Everything in the right-hand column that says "none" is a feature the left-hand column
already has. Adding SSO to the account plane under the old model meant building
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

What must **not** regress: the handoff's watertight anti-bleed binding. A session is
bound to exactly one environment id and re-checked against the request's host-resolved
environment on every resolve, so a session minted for one environment authenticates
nowhere else. That property is independent of where the identity comes from, and its
tests must keep passing through the rework.

## Decisions taken

- **One organization per account**, rather than modelling every account member directly
  in tenant 1 without an organization. Keeps `Account` meaningful, gives a natural home
  for account SSO connections and domain verification, and maps 1:1 so there is no
  ambiguity about which account a member belongs to.
- **`AccountRole` stays a typed enum** on the membership rather than becoming RBAC roles.
  Five capabilities, seven call sites — the churn of dissolving it is not repaid.
- **Clean cut, no data migration.** There are no external consumers, so the platform-root
  environment and its accounts are rebuilt rather than migrated. This is the one decision
  that stops being available the moment someone else runs this software.

## Consequences

- `AccountAuth` stops being a credential store and becomes a session bridge only. The
  password is verified against the member's subject; the SSO mandate is
  `PlatformAuth::passwordLoginAllowedFor()`, the same method the tenant door calls; an
  administratively-issued temporary password expires on both doors alike. The account
  *session* stays distinct from the subject session — the account host must not mint a
  credential for a plane it does not serve — but nothing about *how you prove who you
  are* is implemented twice any more.
- The `plane:account` / `plane:subject` split stops being about *which credential store*
  and becomes purely about *which host resolves to which environment*. `PlaneResolver`
  answers that question once, for both the route gate and the post-authentication
  landings, so the two can never disagree about where a login is allowed to land.
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
