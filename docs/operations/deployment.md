---
title: Deployment
weight: 2
description: From a fresh server to a running, hardened Cbox ID instance.
---

# Deployment

From a fresh server to a running, hardened Cbox ID instance. This is an identity
provider — the guidance here is deliberately security-first.

## Requirements

- **PHP 8.4+** with `ext-sodium` and `ext-openssl` (the crypto layer needs both;
  `cbox-id:doctor` fails loudly if either is missing).
- A database — **PostgreSQL or MySQL** in production (not SQLite).
- A cache/queue backend — **Redis** recommended (sessions, rate limits, queues).
- **TLS terminated in front of the app.** Passkeys (WebAuthn) and secure cookies
  require HTTPS; the platform assumes it.

See [Requirements](../requirements.md) for the full, `composer.json`-backed list.

## 1. Install the code

```bash
composer install --no-dev --optimize-autoloader
```

## 2. Bootstrap

The guided installer generates the crypto master key, asks the few questions that
matter (issuer URL, passkey domain/origin), writes them to `.env`, runs migrations,
and mints the first signing key:

```bash
php artisan cbox-id:install
```

Prefer a non-interactive deploy? Set the environment variables yourself (see
[Configuration](../configuration/environment-variables.md)) and run
`php artisan migrate --force` instead — `cbox-id:install` is the convenience path,
not a requirement.

## 3. Optimize for production

```bash
php artisan config:cache
php artisan route:cache
php artisan event:cache
```

Re-run these on every deploy after the code and `.env` are in place.

## 4. Create the first platform operator

A **platform operator** is the identity above every environment — it administers
environments, tenant organizations, and other operators. On a fresh install visit
**`/operator/login`**: with no operator yet it shows a one-time "create the first
operator" form (serialized behind a lock so only one can win the bootstrap), then
that path closes permanently. Immediately enroll a strong credential — this is the
most sensitive account on the system.

From the operator console, create your environment(s) and use **Provision admin**
on each to seed its first organization and owner-admin. Those org admins then sign
in at `/login`. (Because whoever reaches `/operator/login` first on a fresh,
internet-exposed deploy claims root, complete this step before exposing the host,
or bootstrap the operator on a private network first.)

## 5. Run the workers

Three processes, not one. The web container alone is **not** a working deployment:

```bash
php artisan queue:work --tries=3   # under a supervisor (systemd/supervisord)
php artisan schedule:work          # a long-running process — or `schedule:run` from cron, every minute
```

The scheduler is not optional and its absence does not raise an error. Without it the
domain-event outbox is never relayed, and because every subscriber hangs off that
outbox, all of the following silently do nothing:

| Without the scheduler | Consequence |
|---|---|
| `cbox-id:events:relay` | no webhook is ever delivered; no usage is metered (plan gates read zero); outbound SCIM never provisions; role changes never revoke tokens |
| `cbox-id:webhooks:retry` | a transient endpoint outage never recovers |
| `cbox-id:provisioning:drain` | the provisioning outbox never drains |
| `cbox-id:audit-streams:pump` | SIEM streams stop mid-flight |
| `cbox-id:keys:rotate` | signing keys never rotate or retire |

The app reports healthy throughout. Verify with:

```bash
php artisan schedule:list          # cbox-id:events:relay must appear, every minute
```

`docker-compose.yml` ships `app`, `queue` and `scheduler` services for exactly this
reason — mirror all three in any k8s manifest.

## 6. Verify

```bash
php artisan cbox-id:doctor
```

In production this also checks the **hardening** posture: `APP_DEBUG` off, secure +
encrypted session cookies. Treat any ✗ as release-blocking. A green doctor plus a
reachable `/.well-known/openid-configuration` means you're live.

## Reverse proxy notes

- Terminate TLS; forward the real scheme/host (`X-Forwarded-Proto`/`-Host`) and
  configure Laravel's trusted proxies (`TRUSTED_PROXIES`, see
  [Configuration](../configuration/environment-variables.md#reverse-proxy)) so
  issuer URLs and cookie `Secure` flags are correct.
- The discovery, JWKS, token, introspection, SCIM and SAML ACS endpoints are all
  served by the app — no separate service to route.

## Where to go next

- [Configuration](../configuration/environment-variables.md) — the env reference and
  secure defaults.
- [Day-2 operations](operations.md) — backups, key rotation, upgrades, break-glass.
