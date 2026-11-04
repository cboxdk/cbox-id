---
title: App catalog and switcher
weight: 76
description: Publish one app list for your products, with uploaded icons and personal visibility.
---

# App catalog and switcher

Environment administrators publish apps under **Developers → App catalog**.
The navigation switcher and dashboard read this catalog. OAuth client registration
alone does not publish an app, and catalog visibility does not grant app access.

Create a stable lowercase key, name, optional description, explicit HTTPS launch
URL and position. Upload a PNG, JPEG or WebP icon (128 KiB / 512 pixels maximum),
or use the automatic initials fallback. Icons are public assets served by ID;
do not upload confidential artwork. Drafts stay hidden until published.

Choose everyone in the environment, selected organizations, or selected users.
Organization visibility requires active membership and an active organization.
An optional feature flag further narrows visibility. Related environment-wide
OAuth clients are references only; several integrations can belong to one app.

## Read the person's apps

`GET /api/v1/me/apps` requires a delegated person token with
`account:apps:read`, under the existing account API authentication contract
(management resource audience `<issuer>/mcp`, including DPoP where required).
An environment key or a token issued only for another API is not a person token.

Optionally pass `organization_id` to narrow the result to the selected active
membership. A token confined to an organization cannot select a different one.
Without it, the list covers the person's active organizations.

The response contains `data` entries with `key`, `name`, `description`,
`launch_url`, `icon_url`, `position`, and `version`. `meta.revision` hashes that
visible list; `meta.refresh_after` is 60 seconds. Administrative audiences,
OAuth client IDs and icon bytes are not included.

Render the returned order with ordinary cross-app links. Cache only per issuer,
person and selected organization. Refresh on focus/open when stale and while
visible, and clear on logout or context changes. A server response is private and
not HTTP-cacheable. There is no catalog-change webhook in this release.
Icons use content-addressed public URLs; allow the issuer in your image CSP and
show initials when an image fails.

## Management API

- `GET /api/v1/app-catalog` and `GET /api/v1/app-catalog/{key}` require `app_catalog:read`.
- `PUT /api/v1/app-catalog/{key}` requires `app_catalog:write`; send the full entry with `version: 0` to create, or the version read to update.
- `DELETE /api/v1/app-catalog/{key}` requires `app_catalog:write` and the current `version`.

A stale edit/delete returns 409. Reload and review before retrying. PUT omits
`icon` to retain it, uses `null` to clear it, or a supported image data URI to
replace it. A no-op does not bump the version. Saves and deletions are audited
without copying the icon or target lists into the audit context.

See the [environment actions](../reference/actions-environment.md) and
[account actions](../reference/actions-account.md) for the generated schemas.
The `cbox-design` skill's `references/app-switcher.md` defines the common desktop
and mobile UI contract for rolling this out to other Cbox products.
