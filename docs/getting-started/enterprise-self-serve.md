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
2. **Every mutating route** (`create`, `activate`, `register`, `invite`, …) calls
   a server-side `guardEntitled()` that `abort(403)`s **before** the admin check
   runs — so a hand-crafted request from a non-entitled org is refused even
   though the upsell screen itself is reachable.

## The Admin Portal setup link

An entitled org admin rarely wants to paste X.509 certificates themselves. An **Admin
Portal link** is a **single-use, time-limited** link that an external IT admin opens
**with no account** to set up what the link covers for that one org — and nothing else.

### What a link covers: intents

A link carries a set of **intents**, chosen when it is minted:

| Intent | What the IT admin does | Plan entitlement |
|---|---|---|
| `sso` | Connect their identity provider (SAML or OIDC), guided per provider, and prove the email domains that route to it | `sso` |
| `dsync` | Create a SCIM directory and connect their directory to it | `scim` |
| `domain_verification` | Add domains, publish the DNS TXT record, verify | none |
| `log_streams` | Stream their organization's audit trail to their own SIEM, send a test entry | none |
| `certificate_renewal` | Upload their IdP's new SAML signing certificate, then activate it | `sso` |
| `audit_logs` | Read their organization's app audit events and export them (read-only) | `audit_logs` |

Mint one from an organization's page (**Admin Portal link** in the header: tick the
intents, choose how long it may wait — 30 minutes to 7 days — and optionally email it to
their IT contact in their language), or with `organizations.portal_links.create`:

```bash
curl -X POST https://<env-host>/api/v1/organizations/$ORG/portal-links \
  -H "Authorization: Bearer $CBOX_ID_KEY" -H 'Content-Type: application/json' \
  -d '{"intents":["sso","domain_verification"],"expires_in_minutes":1440,"email":"it@customer.com","locale":"da"}'
```

The answer's `url` is shown once. A link covering an intent the organization's plan lacks
is refused (`403 not_entitled`).

### How it holds together

- **Minting.** A random 32-byte token is generated; only its SHA-256 hash is stored
  (`admin_portal_links`, with the `intents` and, when mailed, `emailed_to`). Minting
  records `portal_link.created` with who minted it.
- **Redemption.** Opening `/setup/{token}` shows a button; only the POST spends the link
  (mail and chat previews would otherwise burn it). It re-checks the plan, consumes the
  link, and opens a **portal session** under its own key (`cbox.portal`) that lasts
  `cbox-id.portal.session_minutes` (default 120) from that moment.
- **Revoking.** `organizations.portal_links.list` (`GET …/portal-links`, scope
  `portal_links:read`) shows an organization's links from the last 30 days and where each
  stands — `pending`, `in_use`, `completed`, `expired`, `revoked` — never the URL.
  `organizations.portal_links.revoke` (`DELETE …/portal-links/{id}`) withdraws one: it can
  no longer be opened, and a session it already opened ends on its next request. The
  organization's Overview in the console lists the outstanding links with a **Revoke**
  button. Recorded as `portal_link.revoked`.
- **The checklist.** `/setup` shows one card per intent with its progress, read from what
  is actually configured. Each intent has its own page: SSO walks provider → our ACS URL
  and entity ID (or OIDC redirect URI, or the SAML SP metadata URL for a provider that
  imports one) to paste, field by field, into Okta, Entra ID,
  Google Workspace, OneLogin, JumpCloud, PingFederate or any SAML/OIDC provider → their
  metadata back → a verified domain → activate. Directory sync shows the SCIM base URL and
  a bearer token (once) with guides for Okta, Entra ID, OneLogin, JumpCloud and generic SCIM.
- **Every write is an action**, run as a `PortalPrincipal`: confined to the link's
  organization (`confinedToOrganization()`), and allowed only the actions its intents
  list. The audit trail names the session — actor `system` with the link's id, plus
  `via: portal`, `portal_link_id` and `portal_link_created_by`.
- **Finishing** records `portal_link.completed` and ends the session.

### SAML certificate renewal and expiry alerts

A SAML connection trusts its primary certificate and any **staged** beside it, so renewal
has no outage: stage the new certificate (`sso.connections.certificates.stage`, from PEM or
the IdP's metadata — checked before it is trusted), switch the IdP over, then activate it
(`sso.connections.certificates.activate`), which retires the old one. The daily
`cbox-id:sso:certificate-expiry` scan sends a `connection.certificate_expiring` webhook, a
trail entry and a mail to the organization's owners and admins when an active connection's
certificates stop working within 30 and again within 7 days (once each), and the
organization's Overview and SSO tab show a warning.

### Isolation invariants

- The portal session never satisfies `platform.auth` — a portal holder hitting
  `/dashboard` is bounced to login like any guest.
- The bound org id lives only in the server session and is handed to every action; the
  principal refuses any other organization and any action its intents do not list, so a
  link for directory sync cannot add a domain by forming the request.
- Expiry and entitlement are re-checked on **every** portal request; an intent the plan
  stops including disappears from the session at once.

## Configuration

| Variable | What it does | Default |
|---|---|---|
| `CBOX_ID_ENTITLEMENT_SSO` | Entitlement key that unlocks self-serve SSO. | `cbox-id-sso` |
| `CBOX_ID_ENTITLEMENT_SCIM` | Entitlement key that unlocks self-serve SCIM. | `cbox-id-scim` |
| `CBOX_ID_PORTAL_TTL_MINUTES` | How long a minted Admin Portal link stays redeemable when its minter does not choose. | `30` |
| `CBOX_ID_PORTAL_SESSION_MINUTES` | How long the setup session a redeemed link opens lasts. | `120` |
| `CBOX_ID_CERTIFICATE_ALERT_MAIL` | Mail an organization's owners and admins when a SAML certificate is about to expire. | `true` |

See the full [environment-variable reference](../configuration/environment-variables.md).
