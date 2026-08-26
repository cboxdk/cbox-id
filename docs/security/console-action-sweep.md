---
title: Console action ownership sweep
weight: 30
description: Every client-invokable console action that accepts an id, and the ownership predicate that scopes it.
---

# Console action ownership sweep

An action that takes an id from the client is **directly invokable by the client**. Scoping
the list a page renders is therefore a display concern, not an authorization control — the
guarantee has to live in the query that mutates.

This inventory exists because that distinction was violated five times in materially
different modules, and no single reviewer found all five. It is a checklist to re-run, not
a statement that the code is finished — the re-run below found four more, and found that
the recipe had been pointed at a directory that no longer existed.

> **THE SHAPE MOVED, THE RULE DID NOT.** This was the *Livewire action ownership sweep*.
> Every console mutation used to be a public method on a Volt component, reached over the
> one `/livewire/update` endpoint, so the recipe read `resources/views/livewire/**` for
> `public function` signatures taking a string. There are no components. Every mutation is
> a route with its own verb, its own middleware stack and its own controller method, and
> the id arrives as a **route parameter** — which changes what to grep for and changes
> nothing about what the answer has to be. The sections below the recipe describe the tree
> as it was at `271d8af` and are kept as evidence of what was found, not as a description
> of the code today; re-running the recipe is the point of this document rather than
> reading the old counts off it.

## How to re-run it

```bash
# Every controller action that takes an id and can mutate.
python3 - <<'SWEEP'
import re, glob
# BOTH trees. The app's controllers and the in-tree modules' are the same kind of surface,
# routed the same way; the earlier recipe looked only at the first, so nine module
# components were never swept at all.
patterns = [
    'app/Http/Controllers/**/*.php',
    'modules/*/src/Http/Controllers/**/*.php',
]
for f in sorted({f for p in patterns for f in glob.glob(p, recursive=True)}):
    s = open(f).read()
    for m in re.finditer(r'^    public function (\w+)\(([^)]*)\)', s, re.M):
        name, params = m.group(1), m.group(2)
        if name in ('__construct', '__invoke'):
            continue
        # A route parameter arrives as a bare string; a Request, a FormRequest or a
        # route-model binding does not count as "an id from the client" here.
        if not re.search(r'\bstring \$', params):
            continue
        print(f"{f}:{s[:m.start()].count(chr(10)) + 1}\t{name}")
SWEEP
```

Then cross-check the hit list against `php artisan route:list --json`: an action nothing
routes cannot be invoked, and an action routed on **both** planes has to satisfy the
stricter of the two. `ConsoleRoutes::action()` registers on both; `organizationAction()`
registers on the organization plane only.

Reads are in scope here too, which they were not before. A detail page that resolves an
unscoped id leaks existence exactly as a write does — it just leaks it by rendering
instead of by mutating — so `show` is on the list rather than excluded from it.

For each hit, answer one question: **does the mutation carry an ownership predicate in the
query?** A pre-fetch `if (...->where('organization_id', $orgId)->exists())` check counts; a
comparison performed after an unscoped fetch is weaker (it leaks existence through timing
and error shape) and a bare id lookup fails outright.

## The `271d8af` run — 57 actions across 108 Volt components


Swept at cbox-id `271d8af`, composing laravel-id **v0.87.2**. Stamp the commit as well as
the package version when you re-run: the count moves with any console refactor, and a bare
version told the next reader nothing about which tree it described.

> **This table describes the tree at `271d8af` and has not been re-swept since.** Both
> findings from that run are closed (below), and the paths have moved under it —
> `livewire/workspace/**` and `livewire/operator/**` are gone, `AccountMember` is
> `Membership`, `account_id` is `organization_id`. The counts are therefore stale by
> construction, and re-running the recipe is the point of the document rather than reading
> the numbers off it.

| Plane | Actions | Scoping rule | Status |
|---|---|---|---|
| Subject / tenant (`livewire/*.blade.php`) | 11 | `CurrentUser::id()` for self-service, `CurrentUser::organizationId()` for anything org-owned, in the mutating query or passed to a service that applies it | 10 scoped, **1 weak** |
| Auth (`livewire/auth/**`) | 1 | `CurrentUser::id()` — the multi-account switcher acts only on accounts held by this browser | scoped |
| Shared console (`livewire/console/**`) | 11 | `ConsoleScope` decides the plane from the session and answers `requireOrganizationId()`; on the environment plane the picked org is re-validated against `availableOrganizations()` on **every** read | all scoped |
| Environment (`livewire/environment/**`) | 16 | env-admin is the operator *of that environment*, re-checked in `boot()` so it runs on every action; `BelongsToEnvironment` is the outer boundary, org predicates inside it | all scoped |
| Members / roster (`livewire/console/members.blade.php`) | 8 | `ConsoleScope::requireOrganizationId()`, carried INTO the query — `Membership` is tenant- and environment-owned, and the service verbs take `(organizationId, userId)` so the fence and the write agree by signature | all scoped |
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
middleware — see `WriteRouteStackTest`, which now holds the successor invariant: a write is
guarded at least as tightly as the pages it sits beside — plus the per-page operator gate. The gate answers **404**,
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

Two findings graded **weak**: an unscoped fetch followed by an in-PHP comparison. Nothing
graded unscoped, and no cross-tenant write was reachable.

**Both are now CLOSED.** The paths and the class names in them are pre-history — there is
no `livewire/workspace/**` and no `AccountMember`; a customer IS an organization, and the
roster moved to `livewire/console/members.blade.php`. They are kept as written, because a
finding rewritten to match the code that fixed it stops being evidence that it happened.

1. **`workspace/members.blade.php` — `changeRole`, `removeMember`, `manageAccess`.**
   CLOSED. All three went through `manageableTarget()`, whose `AccountMembers::find()` was
   `AccountMember::query()->whereKey($id)->first()`. `AccountMember` had **no global scope
   at all** — it sat above tenancy — so that lookup spanned every account on the install,
   and the only fence was `$target->account_id !== $current->account_id` in PHP. The
   services behind them took no account id either.

   Today `manageableTarget()` resolves through `resolve($memberId, $organizationId)`, which
   carries the organization into the query and answers 404 — and `Membership` is both
   tenant- and environment-owned, so the model's own scopes are a second fence. The service
   verbs take `(organizationId, userId)` rather than a bare membership id, which is what
   makes the fence and the write agree without anybody having to remember.

   `workspace/home.blade.php::addEnvironment()` had the same pattern against `Project`,
   which is likewise globally unscoped. It took no id from the client so the recipe did not
   surface it, which is worth knowing: the recipe finds a shape, not every instance of the
   risk.

2. **`social-providers.blade.php` — `disable`.** CLOSED — see
   `SocialProviderMarketplaceTest`, "it will not remove another tenant provider by id",
   which asserts a 404 and that the row survives. Recorded as written: `Connections::byId()` is environment-scoped
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
