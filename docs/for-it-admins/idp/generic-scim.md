---
title: Any SCIM 2.0 provider
weight: 60
description: Connect any SCIM 2.0 provisioning client to Directory Sync through the setup portal — the base URL, the bearer token, and what the endpoint supports.
---

# Any SCIM 2.0 provider

Use this for a directory the portal does not list by name but that can provision over SCIM
2.0. In the portal, choose **SCIM 2.0** under Directory sync, then **Create directory**,
and copy the bearer token straight away; it is shown only once.

## What to set up

In your provider, add a SCIM 2.0 provisioning connection. Providers call it outbound
provisioning, app provisioning or a SCIM integration. Enter:

| Setting | Value |
|---|---|
| SCIM base URL | The portal's **SCIM base URL** (`https://…/scim/v2`) |
| Authentication | `Authorization: Bearer <token>`, with the portal's bearer token |

Then test the connection and turn provisioning on.

## What the endpoint supports

- **Users and Groups**, at `/Users` and `/Groups`: create, read, replace (`PUT`), update
  (`PATCH`) and delete.
- **`userName` is the email address.** Send the person's work email as `userName`.
- **Deactivation**: set `active` to `false`, or delete the user, when someone leaves. This
  is what removes their access, so make sure your provider sends it.
- **Discovery** at `/ServiceProviderConfig`, `/ResourceTypes` and `/Schemas`, which many
  providers read during setup.

Requests are limited to 120 a minute from one address. A provider syncing a large
directory at once is slowed down, so let its first sync run its course.

The protocol details are in the framework's
[SCIM guide](https://github.com/cboxdk/laravel-id/blob/main/docs/core-concepts/scim.md).

## Related

- [Directory Sync](../directory-sync.md) — the portal side, and issuing a new token.
- [Identity provider guides](_index.md) — providers the portal guides step by step.
