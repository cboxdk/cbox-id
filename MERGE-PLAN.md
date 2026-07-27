# Merge plan — one open app

Fold the proprietary console plugins into `cbox-id`, drop the license-key layer, keep
billing optional, and move the deployment from the k8s overlay image to Laravel Cloud.

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

Production right now: `infrastructure/apps/cbox-id/` on k8s, image
`ghcr.io/cboxdk/cbox-id-cloud@sha256:7c30d62f…` (cloud 0.6.4 on base 0.30.0),
ingress `cboxid.com` + `*.cboxid.com`, in-cluster Valkey, queue deployment,
scheduler + billing-usage cronjobs.

### Locked decisions

1. **`cbox-id` stays Elastic-2.0.** Self-hosting is already free and key-free under
   ELv2; what we are dropping is the *license-key verifier*, not the copyright
   license. Going MIT on the app would give away the hosted business at the same
   moment we remove per-feature upsell. `laravel-id` stays MIT.
2. **Merged modules inherit ELv2** and lose their `proprietary` LICENSE files.
3. **Billing stays out of the app** — two optional MIT packages are the hook into
   `cbox-billing`. Not installed ⇒ no billing nav, no metering, no plan gates.
4. **Entitlements go open-by-default as a floor, not an override** (§3).

---

## 1. Phase 0 — Pre-flight (blocking, all against production)

Nothing moves until these five are answered.

1. **License-sourced entitlements.** `select source, count(*) from entitlements group by 1`
   — any `source = 'license'` rows? They decide whether `EntitlementSource::License`
   can be removed from `laravel-id` or must be migrated to `manual` first.
2. **Migration ledger.** Dump `select migration from migrations order by 1` and keep it.
   The plugin migrations are already recorded there from the cloud image
   (`2026_07_16_000100_create_whitelabel_brand_profiles_table`,
   `…_create_audit_export_cursors_table`, `…_create_audit_export_runs_table`,
   `…_create_risk_plus_events_table`, `…_create_billing_bridge_reported_events_table`).
   **Filenames must survive the move byte-for-byte** — a rename means Laravel sees an
   unrun migration and tries to create an existing table.
3. **Plugin table row counts** — `whitelabel_brand_profiles`, `audit_export_cursors`,
   `audit_export_runs`, `risk_plus_events`, `billing_bridge_reported_events`. Tells us
   what a rollback would have to preserve.
4. **ClickHouse.** Is `CLICKHOUSE_DSN` actually set in the `app-env` secret, and does
   `billing_usage_events` hold data? Laravel Cloud has no ClickHouse service — this
   decides between "point analytics at an external CH", "swap the reader to the
   Postgres usage-meter reader" (`UsageMeterReportReader` already exists), or "leave
   the sink inert".
5. **Entitlement blast radius.** `EmbeddedEntitlements` /
   `OAuthServer/JwtTokenIssuer` put entitlements into issued tokens, and
   `Api/Http/Controllers/DecisionController` + `Kernel/Authorization/DefaultPolicyDecisionPoint`
   read them. Confirm no entitlement key is load-bearing for **tenant isolation**
   before flipping the default to granted. This is the only change in the whole plan
   with a security surface.

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

