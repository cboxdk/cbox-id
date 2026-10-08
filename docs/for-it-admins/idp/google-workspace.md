---
title: Google Workspace
weight: 30
description: Set up single sign-on with Google Workspace as a custom SAML app for the setup portal, and how Directory Sync works for a Google Workspace directory.
---

# Google Workspace

## Single sign-on (SAML)

In the portal, choose **Google Workspace** under Enterprise SSO and **Start with Google
Workspace**. Then, in the Google Admin console (you need super admin rights):

1. Go to **Apps → Web and mobile apps**, then **Add app → Add custom SAML app**.
2. Name the app and choose **Continue**.
3. On the **Google Identity Provider details** page, choose **Download Metadata**, then
   **Continue**. Keep the file: you paste it into the portal at the end.
4. Under **Service provider details**, fill in the values the portal shows, then
   **Continue**:

   | Google field | Value |
   |---|---|
   | ACS URL | The portal's ACS URL (`https://…/sso/saml/…/acs`) |
   | Entity ID | The portal's entity ID (`https://…/sso/saml/…`) |
   | Name ID format | `Email` |
   | Name ID | Basic Information > Primary email |

5. Under **Attribute mapping**, you may map **Primary email** to `email`, and **First name**
   and **Last name** to `firstName` and `lastName`, as the portal's steps do. The email
   attribute is optional: with Name ID format `Email`, the NameID already carries the
   address, and it is read from there when no attribute is sent. Choose **Finish**.
6. Open **User access** and turn the app **ON** for everyone, or for the groups or
   organizational units who should use it. Google can take a while to apply the change.
7. Paste the contents of the metadata file you downloaded into the portal's **Metadata URL
   or XML**.

Then verify your domain and turn single sign-on on in the portal. Google's own guide:
[Set up your own custom SAML app](https://support.google.com/a/answer/6087519).

**Certificate renewal.** Google's SAML signing certificates expire after several years.
When Google issues a new one, download the app's new metadata and use the portal's SAML
certificate renewal task. See [SAML certificate renewal](../certificate-renewal.md).

## Directory Sync

Google Workspace does not provision over SCIM, so the portal's Directory sync task does not
list it. Instead, the product's administrators can connect a Google Workspace directory
from their side: the product then reads your users and groups through Google's Admin SDK,
about once an hour. That needs a service account with domain-wide delegation and read access
to your directory, and the email of an admin it acts as. Ask the person who sent you the
link if you want this.

## Related

- [Enterprise SSO](../sso.md) — the portal side.
- [Identity provider guides](_index.md) — other providers.
