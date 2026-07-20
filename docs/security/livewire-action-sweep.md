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
a statement that the code is finished.

## How to re-run it

```bash
# Every public component action that accepts an id from the client.
python3 - <<'PY'
import re, glob
for f in sorted(glob.glob('resources/views/livewire/**/*.blade.php', recursive=True)):
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

## Result — 75 actions across 104 components

Swept at laravel-id v0.33.0. Counts by plane, with the rule each plane relies on.

| Plane | Actions | Scoping rule | Status |
|---|---|---|---|
| Subject / tenant (`livewire/*.blade.php`) | 34 | `CurrentUser::orgId()` must appear in the mutating query or be passed to a service that applies it | all scoped |
| Environment (`livewire/environment/**`) | 24 | env-admin is the operator *of that environment*; `EnvironmentScope` is the outer boundary, org membership checked per action | all scoped |
| Workspace / account (`livewire/workspace/**`) | 8 | `AccountAuth::current()->account_id` ownership check before the mutation | all scoped |
| Operator (`livewire/operator/**`) | 6 | platform superuser — unscoped lookups are intentional, gated by `AuthenticateOperator` | by design |
| Portal (`livewire/portal/**`) | 3 | single-use scoped session + `guardFeature()` + `ownedDomain()` | all scoped |

### Deliberately unscoped

The operator plane resolves records without a tenant predicate (`Environment::find`,
`Organization::find`, `PlatformOperator::find`). That is the point of the plane — it is the
platform's own console. Its protection is `AuthenticateOperator`, which is registered as
persistent middleware (see `PersistentMiddlewareTest`) so it re-runs on every action rather
than only on the initial page load.

### The asymmetry between the two hook / governance / vault consoles

The tenant-facing consoles pass the **acting org**; the environment consoles pass the
**record's own org**. That is not an oversight: the env-admin is the operator above the
orgs in that environment, and `EnvironmentScope` remains the boundary that matters there.
Both call sites carry a comment saying so, because it reads like a bug otherwise.

## Findings the sweep produced

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