**Namespaces stay `Cbox\Id\<Module>\`.** Renaming to `App\` would touch every file
for no gain; keeping them means the merge diff is pure `git mv` plus autoload wiring,
and the console-kit socket pattern is preserved — these remain plugins, just vendored.

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
   — preserves history and blame. (`git read-tree` if you'd rather have a flat import.)
2. Delete the package scaffolding: `composer.json`, `phpstan.neon`, `phpunit.xml`,
   `.github/workflows/ci.yml`, `bin/check-licenses.php`, `bin/generate-sbom.php`,
   `sbom.json`, `LICENSE`, `README.md`.
3. **Providers**: auto-discovery (`extra.laravel.providers`) no longer applies to
   in-repo code. Register each in `bootstrap/app.php` → `->withProviders([...])`,
   after `ConsoleServiceProvider`.
4. **Migrations stay put.** The providers' `loadMigrationsFrom(__DIR__.'/../database/migrations')`
   keeps working from the new path and the filenames are unchanged — production's
   `migrations` rows still match, nothing re-runs. Do **not** consolidate them into
   `database/migrations/`.
5. **Config**: `mergeConfigFrom` paths still resolve relatively; no change. Config keys
   (`whitelabel.*`, `compliance.*`, `connectors.*`, `risk-plus.*`, `id-analytics.*`)
   stay as-is so the existing `app-env` secret keeps working.
6. **Views/routes/Volt**: `loadViewsFrom`, `Volt::mount`, `loadRoutesFrom` all use
   `__DIR__` — no change.

### Dependency reconciliation

The modules bring runtime deps `cbox-id` does not currently require. Add to
`cbox-id/composer.json`:

- `cboxdk/laravel-ssrf: ^1.0` — whitelabel's `ManageCustomDomain` (**missing today**)
- `illuminate/http`, `illuminate/filesystem`, `illuminate/database` — already covered
  by `laravel/framework`
- `cboxdk/laravel-risk` — already required (`^1.1`) ✓
- `cboxdk/laravel-console-kit` — already required ✓
- `livewire/volt` — already required ✓

Drop `orchestra/testbench` entirely — no longer needed anywhere.

### Tests — the only real conversion work

Each module ships a Testbench harness (`tests/TestCase.php`, `tests/Pest.php`, and
stubs like `tests/database/migrations/0000_00_00_000000_create_environments_table.php`).
Those all die; the app has the real schema and a real `TestCase`.

- Move specs to `tests/Feature/<Module>/`.
- Delete each module's `TestCase.php`, `Pest.php`, and migration stubs.
- Rewire to `cbox-id`'s base `TestCase` + `RefreshDatabase`.
- The `src/Testing/Fake*` classes (`FakeReportSink`, `FakeAuditExportSink`,
  `FakeGeoLocator`, `FakeConnectorAnalytics`) **stay in `src/`** — they're the
  dogfooded fakes and the app suite should use them.

### Gate before commit

```bash
vendor/bin/pint --test && composer analyse && composer test && composer license-check && composer sbom && git diff --exit-code sbom.json && composer audit --no-dev
```

The license-check gets *easier*: five `proprietary` dependencies become first-party
code, so they leave `composer.lock` entirely.

---

## 3. Phase 2 — Un-gate (the actual product change)

### 3.1 Entitlements: open by default

New `app/Platform/OpenEntitlements.php` implementing `EntitlementReader`, decorating
the existing `CachedEntitlements` via `$this->app->extend(...)` in
`PlatformServiceProvider`:

- an **explicit** projection row wins (so manual per-org grants and revocations still
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

`metered` = today's deny-by-default, billing-fed behaviour; set it only where a
billing transport is actually wired. Everything else — every self-host, and the
default `.env.example` — is `open`.

**Gate this on Phase 0 item 5.** Entitlements ride in issued tokens; confirm no
authorization path treats "entitled" as "isolated" before flipping.

Consequence to accept explicitly: the SSO/SCIM soft-lock in the console layout
unlocks for everyone by default. That is the point.

### 3.2 Kill the licensing layer

- Archive `cboxdk/laravel-id-licensing` (GitHub archive, **not** delete — its tags
  are referenced by existing image digests).
- Remove `EntitlementSource::License` from `laravel-id` (minor bump), conditional on
  Phase 0 item 1. If rows exist, migrate them to `Manual` first.
- `cboxdk/license` survives only if `cbox-billing` still needs to issue keys for
  *other* products. If not, archive it too.
- Drop `CBOX_ID_LICENSE_*` from `.env.production.example` and the k8s secret.

### 3.3 Feature registration

Each module's `Console::features()->register('<name>', fn () => true)` is already
presence-gated, so merging makes it permanently on. Strip the now-false
"(licensed)" / "(commercial)" comments from the provider docblocks and the
`config/*.php` headers.

---

## 4. Phase 3 — Billing stays optional and goes public

`laravel-id-billing` (console: plan, invoices, usage-vs-limit) and
`laravel-id-billing-bridge` (outbox → `cbox-billing`, exactly-once metering):

1. Relicense both MIT, rewrite the descriptions (drop "commercial", drop "the
   self-hostable app stays billing-free" — that's now the whole design, not a caveat).
2. Publish to Packagist so no private auth is ever needed.
3. Keep them as optional `composer require`, gated on presence.
4. Document in `docs/operations/billing.md`: install the two packages, set
   `BILLING_CLIENT_BASE_URL` / `_API_TOKEN`, set `CBOX_ID_ENTITLEMENTS=metered`.

That triple — install, point at billing, switch to metered — is the complete
"optional billing with cbox-billing" story.

---

## 5. Phase 4 — Deployment: k8s overlay → Laravel Cloud

The merge is what makes this possible: Laravel Cloud builds from the git repo, and a
two-image Docker overlay resolving private VCS deps does not fit that model. One repo,
all public deps, zero `COMPOSER_AUTH`.

### Order of operations

1. **Provision on Cloud**: Postgres 17, Valkey (cache + session + queue), the web app,
   a queue worker, the scheduler. Wildcard domain is already created.
2. **Env parity — the highest-risk step.** Dump every key from the k8s `app-env`
   secret and map it to Cloud. `APP_KEY` and `CBOX_ID_CRYPTO_KEY` **must be carried
   over identically** — a fresh key makes every encrypted column and stored token
   permanently unreadable. Verify by decrypting a known row on Cloud before cutover.
3. **WebAuthn RP ID must stay `cboxid.com`.** Passkeys are bound to it; a change
   silently invalidates every registered credential. Confirm the RP config is env-driven
   and unchanged.
4. **Queue autoscale**: `cboxdk/laravel-queue-autoscale` is a k8s-shaped tool (it sizes
   worker replicas). On Cloud, workers are Cloud-managed. Decide — disable the scaler
   and keep `laravel-queue-metrics` for observability, or keep it and let it fight the
   platform. Recommend disabling.
5. **ClickHouse**: not a Cloud service. Per Phase 0 item 4 — external CH, or switch the
   analytics reader to `UsageMeterReportReader` (Postgres) and leave the sink inert.
6. **Data**: Postgres dump → Cloud restore, with a short read-only window. Then
   `php artisan migrate:status` must show **zero pending** (filenames were preserved,
   so the restored ledger is already at head). If anything shows pending, stop — a
   migration got renamed in Phase 1.
7. **Cutover**: keep k8s serving until Cloud passes a full smoke test on a temporary
   hostname (login, passkey, SSO round-trip, SCIM push, console nav shows all module
   areas, an auth event reaches the outbox). Flip DNS last.
8. **Wind down**: keep the k8s deployment cold for a week, then remove
   `infrastructure/apps/cbox-id/` and archive `cbox-id-cloud`.

### What happens to `cbox-id-cloud`

Its `Dockerfile` becomes the *only* image, and it lives in `cbox-id` — the open image
is now the complete one. `DEPLOY.md` is rewritten as
`docs/getting-started/self-hosting.md` (docker-compose quickstart, no token, no
registry, no license key). `auth.json.example` is deleted.

---

## 6. Phase 5 — Release, docs, cleanup

- **`cbox-id` 0.31.0** — `feat: fold console modules in-tree, open entitlements by default`.
  Write `UPGRADING.md` for the one operator-visible change: anyone running the cloud
  image must drop the plugin `composer require`s and set `CBOX_ID_ENTITLEMENTS`.
- **Docs** (topic-folder layout, `_index.md` + `title`/`weight`/`description` frontmatter
  in every folder, per the cboxdk standard):
  - `docs/core-concepts/modules.md` — what the five modules are and that they're always on
  - `docs/operations/billing.md` — the optional billing wiring
  - `docs/getting-started/self-hosting.md` — from `cbox-id-cloud/DEPLOY.md`
  - update `docs/index.md` TOC
  - **tag the release** — `cbox-web` scrapes tags, not `main`.
  - This also *fixes* an attribution bug: capabilities that lived in separate packages
    are now honestly documented at the layer that ships them.
- **Archive** on GitHub (never delete — tags back existing digests):
  `laravel-id-analytics`, `-compliance`, `-connectors`, `-risk-plus`, `-whitelabel`,
  `-licensing`, `cbox-id-cloud`.
- **Memory**: `onprem-licensing.md` becomes obsolete; update `platform-direction.md`
  and `enterprise-features-roadmap.md`.

---

## 7. Risk register

| # | Risk | Blast radius | Mitigation |
| --- | --- | --- | --- |
| 1 | Entitlement default flip is load-bearing for isolation | tenant data exposure | Phase 0 §5 before any code change |
| 2 | `APP_KEY` / `CBOX_ID_CRYPTO_KEY` not carried to Cloud | all ciphertext unrecoverable | copy verbatim; decrypt a known row pre-cutover |
| 3 | WebAuthn RP ID changes | every passkey dead | keep `cboxid.com`; verify config |
| 4 | A migration filename drifts in the move | duplicate-table failure on deploy | diff the `migrations` ledger; `migrate:status` = 0 pending |
| 5 | ClickHouse unavailable on Cloud | analytics/usage silently inert | decide in Phase 0 §4 |
| 6 | `queue-autoscale` fights Cloud's worker management | flapping workers | disable on Cloud |
| 7 | Modules' Testbench tests silently dropped rather than converted | coverage loss | count specs before/after; the gate must stay green |

## 8. Sequencing

Phase 0 → 1 → 2 can land as three commits on one branch, verified locally.
Phase 3 is independent (two package repos).
Phase 4 must not start until 1–3 are released and the k8s image has run the merged
build for at least one deploy — migrate the *known-good* artifact, don't combine a
code migration and a platform migration in one cutover.
