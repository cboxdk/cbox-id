---
title: Directory Sync
weight: 20
description: Connect your directory through the setup portal so it adds, updates and removes your people in the product automatically over SCIM 2.0 — the SCIM base URL, the bearer token, and where they go in your provider.
---

# Directory Sync

**Page:** Directory sync

With Directory Sync, your directory tells the product who your people are, over the
standard SCIM 2.0 protocol: who joins, who changes, and who leaves. When someone leaves
and you deactivate them in your directory, they lose access to the product too, without
anyone filing a ticket.

Your directory **pushes** to the product. You create a directory in the portal, which
gives you an address and a token, and paste both into your provider's provisioning
settings.

## 1. Choose your directory

Pick yours: Okta, Microsoft Entra ID, OneLogin, JumpCloud, or **SCIM 2.0** for any other
provider that can provision over SCIM.

Google Workspace does not push over SCIM. If your company uses Google Workspace as its
directory, the product's administrators can connect it from their side instead; ask the
person who sent you the link.

## 2. Create the directory

Give it a **Directory name**, for example "Okta", and choose **Create directory**.

The page shows the **Bearer token for** *the directory*. **Copy it now**: it is shown only
once and cannot be retrieved again. If you lose it, issue a new one (below).

## 3. Connect your provider

The page shows a table of your provider's field names and what goes in each:

| Value | Where it goes, by provider |
|---|---|
| **SCIM base URL**, `https://<sign-in host>/scim/v2` | Okta: "SCIM connector base URL". Entra ID: "Tenant URL". OneLogin: "SCIM Base URL". JumpCloud: "Base URL". |
| **Bearer token** | Okta: "Authorization (Bearer)", with Authentication Mode "HTTP Header". Entra ID: "Secret Token". OneLogin: "SCIM Bearer Token". JumpCloud: "Token Key". |

Okta also asks for the "Unique identifier field for users": set it to `userName`.

Then, in your provider:

- **Assign** the people and groups that should exist in the product. Only what you
  assign is sent.
- **Turn on deprovisioning** (deactivate users). That is the part that matters when
  someone leaves.
- **Push groups** if you want them in the product; its administrators can map your groups
  onto roles there, so access follows group membership.

Step-by-step clicks per provider are under **Step by step** on the page, and in the
[identity provider guides](idp/_index.md).

## 4. Check it worked

**Your directories** lists each directory with **Active** or **Paused** and when the last
update arrived: "Last update received …", or "No updates received yet". The task shows
**First update received** once your provider has sent something.

Nothing arriving usually means nobody is assigned to the app in your provider yet, or
provisioning is not switched on there. Microsoft Entra ID syncs on a cycle of about 40
minutes, so give it time or use its "Provision on demand" to test one person.

## Issue a new token

**New token** issues a fresh bearer token. The current one stops working at once, so paste
the new one into your provider straight away. You are asked to type the directory's name to
confirm. Do this whenever the token may have been seen by someone it should not have, for
example in a ticket or a chat.

You cannot pause or delete a directory from the portal. Ask the person who sent you the
link.

## Related

- [Generic SCIM](idp/generic-scim.md) — what the SCIM endpoint supports, for any provider.
- [Okta](idp/okta.md) and [Microsoft Entra ID](idp/entra-id.md) — step by step.
- [Enterprise SSO](sso.md) — sign the people Directory Sync creates in with their work account.
