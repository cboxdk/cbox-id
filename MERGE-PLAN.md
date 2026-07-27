# Merge plan — one open app

Fold the proprietary console plugins into `cbox-id`, drop the license-key layer, keep
billing optional, and stand the app up fresh on Laravel Cloud.

Working doc. Delete once executed.

---

## 0. Where we are today

| Repo | License | LOC | Fate |
| --- | --- | --- | --- |
| `laravel-id` | MIT | 82k | unchanged (framework) |
| `cbox-id` | Elastic-2.0 | 53k | **absorbs the modules** |
| `laravel-id-analytics` | proprietary | 1.6k | → `modules/analytics` |
| `laravel-id-compliance` | proprietary | 2.0k | → `modules/compliance` |
| `laravel-id-connectors` | proprietary | 1.5k | → `modules/connectors` |
| `laravel-id-risk-plus` | proprietary | 1.2k | → `modules/risk-plus` |
| `laravel-id-whitelabel` | proprietary | 1.6k | → `modules/whitelabel` |
| `laravel-id-billing` | proprietary | 0.6k | **stays a package**, relicensed MIT |
| `laravel-id-billing-bridge` | proprietary | small | **stays a package**, relicensed MIT |
| `laravel-id-licensing` | proprietary | 0.8k | **deleted** |
| `cbox-id-cloud` | — | Dockerfile only | **archived** |

The k8s deployment (`infrastructure/apps/cbox-id/`, image
`ghcr.io/cboxdk/cbox-id-cloud@sha256:7c30d62f…`, Postgres on UpCloud, in-cluster
Valkey + Altinity ClickHouse) is **abandoned, not migrated** — Laravel Cloud gets a
fresh database and a fresh install. Its `app-env` Secret is still the source of truth
for the env values we carry over (§5.2).

### Locked decisions

1. **`cbox-id` stays Elastic-2.0.** Self-hosting is already free and key-free under
   ELv2; what we drop is the *license-key verifier*, not the copyright license. Going
   MIT on the app would give away the hosted business at the same moment we remove
   per-feature upsell. `laravel-id` stays MIT.
2. **Merged modules inherit ELv2** and lose their `proprietary` LICENSE files.
3. **Billing stays out of the app** — two optional MIT packages are the hook into
   `cbox-billing`. Not installed ⇒ no billing nav, no metering, no plan gates.
4. **Entitlements go open-by-default as a floor, not an override** (§3.1).
5. **Fresh install on Laravel Cloud, MySQL 8.4 + Valkey**, no data carry-over.

---

## 1. Phase 0 — Pre-flight (blocking)

A fresh database removes most of the usual pre-flight; three things still gate the work.

### 1.1 Entitlement blast radius — the only security-surface change

`EmbeddedEntitlements` / `OAuthServer/JwtTokenIssuer` put entitlements into issued
tokens, and `Api/Http/Controllers/DecisionController` +
`Kernel/Authorization/DefaultPolicyDecisionPoint` read them. Confirm no entitlement key
is load-bearing for **tenant isolation** before flipping the default from deny to
grant. Read those four call sites and write down what each key actually gates.

### 1.2 MySQL 8.4 on the app layer — untested today

This is the sharpest risk in the whole plan, and it is new.

- `laravel-id` **is** green on MySQL: the `engines` CI job runs `mysql:8` and
  PostgreSQL 16 on every run, 1359 tests pass, and `Kernel/Database/JsonDefault`
  (verified against MySQL 8.4.10 on 2026-07-27) fixes the literal-`json`-default
  failure that used to kill `php artisan migrate` on the fifth table.
- `cbox-id` **is not**. Its CI installs `pdo_sqlite` only, with no database services —
  so the app's own 7 migrations, the five modules' migrations, and every Livewire
  query in 53k LOC have never executed against MySQL. Postgres was production;
  SQLite was CI.

