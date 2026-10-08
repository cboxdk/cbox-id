---
title: Requirements
weight: 3
description: What cbox-id's composer.json enforces to run — runtime, extensions, framework, and dependencies.
---

# Requirements

These are taken from this app's `composer.json` (and, where noted, the framework
dependency it pulls in). The Composer resolver enforces the versions below, so this
page just explains them. Storage engines are listed separately as **operations
guidance**, not hard requirements.

## Runtime

| Requirement | Version | Enforced by | Why |
|---|---|---|---|
| PHP | `^8.5` | `composer.json` (`require.php`, and `config.platform.php` for the lock) | The one version the image (`Dockerfile`), the local manifest (`cbox.yaml`) and CI run. |
| ext-openssl | * | `cboxdk/laravel-id` + `cbox-id:doctor` | RSA/EC key generation and JWT/SAML signing. |
| ext-sodium | * | `cboxdk/laravel-id` + `cbox-id:doctor` | Ed25519 signing and AEAD sealing of secrets at rest. |
| ext-pcntl | * | `cboxdk/laravel-queue-autoscale` + `cbox-id:doctor` | The queue manager (`queue:autoscale`) handles signals to drain its workers. |
| ext-posix | * | `cboxdk/laravel-queue-autoscale` + `cbox-id:doctor` | The queue manager signals and reaps the `queue:work` processes it starts. |

`ext-sodium` and `ext-openssl` are not listed in this app's own `require` block, but
the crypto layer in `cboxdk/laravel-id` needs both and `php artisan cbox-id:doctor`
fails loudly if either is missing. `ext-pcntl` and `ext-posix` are required by
`cboxdk/laravel-queue-autoscale`, so Composer refuses to install without them; the doctor
checks them again in the CLI the queue manager actually runs under. The
`ghcr.io/cboxdk/php-baseimages/php-fpm-nginx:8.5-bookworm-v1` base image — what the
`Dockerfile` builds the production image from, and what `cbox.yaml` runs locally — ships
all four.

## Framework

| Requirement | Version | Used for |
|---|---|---|
| `laravel/framework` | `^13.0` | The application framework. |
| `inertiajs/inertia-laravel` | `^3.3` | The server half of the console UI: a controller returns a page name and typed props, Laravel returns them as JSON to a React bundle served same-origin. |
| `laravel/wayfinder` | `^0.1` | Generates TypeScript route helpers and form actions from `routes/*.php` at build time, so no URL is spelled by hand in the client. |

## Cbox / cboxdk dependencies

Pulled in automatically by `composer install`:

| Package | Version | Used for |
|---|---|---|
| `cboxdk/laravel-id` | `^1.23` | The identity engine (crypto, tenancy, OAuth/OIDC, SCIM, SAML, audit). |
| `cboxdk/laravel-postal` | `^0.1.1` | Transactional mail delivery via Postal. |
| `cboxdk/laravel-ssrf` | `^1.1.1` | The outbound URL guard: DNS pinning and private-range refusal. |
| `firebase/php-jwt` | `^7.0` | JWT encode/verify beneath the token signer (vetted, not hand-rolled). |
| `cboxdk/laravel-health` | `^2.0` | Health/readiness reporting. |
| `cboxdk/laravel-risk` | `^1.1` | Bot/abuse risk scoring on signup/login (monitor mode by default). |
| `cboxdk/laravel-telemetry` | `^1.0` | Tracing / telemetry (trace IDs on error screens). |
| `cboxdk/laravel-console-kit` | `^0.2` | Console plugin sockets (nav/areas/widgets) the app and its plugins extend. |
| `cboxdk/laravel-dns` | `^0.1.0` | DNS lookups for domain-verification (TXT) and MX checks. |
| `cboxdk/dns` | `^0.1` | The framework-agnostic DNS resolver beneath laravel-dns. |
| `bacon/bacon-qr-code` | `^3.1` | TOTP enrolment QR codes. |
| `cboxdk/laravel-queue-metrics` | `^3.4` | Queue depth/throughput metrics the autoscaler scales on. |
| `cboxdk/laravel-queue-autoscale` | `^4.3` | The queue manager: starts, sizes and stops the `queue:work` processes. See [Queue workers](operations/queue-workers.md). |
| `cboxdk/laravel-queue-monitor` | `^1.11` | The operator-only job monitor under Platform › Queues. |

## Other Composer dependencies

| Package | Version | Used for |
|---|---|---|
| `laravel/mcp` | `^1.0` | The MCP server at `/mcp` on each environment host. See [Agents and MCP](guides/agents-and-mcp.md). |
| `laravel/tinker` | `^3.0` | REPL for operations/debugging. |

> Social and enterprise sign-in needs **no third-party package**. Google, Entra, Okta,
> GitHub, Apple and the rest are the framework's own `Federation` stack in
> `cboxdk/laravel-id` — a provider catalogue plus OIDC and OAuth 2.0 clients that go
> through this app's SSRF guard. This page previously listed `laravel/socialite` and
> `socialiteproviders/microsoft`, which are in neither `composer.json` nor
> `composer.lock` and appear nowhere in the code; do not add them.

> `cboxdk/laravel-id` is a 1.x release under semantic versioning, and this app tracks it
> at the constraint in the table above. Breaking changes wait for a major and are written
> up in the engine's `UPGRADING.md`; read its changelog before a minor bump anyway, because
> a minor is where new console surfaces and new migrations arrive.

## Building assets

The UI is built with Vite + Tailwind. Producing production assets requires
**Node.js** (CI uses Node 22) and runs `npm ci && npm run build`. Node is a
build-time requirement only; it is not needed to serve the built app.

## Storage (operations guidance, not a hard requirement)

The default `.env.example` ships `DB_CONNECTION=sqlite` and the test suite runs on
SQLite, so nothing in `composer.json` mandates a particular database. For a
production identity provider, however, run a server database:

| | Production (cboxid.com) | Also supported |
|---|---|---|
| Database | **PostgreSQL 18**, in the cluster beside the app, with continuous encrypted backups | MySQL **8.0.13 or later** (CI runs the suite on 8.4) |
| Cache, sessions, queue | **Valkey 8**, in the cluster beside the app, `noeviction` | Redis |

- **Not SQLite** in production: one file, one writer, and no shared state for a second
  replica.
- **MySQL's floor is 8.0.13** because that is where expression column defaults landed,
  which is the only way MySQL accepts a default on a `json` column. MariaDB is not tested.
- **The store that holds the queue runs `maxmemory-policy noeviction`.** A queued job is
  data: under an evicting policy a full Valkey or Redis silently drops webhooks,
  back-channel logouts and mail. With `noeviction` a full instance refuses writes, which is
  an error somebody sees. If the cache shares the instance, size it so the cache's TTL'd
  keys never fill it, or give the cache its own instance.
- **More than one web replica needs all of it shared.** Cache, sessions and rate limits
  in Redis/Valkey rather than `file` or `array`; `cbox-id:doctor` fails a per-process store
  when `CBOX_ID_REPLICAS` is above one.

The CI `engines` job runs the whole suite on both server engines, PostgreSQL on
production's major. `tests/Feature/DeploymentManifestTest.php` fails if that major, the one
the local manifest (`cbox.yaml`) runs and the one named on this page drift apart. See
[Deployment](operations/deployment.md).
