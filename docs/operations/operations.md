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

## Rotating the crypto master key

`CBOX_ID_CRYPTO_KEY` can be rotated without losing a secret (laravel-id 1.22+). Every
sealed value names the key it was sealed under (`v1.<key-id>.…`, the id an HMAC of the
key, never the key), the old key is kept only to **open** values until they have been
re-sealed, and `cbox-id:crypto:rewrap` moves everything onto the new one. Rotate on your
key-custody schedule, when somebody who could read the key leaves, and after a suspected
compromise — and after a compromise rotate the secrets themselves too (signing keys,
webhook and connection secrets): a re-seal does not un-read what a leaked key already
opened.

1. **Generate** the new key:

   ```bash
   php -r "echo base64_encode(random_bytes(32)).PHP_EOL;"
   ```

2. **Swap and deploy.** Make the new key `CBOX_ID_CRYPTO_KEY` and move the old one into
   `CBOX_ID_CRYPTO_PREVIOUS_KEYS` (comma-separated if you already keep one there).
   Deploy everywhere — web, queue workers and the scheduler all seal and open secrets.
   From this moment new secrets are sealed under the new key and old ones still open.
   Store the new key in your secrets manager **before** the deploy, and keep the old one
   there too.

3. **Rewrap, dry run first:**

   ```bash
   php artisan cbox-id:crypto:rewrap --dry-run   # counts per column, writes nothing
   php artisan cbox-id:crypto:rewrap             # re-seals onto the current key
   ```

   It walks every registered sealed column across **every environment** (the key is
   deployment-wide), in bounded chunks, and only writes a value back if the row still
   holds what it read — a secret rotated mid-run is never overwritten. It is safe to
   interrupt and re-run; `--column=table.column` limits a run to one column and
   `--chunk=` sizes the batches. A value no configured key opens is reported by row id and
   left alone, and the command exits non-zero — do not go on to step 5 while that is so.

   The columns this app adds are registered beside the framework's, so the rewrap covers
   them: the devices module's push tokens (`id_devices.token_encrypted`).

4. **Check:**

   ```bash
   php artisan cbox-id:doctor
   ```

   The **Master key rotation** line warns while anything is still sealed under a previous
   key, and tells you when the previous keys are no longer needed. It is a warning, not a
   failure: a deploy mid-rotation is healthy.

5. **Remove the old key** from `CBOX_ID_CRYPTO_PREVIOUS_KEYS` and deploy — only once the
   doctor says nothing needs it. Keep it in your offline backup for as long as you keep
   database backups taken before the rotation: restoring one of those needs it again.

One-time codes already mailed when you swap keys stop verifying (their HMAC key is
derived from the current master key); they live for minutes, and the person asks for a
new one. The framework's [master key
management](https://github.com/cboxdk/laravel-id/blob/main/docs/security/key-management.md)
page has the envelope format and the honest limits.

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

- The platform writes an **append-only, hash-chained audit trail**. Each entry hashes
  the one before it, so modifying an entry or removing one from the middle breaks the
  chain and `verifyChain()` reports where. Ship it to your SIEM via the audit
  read/pull-stream API (see the framework's
  [Security](https://github.com/cboxdk/laravel-id/blob/main/docs/security/_index.md)
  docs).

- **Signed checkpoints are OPT-IN and off by default, so TAIL deletion is not detected
  until you turn them on.** The chain catches modification and gaps by itself; delete the
  newest N entries and what remains is a shorter, perfectly valid chain. Only a signed
  checkpoint — a permanent, exportable statement about the chain's head at a point in
  time — makes that visible.

  It defaults to off deliberately rather than by oversight. The first signature is a
  one-way door: a checkpoint is evidence about the hashes *as they are today*, and any
  later re-chain of the existing rows would make every checkpoint signed before it report
  tampering that never happened, forever. Erasing a person (`users.erase`, laravel-id
  1.22) does **not** need one: it leaves past entries untouched — they keep the person's
  opaque id — and appends a `user.erased` tombstone, so the chain verifies afterwards.

  Set `CBOX_ID_AUDIT_CHECKPOINT_SCHEDULE=true` only after following the order in the
  framework's `UPGRADING.md` — or right away on a deployment with no such migration
  ahead of it. `cbox-id:audit:checkpoint --dry-run` reports what would be signed without
  signing anything.
- Watch the queue depth and failures (webhook delivery + event outbox ride it) and
  the scheduler (the cleanups depend on it). **Key retirement does not** —
  `cbox-id:keys:rotate` is not scheduled and never has been; you run it on your own
  cadence. See [deployment](deployment.md#5-run-the-workers). Platform › Insights ›
  Queues and the `queue_workers` check on `/health/status` show whether the queue manager
  is running and how far behind each queue is — see [Queue workers](queue-workers.md).
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
run `doctor` before returning traffic. Read [UPGRADING](https://github.com/cboxdk/cbox-id/blob/main/UPGRADING.md) for the
release first: it says what the operator has to do, including the framework's
(`cboxdk/laravel-id`) migrations.

**Rehearse the migration before production runs it**, against a restored copy of your
database, and take a backup you have restored before: a backup is the only complete way
back. [Rehearsing an upgrade](upgrade-rehearsal.md) has the steps and the seeded check
that runs on every release.

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
