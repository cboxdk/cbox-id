---
title: Installation & first run
weight: 2
description: Take an empty deployment to a working one — the install command, the first-run screen, and what each creates.
---

# Installation & first run

A fresh deployment holds nothing: no platform operator, no account, no environment,
no user. This page is how it gets from there to a platform someone can sign in to.

There are two supported paths, and they provision exactly the same thing:

| | [`php artisan cbox-id:install`](#the-install-command) | [The first-run screen](#the-first-run-screen) |
|---|---|---|
| Where | A shell on the server (or your CI/Docker build) | A browser, at `/first-run` |
| Gate | You already have console access | The **setup token**, published to the server |
| Chooses the deployment shape | **Yes** — and writes it to `.env` | No — installs the shape already configured |
| Non-interactive | Yes, fully | No |

Both refuse to run on a platform that is not empty. That is not an oversight: a
second install would mint a second platform root and hand out a credential on a live
deployment, so there is deliberately no flag to force it.

For the two-minute version see [Quickstart](../quickstart.md); for production
hardening see [Deployment](../operations/deployment.md).

## 1. Install the code

```bash
composer install --optimize-autoloader
cp .env.example .env         # if you are not using `composer setup`
php artisan key:generate
```

## The install command

```bash
php artisan cbox-id:install
```

Interactively it asks for the operator's email and name, an optional password (blank
generates one), the deployment shape, and the public issuer URL. Then it:

1. mints `CBOX_ID_CRYPTO_KEY` if the deployment has none, and writes it to `.env`;
2. runs migrations if the schema is not there yet;
3. **refuses** and stops if anything already exists — naming what it found;
4. records the shape (`CBOX_ID_MULTI_TENANT`, `CBOX_ID_ACCOUNT_HOST`) and the issuer
   (`CBOX_ID_ISSUER`, plus the passkey `rp_id`/`origin` derived from it) in `.env`,
   never overwriting a value that is already there;
5. creates the platform-root environment and stamps it `is_default`;
6. creates the first platform operator;
7. in the multi-tenant shape, creates the first account, its project and its own
   environment;
8. mints the signing key, so the JWKS answers on the first request;
9. runs `cbox-id:doctor` and fails if the deployment it just built is unhealthy.

**Back up `CBOX_ID_CRYPTO_KEY`**, separately from the database — losing it makes
every sealed secret unrecoverable.

### Non-interactive (CI, Docker, provisioning scripts)

```bash
php artisan cbox-id:install --no-interaction \
    --email=root@acme.example \
    --name="Root Operator" \
    --password="$OPERATOR_PASSWORD" \
    --environment=Production \
    --issuer=https://id.acme.com
```

| Option | |
|---|---|
| `--email=` | The first platform operator. **Required** non-interactively — the command fails rather than guessing. |
| `--name=` | Their display name. Defaults to `Operator`. |
| `--password=` | Their password (minimum 12 characters). Omit it and a strong one is generated and printed **once**. A password you supply is never echoed. |
| `--multi-tenant` | Install the SaaS shape. Requires `--account-host`. |
| `--account-host=` | Where the account console lives, e.g. `cboxid.com`. |
| `--environment=` | Name of the first environment. Defaults to `Production`. |
| `--account=` | Name of the first account (multi-tenant only). |
| `--issuer=` | Public HTTPS URL of this platform. Defaults to `APP_URL`. |

A non-zero exit means either nothing was installed, or the health check found
problems — read the output before retrying.

### Which shape?

- **Single-tenant** (the default, and the self-hosted shape): one host, one identity
  provider, no account plane. The single environment *is* the platform root.
- **Multi-tenant**: an account plane on its own host that provisions IdPs, with
  tenant environments on their own hosts. The install creates the platform root plus
  the first account and its environment.

The shape decides whether the host bulkheads exist at all, so it is stated rather
than inferred — see
[Configuration](../configuration/environment-variables.md).

## The first-run screen

For a deployment you did not install from a shell — an image someone else started, a
container in a cluster — the same install is available in the browser at
**`/first-run`**.

It exists **only while the platform is empty**. While it does, every other web page
redirects to it, so a fresh box never shows a sign-in form that no credential can
satisfy. The moment the platform is claimed, the route 404s permanently.

It is gated by a **setup token**, because "the platform is empty" is not a gate on a
public identity provider — the first visitor to an internet-exposed box is a scanner,
not you. When an empty deployment first serves that page it mints a token and
publishes it in two places only console or filesystem access can read:

- `storage/app/private/cbox-id-first-run.token` on the server, and
- the application log, at warning level — `docker logs <container>` for a container.

The token is never rendered into the page and never appears in a URL. Paste it into
the form together with the operator's details; completing the form provisions the
platform, spends the token, and signs you in.

The screen does **not** choose the deployment shape: a web request cannot durably
write `.env`, so offering the choice would leave a platform provisioned one way and
configured another. It installs the shape this deployment is already configured for,
and refuses outright if that configuration is incoherent (multi-tenant with no
account host). Use the install command when you need to decide the shape.

## 2. The identity hierarchy

Cbox ID has three layers, top to bottom:

1. **Platform operator** — the identity above everything. Administers environments,
   tenant organizations, and other operators.
2. **Environment** — an isolated plane (e.g. staging vs production, or per-region)
   with its own users, keys and issuer. Created and managed by operators.
3. **Organization (tenant)** — a customer's org with its own members, roles, SSO,
   and audit trail. Org admins and members sign in at `/login`.

The install creates the first two. Enrol a passkey or TOTP factor on the operator
immediately — it is the most sensitive account on the system.

## 3. Provision an environment and organization

Sign in at **`/workspace/login`**, then open the **`/platform`** section — the
deployment pages, shown in the rail to whoever has authority over the deployment.
From there create further environments, then use **Provision admin** to seed an
environment's first organization and owner-admin. That admin signs in at `/login`.

## 4. Verify

```bash
php artisan cbox-id:doctor
```

The install runs this for you, and you can run it any time after a deploy. A green
doctor plus a reachable `/.well-known/openid-configuration` means the deployment is
live.

## Key surfaces this app ships

Beyond the login and admin console (see [Screens](screens.md)), the app serves
several operator- and end-user-facing flows:

- **Device approval (`/device`)** — the OAuth 2.0 Device Authorization Grant
  confirmation page. A user completing the device flow enters/confirms the user code
  here and approves the device before it receives tokens.
- **OAuth consent (`/oauth/authorize`)** — the authorization endpoint's consent
  screen. When a registered client requests access, the signed-in user reviews the
  requested scopes and grants or denies them; the decision is recorded.

For step-up authentication (`/sudo`), the organization switcher's security model,
and self-service signup modes, see [Security](../security/_index.md).
