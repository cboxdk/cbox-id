---
title: Deployment
weight: 2
description: From a fresh server to a running, hardened Cbox ID instance.
---

# Deployment

From a fresh server to a running, hardened Cbox ID instance. This is an identity
provider — the guidance here is deliberately security-first.

## Requirements

- **PHP 8.4+** with `ext-sodium` and `ext-openssl` (the crypto layer needs both;
  `cbox-id:doctor` fails loudly if either is missing).
- A database — **PostgreSQL or MySQL** in production (not SQLite).
- A cache/queue backend — **Redis** recommended (sessions, rate limits, queues).
- **TLS terminated in front of the app.** Passkeys (WebAuthn) and secure cookies
  require HTTPS; the platform assumes it.

See [Requirements](../requirements.md) for the full, `composer.json`-backed list.

## 1. Install the code

```bash
composer install --no-dev --optimize-autoloader
```

## 2. Bootstrap

The guided installer generates the crypto master key, asks the few questions that
matter (issuer URL, passkey domain/origin), writes them to `.env`, runs migrations,
and mints the first signing key:

```bash
php artisan cbox-id:install
```

Prefer a non-interactive deploy? Set the environment variables yourself (see
[Configuration](../configuration/environment-variables.md)) and run
`php artisan migrate --force` instead — `cbox-id:install` is the convenience path,
not a requirement.

## 3. Optimize for production

```bash
php artisan config:cache
php artisan route:cache
php artisan event:cache
```

Re-run these on every deploy after the code and `.env` are in place.

## 4. Create the first platform operator

A **platform operator** is the identity above every environment — it administers
environments, tenant organizations, and other operators. On a fresh install visit
**`/operator/login`**: with no operator yet it shows a one-time "create the first
operator" form (serialized behind a lock so only one can win the bootstrap), then
that path closes permanently. Immediately enroll a strong credential — this is the
most sensitive account on the system.

From the operator console, create your environment(s) and use **Provision admin**
on each to seed its first organization and owner-admin. Those org admins then sign
in at `/login`. (Because whoever reaches `/operator/login` first on a fresh,
internet-exposed deploy claims root, complete this step before exposing the host,
or bootstrap the operator on a private network first.)

## 5. Run the workers

Three processes, not one. The web container alone is **not** a working deployment:

```bash
php artisan queue:work --tries=3   # under a supervisor (systemd/supervisord)
php artisan schedule:work          # a long-running process — or `schedule:run` from cron, every minute
```

The scheduler is not optional and its absence does not raise an error. Without it the
domain-event outbox is never relayed, and because every subscriber hangs off that
outbox, all of the following silently do nothing:

| Without the scheduler | Consequence |
|---|---|
| `cbox-id:events:relay` | no webhook is ever delivered; no usage is metered (plan gates read zero); outbound SCIM never provisions; role changes never revoke tokens |
| `cbox-id:webhooks:retry` | a transient endpoint outage never recovers |
| `cbox-id:provisioning:drain` | the provisioning outbox never drains |
| `cbox-id:audit-streams:pump` | SIEM streams stop mid-flight |
| `cbox-id:keys:rotate` | signing keys never rotate or retire |

The app reports healthy throughout. Verify with:

```bash
php artisan schedule:list          # cbox-id:events:relay must appear, every minute
```

`docker-compose.yml` ships `app`, `queue` and `scheduler` services for exactly this
reason — mirror all three in any k8s manifest.

## 6. Verify

```bash
php artisan cbox-id:doctor
```

In production this also checks the **hardening** posture: `APP_DEBUG` off, secure +
encrypted session cookies. Treat any ✗ as release-blocking. A green doctor plus a
reachable `/.well-known/openid-configuration` means you're live.

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

In a **multi-tenant** deployment the platform-root (apex) host is the *account door* —
sign up, manage the account and its environments. It is not an issuer. The whole IdP
protocol surface is confined to the **subject plane**: an environment's own host, i.e. a
custom domain or `{slug}.{base_domain}`.

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
  and the account plane genuinely uses them: an account's own organization lives in the
  platform-root environment, so home-realm discovery on `/workspace/login` or `/signup`
  sends the member to a connection URL **on the host they are already standing on**.
  Gating them locked an account org with enforced SSO out of its own workspace. The
  boundary here is the connection's environment scope, which holds on either plane, not
  the host.
- The account plane itself: `/signup`, `/workspace/*` and the account-management API
  (`/api/v1/account/*`, `/api/v1/openapi.yaml`). The environment-scoped management API is
  a different thing and is served on an environment's own host.

Note that despite what the surrounding config comments say, "SAML" is **not** gated as a
whole — only the IdP-role `/sso/saml/idp/*` endpoints are.

### Why

The apex used to serve half an IdP: discovery returned 200 advertising
`issuer: https://<apex>` alongside `authorization_endpoint: https://<apex>/oauth/authorize`
— a URL that 404s, because the consent screen is subject-plane only. A conformant client
discovers that document and dead-ends. Half an IdP is worse than none, because it is
discoverable.

### It is inert in a single-tenant install

The gate is `App\Http\Middleware\EnforcePlane` (aliased `plane` in `bootstrap/app.php`),
applied to the framework's whole protocol route group through
`cbox-id.api.middleware => ['plane:subject']` in `config/cbox-id.php`, and to this app's
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
   `plane:account` 404s on the host that actually is the account root.
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

**Set these four on every deployment** — the k8s Deployment/ConfigMap, the Helm
values, whatever renders the pod spec. They are already set in this repo's
`Dockerfile` and `docker-compose.yml`; the k8s manifests live in a separate infra
repository, so they must be added there by hand:

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

## Health probe

`GET /up` is a JSON liveness probe served by the framework package:

```json
{"status":"ok"}
```

It is registered **outside** environment resolution and outside
[the IdP-surface gate](#the-idp-surface-gate-the-apex-host-404s-the-protocol-surface),
so it answers on any host — including a kubelet probing the pod IP directly — and
without touching the database. Point k8s `livenessProbe`/`readinessProbe` `httpGet` at
`/up`; any 2xx passes, and the probe does not parse the body.

Laravel's built-in HTML health page is deliberately **not** enabled (no `health:` entry
in `bootstrap/app.php`): it shadowed this route, and the page loads Tailwind from a CDN
and fonts from bunny.net, both refused by the app's own CSP.

## Where to go next

- [Configuration](../configuration/environment-variables.md) — the env reference and
  secure defaults.
- [Day-2 operations](operations.md) — backups, key rotation, upgrades, break-glass.
