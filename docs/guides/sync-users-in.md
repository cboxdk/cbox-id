---
title: Sync users in
weight: 40
description: Let Microsoft Entra ID, Okta or Google Workspace create, update and deactivate people in Cbox ID automatically over SCIM, and map their groups onto your roles.
---

# Sync users in

**Console page:** Sign-in › Sync users in

Syncing users in means your identity provider — not you — decides who exists here.
It creates people when they join, updates them when their details change, and
deactivates them when they leave, over a standard called **SCIM 2.0**.

[Single sign-on](single-sign-on.md) and syncing solve different halves of the same
problem. SSO answers *"is this really them?"* at the moment they sign in. Syncing
answers *"should this person exist at all?"* continuously — which is what closes
the gap where a leaver still has a working account somewhere because no one filed a
ticket.

## Two ways to connect

**Cbox ID pulls** — for Google Workspace and Microsoft Entra, connect the directory
directly on the page with admin credentials. Cbox ID reads users and groups on a
schedule. Nothing to configure on the provider side beyond consent.

**Your provider pushes** — for Okta, OneLogin, or anything else that speaks SCIM.
Register a directory here to get a bearer token, then point your provider at the
SCIM base URL shown on the page (`/scim/v2` on your Cbox ID host) and authenticate
with that token.

## Set it up

1. Register the directory (or connect Google/Entra directly). The bearer token is
   shown **once** — store it in your provider immediately.
2. In your provider, assign the people and groups that should have access. Only
   what you assign is sent; a SCIM connection does not mean "everyone in the
   company" unless you scope it that way.
3. Watch the first sync land. Directories show as **Active** or **Paused**, with the
   last error if one occurred.
4. Map the groups your provider sends onto your [roles](roles.md). This is the part
   worth doing carefully: once `Engineering` maps to a role, access follows group
   membership, and you stop granting anything by hand.

## Things worth knowing

- **Deactivation is the point.** Confirm that deactivating a test user in your
  provider deactivates them here. If it does not, your provider is probably not
  configured to send deactivations, and the whole arrangement is only doing half
  its job.
- **The token is a credential.** Anyone holding it can create and deactivate people
  in your organization. Rotate it if it has ever been in a chat message or a ticket.
- **Group mapping is push-based.** Everyone in a mapped group gets the role; remove
  the mapping and the grant goes with it.

## Troubleshooting

**Nothing appears after connecting** — nobody is assigned to the application in your
provider. Assign users or groups there first.

**"Could not connect … check the credentials and admin consent"** — the service
account or app registration lacks directory read permission, or admin consent was
never granted in the provider.

**Someone was created but has no access** — creating a person is not granting them
anything. Map their group onto a role, or assign one on the Members page.

## Related

- [Sync users out](sync-users-out.md) — the same idea in the other direction.
- [Roles](roles.md) — what group mappings actually grant.
