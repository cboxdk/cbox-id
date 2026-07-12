# Cbox ID — operator documentation

This is the **operator manual** for running the deployable Cbox ID app — the thing
you self-host. It covers deploying, configuring, and operating the platform.

> Building *against* Cbox ID (integrating an app, OAuth/OIDC/SCIM, entitlements)?
> That's the **framework** documentation, in
> [`cboxdk/laravel-id`](../../packages/laravel-id/docs/index.md) — start with
> [Start here](../../packages/laravel-id/docs/start-here.md).

## Run it

- [Deployment](deployment.md) — from a fresh server to a running, hardened instance.
- [Configuration](configuration.md) — every environment variable that matters, and
  the secure defaults this app ships with.
- [Operations](operations.md) — day-2: **backing up the crypto key**, key rotation,
  the health check, audit/monitoring, upgrades, and break-glass.
- [Compliance](compliance.md) — the system-level view (framework controls + what this
  app adds + what remains yours), linking to the framework's control mapping.

## The two-minute version

```bash
composer install --no-dev --optimize-autoloader
php artisan cbox-id:install     # generates keys, asks the few questions, migrates
php artisan cbox-id:doctor      # confirms everything is healthy, in plain language
```

Then serve behind TLS, run the queue worker and scheduler, and **back up
`CBOX_ID_CRYPTO_KEY` somewhere separate from the database** — losing it makes
sealed secrets unrecoverable. Details in [Operations](operations.md).

## What this app is

The deployable app built on `cboxdk/laravel-id`. The framework package provides the
identity engine (crypto, tenancy, OAuth/OIDC, SCIM, SAML, audit); this app adds the
admin console, onboarding, and hosted-cloud concerns. It's server-rendered
(Livewire + Volt) on purpose — session-cookie auth, minimal JS, no tokens in the
browser — because it *is* the login surface.
