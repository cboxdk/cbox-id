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
and optionally a second icon for dark mode with the same limits, or use the
automatic initials fallback. Without a dark icon, the light one is shown in both
themes. Icons are public assets served by ID; do not upload confidential artwork.
Drafts stay hidden until published.

The catalog is the single source of truth for app icons. Products read the icons
from here; they do not ship their own copy.

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
`launch_url`, `icon_url`, `icon_dark_url`, `position`, and `version`.
`icon_dark_url` is `null` when the app has no dark icon; then use `icon_url` in
both themes. `meta.revision` hashes that visible list, so it changes when either
icon changes; `meta.refresh_after` is 60 seconds. Administrative audiences,
OAuth client IDs and icon bytes are not included.

Render the returned order with ordinary cross-app links. Cache only per issuer,
person and selected organization. Refresh on focus/open when stale and while
visible, and clear on logout or context changes. A server response is private and
not HTTP-cacheable. There is no catalog-change webhook in this release.
Icons use content-addressed public URLs; allow the issuer in your image CSP and
show initials when an image fails.

## Read a person's apps from your app's backend

An app that has already signed a person in can read their catalog with its own
client-credentials token, so its switcher never needs the person's
`account:apps:read` grant, a reconnect or a sign-in hop:

```
GET /api/v1/app-catalog/for-user?user_id={user id}&organization_id={organization id}
Authorization: Bearer {client-credentials access token}
```

Grant the app the `app_catalog:resolve` scope in the console, among its
Platform API scopes. The scope is reserved for the console: a management key cannot
hand it to an app. Then fetch a token with `grant_type=client_credentials` and
`scope=app_catalog:resolve` from this environment's token endpoint and reuse it
until it expires.

`organization_id` is required. The answer is exactly that of `GET /api/v1/me/apps`
with the same `organization_id`: the same items, in the same order, with the same
`meta.revision` and `meta.refresh_after`. A person who does not exist, is not
active, or is not an active member of the organization gets `404 not_found`. The
answer does not say which of these it is. A token without the scope gets `403`.
A person's account token (`account:apps:read`) is refused; this route is for the app.

The read is not audited. Like `/me/apps`, cache it per issuer, person and
organization for `refresh_after` seconds.

## Management API

- `GET /api/v1/app-catalog` and `GET /api/v1/app-catalog/{key}` require `app_catalog:read`.
- `GET /api/v1/app-catalog/for-user` requires `app_catalog:resolve` (see above).
- `PUT /api/v1/app-catalog/{key}` requires `app_catalog:write`; send the full entry with `version: 0` to create, or the version read to update.
- `DELETE /api/v1/app-catalog/{key}` requires `app_catalog:write` and the current `version`.

A stale edit/delete returns 409. Reload and review before retrying. PUT omits
`icon` or `icon_dark` to retain it, uses `null` to clear it, or a supported image
data URI to replace it. SVG is refused for both. A no-op does not bump the version. Saves and deletions are audited
without copying the icon or target lists into the audit context.

See the [environment actions](../reference/actions-environment.md) and
[account actions](../reference/actions-account.md) for the generated schemas.
The `cbox-design` skill's `references/app-switcher.md` defines the common desktop
and mobile UI contract for rolling this out to other Cbox products.
