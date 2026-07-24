---
title: Day-2 operations
weight: 3
description: Running a live Cbox ID — crypto-key backup, signing-key rotation, health checks, audit/monitoring, upgrades, and break-glass.
---

# Day-2 operations

Day-2 running of a live identity provider. Read the first section before anything
else — it's the one mistake you can't undo.

## Back up the crypto key (the one irreversible thing)

`CBOX_ID_CRYPTO_KEY` is the master key that seals every secret the platform
encrypts (connection credentials, sealed tokens, …). **If you lose it, those
secrets are unrecoverable — no reset, no recovery.**

- Store it in a **secrets manager** (Vault, AWS/GCP Secrets Manager, 1Password),
  **separate from the database** — a DB backup plus the key in the same place
  defeats envelope encryption.
- Back up `APP_KEY` the same way (it protects Laravel cookies/encryption).
- When you restore a database backup, restore it **with the same crypto key** that
  was live when it was taken, or the sealed columns won't decrypt.

Everything else on this page is routine. This is the part that has to be right.

## Signing-key rotation

Tokens are signed with rotating keys published at `/.well-known/jwks.json`. Rotate
on a schedule (e.g. quarterly) and immediately on suspected compromise:

```bash
# Mint a fresh active key; new tokens sign with it. Old keys stay published so
# tokens already issued keep validating (kid overlap) until they age out.
php artisan cbox-id:keys:rotate

# Rotate AND retire keys older than N hours in one step (drain, then remove):
php artisan cbox-id:keys:rotate --retire-after=168

# Rotate onto a different algorithm when you need to:
php artisan cbox-id:keys:rotate --alg=ES256
```

Never retire the previous key before the longest-lived token signed by it has
expired — retiring early invalidates live tokens. The `--retire-after` window
should exceed your access-token TTL.

## Health checks

```bash
php artisan cbox-id:doctor
```

Run it after every deploy and as a periodic probe. It verifies extensions, the
crypto key, migrations, active signing keys, issuer, passkey config, and — in
production — the hardening posture (`APP_DEBUG` off, secure + encrypted sessions).
Exit code is non-zero only on real problems, so it's safe to wire into CI/monitoring.

## Audit & monitoring

- The platform writes an **append-only, hash-chained audit trail** with signed
  checkpoints — tamper-evident by design. Ship it to your SIEM via the audit
  read/pull-stream API (see the framework's
  [Security](https://github.com/cboxdk/laravel-id/blob/main/docs/security/_index.md)
  docs).
- Watch the queue depth and failures (webhook delivery + event outbox ride it) and
  the scheduler (key retirement/cleanups depend on it).
- Alert on auth anomalies surfaced by risk scoring (`cboxdk/laravel-risk`) and on
  audit-chain verification failures.

## Upgrades

```bash
composer update --no-dev
php artisan migrate --force
php artisan config:cache
php artisan route:cache
php artisan event:cache
php artisan cbox-id:doctor
```

The three cache commands are separate artisan invocations (or use
`php artisan optimize` to run them together). Roll forward one release at a time;
run `doctor` before returning traffic. Because the framework is a versioned package
pinned to a pre-1.0 series (`cboxdk/laravel-id >=0.45 <1.0`), check its changelog for
migration or config changes before bumping — minor bumps in that range may carry
breaking changes.

## Break-glass (emergency admin access)

If normal admin access is lost (MFA device gone, admin locked out), recover through
an **out-of-band, audited** path — never by weakening the running config:

1. Access the server/console directly (SSH + artisan), not the public UI.
2. Provision or re-enroll a break-glass admin via a seeding/artisan path; the action
   is written to the audit trail like any other.
3. Enroll a fresh MFA/passkey on it immediately, complete the emergency task, then
   **rotate anything exposed** (signing keys if a key was touched, the break-glass
   credential afterward).

Do **not** set `APP_DEBUG=true`, disable MFA globally, or loosen session hardening to
get back in — that trades a lockout for a breach.

## Where to go next

- [Configuration](../configuration/environment-variables.md) — the variables
  referenced above.
- Framework
  [Security](https://github.com/cboxdk/laravel-id/blob/main/docs/security/_index.md)
  and
  [Threat model](https://github.com/cboxdk/laravel-id/blob/main/docs/security/threat-model.md) —
  the invariants this app inherits.
