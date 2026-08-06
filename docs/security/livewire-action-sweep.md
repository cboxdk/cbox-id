---
title: Livewire action ownership sweep
weight: 30
description: Every client-invokable console action that accepts an id, and the ownership predicate that scopes it.
---

# Livewire action ownership sweep

Livewire's public component methods are **directly invokable by the client**. Scoping the
list a component renders is therefore a display concern, not an authorization control — the
guarantee has to live in the query that mutates.

This inventory exists because that distinction was violated five times in materially
different modules, and no single reviewer found all five. It is a checklist to re-run, not
a statement that the code is finished — the re-run below found four more, and found that
the recipe had been pointed at a directory that no longer existed.

## How to re-run it

```bash
# Every public component action that accepts an id from the client.
python3 - <<'PY'
import re, glob
# BOTH trees. The app's components and the vendored modules' are the same kind of
# surface and reachable over the same /livewire/update endpoint; the earlier recipe
# looked only at the first, so nine module components were never swept at all.
patterns = [
    'resources/views/livewire/**/*.blade.php',
    'modules/*/resources/views/livewire/**/*.blade.php',
]
for f in sorted({f for p in patterns for f in glob.glob(p, recursive=True)}):
    s = open(f).read()
    for m in re.finditer(r'^\s*public function (\w+)\(([^)]*string \$[^)]*)\)', s, re.M):
        if m.group(1) in ('with','mount','render','rules','boot','updated'):
            continue
        print(f"{f}:{s[:m.start()].count(chr(10))+1}\t{m.group(1)}")
PY
```

For each hit, answer one question: **does the mutation carry an ownership predicate in the
query?** A pre-fetch `if (...->where('organization_id', $orgId)->exists())` check counts; a
comparison performed after an unscoped fetch is weaker (it leaks existence through timing
and error shape) and a bare id lookup fails outright.

## Result — 57 actions across 108 components

Swept at cbox-id `271d8af`, composing laravel-id **v0.87.2**. Stamp the commit as well as
the package version when you re-run: the count moves with any console refactor, and a bare
version told the next reader nothing about which tree it described.

| Plane | Actions | Scoping rule | Status |
|---|---|---|---|
| Subject / tenant (`livewire/*.blade.php`) | 11 | `CurrentUser::id()` for self-service, `CurrentUser::organizationId()` for anything org-owned, in the mutating query or passed to a service that applies it | 10 scoped, **1 weak** |
| Auth (`livewire/auth/**`) | 1 | `CurrentUser::id()` — the multi-account switcher acts only on accounts held by this browser | scoped |
| Shared console (`livewire/console/**`) | 11 | `ConsoleScope` decides the plane from the session and answers `requireOrganizationId()`; on the environment plane the picked org is re-validated against `availableOrganizations()` on **every** read | all scoped |
| Environment (`livewire/environment/**`) | 16 | env-admin is the operator *of that environment*, re-checked in `boot()` so it runs on every action; `BelongsToEnvironment` is the outer boundary, org predicates inside it | all scoped |
| Workspace / account (`livewire/workspace/**`) | 8 | `ConsoleScope::organizationId()`. **These models have no global scope** — they sit above tenancy — so the predicate has to be explicit | 5 scoped, **3 weak** |
| Platform (`livewire/platform/**`) | 6 | deployment authority — unscoped lookups are intentional; every component's `boot()` is `abort_unless($scope->isPlatformOperator(), 404)` | by design |
| Portal (`livewire/portal/**`) | 3 | single-use scoped session + `guardFeature()` + `ownedDomain()`; the org id comes from the portal session, never from input | all scoped |
| Modules (`modules/*/…/livewire/**`) | 1 | `where('subject_id', CurrentUser::id())` — a device is removed by its owner or by nobody | scoped |

The shared `livewire/console/**` components are served on the subject plane **and** the
environment plane; which one they are on is not a property of the file, so the scope has to
be asked rather than assumed. That is what `ConsoleScope` is. Note that
`organizationId()` **throws** rather than returning null when the membership is gone — a
null would have flowed into a `when($id !== null, …)` and silently widened the query.

### Deliberately unscoped

