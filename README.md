# Cbox ID

The identity platform behind [cboxid.com](https://cboxid.com), and the app you can run
yourself. Sign-in for your apps, your customers' organizations, Enterprise SSO (SAML and
OIDC), Directory Sync (SCIM), roles and permissions, API keys, App audit logs, an Admin
Portal for your customers' IT admins, and a tamper-evident Audit log — every change
reachable from the console, a REST API, an MCP server and the `cbox` CLI.

It is the deployable app built on the [`cboxdk/laravel-id`](https://github.com/cboxdk/laravel-id)
framework, which provides the identity engine (crypto, tenancy, OAuth 2.1 and OpenID
Connect, SAML, SCIM, CIBA, audit). This repository adds the consoles, the hosted pages,
onboarding, the action layer and the hosted-cloud concerns.

**Source-available** under the Elastic License 2.0 (see [License](#license)).

## Quick links

| | |
|---|---|
| Sign in to your first app | [Quickstarts](docs/quickstarts/_index.md): Next.js, React, Laravel, Nuxt, Go, Python |
| Understand the model | [Concepts](docs/core-concepts/_index.md), starting with [Workspaces & organizations](docs/core-concepts/workspaces-and-organizations.md) |
| Wire up an AI agent | [Agents and MCP](docs/guides/agents-and-mcp.md), [Actions reference](docs/reference/_index.md) |
| Administer an environment | [Admin guides](docs/guides/_index.md) |
| Hand a customer's IT admin a setup link | [Admin Portal](docs/guides/admin-portal.md), [For IT admins](docs/for-it-admins/_index.md) |
| Run it yourself | [Self-hosting](docs/self-hosting/_index.md), [Upgrading](UPGRADING.md) |
| Report a vulnerability | [SECURITY.md](SECURITY.md) |

All documentation starts at [`docs/index.md`](docs/index.md).

## How it is put together

**Workspace → project → environment → organizations → users.** Your workspace is your own
Cbox account; it owns projects (one identity product each), each project has environments
(production, sandbox), and each environment is a fully isolated identity provider on its
own host, holding your customers' organizations and their people.
[More](docs/core-concepts/workspaces-and-organizations.md).

**Two kinds of host.** The platform root (`cboxid.com`) serves the workspace console, the
workspace and platform APIs, and one MCP server for your whole workspace. Each
environment's own host (`<environment>.cboxid.com`, or a custom domain) is the OpenID
Connect issuer your apps use, with its hosted sign-in pages, its console at `/admin`, its
management API and its own MCP server. [More](docs/core-concepts/planes-and-hosts.md).

**One action, four doors.** Every change the console can make is an *action*: one class in
`app/Actions` declaring its name, REST route, scope, danger and input. The console, the
REST API (generated from the registry), the MCP server (one tool per action at `/mcp`) and
the `cbox` CLI (one command per action, built from the OpenAPI documents) all run it
through the same runner: same authorization, validation, approvals, idempotency and audit
entry. A key's creator can require a person's approval on their device before its
dangerous actions run, and a token a person delegated always waits for them on critical
ones. [More](docs/core-concepts/actions.md).

**Four planes.** The management API is split by who acts over what: environment
(`/api/v1`), workspace (`/api/v1/workspace`), account (`/api/v1/me`) and platform
(`/api/v1/platform`). Each publishes a public OpenAPI 3.1 document, and the
[actions reference](docs/reference/_index.md) is generated from the same registry.

## Stack

- **Laravel 13**, PHP 8.5, argon2id password hashing.
- **Inertia + React 19 + Tailwind v4.** Every page and every write is a Laravel route with
  its own middleware, and React renders the props the controller hands it. Chosen for an
  identity console: session-cookie auth with no tokens in the browser, one same-origin
  bundle, and a `script-src` without `unsafe-inline` or `unsafe-eval`. The console is in
  English; the hosted pages (sign-in, consent, the Admin Portal and their emails) are
  translated into six languages.
- **`laravel/mcp`** for the MCP server; **`cboxdk/laravel-id`** for the identity engine; the
  first-party observability stack (`laravel-telemetry`, `laravel-health`,
  `laravel-queue-metrics`, `laravel-queue-autoscale`).

## Run it locally

```bash
git clone https://github.com/cboxdk/cbox-id.git && cd cbox-id
composer setup          # installs deps, copies .env, creates the sqlite db, then runs
                        # `cbox-id:install`: mints the crypto master key, migrates, and
                        # creates the first operator and environment (and, in the
                        # SaaS shape, the first workspace)
composer run dev        # serve + queue + vite + logs
```

Sign in at `/login`. **Back up `CBOX_ID_CRYPTO_KEY`** somewhere separate from the database:
losing it makes sealed secrets unrecoverable. The required variables are `CBOX_ID_CRYPTO_KEY`,
`CBOX_ID_ISSUER`, `CBOX_ID_WEBAUTHN_RP_ID` and `CBOX_ID_WEBAUTHN_ORIGIN`, all in
`.env.example`. No shell on the box? An empty deployment serves one page, `/first-run`,
guarded by a setup token. See the [self-hosting quickstart](docs/self-hosting/quickstart.md)
and, for production, [Deployment](docs/operations/deployment.md).

## Develop

```bash
composer run dev                                  # the app, the queue, vite and logs
vendor/bin/pest --parallel --testsuite=Unit,Feature
vendor/bin/pest --testsuite=Browser               # real-browser tests (Playwright)
vendor/bin/pint --test
vendor/bin/phpstan analyse --memory-limit=2G
php artisan openapi:build --check                 # the OpenAPI documents are current
php artisan docs:actions --check                  # so is docs/reference
```

Adding an action: write the class in `app/Actions/<Area>/`, then run
`php artisan openapi:build` and `php artisan docs:actions` and commit what they write.

## Status

Actively developed and dogfooded on [cboxid.com](https://cboxid.com). It composes
`cboxdk/laravel-id` 1.x (see [`composer.json`](composer.json) for the constraint). Review the
[security notes](docs/security/_index.md) and [`SECURITY.md`](SECURITY.md) before running it in
production, and [UPGRADING.md](UPGRADING.md) before crossing a version.

## License

Cbox ID (this application) is **source-available** under the **Elastic License 2.0** — see
[LICENSE](LICENSE). It is not open source. You may use, copy, modify and redistribute it,
with three limitations:

- you may **not provide it to third parties as a hosted or managed service** that gives them
  substantial access to its features;
- you may not circumvent the licence-key functionality or remove or obscure protected
  features;
- you may not remove or alter any licensing, copyright or other notices.

The framework it is built on, [`cboxdk/laravel-id`](https://github.com/cboxdk/laravel-id), is
**MIT**, so building your own identity product on the framework is unrestricted. The
Elastic-2.0 terms apply to this deployable app. To run Cbox ID as a managed service for your
own customers, get in touch about a commercial licence.
