# Cbox ID

The hosted, self-hostable identity platform — the deployable app built on
[`cboxdk/laravel-id`](../packages/laravel-id). Central login, enterprise SSO
(SAML/OIDC), directory sync (SCIM), RBAC, billing-driven entitlements, and a
tamper-evident audit trail.

Repo: `cboxdk/cbox-id` (private). This app composes the framework package and
adds the admin console + hosted-cloud concerns (UI, onboarding, billing).

## Stack

- **Laravel 13**, PHP 8.4+
- **Livewire + Volt + Tailwind v4** — server-rendered UI. Chosen for security:
  session-cookie auth (no tokens in the browser), a minimal JS surface, and a
  CSP-friendly footprint suit an identity console.
- `cboxdk/laravel-id` wired via a Composer **path repository** to `../packages/*`
  (edit the framework, changes are live).

## Framework wiring

The package auto-registers its service providers, migrations, config and HTTP
routes. Because a host app owns its own users, the framework does **not** create
a `users` table; this app is greenfield, so it publishes the optional one.

```bash
composer install
php artisan vendor:publish --tag=cbox-id-config              # config/cbox-id.php
php artisan vendor:publish --tag=cbox-id-users-migration     # greenfield users table
php artisan migrate
```

Required env (see `.env`): `CBOX_ID_CRYPTO_KEY` (base64 32 bytes),
`CBOX_ID_ISSUER`, `CBOX_ID_WEBAUTHN_RP_ID`, `CBOX_ID_WEBAUTHN_ORIGIN`.

Live platform endpoints (from the package): `/.well-known/openid-configuration`,
`/.well-known/jwks.json`, `POST /oauth/token`, `POST /oauth/introspect`,
`/scim/v2/Users`, `POST /sso/saml/{connection}/acs`.

## Operator documentation

Running or self-hosting this app? See [`docs/`](docs/index.md):

- [Deployment](docs/deployment.md) — fresh server to a hardened instance.
- [Configuration](docs/configuration.md) — env reference + secure defaults.
- [Operations](docs/operations.md) — crypto-key backup, key rotation, upgrades, break-glass.

Integrating *against* the platform (OAuth/OIDC/SCIM, entitlements, existing users)
is the framework documentation in [`cboxdk/laravel-id`](../packages/laravel-id/docs/index.md).

## Develop

```bash
composer run dev     # serve + queue + vite + logs
```

## Status

Scaffold + framework wiring complete. Admin console and auth/onboarding flows
are next.
