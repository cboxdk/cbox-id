---
title: Any OpenID Connect provider
weight: 50
description: Connect any OpenID Connect identity provider through the setup portal — the client to register, the redirect URI and scopes, and the values to bring back.
---

# Any OpenID Connect provider

Use this for an identity provider that speaks OpenID Connect, such as Auth0, Keycloak or
Ping. In the portal, choose **OpenID Connect** under Enterprise SSO and **Start with
OpenID Connect**.

## What to create

In your provider, register a new **web application**: a confidential OpenID Connect client
using the authorization code flow. Set:

| Setting | Value |
|---|---|
| Redirect URI / Callback URL | The portal's redirect URI (`https://…/sso/oidc/…/callback`), exactly as shown |
| Scopes | `openid email profile` |

The ID token must carry the person's `email`. Give the application to the people who should
sign in this way.

## What to bring back

| Portal field | From your provider |
|---|---|
| Issuer URL | The issuer: the base URL under which your provider publishes `/.well-known/openid-configuration`, not that path itself |
| Client ID | The client's ID |
| Client secret | The client's secret |
| Signing key (optional) | Leave blank. Only for a provider whose OpenID configuration publishes no `jwks_uri`: its RS256 token-signing **public key**, in PEM form (`-----BEGIN PUBLIC KEY-----`) |

The endpoints are read from the issuer, so there is nothing else to paste. If the portal
says "We couldn't read the provider's OpenID configuration", check that the issuer URL is
right and reachable from the internet.

The signing keys are read from the provider's `jwks_uri`, and a key rotation there is
picked up on its own. ID tokens signed with a shared secret (HS256) are not supported.

## Related

- [Enterprise SSO](../sso.md) — the portal's five steps.
- [Any SAML 2.0 provider](generic-saml.md) — if your provider speaks SAML instead.
