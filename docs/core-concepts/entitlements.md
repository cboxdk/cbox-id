---
title: Entitlements
weight: 4
description: Identity security on every plan, independent project admission, and application entitlements.
---

# Entitlements

An entitlement is a per-organization capability flag: does *this* organization get
SSO, SCIM, a seat limit, whatever a deployment chooses to key on. They live in a
projection that something else — usually billing — writes to.

The interesting question is what an **unset** key means, and that is a deployment
choice:

```dotenv
CBOX_ID_ENTITLEMENTS=open      # unset means granted  (default)
CBOX_ID_ENTITLEMENTS=inclusive # included ID security; other unset keys denied
CBOX_ID_ENTITLEMENTS=metered   # legacy: all unset keys denied
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

## `inclusive` — hosted security on every plan

SSO, SCIM and organization audit access default to granted, using the configured
`cbox-id.entitlements.sso`, `scim` and `audit_logs` keys. MFA and passkeys already
have no subscription gate. Other absent application keys remain denied. Explicit
operational denials still win; authorization and tenant isolation remain mandatory.
The synthetic security grants are live checks, never additional token claims.

Project provisioning is separate:

```dotenv
CBOX_ID_ENTITLEMENTS=inclusive
CBOX_ID_REQUIRE_SUBSCRIPTION=true
```

A project starts with zero environment allowance until a synchronized subscription
(including a free one) grants it. This does not disable identity security. Leaving
`CBOX_ID_REQUIRE_SUBSCRIPTION` unset preserves legacy admission based on `metered`;
explicit true/false takes precedence. Billing's doctor checks follow this separate
admission policy. An ended subscription stops new environment creation; it does not
delete existing environments or revoke SSO/SCIM. No local fallback plan is invented.

Service admission still applies first. Early access is invitation/waitlist based;
creating an identity or choosing a free plan must not bypass it. Keep product access
enabled and its Billing program in waitlist mode. Inclusive does not mean open signup.

## Early-access pricing decision, 6 November 2026

Free / Start / Grow are approved at 0 / 99 / 349 DKK per project/month, net of VAT.
All include identity security and production plus test environments. ID is an entry
point to other Cbox products; additional consumption, not SSO or SCIM, motivates an upgrade.
The canonical `cbox-billing/catalogs/cbox-id.json` keeps these three plans inactive
for invitation-based early access. Catalog publication is separate from enabling
checkout or requiring subscriptions. Do not turn on subscription admission before
approved customers have a usable plan and payment acceptance has passed.

Billing's inclusion operation selects one organization-wide Signals allowance from
the highest serving ID tier: Free includes 10,000 events and 1,000 evaluations per
month, Start 100,000 and 10,000, and Grow 1,000,000 and 100,000. These are bounded pilot
allocations, not measured unit economics. Every included Signals plan has zero
recurring price and blocks overage. Separate Signals subscriptions are preserved;
ending ID falls back to the free baseline without creating a charge.

Signals activation is explicit and retains its own waitlist and owner/admin checks.
It neither connects identity events nor installs risk policies. The consumer defaults
off until the Billing API, inactive catalogs and qualification are ready. Upgrade and
downgrade retain usage; existing bounded engine leases can finish. Login and ordinary
MFA policy remain independent of Signals quota exhaustion.

See `cbox-billing/catalogs/cbox-id.md` for the authoritative contract and rollout order,
and `cbox-signals/docs/id-included-signals.md` for the consumer configuration. Record
actual activation in the infrastructure handoff; a catalog file or passing unit test
alone does not prove a live payment flow. Preserve all service waitlists during rollout.

## `metered`

Deny-by-default, and the billing projection is the only thing that grants. Retained for installations that deliberately gate features through the projection.
Cbox hosted plans use `inclusive` and independent subscription admission instead.

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
