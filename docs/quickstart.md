---
title: Quickstart
weight: 2
description: Operator zero-to-running — from a fresh checkout to a signed-in platform console.
---

# Quickstart

The fastest path from a fresh checkout to a running Cbox ID with a signed-in
operator. For production hardening see [Deployment](operations/deployment.md); for
the full first-run walkthrough see [Installation](getting-started/installation.md).

## 1. Bootstrap

```bash
git clone … && cd cbox-id
composer setup          # installs deps, copies .env, creates the sqlite db,
                        # then runs `cbox-id:install` (guided: mints the crypto
                        # master key, migrates, and creates the first operator,
                        # environment and — in the SaaS shape — account)
composer run dev        # serve + queue + vite + logs
```

`composer setup` runs `cbox-id:install`. It asks for the first operator, the
deployment shape and the issuer URL, writes what it learns to `.env`, provisions the
platform, and finishes by running `cbox-id:doctor` against what it built. **Back up
`CBOX_ID_CRYPTO_KEY`** — losing it makes sealed secrets unrecoverable
([Operations](operations/_index.md)).

Fully non-interactive, for CI and images:

```bash
php artisan cbox-id:install --no-interaction --email=root@acme.example
```

## 2. …or claim it from the browser

Did not install from a shell — a container someone else started, say? An empty
deployment serves exactly one page, at **`/first-run`**, and points every other page
at it. It requires the **setup token** that the deployment publishes to
`storage/app/private/cbox-id-first-run.token` and to the application log
(`docker logs`), so reaching the URL is not enough to claim the platform. Completing
it does what the install command does, then the route 404s for good.

The **platform operator** it creates is the identity above every environment — it
administers environments, tenant organizations, and other operators. Enroll a
passkey or TOTP factor immediately; this is the most sensitive account on the
system.

## 3. Create an environment and its first org

Sign in at **`/workspace/login`** — the one door; there is no separate operator
login. The deployment pages are the **`/platform`** section of that console, and they
appear in the rail for whoever has authority over the deployment. From there create
your environment(s) and use **Provision admin** on each to seed its first
organization and owner-admin. Those org admins then sign in at `/login`; end users
sign in there too.

## 4. Verify

```bash
php artisan cbox-id:doctor
```

A green doctor plus a reachable `/.well-known/openid-configuration` means you're
live. Required environment variables are documented in
[Configuration](configuration/environment-variables.md).

## Where to go next

- [Requirements](requirements.md) — what the app needs to run.
- [Configuration](configuration/_index.md) — env reference + secure defaults.
- [Deployment](operations/deployment.md) — fresh server to a hardened instance.
