---
title: Enterprise self-serve (SSO, SCIM & the Admin Portal)
weight: 4
description: Entitlement-gated self-serve SAML/OIDC SSO and SCIM, plus a single-use setup link an org admin hands to an external IT admin.
---

# Enterprise self-serve (SSO, SCIM & the Admin Portal)

Cbox ID gives your customers self-serve enterprise onboarding: an org admin turns on SAML
or OIDC single sign-on and SCIM directory sync themselves, and can delegate the
IdP wiring to their own IT team through a single-use link — no support ticket, no
shared credentials.

## What ships where — an honest split

This is an **app-layer** feature. Two layers cooperate:

- **The `cboxdk/laravel-id` package** provides the primitives: the org-scoped
  [federation](https://github.com/cboxdk/laravel-id) `Connections` contract
  (SAML/OIDC), the [directory](https://github.com/cboxdk/laravel-id)
  `Directories`/`DirectorySync` contracts (SCIM 2.0), the billing-fed
  **entitlement projection** (`EntitlementReader`/`EntitlementWriter`), and the
  hash-chained `AuditLog`. The package does **not** ship any UI, gating policy, or
  portal link.
- **This app** provides everything a customer actually touches: the SSO and SCIM
  console screens, the entitlement **gate** that decides who may use them, the
  upsell states, and the Admin Portal setup link. If you build your own app on the
  package, these are yours to build — the package gives you the moving parts, not
  the product.

## Entitlement-gated SSO & SCIM

Both self-serve screens are **deny-by-default**. An org sees a usable SSO or SCIM
screen only when billing has set the matching entitlement's `enabled` flag; every
other org gets a clean upsell card instead of the feature, and the nav item is
marked *Enterprise*.

The two entitlement keys are **namespaced** so they never collide with the
entitlements your tenant products push through the same projection:

| Feature | Config key | Default entitlement key |
|---|---|---|
| SAML / OIDC SSO | `cbox-id.entitlements.sso` | `cbox-id-sso` |
| SCIM directory sync | `cbox-id.entitlements.scim` | `cbox-id-scim` |

Grant one from billing (or, in tests, directly):

```php
app(Cbox\Id\Kernel\Authorization\Contracts\EntitlementWriter::class)->set(
    $organizationId,
    new Cbox\Id\Kernel\Authorization\ValueObjects\EntitlementInput('cbox-id-sso', ['enabled' => true]),
    Cbox\Id\Kernel\Authorization\Enums\EntitlementSource::Billing,
);
```

The gate is enforced in **two** places, never just the UI:

1. **The screen** renders the upsell instead of the feature when the org isn't
   entitled (`App\Platform\Entitlements::entitled($orgId, 'sso'|'scim')`).
2. **Every mutating action** (`create`, `activate`, `register`, `invite`, …) calls
   a server-side `guardEntitled()` that `abort(403)`s **before** the admin check
   runs — so a hand-crafted Livewire request from a non-entitled org is refused
   even though the upsell screen itself is reachable.

## The Admin Portal setup link

An entitled org admin rarely wants to paste X.509 certificates themselves. The
**"Invite your IT admin"** action mints a **single-use, short-lived** link that an
external IT admin opens **with no account** to configure that one org's SSO/SCIM —
and nothing else.

How it holds together:

- **Minting.** A cryptographically random 32-byte token is generated; only its
  SHA-256 hash is stored (`admin_portal_links`). The full URL
  (`route('portal.enter', $token)`) is shown to the admin **once**. Minting records
  a `portal_link.created` audit event on the org's trail. Links expire after
  `cbox-id.portal.ttl_minutes` (default 30).
- **Redemption.** `GET /setup/{token}` hashes the token, looks up a link that is
  neither expired nor consumed, and **re-checks the org is still entitled** (a
  lapsed plan refuses redemption). On success it establishes a **scoped portal
  session** under a dedicated key (`cbox.portal`) — never the platform login key —
  and redirects to the setup screen. The link is *not* consumed yet. Any failure
  shows a friendly "expired or already used" page with no enumeration detail.
- **The setup screen** (`/setup`, guarded by the `portal.session` middleware) reads
  the bound org id and scope **only from the portal session**, never from request
  input, and renders the SSO and/or SCIM forms for that one org — reusing the exact
  same package contracts the console uses. Because the org id is never
  client-supplied, a redeemer cannot pivot to another tenant.
- **Finishing** marks the link `consumed_at`, records `portal_link.completed`, and
  clears the portal session.

### Isolation invariants

The portal session is deliberately a *different* thing from a platform login:

- It is stored under `cbox.portal`, so it **never** satisfies `platform.auth` — a
  portal holder hitting `/dashboard`, `/members`, `/connections`, … is bounced to
  login like any guest.
- The bound org id lives only in the server session; the setup screen feeds it to
  the org-scoped package contracts, so the portal can only ever configure its own
  org.
- Expiry and entitlement are re-checked on **every** portal request (middleware
  *and* the component's own guard), so an expired link or a mid-session plan lapse
  is caught immediately.

## Configuration

| Variable | What it does | Default |
|---|---|---|
| `CBOX_ID_ENTITLEMENT_SSO` | Entitlement key that unlocks self-serve SSO. | `cbox-id-sso` |
| `CBOX_ID_ENTITLEMENT_SCIM` | Entitlement key that unlocks self-serve SCIM. | `cbox-id-scim` |
| `CBOX_ID_PORTAL_TTL_MINUTES` | How long a minted Admin Portal link stays redeemable. | `30` |

See the full [environment-variable reference](../configuration/environment-variables.md).
