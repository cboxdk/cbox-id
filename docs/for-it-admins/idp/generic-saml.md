---
title: Any SAML 2.0 provider
weight: 40
description: Connect any SAML 2.0 identity provider through the setup portal — the service-provider values to enter, what the assertion must carry, and what to bring back.
---

# Any SAML 2.0 provider

Use this for an identity provider the portal does not list by name, such as ADFS,
Keycloak, Shibboleth or Auth0. In the portal, choose **SAML 2.0** under Enterprise SSO and
**Start with SAML 2.0**.

## What to create

In your provider, create a new SAML 2.0 application. Providers call it a service provider,
a relying party trust or an application. Enter:

| Setting | Value |
|---|---|
| SP Entity ID / Audience | The portal's entity ID (`https://…/sso/saml/…`) |
| ACS URL / Reply URL | The portal's ACS URL (`https://…/sso/saml/…/acs`), binding HTTP-POST |
| NameID format | `urn:oasis:names:tc:SAML:1.1:nameid-format:emailAddress` |

The portal does not offer a service-provider metadata file to import, so enter these by
hand.

The response must:

- **have a signed assertion.** Sign the assertion itself, or both the assertion and the
  response. A response that is signed while its assertion is not is refused.
- **carry a NameID**, ideally the person's email address. It identifies the person, so it
  must not change.
- **send the email address as an attribute**, named `email`, `mail`, `emailAddress`,
  `http://schemas.xmlsoap.org/ws/2005/05/identity/claims/emailaddress` or
  `urn:oid:0.9.2342.19200300.100.1.3`. The NameID is not read as the email address, even
  in the `emailAddress` format, so send it in both places.
- optionally send a display name as `name` or `displayName`. First and last name are
  accepted and ignored.

Give the application to the people who should sign in this way.

## What to bring back

Your provider's metadata URL, or its metadata XML, pasted into **Metadata URL or XML**.
Without metadata, choose **Enter the values by hand instead** and fill in:

| Portal field | From your provider |
|---|---|
| IdP entity ID | Its entity ID, or issuer |
| IdP SSO URL | Its single sign-on URL |
| IdP X.509 certificate | Its signing certificate, in PEM form |

## Related

- [Enterprise SSO](../sso.md) — the portal's five steps.
- [SAML certificate renewal](../certificate-renewal.md) — before the signing certificate expires.
- [Any OpenID Connect provider](generic-oidc.md) — if your provider speaks OpenID Connect instead.
