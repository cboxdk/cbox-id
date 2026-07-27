---
title: Upgrading
description: Breaking changes for an already-running deployment, and the migration each one needs
weight: 4
---

# Upgrading

Breaking changes only, newest first. Everything else is in [`CHANGELOG.md`](CHANGELOG.md).

This app embeds `cboxdk/laravel-id`, so a release usually crosses two changelogs. The
package's own breaking changes are in
[`vendor/cboxdk/laravel-id/UPGRADING.md`](https://github.com/cboxdk/laravel-id/blob/main/UPGRADING.md);
this file covers what an **operator of this deployment** has to do, and repeats the
package changes that need action here rather than in a client.

## 0.32.0

### Every capability is now on by default

`CBOX_ID_ENTITLEMENTS` defaults to `open`, meaning an unset entitlement is **granted**.
If your deployment relies on the entitlement projection to gate SSO/SCIM per
organization — that is, if you have a billing plane feeding it — you must set it
explicitly or every organization gains those screens on deploy:

```dotenv
CBOX_ID_ENTITLEMENTS=metered
```

An explicit entitlement still wins in both directions under `open`, so hand-written
grants and revocations (`{"enabled": false}`) keep working either way.

### The five console modules are no longer separate packages

If you ran the composed image or installed the plugins yourself, remove them — the code
is in the application now and Composer will otherwise resolve two copies of the same
namespaces:

```bash
composer remove cboxdk/laravel-id-analytics cboxdk/laravel-id-compliance \
    cboxdk/laravel-id-connectors cboxdk/laravel-id-risk-plus \
    cboxdk/laravel-id-whitelabel cboxdk/laravel-id-licensing
```

No private Composer registry and no read-only GitHub token are needed any more. Their
configuration keys are unchanged, so existing env values keep binding.

### The licensing layer is gone

`CBOX_ID_LICENSE_*` is no longer read; drop those keys. `EntitlementSource::License` is
removed from the framework — if you ever ran the licensing plugin **with a real key**,
check for rows before upgrading, because a stored `license` source will no longer
hydrate:

```sql
SELECT count(*) FROM entitlements WHERE source = 'license';
```

Migrate any to `manual` and they keep working.

### If you switch on the relational analytics store

`ID_ANALYTICS_STORE=database` is opt-in and off by default. If you enable it, **the
scheduler must be running**: `id_analytics_events` is the only table that grows with
traffic rather than with tenants, and the daily `model:prune` is what bounds it.

## Unreleased (from 0.22.x)

### Pre-deploy checklist

Do all of these before the new version serves traffic. The first three fail in ways that
do not raise an error.

- [ ] **A queue worker is running.** Webhook delivery is queued now; without a worker it
      silently delivers nothing.
- [ ] **The scheduler is running** (`schedule:run` every minute). The outbox relay, the
      webhook retry sweep, provisioning drain and nightly pruning all hang off it.
- [ ] **Every OAuth client's registered scope list covers what its SDK sends.**
      `invalid_scope` is now a hard refusal at `/authorize`.
- [ ] **On PostgreSQL: no case-variant duplicate usernames or emails remain** in
      `directory_users`.
- [ ] **Clients point at their environment's issuer, not at the apex host.**
- [ ] **Migrations run** — this release adds several, including the normalized SCIM
      columns and the webhook circuit-breaker columns.

---

### Webhooks now require a running queue worker — SILENT if missing

Delivery moved from an in-band HTTP call to a queued `DeliverWebhook` job. **A deployment
with no worker enqueues jobs nothing ever runs**: deliveries sit at `pending`, no exception
is raised, and nothing is logged as an error. From the console the endpoint just looks
quiet.

```sh
php artisan queue:work
```

Run it under a supervisor. `docker-compose.yml` in this repo runs one; a bare
`php artisan serve` install does not.

Optionally give webhooks their own connection or queue so a slow endpoint cannot starve
other work:

```env
CBOX_ID_WEBHOOKS_QUEUE_CONNECTION=redis
CBOX_ID_WEBHOOKS_QUEUE=webhooks
```

To confirm it is working: `php artisan cbox-id:events:backlog --json`. A depth that only
grows means the relay or the worker is not running. See
[Operations](docs/operations/operations.md).

### The apex host now 404s the IdP protocol surface

`config/cbox-id.php` sets `api.middleware => ['plane:subject']`, which confines OIDC
discovery, JWKS, the RFC 8414 / RFC 9728 metadata, every `/oauth/*` endpoint, SAML and SCIM
to the **subject plane** — i.e. to an environment's own host.

The platform-root host is the *account* door (sign up, manage environments). It is not an
issuer, and it never was a working one: it used to answer discovery advertising
`issuer: https://<apex>` next to an `authorization_endpoint` that 404s, so a conformant
client followed the document into a dead end. Half an IdP is worse than none, because it is
discoverable.

**This is inert in a single-tenant / self-hosted install** (no `CBOX_ID_ENVIRONMENT_BASE_DOMAINS`):
the gate is a no-op and one host serves everything, exactly as before.

**What breaks.** Anything that was pointed at the apex for a protocol endpoint now gets a
`404`. It will look like an outage. Repoint it at the issuer the environment's discovery
document advertises.

`GET /up` is deliberately registered outside the gate and still answers on every host, so a
kubelet hitting the pod directly is unaffected. See
[Deployment](docs/operations/deployment.md).

### `/up` returns JSON

The app no longer registers Laravel's built-in health route, which was rendering an HTML
status page at `/up` and shadowing the package's documented JSON probe. `/up` now returns
`{"status":"ok"}` as `application/json`.

The HTML page also pulled Tailwind from a CDN and fonts from a third-party host, both
refused by this app's CSP — so every probe rendered unstyled and generated CSP violation
noise.

**What to do.** A probe asserting on the status code alone is unaffected. A probe asserting
on an empty body or on `text/html` needs updating.

### `invalid_scope` at `/authorize` — the widest blast radius in the release

A scope a client is not registered for used to be accepted and then quietly dropped when
the token was minted; the client's next API call failed with an unexplained 403. It is now
refused at `/authorize` with `error=invalid_scope`.

**Every deployed client whose SDK requests more scopes than it registered now hard-fails
instead of degrading.** You do not control when those clients next run. `email` and
`offline_access` are the usual culprits — SDK defaults nobody added to the registration.

Audit the registrations **before** you deploy: the console lists each application's scopes
under its detail page, or

```sh
php artisan tinker
>>> \Cbox\Id\OAuthServer\Models\Client::query()->get(['client_id', 'name', 'scopes']);
```

A client registered with no scopes at all is exempt (it has declared no surface to check
against), so an empty list is not a finding.

### SCIM and SAML got stricter

Both are package-level changes. They are summarised here because the integrations they
affect are configured in this console:

- **SCIM** — `DELETE` of an unknown id is `404` (was `204`); a `PATCH` with malformed or
  absent `Operations` is `400` (was a silent `200`); a non-boolean `active` is `400`;
  `429` is now framed in the SCIM Error envelope; **`GET /Groups` listings omit `members`
  unless asked for** (request `?attributes=members`, or read the group singly); filter
  literals are type-checked against their attribute.
- **SAML** — `NameIDPolicy` is enforced against what the SP registered; a signed
  `AuthnRequest` must carry a `Destination`; an `AuthnRequest` is single-use and must be
  under 15 minutes old.

Full detail and the migration steps are in the package's `UPGRADING.md`.

### `userName` / email equality is case-insensitive on every driver

Previously this depended on the database collation — case-insensitive on MySQL's default,
case-**sensitive** on PostgreSQL.

**On PostgreSQL you may be holding rows that are now duplicates.** Find them before
deploying:

```sql
SELECT directory_id, lower(user_name), count(*)
FROM directory_users
GROUP BY directory_id, lower(user_name)
HAVING count(*) > 1;
```

Repeat for `email`. The migration backfills and indexes the normalized columns; it does not
merge duplicates, and it should not — the two rows can carry different memberships, so
which one survives is a decision only you can make. Left unreconciled, the next SCIM
create or update touching either row returns `409 uniqueness`.

### `CACHE_STORE=database` is no longer a reasonable production setting

Not breaking, but it belongs on the same checklist. The platform leans hard on the cache:
JWKS and verification keys, host → environment resolution on **every request**, the
entitlement hot path, and the active inline-hook set on every token mint. On the `database`
store each of those becomes a query against the `cache` table — the very round trip the
caching exists to remove — plus write contention on top.

Use Redis anywhere real. `docker-compose.yml` already does; `database` is the
zero-dependency default for a first `php artisan serve` and nothing more.

### New configuration

Roughly twenty new `CBOX_ID_*` keys ship with this release — retention windows for the new
`cbox-id:prune` command, webhook queue and circuit-breaker settings, the environment
resolution cache TTL, and the OTP rate limits. They all have defaults, so none of them is
required to deploy. They are documented in
[Environment variables](docs/configuration/environment-variables.md).

---

## How to read this file

An entry earns a place here only if a deployment that was working can stop working, or
behave differently, without anyone changing their own code. Additive features and bug fixes
live in [`CHANGELOG.md`](CHANGELOG.md).
