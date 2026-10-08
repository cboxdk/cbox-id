---
title: Deployment
weight: 2
description: From a fresh server or cluster to a running, hardened Cbox ID instance — the processes, the probes, the shared state, and how a release rolls out.
---

# Deployment

From a fresh server or cluster to a running, hardened Cbox ID instance. This is an
identity provider — the guidance here is deliberately security-first.

**Where `cboxid.com` runs.** Production is the company's own Kubernetes cluster, deployed
from [`cbox.yaml`](https://github.com/cboxdk/cbox-id/blob/main/cbox.yaml) through the Cbox
platform: two web replicas, a queue-manager pod and a scheduler pod, a managed PostgreSQL
and Valkey, and every secret referenced from a platform Secret. Everything on this page
holds for any platform — Kubernetes, a VM with systemd or Supervisor, or a PaaS — and where
the steps differ, the Kubernetes way is written first. The shape every deployment needs:

| Piece | On the cluster (`cbox.yaml`) | Anywhere else |
|---|---|---|
| Web | the image's nginx + PHP-FPM, `replicas: 2` | the same, behind your TLS proxy |
| Queue manager | `processes.queue`: `php artisan queue:autoscale` | one per host, under a supervisor |
| Scheduler | `processes.scheduler`: `php artisan schedule:work` | one, under a supervisor (or `schedule:run` from cron) |
| Database | `resources.database`: PostgreSQL 17 | PostgreSQL, or MySQL 8.0.13 or later |
| Cache, sessions, queue | `resources.cache`: Valkey, `noeviction` | Redis, `noeviction` |
| Secrets | `secrets:` references to the Secrets `cbox-id-app` and `cbox-id-mail` | your secrets manager, into the environment |
| Probes | liveness `/up`, readiness `/health/ready` with `HEALTH_TOKEN` | the same, on your load balancer |

## Requirements

- **PHP 8.5** with `ext-sodium` and `ext-openssl` (the crypto layer needs both;
  `cbox-id:doctor` fails loudly if either is missing).
- A database — **PostgreSQL or MySQL** in production (not SQLite). The cluster runs
  PostgreSQL 17.
- A cache/queue backend — **Valkey or Redis** (sessions, rate limits, queues), running
  `maxmemory-policy noeviction` because it holds the queue.
- **TLS terminated in front of the app.** Passkeys (WebAuthn) and secure cookies
  require HTTPS; the platform assumes it.

See [Requirements](../requirements.md) for the full, `composer.json`-backed list.

## 1. Install the code

On the cluster there is nothing to install: the platform runs the
`php-fpm-nginx:8.5-bookworm-v1` base image with the application in it. Anywhere else:

```bash
composer install --no-dev --optimize-autoloader
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

**On Kubernetes, nothing it writes to `.env` survives the pod.** Put the keys in the
platform Secret first — `APP_KEY`, and `CBOX_ID_CRYPTO_KEY` from
`php -r "echo base64_encode(random_bytes(32)).PHP_EOL;"`; the installer only mints a crypto
key when none is set — put the issuer and the deployment shape's variables under `env` in
`cbox.yaml`, deploy, and then run the non-interactive install once as a one-off command in
a web pod. The `/first-run` screen works too, with any number of replicas: run
`php artisan cbox-id:setup-token` in any pod and paste what it prints. The token is kept,
hashed, in the database every replica shares, so whichever pod answers the browser accepts
it. It is single use and expires after an hour (`CBOX_ID_SETUP_TOKEN_TTL`); running the
command again mints a fresh one and retires the last.

## 3. Optimize for production

```bash
php artisan config:cache
php artisan route:cache
php artisan event:cache
```

Re-run these on every deploy after the code and `.env` are in place. On the cluster the
base image's entrypoint does this at container start, against the pod's own environment.

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

Three processes, not one. The web container alone is **not** a working deployment:

```bash
php artisan queue:autoscale        # the queue manager, one per host, under a supervisor
php artisan schedule:work          # a long-running process — or `schedule:run` from cron, every minute
```

On the cluster these are the `queue` and `scheduler` entries under `processes:` in
`cbox.yaml`. The platform runs each as its own Deployment of one pod, from the same image,
environment and secrets as the web pods, and restarts it if it exits.

The queue manager starts and sizes the `queue:work` processes itself; do not run
`queue:work` beside it. Without it no webhook, back-channel logout or queued mail is ever
sent, and `/health/status` reports it. This repository's own manifests already declare
it — `cbox.yaml` and `docker-compose.yml`; for any other host, the systemd unit, the
deploy step and the sizing are in [Queue workers](queue-workers.md).

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

`docker-compose.yml` ships `app`, `queue` and `scheduler` services, and `cbox.yaml` declares
the `queue` and `scheduler` processes beside the web container. Mirror all three in any
other manifest.

### `cbox.yaml` is the production shape

The manifest in this repository is the production deployment, held to it by
`tests/Feature/DeploymentManifestTest.php`, which boots its environment and runs the
doctor's production checks against it:

- `APP_ENV=production`, `APP_DEBUG=false`, `LOG_CHANNEL=stderr`;
- two web replicas (`replicas: 2`, told to the app as `CBOX_ID_REPLICAS`), so cache,
  sessions and the queue are all in the bound Valkey — whose host and port arrive in
  `REDIS_HOST`/`REDIS_PORT` from the binding, never as a name written in the file — and the
  queue manager runs as a cluster (`QUEUE_AUTOSCALE_CLUSTER_ENABLED=true`);
- the Valkey that holds the queue runs **`maxmemory-policy noeviction`**: a queued job is
  data, and an evicting policy silently deletes webhooks and mail when memory runs out;
- `MAIL_MAILER=smtp`, with the host, port, user, password and from-address all referenced
  from a platform Secret — the provider is the operator's choice;
- every secret (`APP_KEY`, `CBOX_ID_CRYPTO_KEY`, `HEALTH_TOKEN`, `MAIL_*`) under `secrets:`
  as a reference to a platform Secret, never a value; the database password is the
  resource binding's, which resolves to the platform's own Secret. The platform's Valkey
  runs without a password inside the project network, so there is none to bind; a Redis
  that has one takes `REDIS_PASSWORD` under `secrets:` like the rest.

Create the referenced Secrets (`cbox-id-app`, `cbox-id-mail`) on the platform before the
first deploy. A missing one fails the pod at start (`CreateContainerConfigError`) rather
than booting it without the value.

| Secret | Keys |
|---|---|
| `cbox-id-app` | `APP_KEY`, `CBOX_ID_CRYPTO_KEY` (raw base64 of 32 bytes, no `base64:` prefix), `HEALTH_TOKEN` |
| `cbox-id-mail` | `MAIL_HOST`, `MAIL_PORT`, `MAIL_USERNAME`, `MAIL_PASSWORD`, `MAIL_FROM_ADDRESS` |

**What the platform does not read yet.** The Cbox platform's manifest reader refuses keys
it does not know, and two of this file's are among them: `secrets:` (an environment
variable taken from a Secret the operator created) and `health:` (the readiness and
liveness probes). Both describe what this deployment needs, so they stay; until the
platform reads them, whatever applies this deployment must set the same `secretKeyRef`
entries and probes on the pod spec. The test pins them as the only two such keys.

## Rolling out a release on Kubernetes

The platform's deploy compiles `cbox.yaml`, removes objects the manifest no longer asks
for (never a database), and applies the rest. The web Deployment then rolls: with two
replicas, a new pod starts and has to pass readiness (`/health/ready`: database, cache,
queue, storage) before an old one is stopped, so the sign-in page stays up. That is the
readiness probe's job, which is why it matters that the pod spec carries one: without it a
pod counts as ready the moment its container starts. The queue and
scheduler pods are replaced the same way; the outgoing queue manager gets SIGTERM, lets its
workers finish their current job and exits, and the new one starts on the new code.

**A deploy does not run migrations.** The manifest has no release or pre-deploy hook, so
the order is the operator's:

1. **Back up the database** (the platform's backup of the Postgres cluster), and confirm
   `CBOX_ID_CRYPTO_KEY` and `APP_KEY` are in your offline key backup.
2. **Create or update the platform Secrets** if the release needs a new key in them —
   before the deploy, since a missing key fails the new pods.
3. **Migrate, once, with the new release:** `php artisan migrate --force`, run as a one-off
   command (a Kubernetes Job) from the new release's web container, so it has the same
   image, environment, secrets and database binding. Not in a running old pod: that is the
   old code, with the old migrations.
4. **Deploy.** The web pods roll behind readiness; the queue and scheduler pods follow.
5. **Run `php artisan cbox-id:doctor`** in a new web pod and treat any ✗ as a reason to
   roll back. `/health/status` should be green within a couple of minutes, once the new
   scheduler and queue manager have reported in.

Migrations run while the old pods still serve, so they have to be safe for the old code:
add columns and tables, do not drop or rename one a running release reads. A release that
cannot keep to that says so in
[`UPGRADING.md`](https://github.com/cboxdk/cbox-id/blob/main/UPGRADING.md).

The base image can also migrate at container start (`LARAVEL_MIGRATE_ENABLED=true`, with
`--isolated` so only one pod runs it at a time). It is **off** here on purpose: every pod —
web, queue and scheduler — would run it, a failed migration would crash-loop the new pods,
and it takes away the backup-first step a release like 2.0.0 needs.

**Anywhere else** the order is the same: back up, put the code in place, migrate, rebuild
the caches, restart the queue manager (`php artisan queue:restart`) and the scheduler, run
the doctor. See [Day-2 operations](operations.md#upgrades).

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
process environment. They are already set in this repository's `cbox.yaml` (the
production deployment), `Dockerfile` and `docker-compose.yml`; any other manifest needs
them by hand:

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
rotation. Without `HEALTH_TOKEN` readiness answers 403 to everything, the platform's own
probe included, and `cbox-id:doctor` fails it in production. `cbox.yaml` declares both
probes under `health:` and references the token from the `cbox-id-app` Secret. Only the
web pods are probed: the queue and scheduler pods serve nothing, and their health is the
`queue_workers` and `scheduler` checks on `/health/status`.

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
