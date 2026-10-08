---
title: Rehearsing an upgrade
weight: 6
description: Prove a release upgrades your database before you deploy it — the seeded check on MySQL and PostgreSQL, and the rehearsal against a restored copy of your own data.
---

# Rehearsing an upgrade

An upgrade is `php artisan migrate --force` against the only copy of your identity data.
Rehearse it first, on a database you can throw away. There are two rehearsals, and you want
both:

1. **The seeded check** (`scripts/upgrade-path-check.sh`). The previous release builds a
   database the way production would — through its own services, so password hashes,
   sealed secrets and the audit chain are what it really writes — and this release
   upgrades it and checks every credential still works. It runs on every release tag in CI
   and on a laptop with Docker. It proves the migrations and the credential formats, on
   data shaped like yours.
2. **Your own data.** A restored copy of your production database, upgraded with the new
   release. Only this one finds what the seeded check cannot know about: a row an old bug
   wrote, a table far larger than the seed's, a hand edit someone made in 2025. The seeded
   check passing does not replace it.

## The seeded check

```bash
composer install && npm ci && npm run build      # this checkout, as for development
scripts/upgrade-path-check.sh --from v1.1.1 --engine mysql
scripts/upgrade-path-check.sh --from v1.1.1 --engine pgsql
```

It needs PHP, Composer, git and Docker, starts its own `mysql:8.4` or `postgres:17`
container on a free port, and removes everything when it is done (`--keep` leaves the
container and the scratch directory for a look). In order:

| Stage | What passes |
|-------|-------------|
| Previous release | `git archive <tag>` and `composer install`; `migrate`, `cbox-id:install --multi-tenant` and `scripts/upgrade-path/seed-v1.php`: people with passwords, TOTP, recovery codes and a passkey; three organizations and their members; apps with client secrets, sessions and a refresh token; invitations and role grants; webhooks; a SAML and an OIDC connection; a SCIM directory; a vault secret; a personal API token; `cbid_env_` and `cbid_org_` management keys; Admin Portal links; audit entries |
| Upgrade | this checkout's `migrate --force` |
| Row counts | no table that held rows is dropped, and no table loses rows except where a migration says it does (`scripts/upgrade-path/compare-counts.php` names each one) |
| Verification | `scripts/upgrade-path/verify.php`, with the new code: every password, TOTP secret, recovery code, client secret, refresh token, session, webhook secret, SSO configuration, SCIM token, vault secret, personal token and environment key from the old release still works; the old signing key is still the active one; the audit chains verify; the documented data changes happened |
| `audit-chain:verify` | every chain intact |
| `cbox-id:doctor` | printed for reading; it fails here only because no scheduler or queue manager runs |
| HTTP smoke | under `php artisan serve`: `/login` on the console and an environment host, OIDC discovery and JWKS on the environment host, the management API with an old and a new `cbid_env_` key, a new `cbid_ws_` key accepted and the old `cbid_org_` refused, and a browser-style sign-in with an old password |
| Rollback | `migrate:rollback --step=<every migration the upgrade ran>` leaves a schema identical to the previous release's, and `migrate` applies cleanly again |

**In CI** the same script runs as the *Upgrade path* workflow
(`.github/workflows/upgrade-path.yml`) on every release tag, against both engines, from
v1.1.1 — the last 1.x, which every 1.x deployment upgrades through. Run it by hand from the
Actions tab to rehearse from another tag. A tag of a new major version needs its own
`scripts/upgrade-path/seed-<major>.php`, written against that release's services; the
script refuses a tag it has no seed for rather than seeding with the wrong code.

With `--external-db` it uses the database `DB_*` names instead of starting one. That
database must be empty and disposable: the script installs a platform into it, seeds it
and rolls it back and forth.

## Your own data

Do this against a **copy**, on a machine that is not production, with the new release's
code. The copy holds every user's personal data and every sealed secret: treat the
machine and the copy like production, and destroy both afterwards.

1. **Take a backup** the way you would restore one — `pg_dump -Fc` or `mysqldump
   --single-transaction`, or your provider's snapshot — and restore it into a new,
   isolated database on the **same engine and major version** production runs.
2. **Check out the new release** and install it (`composer install --no-dev`, `npm ci &&
   npm run build`). Give it the production `APP_KEY` and `CBOX_ID_CRYPTO_KEY` — from your
   secret store into the process environment, never into a file in the checkout. Without
   them every sealed column reads as garbage and the rehearsal proves nothing. Point
   `DB_*` at the copy and set `MAIL_MAILER=log`.
3. **Do not run the scheduler or the queue manager against the copy.** They would deliver
   the copy's webhooks, outbound SCIM and log streams to your customers' real endpoints.
   Nothing below needs them.
4. **Record the starting point:**

   ```bash
   php scripts/upgrade-path/row-counts.php  > before.json
   php scripts/upgrade-path/schema-dump.php > schema-before.json
   ```

5. **Upgrade, and time it** — the time is your maintenance window, or tells you whether
   you need one:

   ```bash
   time php artisan migrate --force
   php scripts/upgrade-path/row-counts.php > after.json
   php scripts/upgrade-path/compare-counts.php before.json after.json
   ```

6. **Check it:** `php artisan cbox-id:doctor` (ignore the scheduler and queue manager
   lines, by step 3) and `php artisan audit-chain:verify`. Then `php artisan serve` and
   sign in to the console and to one environment host with a staff account, open an app's
   page, and call the management API with a key you mint on the copy.
7. **Rehearse the way back.** `php artisan migrate:rollback --step=<N>`, where N is how many
   rows the upgrade added to `migrations` (`after.json` minus `before.json`), then
   `php scripts/upgrade-path/schema-dump.php | diff schema-before.json -`. An empty diff
   means the old release can run on the rolled-back schema. Some changes have no way back
   (below); the real rollback of a production upgrade is restoring the backup from step 1.
8. **Destroy the copy** and anything you exported from it.

## What a rollback cannot undo (1.1.x → 2.0.0)

The rehearsal from v1.1.1 on MySQL 8.4 and PostgreSQL 17 rolls back to v1.1.1's schema
exactly, but these data changes stay rolled forward:

- **`cbid_org_` workspace keys stay revoked.** They cannot resolve under 2.0.0 either way
  ([UPGRADING](https://github.com/cboxdk/cbox-id/blob/main/UPGRADING.md)); mint a replacement.
- **Role grants of invitations that were no longer pending** are deleted on the way up
  (they could never be applied again).
- **The environment checklist's dismissals** (`onboarding_dismissals` without an
  organization) are deleted on the way down: 1.1.x has no such checklist, and the
  checklist simply shows again after a later upgrade.
- **Admin Portal links for intents 1.1.x cannot name** (domain verification, log streams,
  certificate renewal, without SSO or directory sync) are expired on the way down rather
  than turned into SSO links. Send a new link.
- **Rows only 2.0.0 writes** (the new tables) are dropped with their tables.

## Where to go next

- [Upgrading](https://github.com/cboxdk/cbox-id/blob/main/UPGRADING.md) — what each release needs from an operator.
- [Day-2 operations](operations.md) — backups, the crypto key, and the upgrade commands.
