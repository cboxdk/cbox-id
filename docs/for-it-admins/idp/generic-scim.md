---
title: Any SCIM 2.0 provider
weight: 60
description: Connect any SCIM 2.0 provisioning client to Directory Sync through the setup portal — the base URL, the bearer token, and what the endpoint supports — filters, sorting, ETags and Bulk.
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
- **`externalId` is unique.** Creating a user with an `externalId` the directory already
  holds is answered `409` with `scimType: uniqueness`, and so is a second group with the
  same `displayName`. Microsoft Entra ID and Okta expect exactly that: they then find the
  existing user with a filter and update it. A client that relied on `POST` updating an
  existing user must do the same: `GET /Users?filter=externalId eq "…"`, then `PUT` or
  `PATCH`. Changing a provisioned user's `externalId` with `PATCH` is refused
  (`400 mutability`).
- **Deactivation**: set `active` to `false`, or delete the user, when someone leaves. This
  is what removes their access, so make sure your provider sends it.
- **Filters** (`?filter=`): every operator (`eq ne co sw ew gt ge lt le pr`), `and`, `or`
  and `not` with the standard precedence, grouping with parentheses, and value filters such
  as `emails[type eq "work"].value`. Dates (`meta.created`, `meta.lastModified`) compare
  as dates. A filter on an attribute the directory does not store is refused with
  `400 invalidFilter`, never answered with every user.
- **Sorting**: `sortBy` and `sortOrder` (`ascending` or `descending`) on `/Users` and
  `/Groups`. An attribute that cannot be sorted on is `400 invalidValue`.
- **ETags**: every resource carries a weak `ETag` header and `meta.version`, which change on
  every write (a group's also when its members change). Send `If-Match` on `PUT`, `PATCH`
  or `DELETE` and a stale tag is answered `412`, so two writers cannot silently overwrite
  each other. `If-None-Match` on a read answers `304` when nothing changed.
- **Bulk**: `POST /Bulk` runs up to 1,000 `POST`, `PUT`, `PATCH` and `DELETE` operations on
  users and groups in one request, of up to 1 MiB. `bulkId` references (`"value":
  "bulkId:u1"`) resolve to the resource an earlier operation in the same request created,
  `version` is honoured as `If-Match`, and `failOnErrors` stops the run after that many
  failures. A larger request is answered `413`.
- **Discovery** at `/ServiceProviderConfig`, `/ResourceTypes` and `/Schemas`, which many
  providers read during setup. `ServiceProviderConfig` advertises filter, sort, ETag and
  bulk support, and the bulk limits.

Requests are limited to 120 a minute from one address. A bulk request counts once, so a
provider that batches its first sync finishes it far sooner.

The protocol details are in the framework's
[SCIM guide](https://github.com/cboxdk/laravel-id/blob/main/docs/core-concepts/scim.md).

## Related

- [Directory Sync](../directory-sync.md) — the portal side, and issuing a new token.
- [Identity provider guides](_index.md) — providers the portal guides step by step.
