# Cbox ID

The hosted, self-hostable identity platform — the deployable app built on
[`cboxdk/laravel-id`](../packages/laravel-id). Central login, enterprise SSO
(SAML/OIDC), directory sync (SCIM), RBAC, billing-driven entitlements, and a
tamper-evident audit trail.

Repo: `cboxdk/cbox-id` (private). This app composes the framework package and
adds the admin console + hosted-cloud concerns (UI, onboarding, billing).

## Stack

- **Laravel 13**, PHP 8.4+ (argon2id password hashing is the configured default).
- **Livewire + Volt + Tailwind v4** — server-rendered UI. Chosen for security:
  session-cookie auth (no tokens in the browser), a minimal JS surface, and a
  strict-CSP footprint suit an identity console.
- Depends on **`cboxdk/laravel-id`** (Composer/Packagist) plus the first-party
  observability stack (`laravel-telemetry`, `laravel-health`, `laravel-queue-metrics`,
  `laravel-queue-autoscale`).

## Quickstart

```bash
git clone … && cd cbox-id
composer setup          # installs deps, copies .env, creates the sqlite db,
                        # then runs `cbox-id:install` (guided: mints the crypto
                        # master key, sets issuer/WebAuthn, runs migrations)
composer run dev        # serve + queue + vite + logs
```

Then create the first **platform operator** (the identity above every
environment) by visiting **`/operator/login`** — on a fresh install it offers a
one-time "create the first operator" form, then closes. From the operator console
you create environments and provision each one's first organization + admin. End
users then sign in at `/login`.

Required env (all in `.env.example`, keep secrets out of git): `CBOX_ID_CRYPTO_KEY`
(base64 32 bytes — **back it up**), `CBOX_ID_ISSUER`, `CBOX_ID_WEBAUTHN_RP_ID`,
`CBOX_ID_WEBAUTHN_ORIGIN`. Brand the console without editing code via
`CBOX_ID_BRAND_*`; gate self-service signup with `CBOX_ID_SIGNUP_MODE`.

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

Production-ready. Shipped: full auth (password + magic-link + TOTP MFA +
passkeys + social), signup → org onboarding with signup-mode lockdown, the
9-section org admin console (Overview, Members, SSO, Directory/SCIM, Roles, API
clients, Webhooks, Audit, Settings), the **operator console** (environments,
tenant management, operators) above every environment, branded error screens with
telemetry trace IDs, and health/metrics endpoints. Session-cookie auth, strict
CSP, rate limiting, and argon2id throughout.
