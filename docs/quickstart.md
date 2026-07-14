---
title: Quickstart
weight: 2
description: Operator zero-to-running — from a fresh checkout to a signed-in operator console.
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
                        # master key, sets issuer/WebAuthn, runs migrations)
composer run dev        # serve + queue + vite + logs
```

`composer setup` runs `cbox-id:install`, which mints the `CBOX_ID_CRYPTO_KEY`,
asks for the issuer URL and passkey domain/origin, writes them to `.env`, migrates,
and mints the first signing key. **Back up `CBOX_ID_CRYPTO_KEY`** — losing it makes
sealed secrets unrecoverable ([Operations](operations/_index.md)).

## 2. Create the first platform operator

The **platform operator** is the identity above every environment — it administers
environments, tenant organizations, and other operators. On a fresh install, visit
**`/operator/login`**: with no operator yet it shows a one-time "create the first
operator" form (serialized behind a lock so only one can win the bootstrap), then
that path closes permanently. Enroll a strong credential immediately — this is the
most sensitive account on the system.

> On an internet-exposed deploy, whoever reaches `/operator/login` first claims
> root. Complete this step before exposing the host, or bootstrap the operator on a
> private network first.

## 3. Create an environment and its first org

From the operator console, create your environment(s) and use **Provision admin**
on each to seed its first organization and owner-admin. Those org admins then sign
in at `/login`; end users sign in there too.

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
