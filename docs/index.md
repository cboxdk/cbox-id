---
title: Cbox ID — operator documentation
weight: 1
description: The operator manual for deploying, configuring, and running the self-hostable Cbox ID app.
---

# Cbox ID — operator documentation

This is the **operator manual** for running the deployable Cbox ID app — the thing
you self-host. It covers deploying, configuring, and operating the platform.

> Building *against* Cbox ID (integrating an app, OAuth/OIDC/SCIM, entitlements)?
> That's the **framework** documentation, which ships inside the
> `cboxdk/laravel-id` package
> ([github.com/cboxdk/laravel-id](https://github.com/cboxdk/laravel-id/blob/main/docs/index.md)) —
> start with its
> [Start here](https://github.com/cboxdk/laravel-id/blob/main/docs/getting-started/start-here.md)
> guide.

## Cross-repo links

Cbox ID is a separate repository from the framework it composes. Because there is
no shared checkout, references into the framework docs use **canonical public URLs**
on `github.com/cboxdk/laravel-id`, never relative `../` paths. If you have the
package installed, the same files live under `vendor/cboxdk/laravel-id/docs/`.

## Start here

- [Accounts & organizations](core-concepts/accounts-and-organizations.md) — the five
  layers, and why "organization" means two different things depending on where you are
  standing. Read this before the rest.
- [Quickstart](quickstart.md) — operator zero-to-running in a few commands.
- [Requirements](requirements.md) — exactly what `composer.json` enforces.
- [Getting started](getting-started/_index.md) — installation and the first-run flow.
- [Integrate your app](getting-started/integrate-your-app.md) — where a `client_id` comes from, and the first SDK call.
- [Admin guides](guides/_index.md) — for the person administering an organization in
  the console: single sign-on, user sync, roles, access reviews. These are what the
  "?" beside each console page title links to.

## Configure and run

- [Configuration](configuration/_index.md) — every environment variable that
  matters, and the secure defaults this app ships with.
- [Operations](operations/_index.md) — deployment and day-2: **backing up the
  crypto key**, key rotation, the health check, audit/monitoring, upgrades, and
  break-glass.
- [Security](security/_index.md) — the operator-facing security surfaces and the
  system-level compliance view.

## The two-minute version

```bash
composer install --no-dev --optimize-autoloader
php artisan cbox-id:install     # keys, migrations, the first operator + environment
php artisan cbox-id:doctor      # confirms everything is healthy, in plain language
```

Then serve behind TLS, run the queue worker and scheduler, and **back up
`CBOX_ID_CRYPTO_KEY` somewhere separate from the database** — losing it makes
sealed secrets unrecoverable. Details in [Operations](operations/_index.md).

## What this app is

The deployable app built on `cboxdk/laravel-id`. The framework package provides the
identity engine (crypto, tenancy, OAuth/OIDC, SCIM, SAML, audit); this app adds the
admin console, onboarding, and hosted-cloud concerns. The console is Inertia +
React over server-rendered props: session-cookie auth, and no tokens in the
browser — because it *is* the login surface.
