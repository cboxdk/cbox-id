---
title: Single sign-on
weight: 30
description: Connect Microsoft Entra ID, Okta or Google Workspace so your people sign in with the company account they already have, and claim the email domains that route them there.
---

# Single sign-on

**Console page:** Sign-in › Single sign-on

Single sign-on lets your people authenticate against the identity provider your
company already runs, instead of holding a second set of credentials here. You
connect the provider once and claim the email domains you own; from then on, anyone
whose address is on those domains is sent to your provider to sign in, and access
here follows what your provider says.

The practical payoff is offboarding: disable someone in your provider and they stop
being able to sign in here — and to everything connected to here — without anyone
remembering to do a second thing.

Both **SAML 2.0** and **OpenID Connect** are supported; use whichever your provider
makes easiest.

## Before you start

- You need admin rights in the identity provider, or someone who has them. If that
  is a different person, use **Invite your IT admin** on the page: it mints a
  single-use setup link that works without a Cbox ID account and expires shortly.
- Single sign-on is an Enterprise feature. If the page shows a lock, the
  organization is not entitled yet.
- Have a test account ready that you can sign in with, but that is not your only
  way into the console.

## Connect a provider with SAML

1. In your identity provider, create an application for Cbox ID.
2. In the console, choose **New connection → SAML**.
3. Paste your provider's **metadata XML**, or its metadata URL, into the import
   field. The IdP entity ID, sign-on URL and certificate are filled in for you —
   this is the step people most often get wrong by hand.
4. Fill in the service-provider side: the SP entity ID and the ACS URL. Once the
   connection exists, its exact **ACS URL** is shown on the connection card; copy
   that back into your provider.
5. Save. The connection is created as a **draft** — it is not used for anyone yet.
6. Test a sign-in, then **Activate** it.

## Connect a provider with OIDC

1. Register a confidential client in your provider and note its client ID and secret.
2. Choose **New connection → OIDC** and enter the **issuer** URL, client ID, client
   secret and signing key.
3. Cbox ID reads the provider's OpenID configuration from the issuer and fills in
   the endpoints. If that fails, the issuer URL is wrong or unreachable — it is the
   base URL, not the `.well-known` path.
4. Save, test, **Activate**.

## Claim your domains

A connection on its own does not route anybody. Verified domains are what let Cbox
ID recognise `you@acme.com` as yours and send that person to your provider.

1. Add the domain under **Verified domains**.
2. Publish the TXT record shown at the host given. The values are displayed once,
   right after you add the domain.
3. Click verify. DNS can take a while to propagate; re-check rather than re-adding.

A domain can only be claimed by one organization — if verification is refused
because it is already claimed, that is why.

## Troubleshooting

**"Couldn't read the provider's OpenID configuration"** — the issuer URL is wrong,
or the host is not reachable from the platform. Check it resolves publicly.

**Sign-in loops back to the Cbox ID login form** — the connection is still a draft.
Activate it.

**People are not being sent to the provider** — their email domain is not verified,
or their address is on a domain you have not claimed.

**The provider rejects the request** — the SP entity ID or ACS URL in your provider
does not match the connection exactly, character for character.

## Related

- [Sync users in](sync-users-in.md) — SSO authenticates people; syncing creates and
  deactivates them. Most organizations want both.
- [Roles](roles.md) — what those people can do once they are in.
