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
| PHP | `^8.4` | `composer.json` | Uses PHP 8.4 language features throughout. |
| ext-openssl | * | `cboxdk/laravel-id` + `cbox-id:doctor` | RSA/EC key generation and JWT/SAML signing. |
| ext-sodium | * | `cboxdk/laravel-id` + `cbox-id:doctor` | Ed25519 signing and AEAD sealing of secrets at rest. |

`ext-sodium` and `ext-openssl` are not listed in this app's own `require` block, but
the crypto layer in `cboxdk/laravel-id` needs both and `php artisan cbox-id:doctor`
fails loudly if either is missing.

## Framework

| Requirement | Version |
|---|---|
| `laravel/framework` | `^13.0` |
| `livewire/livewire` | `^4.3` |
| `livewire/volt` | `^1.10` |

## Cbox / cboxdk dependencies

Pulled in automatically by `composer install`:

| Package | Version | Used for |
|---|---|---|
| `cboxdk/laravel-id` | `^1.16` | The identity engine (crypto, tenancy, OAuth/OIDC, SCIM, SAML, audit). |
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
| `cboxdk/laravel-queue-metrics` | `^3.2` | Queue depth/throughput metrics. |
| `cboxdk/laravel-queue-autoscale` | `^3.0` | Worker autoscaling. |

## Other Composer dependencies

| Package | Version | Used for |
|---|---|---|
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

- **Recommended in production:** PostgreSQL or MySQL/MariaDB (not SQLite).
- **Recommended cache/queue/session backend:** Redis.

These are recommendations for a live deployment — see
[Deployment](operations/deployment.md) — not constraints the resolver enforces.
