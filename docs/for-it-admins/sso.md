---
title: Enterprise SSO
weight: 10
description: Connect your identity provider through the setup portal so your people sign in to the product with their work account — the five steps, the values to copy each way, and what to check before you turn it on.
---

# Enterprise SSO

This task connects your identity provider (Okta, Microsoft Entra ID, Google Workspace or
any SAML 2.0 or OpenID Connect provider) to the product, so your people sign in with their
work account. Your people keep signing in as they do today until the last step, when you
turn it on.

The page walks you through five steps.

## 1. Choose your identity provider

Pick yours from the list: twenty identity providers, from Okta, Microsoft Entra ID and
Google Workspace to AD FS, Keycloak, Shibboleth and Cloudflare Access
([every one, with its own page](idp/_index.md)), or **SAML 2.0** or **OpenID Connect**.
Not listed? Choose SAML 2.0 or OpenID Connect; any standard identity provider works.

## 2. Create the app in your provider

Give the connection a name if you like (**Connection name**) and choose **Start with**
*provider*. This creates the connection on the product's side, so the exact values to paste
into your provider exist. Nobody signs in through it yet.

The page then shows a table: each row is the name of a field **on your provider's screen**,
and the value to put in it. Copy each value into the field with the same name. A row that
says **Set to** is a fixed setting, such as the Name ID format.

The values look like this; always copy the ones the page shows you:

| Value | Shape |
|---|---|
| ACS URL (where your provider posts sign-ins) | `https://<sign-in host>/sso/saml/<connection id>/acs` |
| Entity ID (who the product is, to your provider) | `https://<sign-in host>/sso/saml/<connection id>` |
| Redirect URI, for OpenID Connect | `https://<sign-in host>/sso/oidc/<connection id>/callback` |

Under **Step by step** the page lists the clicks for your provider. The same steps, with
more context, are in the [identity provider guides](idp/_index.md).

Whatever the provider, send the person's **email address as the Name ID** and also as an
`email` attribute (for OpenID Connect, the `email` claim), and **sign the assertion**. The
[SAML guide](idp/generic-saml.md) has the details. Assign the app to the people or groups
who should use it; most providers send nobody until you do.

If your provider can import service-provider metadata, the page also shows a **Service
provider metadata URL**. Give your provider that URL (or the file it downloads) instead of
copying the values one by one: PingFederate takes it under **Import Metadata — URL**,
Microsoft Entra ID through **Upload metadata file** after you download it, AD FS as the
federation metadata address. It works as soon as you have started the connection.

## 3. Bring back the details from your provider

Now give the portal what your provider created:

- **SAML:** paste your provider's metadata URL, or the contents of its metadata XML file,
  into **Metadata URL or XML** and choose **Save**. The identity provider's entity ID,
  sign-on URL and certificate are read from it. Prefer the metadata: it is the step people
  most often get wrong by hand. If you must, **Enter the values by hand instead**:
  **IdP entity ID**, **IdP SSO URL** and **IdP X.509 certificate**.
- **OpenID Connect:** enter the **Issuer URL**, **Client ID** and **Client secret**. The
  issuer is the base URL, not the `.well-known` path; the endpoints and the signing keys
  are read from it, and key rotations at your provider are followed on their own. Leave
  **Signing key (optional)** blank unless your provider publishes no signing keys in its
  OpenID configuration; then paste its RS256 public key, in PEM form. Tokens signed with a
  shared secret (HS256) are not supported.

When the details are saved, the page shows **Received from your identity provider** with
what it read.

## 4. Verify your domain

People are sent to single sign-on when their email address is at a **verified domain**.
The page shows how many are verified; choose **Add or check domains** to add one. See
[Domain verification](domain-verification.md) for the DNS record.

Verify every domain your people have addresses on, such as `example.com` and
`example.co.uk`. Someone on an unverified domain is not sent to your provider.

## 5. Turn on single sign-on

Test it first if you can. Then choose **Turn on single sign-on**. From then on, people at
your verified domains sign in through your identity provider.

The button is refused until your provider's details are saved ("Save your identity
provider's details first").

Whether those people can still use a password as well is the product's decision, not
the portal's. Tell the person who sent you the link once SSO works; they can make it the
only way in.

## Your connections

**Your connections** lists what you have set up, with its status: **Draft** (started, not
complete), **Off** or **On**. Use **Set up another connection** if you need a second one,
for example for a subsidiary on a different provider.

## If something goes wrong

| Message | What it means |
|---|---|
| "We couldn't read that metadata." | Paste the whole XML, or a metadata URL that starts with `https://`. |
| "We couldn't read the provider's OpenID configuration." | The issuer URL is wrong, or not reachable from the internet. |
| "Some of your identity provider's details are missing." | Fill in every field. |
| "That address isn't allowed." | The URL points at a private network address. It must be a public endpoint. |

If sign-in fails at your provider with an error about the audience, recipient or ACS URL,
the values in your provider do not match the ones the portal shows, character for
character. Copy them again.

## Related

- [Identity provider guides](idp/_index.md) — where each value goes in your provider.
- [Domain verification](domain-verification.md) — the DNS record for step 4.
- [SAML certificate renewal](certificate-renewal.md) — when your provider's signing certificate is about to expire.
- [Directory Sync](directory-sync.md) — create and remove your people automatically, too.
