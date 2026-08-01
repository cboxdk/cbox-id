---
title: Sync users out
weight: 50
description: Push your people into the other SaaS products your company uses over their SCIM endpoints, so onboarding and offboarding happen once instead of once per vendor.
---

# Sync users out

**Console page:** Sign-in › Sync users out

This is [syncing users in](sync-users-in.md) turned around. Instead of a provider
filling Cbox ID, Cbox ID fills the other SaaS products your company pays for: it
creates accounts there when someone joins, updates them, and deactivates them when
they leave — over each product's own SCIM 2.0 endpoint.

The reason to bother is the leaver. Most organizations can name the day someone
left; far fewer can prove that every tool they had a seat in knew about it. Each
connection registered here is one more tool that finds out automatically.

## Set up a connection

1. In the downstream app's admin settings, find its SCIM endpoint (often called
   "provisioning" or "directory sync") and generate a token there.
2. In the console, choose **Register connection** and enter:
   - a **name** you will recognise in a list a year from now,
   - the app's **SCIM base URL**,
   - the **auth scheme** — a bearer token, or OAuth2 client credentials,
   - the **secret**. It is sealed at rest and never shown again.
3. Save. Changes to your people are pushed from then on.
4. Confirm on the downstream side that a test user arrived, then deactivate that
   test user here and confirm the deactivation arrived too.

## Things worth knowing

- **Each vendor's SCIM is a little different.** If a push fails, the connection
  records the error — read it before assuming the token is wrong; the most common
  cause is a required attribute the downstream app wants and is not being sent.
- **Rotate secrets here, not by re-registering.** Registering a second connection to
  the same app means two systems pushing at it.
- **Deactivation, not deletion.** Cbox ID deactivates downstream accounts; whether
  the vendor then deletes data is the vendor's policy, not ours.

## Related

- [Activity log](activity-log.md) — every push is recorded there.
- [Sync users in](sync-users-in.md).
