---
title: Entitlements
weight: 4
description: What an unset entitlement means — granted by default for a self-host, denied by default where billing decides — and why widening it is not a tenancy concern.
---

# Entitlements

An entitlement is a per-organization capability flag: does *this* organization get
SSO, SCIM, a seat limit, whatever a deployment chooses to key on. They live in a
projection that something else — usually billing — writes to.

The interesting question is what an **unset** key means, and that is a deployment
choice:

```dotenv
CBOX_ID_ENTITLEMENTS=open      # unset means granted  (default)
CBOX_ID_ENTITLEMENTS=metered   # unset means denied
```

## `open` — the default

No billing plane, no limits: every feature is available to every organization. This
is what a self-hosted deployment runs, and it is why there is nothing to license.

It is a **floor, not an override**. An explicit entitlement still wins, in both
directions:

| Stored for the org | `open` resolves to |
| --- | --- |
| nothing | **granted** |
| `{"enabled": true}` | granted |
| `{"enabled": false}` | **denied** |

So per-organization differentiation by hand keeps working, and a revocation actually
revokes. Only the *absence* of a row changes meaning.

Two things `open` deliberately does not do:

- **It does not fabricate limits.** A synthesised grant carries `enabled` and nothing
  else, so a numeric read like `seats` stays null — "no limit was stated" — rather
  than inventing a number some caller might enforce.
- **It does not synthesise into token claims.** The `ent` claim is minted from the
  full entitlement set, and the key space is open, so there is nothing to enumerate;
  tokens carry only entitlements someone actually granted. A synthesised grant is
  always live-checked, never embedded, so it cannot outlive a later decision to start
  metering.

## `metered`

Deny-by-default, and the billing projection is the only thing that grants. Set it
where a billing transport is actually wired — it is what a hosted plane runs so that
plan tiers mean something.

## Entitlements are not a tenancy control

Widening what an unset key means is a **feature-availability** change, not an
isolation one. That distinction is worth being precise about, because "grant
everything by default" sounds alarming until you know what entitlements do and do not
gate:

- The authorization decision — may this subject do X to Y — resolves purely from the
  relationship store. Entitlements sit beside it as a separate lookup and never
  contribute an "allow".
- Tenant isolation is enforced by the environment scope and organization scoping,
  neither of which consults an entitlement.
- Every entitlement read is keyed by an organization id the caller had to prove. The
  decision endpoint takes it from the introspected access token, never from the
  request body, so no caller can ask about an organization it does not hold a token
  for.

What changes under `open` is which **screens and features** an organization sees.
What data it can reach is decided somewhere else entirely, and is unaffected.

One consequence to be aware of: under `open` the decision endpoint answers "granted"
for *any* key it is asked about, including keys nothing has ever defined. It is a
capability check, not a way to discover which keys are real.
