---
title: Installation & first run
weight: 2
description: Install the app, bootstrap the first platform operator, and provision an environment and organization.
---

# Installation & first run

This walks the full first-run flow. For the two-minute version see
[Quickstart](../quickstart.md); for production deployment see
[Deployment](../operations/deployment.md).

## 1. Install

```bash
composer install --optimize-autoloader
cp .env.example .env         # if you are not using `composer setup`
php artisan key:generate
```

Then run the guided installer, which mints the crypto master key, asks for the
issuer URL and passkey domain/origin, writes them to `.env`, migrates, and mints
the first signing key:

```bash
php artisan cbox-id:install
```

Prefer a non-interactive install? Set the variables yourself (see
[Configuration](../configuration/environment-variables.md)) and run
`php artisan migrate --force`.

## 2. The identity hierarchy

Cbox ID has three layers, top to bottom:

1. **Platform operator** — the identity above everything. Administers environments,
   tenant organizations, and other operators. Signs in at `/operator/login`.
2. **Environment** — an isolated plane (e.g. staging vs production, or per-region).
   Created and managed by operators.
3. **Organization (tenant)** — a customer's org with its own members, roles, SSO,
   and audit trail. Org admins and members sign in at `/login`.

## 3. Create the first operator

On a fresh install, visit **`/operator/login`**. With no operator yet, it shows a
one-time "create the first operator" form, serialized behind a lock so only one
request can win the bootstrap. Once an operator exists, that path closes
permanently. Enroll a strong credential (passkey/MFA) immediately.

## 4. Provision an environment and organization

From the operator console, create an environment, then use **Provision admin** to
seed its first organization and owner-admin. That admin signs in at `/login`.

## Key surfaces this app ships

Beyond the login and admin console (see [Screens](screens.md)), the app serves
several operator- and end-user-facing flows:

- **Device approval (`/device`)** — the OAuth 2.0 Device Authorization Grant
  confirmation page. A user completing the device flow enters/confirms the user code
  here and approves the device before it receives tokens.
- **OAuth consent (`/oauth/authorize`)** — the authorization endpoint's consent
  screen. When a registered client requests access, the signed-in user reviews the
  requested scopes and grants or denies them; the decision is recorded.

For step-up authentication (`/sudo`), the organization switcher's security model,
and self-service signup modes, see [Security](../security/_index.md).
