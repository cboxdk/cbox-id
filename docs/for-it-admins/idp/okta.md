---
title: Okta
weight: 10
description: Set up single sign-on (SAML) and Directory Sync (SCIM) with Okta for the setup portal — which app to create, which values go into which Okta field, and what to paste back.
---

# Okta

## Single sign-on (SAML)

In the portal, choose **Okta** under Enterprise SSO and **Start with Okta**. Then, in the
Okta Admin Console:

1. Go to **Applications → Applications** and choose **Create App Integration**.
2. Choose **SAML 2.0**, then **Next**. Name the app (for example after the product), then
   **Next**.
3. Under **SAML Settings**, fill in the values the portal shows:

   | Okta field | Value |
   |---|---|
   | Single sign-on URL | The portal's ACS URL (`https://…/sso/saml/…/acs`). Leave **Use this for Recipient URL and Destination URL** ticked. |
   | Audience URI (SP Entity ID) | The portal's entity ID (`https://…/sso/saml/…`) |
   | Name ID format | `EmailAddress` |
   | Application username | `Email` |

4. Under **Attribute Statements**, add `email` → `user.email`. The portal's steps also add
   `firstName` → `user.firstName` and `lastName` → `user.lastName`; they do no harm. The
   `email` statement is the one that matters. Choose **Next**, then **Finish**.
5. On the app's **Sign On** tab, copy the **Metadata URL** and paste it into the portal's
   **Metadata URL or XML**.
6. On the **Assignments** tab, assign the people or groups who should sign in this way.

Then verify your domain and turn single sign-on on in the portal. Okta's own guide:
[Create SAML app integrations](https://help.okta.com/en-us/content/topics/apps/apps_app_integration_wizard_saml.htm).

**Certificate renewal.** Okta's signing certificate is on the app's **Sign On** tab. To
renew, generate a new certificate there, upload it (or the app's metadata) in the portal's
SAML certificate renewal task, switch Okta to it, then activate it in the portal. See
[SAML certificate renewal](../certificate-renewal.md).

## Directory Sync (SCIM)

In the portal, choose **Okta** under Directory sync and **Create directory**; copy the
bearer token now. Then in Okta, open the app you use for single sign-on with the product
(or create one):

1. On the app's **General** tab, choose **Edit** and set **Provisioning** to **SCIM**.
2. On the **Provisioning** tab, choose **Integration → Edit** and fill in:

   | Okta field | Value |
   |---|---|
   | SCIM connector base URL | The portal's **SCIM base URL** (`https://…/scim/v2`) |
   | Unique identifier field for users | `userName` |
   | Supported provisioning actions | Push New Users, Push Profile Updates, Push Groups |
   | Authentication Mode | HTTP Header |
   | Authorization (Bearer) | The portal's bearer token |

3. Choose **Test Connector Configuration**, then **Save**.
4. Under **Provisioning → To App**, enable **Create Users**, **Update User Attributes** and
   **Deactivate Users**.
5. Assign people to the app, and push the groups you want to sync on the **Push Groups**
   tab.

Okta's own guide:
[Add SCIM provisioning to app integrations](https://help.okta.com/en-us/content/topics/apps/apps_app_integration_wizard_scim.htm).

## Related

- [Enterprise SSO](../sso.md) and [Directory Sync](../directory-sync.md) — the portal side.
- [Identity provider guides](_index.md) — other providers.
