---
title: Microsoft Entra ID
weight: 20
description: Set up single sign-on (SAML) and Directory Sync (SCIM) with Microsoft Entra ID for the setup portal — the enterprise application to create, which values go where, and what to paste back.
---

# Microsoft Entra ID

## Single sign-on (SAML)

In the portal, choose **Microsoft Entra ID** under Enterprise SSO and **Start with
Microsoft Entra ID**. Then, in the Microsoft Entra admin center:

1. Go to **Identity → Applications → Enterprise applications** and choose **New
   application**.
2. Choose **Create your own application**, name it, choose **Integrate any other
   application you don't find in the gallery (Non-gallery)** and choose **Create**.
3. Open **Single sign-on** and choose **SAML**. Under **Basic SAML Configuration**, choose
   **Edit** and fill in the values the portal shows:

   | Entra field | Value |
   |---|---|
   | Identifier (Entity ID) | The portal's entity ID (`https://…/sso/saml/…`) |
   | Reply URL (Assertion Consumer Service URL) | The portal's ACS URL (`https://…/sso/saml/…/acs`) |

4. Under **Attributes & Claims**, set **Unique User Identifier (Name ID)** to `user.mail`,
   and check that the `emailaddress` claim is sent (Entra sends it by default, with
   `givenname` and `surname`).
5. Under **SAML Certificates**, copy the **App Federation Metadata Url** and paste it into
   the portal's **Metadata URL or XML**.
6. Under **Users and groups**, assign the people or groups who should sign in this way.

Then verify your domain and turn single sign-on on in the portal. Microsoft's own guide:
[Enable SAML single sign-on for an enterprise application](https://learn.microsoft.com/en-us/entra/identity/enterprise-apps/add-application-portal-setup-sso).

**Certificate renewal.** Entra's signing certificates are under the app's **SAML
Certificates**. Create the new certificate there without making it active, upload it (or
the App Federation Metadata Url) in the portal's SAML certificate renewal task, make it
active in Entra, then activate it in the portal. See
[SAML certificate renewal](../certificate-renewal.md).

## Directory Sync (SCIM)

In the portal, choose **Microsoft Entra ID** under Directory sync and **Create directory**;
copy the bearer token now. Then in the Entra admin center, open the enterprise application
you use for single sign-on with the product (or create a non-gallery one):

1. Open **Provisioning**, choose **Get started** (or **New configuration**) and set
   **Provisioning Mode** to **Automatic**.
2. Under **Admin Credentials**, fill in:

   | Entra field | Value |
   |---|---|
   | Tenant URL | The portal's **SCIM base URL** (`https://…/scim/v2`) |
   | Secret Token | The portal's bearer token |

3. Choose **Test Connection**, then **Save**.
4. Under **Settings**, set **Scope** to **Sync only assigned users and groups**.
5. Assign the people and groups to sync under **Users and groups**.
6. Set **Provisioning Status** to **On**.

Entra syncs about every 40 minutes, so the first people can take a while to appear. Use
**Provision on demand** to test one person straight away. Microsoft's own guide:
[Use SCIM to provision users and groups](https://learn.microsoft.com/en-us/entra/identity/app-provisioning/use-scim-to-provision-users-and-groups).

## Related

- [Enterprise SSO](../sso.md) and [Directory Sync](../directory-sync.md) — the portal side.
- [Identity provider guides](_index.md) — other providers.