**Action, before any Cloud deploy:** add a MySQL 8.4 + PostgreSQL engines job to
`cbox-id/.github/workflows/ci.yml`, modelled on `laravel-id`'s. Ship the merge with it
green.

Then audit the five modules' migrations against `laravel-id`'s portability rules —
they were only ever run on Postgres and SQLite:

- **no literal default on a `json` column** — use `JsonDefault::emptyObject()` /
  `::emptyArray()`;
- **no `CHAR`** — not `char()`, and not `ulid()` / `uuid()` / `foreignUlid()` /
  `ulidMorphs()`, which compile to one. Use `string($col, 26)`.
  `laravel-id`'s `tests/Feature/SchemaPortabilityTest.php` enforces this — port that
  test into `cbox-id` so it covers the modules too;
- **index/constraint names ≤ 63 chars** (Postgres's limit, one tighter than MySQL's);
  name long composite indexes explicitly;
- **InnoDB caps an index key at 3072 bytes** and utf8mb4 charges 4 bytes/char — a
  composite index over four default `varchar(255)` columns is already over. Give
  indexed columns explicit lengths.

Collation: the default `utf8mb4_unicode_ci` is case-**in**sensitive, where Postgres was
case-sensitive. The identity hot path is already immune — SCIM `userName` and primary
email use dedicated folded columns (`user_name_lower`, `email_lower`) so equality no
longer depends on collation. Confirm nothing else (client ids, external ids, org slugs)
was relying on Postgres's case-sensitive uniqueness.

### 1.3 ClickHouse

The analytics sink points at in-cluster Altinity ClickHouse
(`clickhouse-cbox.clickhouse.svc.cluster.local:8123`) — unreachable from Laravel Cloud,
and Cloud offers no ClickHouse service. Pick one:

- **inert** — leave `ID_ANALYTICS_ENABLED=false`; the console area still renders, the
  sink is a no-op (recommended for launch);
- **Postgres/MySQL reader** — `UsageMeterReportReader` already exists and reads the
  usage meter instead of ClickHouse;
- **external** — expose a managed ClickHouse and keep `ID_ANALYTICS_CLICKHOUSE_DSN`.

Note the k8s secret flags `id-analytics:install` as currently broken; if you go with
anything but "inert", that command needs fixing first.

---

## 2. Phase 1 — Repo merge (mechanical, zero behaviour change)

### Layout

```
cbox-id/
  modules/
    analytics/{src,config,resources,routes}
    compliance/{src,config,database/migrations,resources,routes}
    connectors/{src,config,resources,routes}
    risk-plus/{src,config,database/migrations,resources,routes}
    whitelabel/{src,config,database/migrations,resources,routes}
```