The platform section resolves records without a tenant predicate (`Environment::find`,
`Organization::find`, `PlatformOperator::find`). That is the point of it — these are the
deployment's own pages. Its protection is `AuthenticateOperator`, registered as persistent
middleware (see `PersistentMiddlewareTest`) so it re-runs on every action rather than only
on the initial page load, plus the per-component `boot()` gate. The gate answers **404**,
not 403: whether this deployment has staff pages at all is not something to confirm.

It was `livewire/operator/**` when this document was first written. The directory is
`livewire/platform/**` now — the staff pages became a section of the one console — so the
recipe above matched nothing there for as long as the old path stood in this table.

### The asymmetry between the two hook / governance / vault consoles

The tenant-facing consoles pass the **acting org**; the environment consoles pass the
**record's own org**. That is not an oversight: the env-admin is the operator above the
orgs in that environment, and `EnvironmentScope` remains the boundary that matters there.
Both call sites carry a comment saying so, because it reads like a bug otherwise.

## Findings — the `271d8af` run

Four actions grade **weak**: an unscoped fetch followed by an in-PHP comparison. Nothing
grades unscoped, and no cross-tenant write is reachable. They are open.

1. **`workspace/members.blade.php` — `changeRole`, `removeMember`, `manageAccess`.** All
   three go through `manageableTarget()`, whose `AccountMembers::find()` is
   `AccountMember::query()->whereKey($id)->first()`. `AccountMember` has **no global scope
   at all** — it sits above tenancy — so that lookup spans every account on the install, and
   the only fence is `$target->account_id !== $current->account_id` in PHP. The services
   behind them take no account id either: `setRole()`, `remove()` and
   `setEnvironmentAccess()` all re-find by primary key alone. So the in-PHP compare is not
   defence in depth, it is the whole defence, on a set of actions that includes a
   destructive one.

   `makeOwner()` in the same file is the shape to copy: `transferOwnership()` carries
   `->where('account_id', $accountId)` into the query, which is why it grades scoped despite
   an identical-looking `find()` above it.

   `workspace/home.blade.php::addEnvironment()` has the same pattern against `Project`,
   which is likewise globally unscoped. It takes no id from the client so the recipe does
   not surface it, which is worth knowing: the recipe finds a shape, not every instance of
   the risk.

2. **`social-providers.blade.php` — `disable`.** `Connections::byId()` is environment-scoped
   but not organization-scoped, and ownership is settled afterwards in PHP. The delete is
   then issued on the already-hydrated model, so no ownership predicate ever reaches the
   database. The write is blocked; what leaks is that a foreign id inside the same
   environment is distinguishable from a missing one by the work done. `Connections` has no
   org-scoped fetch verb — the fix is to resolve with one, as
   `console/connections/index.blade.php::ownedDomain()` already does.

Two more are borderline and grade scoped, recorded so the next run does not re-litigate
them: `console/governance/show.blade.php`'s `certify`/`revoke` establish ownership with a
**query** on the campaign rather than in PHP (the residue is error shape only — a foreign
item and an unknown one throw different exceptions), and `workspace/home.blade.php`'s
`startCreate` mutates nothing a client could not already set.

## Findings — the first run

Two, both fixed in the same change as this document:

1. **`GroupRoleMappings::map()` accepted a foreign role id.** `RoleService::assign()` blocked
   the escalation, but only during reconciliation — after the mapping row was committed and
   outside any transaction. A foreign id left a poison pill: the write stuck, reconciliation
   threw, and every later reconcile of that group threw on the same row, breaking directory
   sync for everyone in it. `map()` now calls `Roles::assertAssignableIn()` first.

2. **The environment approvals console could not deny, and should never have approved.**
   `deny()` was calling the service with one argument after the contract gained a second —
   an `ArgumentCountError` at runtime, invisible because nothing tested that screen. And
   `approve()` passed the env-admin's own member id as the approving subject, which can
   never match the request's user: the button silently did nothing. Approving was removed
   outright rather than repaired — a CIBA approval is the *user's* consent for an agent to
   act as them, so an operator granting it is the very bypass the service layer now refuses.
   Denying is the safe half of the pair and remains.

## Standing rule

A component action that takes an id and mutates **must** reach a service that applies an
ownership predicate. Prefer filtering in the query over fetch-then-compare: a foreign id
should be indistinguishable from a missing one, so a caller learns nothing about what exists
outside their scope.
