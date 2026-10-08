---
title: Deployment
weight: 2
description: How cboxid.com runs and is released from main, what the application expects from its environment, and how to deploy Cbox ID yourself on a VM, a PaaS or any Kubernetes cluster.
---

# Deployment

Two audiences, two halves. [Production](#production-cboxidcom) describes how
`cboxid.com` runs and how a commit on `main` reaches it — read it before you merge.
[Self-hosting](#self-hosting-a-vm-a-paas-or-any-kubernetes-cluster) is for running Cbox ID
yourself, from a fresh server or cluster to a hardened instance. The sections after it —
the IdP-surface gate, security headers, health probes — hold for both. This is an identity
provider; the guidance is deliberately security-first.

## Production: cboxid.com

> **Merging to `main` deploys to production.** Every commit on `main` whose checks all
> pass is released to `cboxid.com` automatically, within minutes of the last one going
> green.
> There is no separate deploy step and no approval after the merge. Work on a branch, open
> a pull request, and merge only what you would ship.

`cboxid.com` and every tenant host under `*.cboxid.com` run on Cbox's own Kubernetes
cluster, `cbox-hel1`: Talos Linux on Hetzner in Helsinki, on **ARM64** nodes. The cluster,
the manifests and the secrets are defined in the private infrastructure repository, not
here; this page describes what they do, so that a change in this repository can be judged
against it. `cbox.yaml` in this repository is **not** the production definition — it is
the [local manifest](#cboxyaml-is-the-local-manifest) that mirrors production's shape.

### What runs

Everything lives in one namespace, `cbox-id`.

| Piece | How it runs |
|---|---|
| **Web** | A Deployment of **2 replicas** of `ghcr.io/cboxdk/cbox-id` (this repository's `Dockerfile`, built on the `php-fpm-nginx:8.5-bookworm-v1` base). `cbox-init` is PID 1 and runs nginx and PHP-FPM. Rolling updates add one new pod before removing an old one, so two stay serving; a disruption budget keeps at least one up during node maintenance, and the replicas are spread across nodes. |
| **Worker** | A Deployment of **1 replica** of the same image, with nginx and PHP-FPM switched off. `cbox-init` runs two processes in it: the **scheduler** (`php artisan schedule:work`) and the **queue manager** (`php artisan queue:autoscale`, which starts and sizes the `queue:work` processes itself). The pod is replaced on a rollout, never overlapped, so there is exactly one scheduler. |
| **Migrations** | A suspended CronJob, `cbox-id-migrate`, used only as a template: each release creates a Job from it on the new image, running `php artisan migrate --force`, **before** the web and worker pods roll. |
| **Releases** | The CronJob `cbox-id-release`, every five minutes — see [Releases from main](#releases-from-main). |
| **Database** | **PostgreSQL 18** in the namespace, one instance on its own volume. WAL is archived continuously and the database backed up nightly (a full backup weekly, differentials between) to encrypted object storage, and restores are tested. |
| **Cache, sessions, queue** | **Valkey 8** in the namespace, one instance with a password and an append-only file, running **`maxmemory-policy noeviction`** — it holds the queue, and a queued job is data. |
| **Ingress** | A Cloudflare Tunnel routes `cboxid.com` and `*.cboxid.com` to the web Service. TLS ends at Cloudflare's edge; the application has no public address of its own. |
| **Network** | Default deny. The application's pods reach PostgreSQL, Valkey and the public internet — webhooks, outbound SCIM and back-channel logout go to endpoints tenants configure, mail to its provider — and never a private address range. |
| **Probes** | `cbox-init`'s own health port: readiness on `/readyz`, liveness on `/livez`. Prometheus metrics on port 9090. |

### Releases from main

The release CronJob runs every five minutes. It walks the newest commits on `main` and
releases the newest one that:

1. is **ahead of** the commit that is running (it never moves production backwards);
2. has **every check run finished, none failed** — CI, the supply-chain job, the image
   build, anything else that reports a check on the commit (`success`, `skipped` and
   `neutral` count as passed). A commit with a check still running is skipped for now;
3. has its image, **`ghcr.io/cboxdk/cbox-id:sha-<short sha>`, with a `linux/arm64`
   variant** — built by this repository's
   [`build-image.yml`](https://github.com/cboxdk/cbox-id/blob/main/.github/workflows/build-image.yml)
   on GitHub's arm64 runners, on every push to `main`.

A release then runs, in this order:

1. **Migrate.** A Job from the `cbox-id-migrate` template on the new image runs
   `php artisan migrate --force`, while the old pods keep serving. If it fails, nothing
   rolls: the commit is marked failed and production stays on the running release.
2. **Roll** the web Deployment and the worker Deployment to the new image, and wait for
   both to be fully available.
3. **Record** the commit on both Deployments; that is what the next run compares with.

**If the rollout fails** — a pod that never becomes ready, a crash loop — both
Deployments are rolled back to the images that ran before, and the commit is marked
failed. **The migrations are not reverted.** A failed commit is never retried; the next
commit that passes its checks is released instead, so the fix for a failed release is a
new commit on `main`.

What follows from this, for anyone merging:

- **A release trails its merge by CI's duration** — typically 35–50 minutes, because the
  CronJob waits for every check. Several merges in that window ship together, as the
  newest one.
- **A red check on `main` holds every release** until a newer commit is green. Fix `main`
  forward rather than leaving it red.
- **Commits that change only documentation are skipped.** `build-image.yml` ignores
  `**.md` and `docs/**`, so no image exists for them; they go out with the next commit that
  builds one.
- **Migrations run against the old code.** The old release keeps serving while they run,
  and a failed rollout returns to it on the new schema. Every migration must be safe for
  the release before it: add tables and columns, backfill, and only drop or rename what
  the running release no longer reads, one release later. A release that cannot keep to
  this says so in [`UPGRADING.md`](https://github.com/cboxdk/cbox-id/blob/main/UPGRADING.md)
  and is coordinated with the operator before it merges.
- **A release that needs a new environment variable or secret** needs it in production
  **before** it merges, because nothing waits once it does. Ask the operator to add it to
  `cbox-id-env` first; give it a safe default in `config/` wherever you can.
- **The release depends on this repository's image build.** Keep `build-image.yml`
  publishing a multi-arch image with `linux/arm64` and the `sha-<short>` tag, and keep the
  GHCR package public: the CronJob reads it, the commit list and the check runs without
  any credential.

**Holding releases.** During an incident, or ahead of a change that needs hands on it,
the operator suspends the `cbox-id-release` CronJob in the cluster; nothing is released
until it is resumed, and then the newest passing commit goes out. There is nothing to run
from this repository to hold or trigger a release, and nothing here can reach the
cluster. A manual release workflow in the infrastructure repository covers the case where
the CronJob cannot.

### What the application expects from its environment

Every pod — web, worker and the migrate Job — takes the same environment from the
ConfigMap and Secret `cbox-id-env`, which the infrastructure repository assembles: the
application's own settings carried over from its previous host, the connection settings
it generates for the database and Valkey, and these overrides.

| Variable | Production | Why |
|---|---|---|
| `APP_ENV`, `APP_DEBUG` | `production`, `false` | The doctor's hardening checks run in production only. |
| `APP_URL` | `https://cboxid.com` | The platform root. |
| `LOG_CHANNEL`, `LOG_LEVEL` | `stderr`, `info` | The pod's disk goes with the pod; the cluster collects stderr. |
| `TRUSTED_PROXIES` | the pod network | TLS ends before the pod. Without it every URL, redirect and issuer is built as `http`. |
| `SESSION_SECURE_COOKIE` | `true` | |
| `CBOX_ID_REPLICAS` | `2` | Tells the application the web Deployment's replica count, which it cannot see. Change the two together. |
| `CACHE_STORE`, `SESSION_DRIVER`, `QUEUE_CONNECTION` | `redis` | Two replicas share rate limits, single-use tokens, replay guards, sessions and the queue through Valkey. `cbox-id:doctor` fails a per-process store when `CBOX_ID_REPLICAS` is above one. |
| `REDIS_CLIENT` | `phpredis` | |
| `QUEUE_AUTOSCALE_CLUSTER_ENABLED` | `true` | The queue manager coordinates through Valkey, so a second manager — a replica added later, one started by hand — never doubles the workers. |
| `DB_CONNECTION` and `DB_*` | `pgsql`, generated | The namespace's PostgreSQL; the password is generated and kept in the Secret. |
| `REDIS_HOST`, `REDIS_PORT`, `REDIS_PASSWORD` | generated | The namespace's Valkey. |
| `HEALTH_TOKEN` | from a secret file | What `/health/ready` and `/health/status` require; `cbox-id:doctor` fails without it. |
| `APP_KEY`, `CBOX_ID_CRYPTO_KEY` | carried over, unchanged | `CBOX_ID_CRYPTO_KEY` seals every stored secret. It is backed up apart from the database; a new one opens nothing sealed under the old. |
| `CBOX_ID_*` (issuer, base domains, WebAuthn), `MAIL_*` | carried over | The deployment's own settings and its mail transport. |

The pods add their own:

| Variable | Set on | Value |
|---|---|---|
| `LARAVEL_AUTO_OPTIMIZE` | web, worker | `true` — the base image caches config, routes and events at container start, against the pod's own environment. |
| `CBOX_INIT_PROCESS_PHP_FPM_ENABLED`, `CBOX_INIT_PROCESS_NGINX_ENABLED` | worker | `false` — the worker serves nothing. |
| `CBOX_INIT_PROCESS_SCHEDULER_ENABLED`, `CBOX_INIT_PROCESS_AUTOSCALER_ENABLED` | worker | `true` — `schedule:work` and `queue:autoscale`, each run as `www-data`. |
| `CBOX_INIT_PROCESS_AUTOSCALER_SHUTDOWN_TIMEOUT`, `CBOX_INIT_GLOBAL_SHUTDOWN_TIMEOUT` | worker | `80` seconds, inside a 90-second termination grace, so the queue manager's workers finish their current job before the pod goes. |

The security-header variables (`NGINX_HEADER_*`, [below](#security-headers-the-app-is-the-single-owner))
are baked into the image by the `Dockerfile`, as is `APP_ENV=production`.

Changing production's environment is the operator's change in the infrastructure
repository, re-applied there; it is not something a commit here can do.

### Health in production

Kubernetes routes on `cbox-init`'s `/readyz` and restarts on `/livez`, on the health port.
The application's own endpoints are for monitoring and the operator:

- `GET /up` — liveness, no database, answers on every host.
- `GET /health/ready` — database, cache, queue and storage, with `HEALTH_TOKEN`.
- `GET /health/status` — the `scheduler`, `event_relay` and `queue_workers` checks: red
  when the worker pod's scheduler or queue manager stops reporting. Alert on it; never
  route on it.
- `php artisan cbox-id:doctor`, run in a web pod, after a release that changes anything
  operational.

### `cbox.yaml` is the local manifest

[`cbox.yaml`](https://github.com/cboxdk/cbox-id/blob/main/cbox.yaml) is what
cbox-engine — the engine behind the `cbox` CLI and Cbox Local —
reads to run Cbox ID on a developer's machine, in a Kubernetes cluster inside Docker, the
way production runs it: two web replicas that share all state through Valkey, the queue
manager and the scheduler beside them, PostgreSQL 18, the same proxy and cookie settings,
the same base image with the working copy mounted in. The keys come from the working
copy's `.env`. `tests/Feature/DeploymentManifestTest.php` holds it to cbox-engine's
reader and to production's shape. Nothing deploys production from it.

## Self-hosting: a VM, a PaaS or any Kubernetes cluster

From a fresh server or cluster to a running, hardened Cbox ID instance. Production's
shape above is one answer; the pieces every deployment needs are these:

| Piece | Runs as |
|---|---|
| Web | nginx + PHP-FPM (the `Dockerfile` image does both), behind your TLS proxy; as many replicas as you like once state is shared |
| Queue manager | `php artisan queue:autoscale`, one per host, under a supervisor |
| Scheduler | `php artisan schedule:work`, exactly one, under a supervisor (or `schedule:run` from cron) |
| Database | PostgreSQL (production runs 18), or MySQL 8.0.13 or later |
| Cache, sessions, queue | Valkey or Redis, `maxmemory-policy noeviction` |
| Secrets | your secrets manager, into the process environment |
| Probes | liveness `/up`, readiness `/health/ready` with `HEALTH_TOKEN` |

## Requirements

- **PHP 8.5** with `ext-sodium` and `ext-openssl` (the crypto layer needs both;
  `cbox-id:doctor` fails loudly if either is missing).
- A database — **PostgreSQL or MySQL** in production (not SQLite).
- A cache/queue backend — **Valkey or Redis** (sessions, rate limits, queues), running
  `maxmemory-policy noeviction` because it holds the queue.
- **TLS terminated in front of the app.** Passkeys (WebAuthn) and secure cookies
  require HTTPS; the platform assumes it.

See [Requirements](../requirements.md) for the full, `composer.json`-backed list.

## 1. Install the code

From the image there is nothing to install: `ghcr.io/cboxdk/cbox-id` (built from this
repository's `Dockerfile` for `linux/amd64` and `linux/arm64`, tagged per commit on `main`
and per release) has the application in it. From a checkout:

```bash
composer install --no-dev --optimize-autoloader
npm ci && npm run build
```

## 2. Bootstrap

The guided installer generates the crypto master key, asks the few questions that
matter (the first operator, the deployment shape, the issuer URL), writes them to
`.env`, runs migrations, provisions the platform root — and the first workspace, in the
multi-tenant shape — mints the first signing key, and then runs `cbox-id:doctor`
against what it built:

```bash
php artisan cbox-id:install
```

Non-interactive deploys pass the same answers as options, and the command fails
rather than guessing when a required one is missing:

```bash
php artisan cbox-id:install --no-interaction \
    --email=root@acme.example --password="$OPERATOR_PASSWORD" \
    --issuer=https://id.acme.com
```

It refuses to run on a deployment that already holds anything, so it is safe to leave
in a provisioning script — but it is not idempotent and there is no `--force`. See
[Installation](../getting-started/installation.md) for every option.

**In a container, nothing it writes to `.env` survives the container.** Put the keys in
your secrets store first — `APP_KEY`, and `CBOX_ID_CRYPTO_KEY` from
`php -r "echo base64_encode(random_bytes(32)).PHP_EOL;"`; the installer only mints a crypto
key when none is set — put the issuer and the deployment shape's variables in the
environment, deploy, and then run the non-interactive install once as a one-off command in
a web container. The `/first-run` screen works too, with any number of replicas: run
`php artisan cbox-id:setup-token` in any container and paste what it prints. The token is
kept, hashed, in the database every replica shares, so whichever container answers the
browser accepts it. It is single use and expires after an hour (`CBOX_ID_SETUP_TOKEN_TTL`);
running the command again mints a fresh one and retires the last.

## 3. Optimize for production

```bash
php artisan config:cache
php artisan route:cache
php artisan event:cache
```

Re-run these on every deploy after the code and `.env` are in place. The image's
entrypoint does this at container start, against the container's own environment, when
`LARAVEL_AUTO_OPTIMIZE=true`.

## 4. Create the first platform operator

Step 2 already did this: `cbox-id:install` creates the **platform operator** — the
identity above every environment, which administers workspaces, environments,
organizations and other operators — along with the platform-root environment and, in
the multi-tenant shape, the first workspace. Immediately enroll a passkey or TOTP
factor; this is the most sensitive account on the system.

If the deployment was stood up without a shell (an image started by someone else),
claim it in the browser at `/first-run` instead. That page exists only while the
platform is empty and requires a setup token, which `php artisan cbox-id:setup-token`
prints on any instance of the deployment — so an internet-exposed box cannot be claimed by
whoever finds it first. See [Installation](../getting-started/installation.md).

Sign in at **`/workspace/login`** — the install command prints the URL — and open the
**`/platform`** section, the deployment pages in that console's rail. From there
create your remaining environment(s) and use **Provision admin** on each to seed its
first organization and owner-admin. Those org admins then sign in at `/login`.

## 5. Run the workers

Three processes, not one. The web server alone is **not** a working deployment:

```bash
php artisan queue:autoscale        # the queue manager, one per host, under a supervisor
php artisan schedule:work          # a long-running process — or `schedule:run` from cron, every minute
```

Run them from the same code, environment and secrets as the web tier, and restart them if
they exit. In the image, `cbox-init` runs either one when told to
(`CBOX_INIT_PROCESS_AUTOSCALER_ENABLED=true`, `CBOX_INIT_PROCESS_SCHEDULER_ENABLED=true`);
production runs both in one worker container with nginx and PHP-FPM off.

The queue manager starts and sizes the `queue:work` processes itself; do not run
`queue:work` beside it. Without it no webhook, back-channel logout or queued mail is ever
sent, and `/health/status` reports it. `docker-compose.yml` and the local `cbox.yaml`
already declare it; for any other host, the systemd unit, the deploy step and the sizing
are in [Queue workers](queue-workers.md).

The scheduler is not optional and its absence does not raise an error. Without it the
domain-event outbox is never relayed, and because every subscriber hangs off that
outbox, all of the following silently do nothing:

| Without the scheduler | Consequence |
|---|---|
| `cbox-id:events:relay` | no webhook is ever delivered; no usage is metered (plan gates read zero); outbound SCIM never provisions; role changes never revoke tokens |
| the webhook retry sweep¹ | a transient endpoint outage never recovers |
| `cbox-id:provisioning:drain` | the provisioning outbox never drains |
| `cbox-id:audit-streams:pump` | SIEM streams stop mid-flight |

¹ A scheduled closure, not an artisan command — `php artisan cbox-id:webhooks:retry`
does not exist. It appears in `schedule:list` under that name, which is why it reads
like one.

`cbox-id:keys:rotate` is **not** scheduled: run it yourself, on your own cadence. It is
listed here because operators reasonably assume the scheduler covers it, and it does not.

Two checks on `/health/status` turn red when this goes wrong, and `cbox-id:doctor` fails
on both in production:

| Check | Red when |
|---|---|
| `scheduler` | the scheduler has not run for 5 minutes (`HEALTH_SCHEDULER_MAX_AGE_SECONDS`), or never has |
| `event_relay` | the oldest undelivered domain event is older than 5 minutes (`HEALTH_EVENT_RELAY_MAX_LAG_SECONDS`) |

Neither is on readiness: a stopped scheduler is something to be told about, not a reason
to take web instances out of rotation. Verify by hand with:

```bash
php artisan schedule:list          # cbox-id:events:relay must appear, every minute
```

`docker-compose.yml` ships `app`, `queue` and `scheduler` services. Mirror all three in any
other manifest.

**More than one web replica needs every piece of state shared.** Cache, sessions and the
queue in Valkey or Redis (`CACHE_STORE`, `SESSION_DRIVER`, `QUEUE_CONNECTION` = `redis`),
`CBOX_ID_REPLICAS` set to the replica count, and `QUEUE_AUTOSCALE_CLUSTER_ENABLED=true` if
more than one queue manager can run. The Valkey or Redis that holds the queue runs
**`maxmemory-policy noeviction`**: a queued job is data, and an evicting policy silently
deletes webhooks and mail when memory runs out.

## Rolling out a release

The order, wherever it runs:

1. **Back up the database**, and confirm `CBOX_ID_CRYPTO_KEY` and `APP_KEY` are in your
   offline key backup.
2. **Put new configuration in place first** — a variable or secret the release needs,
   before the new code starts.
3. **Migrate, once, with the new release:** `php artisan migrate --force`, from the new
   code — on Kubernetes a one-off Job on the new image, with the same environment and
   secrets. Not from a running old container: that is the old code, with the old
   migrations.
4. **Roll the web tier**, behind readiness (`/health/ready`), so an instance takes traffic
   only once it can serve; then replace the queue manager and the scheduler. A replaced
   queue manager gets SIGTERM, lets its workers finish their current job and exits; on a
   VM, `php artisan queue:restart` does the same.
5. **Run `php artisan cbox-id:doctor`** and treat any ✗ as a reason to roll back.
   `/health/status` should be green within a couple of minutes, once the new scheduler and
   queue manager have reported in.

Migrations run while the old code still serves, so they have to be safe for it: add
columns and tables, do not drop or rename one a running release reads. A release that
cannot keep to that says so in
[`UPGRADING.md`](https://github.com/cboxdk/cbox-id/blob/main/UPGRADING.md).

The base image can also migrate at container start (`LARAVEL_MIGRATE_ENABLED=true`, with
`--isolated` so only one container runs it at a time). Leave it **off**: every container —
web, queue and scheduler — would run it, a failed migration would crash-loop the new
containers, and it takes away the backup-first step a release like 2.0.0 needs. Production
migrates in a Job before the rollout instead, as above.

See [Day-2 operations](operations.md#upgrades) for the VM commands.

## 6. Verify

```bash
php artisan cbox-id:doctor
```

In production this also checks the **hardening** posture: `APP_DEBUG` off, secure +
encrypted session cookies. Treat any ✗ as release-blocking. A green doctor plus a
reachable `/.well-known/openid-configuration` means you're live.

## Upgrading: SSO enforcement now means what it says

**Read this before deploying if any tenant has a verified domain and an active SSO
connection.**

A verified domain used to route to the provider whatever the organization's **Single
sign-on** setting was, so *Off* and *Prefer SSO* both behaved like *Require SSO*. They no
longer do: only *Require SSO* refuses the password form. Anything weaker offers the
connection and leaves the form standing.

That is the correct reading of the setting, and it is what lets somebody with a passkey
sign in on a federated domain — but for a tenant that was relying on the implicit
behaviour it is a loosening, and nothing will tell them. Find them before you deploy:

```sql
SELECT o.id, o.name, o.slug
FROM organizations o
JOIN verified_domains d ON d.organization_id = o.id AND d.verified_at IS NOT NULL
JOIN connections c ON c.organization_id = o.id AND c.status = 'active'
LEFT JOIN auth_policies p ON p.organization_id = o.id
WHERE p.sso IS NULL OR p.sso <> 'required';
```

Every row is an organization whose people are force-redirected today and will get a
password form afterwards. Set **Require SSO** on each — from the console, or by writing
`sso = 'required'` to its auth policy — and the behaviour is unchanged. Deliberately not
migrated for you: it writes a customer's security posture, and inferring consent from a
configuration they never made is how the setting came to be ignored in the first place.

## Reverse proxy notes

- Terminate TLS; forward the real scheme/host (`X-Forwarded-Proto`/`-Host`) and
  configure Laravel's trusted proxies (`TRUSTED_PROXIES`, see
  [Configuration](../configuration/environment-variables.md#reverse-proxy)) so
  issuer URLs and cookie `Secure` flags are correct.
- The discovery, JWKS, token, introspection, SCIM and SAML ACS endpoints are all
  served by the app — no separate service to route. In a multi-tenant deployment they
  are not served on *every* host: see
  [the IdP-surface gate](#the-idp-surface-gate-the-apex-host-404s-the-protocol-surface).

## The IdP-surface gate: the apex host 404s the protocol surface

**Symptom:** `https://<apex>/.well-known/openid-configuration` returns **404** on a
deployment where it used to return 200. This is deliberate, not an outage. Read this
before rolling back.

In a **multi-tenant** deployment the platform-root (apex) host is not an issuer. It mints
no tokens, signs no assertions and has no relying parties, so the whole IdP protocol
surface is confined to the **issuer plane**: an environment's own host, i.e. a custom
domain or `{slug}.{base_domain}`.

That is a narrower claim than it used to be. The apex is also the *workspace door* — sign up,
manage the workspace and its environments — and it was once assumed the two went together, so
one gate answered both "is this an issuer?" and "does the console live here?". They differ
on exactly this host: the platform root is a tenant like any other, whose subjects sign in
and administer their organizations there. `/login` and the console are served on the apex
(`plane:console`); the list below is what it still refuses (`plane:issuer`).

### What the apex refuses

Every one of these returns 404 on the apex host, and 200 (or its own error) on the
environment's host:

- `/.well-known/openid-configuration`, `/.well-known/jwks.json`,
  `/.well-known/oauth-authorization-server`, `/.well-known/oauth-protected-resource`
- `/oauth/token`, `/oauth/introspect`, `/oauth/revoke`, `/oauth/par`,
  `/oauth/device_authorization`, `/oauth/backchannel_authentication`,
  `/oauth/userinfo`, `/oauth/decisions`, `/oauth/logout`, `/oauth/register*`,
  `/user-tokens/introspect`
- `/oauth/authorize` — the interactive consent screen
- `/scim/v2/*`
- The **IdP-role** SAML endpoints only: `/sso/saml/idp/metadata`, `/sso/saml/idp/sso`,
  `/sso/saml/idp/slo`

### What the apex still serves

- **`GET /up`** — registered outside both environment resolution and this gate, so a
  kubelet probing the pod IP still gets an answer.
- **Inbound federation** — `/sso/saml/{connection}/metadata`, `/sso/saml/{connection}/acs`,
  `/sso/saml/{connection}/login`, `/sso/saml/{connection}/slo`,
  `/sso/oidc/{connection}/redirect`, `/sso/oidc/{connection}/callback`. These are the
  *opposite* role — this server as the relying party consuming someone else's assertion —
  and the management plane genuinely uses them: an account's own organization lives in the
  platform-root environment, so home-realm discovery on `/workspace/login` or `/signup`
  sends the member to a connection URL **on the host they are already standing on**.
  Gating them locked an account org with enforced SSO out of its own workspace. The
  boundary here is the connection's environment scope, which holds on either plane, not
  the host.
- The management plane itself: `/signup`, `/console/*` and the organization-management API
  (`/api/v1/workspace/*`, `/api/v1/openapi.yaml`). The environment-scoped management API is
  a different thing and is served on an environment's own host.
- **The console** — `/login`, `/dashboard`, `/account` and every page behind them. The
  apex is a tenant, and its subjects sign in there. What it does *not* serve is the
  environment-admin door `/admin/*` (`plane:environment`), which is how an account reaches
  *into* an environment from the management plane and so has no meaning on the management plane
  itself. Only under the apex's own name: an unmapped host under `base_domain` resolves to
  the platform root, and the console gate matches the host rather than the resolved
  environment so that a wildcard name does not get a working sign-in form.

Note that despite what the surrounding config comments say, "SAML" is **not** gated as a
whole — only the IdP-role `/sso/saml/idp/*` endpoints are.

### Why

The apex used to serve half an IdP: discovery returned 200 advertising
`issuer: https://<apex>` alongside `authorization_endpoint: https://<apex>/oauth/authorize`
— a URL that 404s, because the consent screen is issuer-plane only. A conformant client
discovers that document and dead-ends. Half an IdP is worse than none, because it is
discoverable.

### It is inert in a single-tenant install

The gate is `App\Http\Middleware\EnforcePlane` (aliased `plane` in `bootstrap/app.php`),
applied to the framework's whole protocol route group through
`cbox-id.api.middleware => ['plane:issuer']` in `config/cbox-id.php`, and to this app's
own routes directly. It resolves the plane from the host-resolved environment and 404s
anything asked for on the wrong one.

`EnforcePlane` short-circuits when `CBOX_ID_ENVIRONMENT_BASE_DOMAINS` is empty: a
single-tenant / self-hosted install is one host that **is** the identity provider, so
there is no account/subject split and the one host serves everything, exactly as before.
**If you did not set `CBOX_ID_ENVIRONMENT_BASE_DOMAINS`, this section does not apply to
your deployment.**

### What an operator must configure

1. Set `CBOX_ID_ENVIRONMENT_BASE_DOMAINS` to the base domain(s) tenant subdomains sit
   under, and nothing else — a host is trusted for slug resolution only under one of
   these, which is what stops a spoofed `Host` selecting a plane.
2. Make sure the platform-root environment is the one flagged `is_default` in the
   database. That row — not `CBOX_ID_ENVIRONMENT_DEFAULT` — is what both the request's
   environment resolution and the plane gate read first. If the two disagree,
   `plane:console` 404s on the host that actually is the account root.
3. Route the apex **and** the tenant hosts (a wildcard for `*.{base_domain}`, plus any
   custom domains) to the same app — the gate does the splitting, not your ingress.
4. Make sure TLS covers the wildcard/custom hosts, and that every client is configured
   with the **environment's** issuer, never the apex. Discovery is what hands them the
   right values; run it against the tenant host.

Verify after a deploy:

```bash
curl -sio /dev/null -w '%{http_code}\n' https://<apex>/.well-known/openid-configuration   # 404, expected
curl -s https://<tenant-host>/.well-known/openid-configuration | head                     # 200, issuer = the tenant host
curl -sio /dev/null -w '%{http_code}\n' https://<apex>/up                                 # 200 on every host
```

The behaviour is pinned by `tests/Feature/IdpSurfaceBulkheadTest.php` in this app and
`tests/Feature/Api/SurfaceMiddlewareTest.php` in `cboxdk/laravel-id`.

## Security headers: the app is the single owner

`App\Http\Middleware\SecurityHeaders` sets `X-Frame-Options`, `X-Content-Type-Options`,
`Referrer-Policy`, `Permissions-Policy`, the CSP and HSTS on **every** response,
including JSON and error responses. Nothing in front of the app should add its own
copy.

The `ghcr.io/cboxdk/php-baseimages/php-fpm-nginx` base image adds four of those by
default, which produced two conflicting values for each in production
(`x-frame-options: DENY` **and** `SAMEORIGIN`; `referrer-policy: same-origin` **and**
`strict-origin-when-cross-origin`; two different `permissions-policy` lists). A user
agent takes the **last** valid `Referrer-Policy`, and nginx's is sent last — so the
stricter `same-origin` this identity provider chose was being silently downgraded.
(Clickjacking itself stayed blocked throughout by the CSP's `frame-ancestors 'none'`,
so the `X-Frame-Options` half was defence in depth, not an open hole.)

**Set these four on every deployment** — whatever renders the pod spec or the
process environment. They are already set in this repository's `Dockerfile` (so in the
image production runs), `docker-compose.yml` and the local `cbox.yaml`; any other manifest
that runs a different image needs them by hand:

```yaml
env:
  - name: NGINX_HEADER_X_FRAME_OPTIONS
    value: ""
  - name: NGINX_HEADER_X_CONTENT_TYPE_OPTIONS
    value: ""
  - name: NGINX_HEADER_REFERRER_POLICY
    value: ""
  - name: NGINX_HEADER_PERMISSIONS_POLICY
    value: ""
```

> **Base-image caveat.** Emptying these is the documented off switch, but the base
> entrypoint currently re-applies its defaults with `${VAR:=default}`, which POSIX
> also applies to a variable that is set but empty — so today the empty values alone
> are a no-op for these four (only headers whose default is already empty — CSP,
> COOP, COEP, CORP — can be switched off this way). The image therefore also ships
> `/docker-entrypoint-init.d/10-app-owns-security-headers.sh`, which strips the four
> `add_header` directives from the generated nginx config before nginx starts. Set
> the env vars anyway: they are the durable declaration of ownership and become the
> whole fix once the base image switches to `${VAR=default}`.

Verify after a deploy — each header must appear exactly **once**:

```bash
curl -sI https://<your-host>/ | grep -iE 'frame-options|referrer-policy|permissions-policy|content-type-options'
```

## Health probes

Two probes, and they answer different questions:

| Probe | Path | Asks | Auth |
|---|---|---|---|
| **Liveness** | `/up` | Is this process running? Asserts nothing else, so a slow database never restarts a healthy pod. | none |
| **Readiness** | `/health/ready` | Can this instance serve? Runs the database, cache, queue and storage checks (`config/health.php`). | `HEALTH_TOKEN`, as `Authorization: Bearer …` or `?token=` |

Route on both; **alert** on `/health/status` (scheduler, event relay, queue workers),
which must never route — a stopped scheduler is not a reason to take web pods out of
rotation. Without `HEALTH_TOKEN` readiness answers 403 to everything, your own probe
included, and `cbox-id:doctor` fails it in production. Probe the web tier only: the queue
manager and the scheduler serve nothing, and their health is the `queue_workers` and
`scheduler` checks on `/health/status`. (Production's pods are probed on `cbox-init`'s own
`/readyz` and `/livez` instead, which also cover the worker container's processes; the
application's endpoints are its monitoring — see
[Health in production](#health-in-production).)

`GET /up` is a JSON liveness probe served by the framework package:

```json
{"status":"ok"}
```

It is registered **outside** environment resolution and outside
[the IdP-surface gate](#the-idp-surface-gate-the-apex-host-404s-the-protocol-surface),
so it answers on any host — including a kubelet probing the pod IP directly — and
without touching the database. Point k8s `livenessProbe` `httpGet` at `/up`; any 2xx
passes, and the probe does not parse the body. Point `readinessProbe` at `/health/ready`
with the token header.

Laravel's built-in HTML health page is deliberately **not** enabled (no `health:` entry
in `bootstrap/app.php`): it shadowed this route, and the page loads Tailwind from a CDN
and fonts from bunny.net, both refused by the app's own CSP.

## Where to go next

- [Configuration](../configuration/environment-variables.md) — the env reference and
  secure defaults.
- [Day-2 operations](operations.md) — backups, key rotation, upgrades, break-glass.