**Namespaces stay `Cbox\Id\<Module>\`.** Renaming to `App\` would touch every file for
no gain; keeping them makes the merge diff pure `git mv` plus autoload wiring, and the
console-kit socket pattern is preserved — these stay plugins, just vendored.

Add to `composer.json`:

```json
"autoload": {
  "psr-4": {
    "App\\": "app/",
    "Cbox\\Id\\Analytics\\":   "modules/analytics/src/",
    "Cbox\\Id\\Compliance\\":  "modules/compliance/src/",
    "Cbox\\Id\\Connectors\\":  "modules/connectors/src/",
    "Cbox\\Id\\RiskPlus\\":    "modules/risk-plus/src/",
    "Cbox\\Id\\Whitelabel\\":  "modules/whitelabel/src/"
  }
}
```

(Verify each module's real namespace from its own `composer.json` before pasting.)

### Steps, per module

1. `git subtree add --prefix=modules/<name> git@github.com:cboxdk/laravel-id-<name>.git main`
   — preserves history and blame.
2. Delete the package scaffolding: `composer.json`, `phpstan.neon`, `phpunit.xml`,
   `.github/workflows/ci.yml`, `bin/check-licenses.php`, `bin/generate-sbom.php`,
   `sbom.json`, `LICENSE`, `README.md`.
3. **Providers**: auto-discovery (`extra.laravel.providers`) no longer applies to
   in-repo code. Register each in `bootstrap/app.php` → `->withProviders([...])`,
   after `ConsoleServiceProvider`.
4. **Migrations stay in `modules/*/database/migrations`.** With a fresh database the
   filenames are no longer a *safety* constraint — nothing is recorded yet — but the
   module should still own its own schema, and the providers' existing
   `loadMigrationsFrom(__DIR__.'/../database/migrations')` keeps working unchanged from
   the new path. Don't consolidate.
5. **Config**: `mergeConfigFrom` paths resolve relatively; no change. Keep the config
   keys (`whitelabel.*`, `compliance.*`, `connectors.*`, `risk-plus.*`,
   `id-analytics.*`) so the carried-over env keys still bind.
6. **Views/routes/Volt**: `loadViewsFrom`, `Volt::mount`, `loadRoutesFrom` all use
   `__DIR__` — no change.

### Dependency reconciliation

The modules bring runtime deps `cbox-id` does not currently require:

- `cboxdk/laravel-ssrf: ^1.0` — whitelabel's `ManageCustomDomain`. **Missing today; add it.**
- `illuminate/http`, `illuminate/filesystem`, `illuminate/database` — already covered
  by `laravel/framework`.
- `cboxdk/laravel-risk` ✓, `cboxdk/laravel-console-kit` ✓, `livewire/volt` ✓ — already required.

Drop `orchestra/testbench` entirely — no longer needed anywhere.

### Tests — the only real conversion work

Each module ships a Testbench harness (`tests/TestCase.php`, `tests/Pest.php`, stubs
like `tests/database/migrations/0000_00_00_000000_create_environments_table.php`).
Those all die; the app has the real schema and a real `TestCase`.

- Move specs to `tests/Feature/<Module>/`, rewire to `cbox-id`'s base `TestCase`.
- Delete each module's `TestCase.php`, `Pest.php` and migration stubs.
- The `src/Testing/Fake*` classes (`FakeReportSink`, `FakeAuditExportSink`,
  `FakeGeoLocator`, `FakeConnectorAnalytics`) **stay in `src/`** — they're the
  dogfooded fakes and the app suite should use them.
- Count specs before and after; a silent drop is the easiest way to lose coverage here.

### Type the modules' json columns while we're in there

The three json columns the modules bring in all cast to `'array'` today, which is the
array shortcut the house rule forbids. The `json` *column* is fine — that's the
serialization boundary — but the PHP side should hydrate into a value object via a
`CastsAttributes` cast, the way `Organization/Casts/ResourceFamiliesCast` →
`ResourceFamilies` already does in `laravel-id` (currently the only custom cast in the
codebase).

| Column | Today | Fix |
| --- | --- | --- |
| `whitelabel_brand_profiles.palette` | `'array'`, normalized late by `PaletteTokens::normalize()` at `TenantBrandingResolver:46` | `PaletteTokensCast` — the VO already exists, just apply it at the boundary instead of at read |
| `whitelabel_brand_profiles.email_templates` | `'array'` | `EmailTemplates` VO |
| `risk_plus_events.reasons` | `'array'` | `array<RiskReason>` — the signal set is closed (`ImpossibleTravelSignal`, `NewDeviceSignal`), so an enum fits |

Cheap here because these files are being touched anyway. The equivalent sweep across
`laravel-id`'s ~30 remaining `'array'` casts — `scopes` (8 tables), `redirect_uris`,
`amr`, `transports`, `grant_types`, `mappings`, `settings`, and `entitlements.value`
(whose `EntitlementValue` VO already exists, unused for persistence) — is **a separate
workstream in that repo**, deliberately not folded into this merge.

### Gate before commit

```bash
vendor/bin/pint --test && composer analyse && composer test && composer license-check && composer sbom && git diff --exit-code sbom.json && composer audit --no-dev
```

Plus the new MySQL/Postgres engines job from §1.2. The license-check gets *easier*:
five `proprietary` dependencies become first-party code and leave `composer.lock`.

---

## 3. Phase 2 — Un-gate (the actual product change)

### 3.1 Entitlements: open by default

New `app/Platform/OpenEntitlements.php` implementing `EntitlementReader`, decorating
the existing `CachedEntitlements` via `$this->app->extend(...)` in
`PlatformServiceProvider`:

- an **explicit** projection row wins (manual per-org grants and revocations still
  work, and a self-hoster can still differentiate orgs);
- an **absent** row resolves to *granted* rather than denied.

Driven by a new key in `config/cbox-id.php`:

```php
'entitlements' => [
    'mode' => env('CBOX_ID_ENTITLEMENTS', 'open'), // 'open' | 'metered'
    'sso'  => env('CBOX_ID_ENTITLEMENT_SSO', 'cbox-id-sso'),
    'scim' => env('CBOX_ID_ENTITLEMENT_SCIM', 'cbox-id-scim'),
],
```

`metered` = today's deny-by-default, billing-fed behaviour; set it only where a billing
transport is wired. Everything else — every self-host, and the default `.env.example` —
is `open`.

**Gated on §1.1.** Consequence to accept explicitly: the SSO/SCIM soft-lock in the
console layout unlocks for everyone by default. That is the point.

### 3.2 Kill the licensing layer

- Archive `cboxdk/laravel-id-licensing` (GitHub archive, **not** delete — its tags are
  referenced by existing image digests).
- Remove `EntitlementSource::License` from `laravel-id` (minor bump). Fresh database,
  so there are no `source = 'license'` rows to migrate.
- `cboxdk/license` survives only if `cbox-billing` still needs to issue keys for *other*
  products. If not, archive it too.
- Drop `CBOX_ID_LICENSE_*` from `.env.production.example` and the carried-over env.

### 3.3 Feature registration

Each module's `Console::features()->register('<name>', fn () => true)` is already
presence-gated, so merging makes it permanently on. Strip the now-false "(licensed)" /
"(commercial)" comments from the provider docblocks and `config/*.php` headers.

---

## 4. Phase 3 — Billing stays optional and goes public

`laravel-id-billing` (console: plan, invoices, usage-vs-limit) and
`laravel-id-billing-bridge` (outbox → `cbox-billing`, exactly-once metering):

1. Relicense both MIT; rewrite the descriptions (drop "commercial", drop "the
   self-hostable app stays billing-free" — that's now the whole design, not a caveat).
2. Publish to Packagist so no private auth is ever needed.
3. Keep them as optional `composer require`, gated on presence.
4. Document in `docs/operations/billing.md`: install the two packages, set
   `BILLING_CLIENT_BASE_URL` / `_API_TOKEN`, set `CBOX_ID_ENTITLEMENTS=metered`.

That triple — install, point at billing, switch to metered — is the complete "optional
billing with cbox-billing" story.

---

## 5. Phase 4 — Fresh install on Laravel Cloud

The merge is what makes this possible: Cloud builds from the git repo, and a two-image
Docker overlay resolving private VCS deps does not fit that model. One repo, all public
deps, zero `COMPOSER_AUTH`.

### 5.1 Let Cloud own the infrastructure env

Laravel Cloud **injects** the database and cache connection variables. Do not set them
by hand — a manual `DB_HOST` or `REDIS_HOST` shadows the injected value and points the
app at nothing.

**Cloud-managed (do not set):** `DB_CONNECTION`, `DB_HOST`, `DB_PORT`, `DB_DATABASE`,
`DB_USERNAME`, `DB_PASSWORD`, `MYSQL_ATTR_SSL_CA`, `REDIS_HOST`, `REDIS_PORT`,
`REDIS_PASSWORD`, `REDIS_URL`.

**Dropped entirely from the k8s set:** `DB_SSLMODE` (a pgsql-only key — meaningless on
MySQL), `REDIS_CLIENT` (unless Cloud's default differs from `phpredis`), `TRUSTED_PROXIES`
(re-derive for Cloud's load balancer rather than copying `*`), all `CBOX_ID_LICENSE_*`,
and the in-cluster `ID_ANALYTICS_CLICKHOUSE_*` (§1.3).

`config/database.php` already carries a complete `mysql` block (utf8mb4,
`utf8mb4_unicode_ci`, strict mode, `MYSQL_ATTR_SSL_CA` passthrough) — no change needed.

### 5.2 Carry over from the k8s `app-env` Secret

```
APP_NAME="Cbox ID"
APP_ENV=production
APP_DEBUG=false
APP_URL=https://cboxid.com
LOG_CHANNEL=stderr
LOG_LEVEL=info

CBOX_ID_ISSUER=https://cboxid.com
CBOX_ID_WEBAUTHN_RP_ID=cboxid.com
CBOX_ID_WEBAUTHN_ORIGIN=https://cboxid.com

SESSION_DRIVER=redis          # Cloud supplies the connection, not the driver choice
CACHE_STORE=redis
QUEUE_CONNECTION=redis
SESSION_SECURE_COOKIE=true
SESSION_ENCRYPT=true
SESSION_SAME_SITE=lax         # relaxed from strict so cross-site OIDC redirects work
SESSION_HTTP_ONLY=true
SESSION_LIFETIME=120

# --- Mail: transactional (verification, MFA, invites) — carry the real values ---
MAIL_MAILER=smtp
MAIL_HOST=…
MAIL_PORT=587
MAIL_USERNAME=…
MAIL_PASSWORD=…
MAIL_FROM_ADDRESS=no-reply@cboxid.com
MAIL_FROM_NAME="Cbox ID"

CBOX_ID_ENTITLEMENTS=open
```

**Generate fresh, do not copy** (new database, so nothing is encrypted under the old keys):

- `APP_KEY` — `php artisan key:generate --show`, **with** the `base64:` prefix;
- `CBOX_ID_CRYPTO_KEY` — `php -r 'echo base64_encode(random_bytes(32)),"\n";'`,
  **raw base64, no `base64:` prefix** (the crypto layer strict-decodes it; a prefix
  throws `CryptoConfigurationException::missingKey`).

Back `CBOX_ID_CRYPTO_KEY` up somewhere durable the moment it's created — losing it makes
all stored crypto material unrecoverable, and on a fresh install it's trivially easy to
treat as disposable right up until it isn't.

### 5.3 Turn the wildcard on

The k8s secret deliberately left `CBOX_ID_ENVIRONMENT_BASE_DOMAINS` empty (every tenant
lived on the default plane, and a host under no base domain is refused). With a wildcard
domain on Cloud, that's the whole point of the move:

```
CBOX_ID_ENVIRONMENT_BASE_DOMAINS=cboxid.com
```

which enables `foo.cboxid.com` → the `foo` environment. Two traps carried over from the
k8s notes:

- `CBOX_ID_ENVIRONMENT_DEFAULT` takes an environment **key (ULID)**, not a slug —
  setting it to `production` points at a key that doesn't exist and breaks
  host→environment resolution. Leave it unset; the fallback is the `is_default`
  environment row created by `cbox-id:install`.
- The same variable name means something different in `cbox-billing`.

### 5.4 Bring-up order

1. Provision on Cloud: **MySQL 8.4**, **Valkey**, the web app, a queue worker, the
   scheduler.
2. **Queue autoscale**: `cboxdk/laravel-queue-autoscale` sizes worker replicas — a
   k8s-shaped tool. On Cloud, workers are Cloud-managed. Disable the scaler; keep
   `laravel-queue-metrics` for observability.
3. Deploy from `main`, then `php artisan migrate --force` on the empty database. **This
   is the first time the full app schema is created on MySQL** — §1.2's CI job should
   have caught anything, but watch this run.
4. `php artisan cbox-id:install` — creates the default environment row and the first
   operator.
5. Smoke test: login, passkey registration (RP ID must match the served host), an
   OIDC round-trip, a SCIM push, console nav shows all five module areas, an auth
   event reaches the outbox.
6. Point `cboxid.com` + `*.cboxid.com` at Cloud.
7. Tear down `infrastructure/apps/cbox-id/` and archive `cbox-id-cloud`.

### 5.5 What happens to `cbox-id-cloud`

Its `Dockerfile` becomes the *only* image and moves into `cbox-id` — the open image is
now the complete one, useful for self-hosters even though Cloud builds from source.
`DEPLOY.md` is rewritten as `docs/getting-started/self-hosting.md` (docker-compose
quickstart: no token, no registry, no license key). `auth.json.example` is deleted.

---

## 6. Phase 5 — Release, docs, cleanup

- **`cbox-id` 0.31.0** — `feat: fold console modules in-tree, open entitlements by default`.
  `UPGRADING.md` covers the one operator-visible change: anyone running the cloud image
  drops the plugin `composer require`s and sets `CBOX_ID_ENTITLEMENTS`.
- **Docs** (topic-folder layout, `_index.md` + `title`/`weight`/`description`
  frontmatter in every folder):
  - `docs/core-concepts/modules.md` — the five modules and that they're always on
  - `docs/operations/billing.md` — the optional billing wiring
  - `docs/getting-started/self-hosting.md` — from `cbox-id-cloud/DEPLOY.md`
  - `docs/requirements.md` — state MySQL 8.0.13+ / PostgreSQL 14+ honestly, and only
    once the engines job is green
  - update `docs/index.md` TOC, then **tag the release** — `cbox-web` scrapes tags, not `main`
  - this also fixes an attribution bug: capabilities that lived in separate packages are
    now documented at the layer that ships them.
- **Archive** on GitHub (never delete — tags back existing digests): `laravel-id-analytics`,
  `-compliance`, `-connectors`, `-risk-plus`, `-whitelabel`, `-licensing`, `cbox-id-cloud`.
- **Memory**: `onprem-licensing.md` becomes obsolete; update `platform-direction.md`,
  `enterprise-features-roadmap.md`, and the MySQL line in `platform-review-2026-07-27.md`.

---

## 7. Risk register

| # | Risk | Blast radius | Mitigation |
| --- | --- | --- | --- |
| 1 | App layer has never run on MySQL (CI is SQLite-only) | migrate fails or queries misbehave on first deploy | add the MySQL 8.4 engines job in §1.2 and ship green |
| 2 | Entitlement default flip is load-bearing for isolation | tenant data exposure | §1.1 before any code change |
| 3 | Module migrations violate a MySQL portability rule (json default, `CHAR`, index length/name) | `migrate` dies partway | audit per §1.2; port `SchemaPortabilityTest` into `cbox-id` |
| 4 | `CBOX_ID_CRYPTO_KEY` generated and not backed up | stored crypto material unrecoverable later | back it up at creation, before first deploy |
| 5 | Manually set `DB_*`/`REDIS_*` shadow Cloud's injected values | app can't reach its own infra | set only the §5.2 keys |
| 6 | ClickHouse unreachable from Cloud | analytics silently inert | decide in §1.3 |
| 7 | `queue-autoscale` fights Cloud's worker management | flapping workers | disable on Cloud |
| 8 | Module Testbench tests dropped rather than converted | coverage loss | count specs before/after; gate stays green |

## 8. Sequencing

Phases 0 → 1 → 2 land as three commits on one branch, verified locally and on the new
engines job. Phase 3 is independent (two package repos). Phase 4 only starts once
1–3 are released and green on MySQL — a fresh install removes the data risk, so the
remaining risk is entirely "does this code run on this engine", and that is answered in
CI, not in production.
