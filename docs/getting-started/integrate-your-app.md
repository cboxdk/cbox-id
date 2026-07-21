---
title: Integrate your app
weight: 20
description: Where a client_id comes from — register an application, get its credentials, and point an SDK at them.
---

# Integrate your app

Every SDK starts the same way:

```js
createCboxId({ issuer, clientId, redirectUri })
```

…and nothing in the SDK READMEs said where those three values come from. This page is
that missing step. It takes about two minutes.

## 1. Sign in to the environment console

Open your environment's host — `https://<your-environment>/admin` — and sign in as an
account member with admin access. If you have not created an environment yet, see
[Quickstart](../quickstart.md) first.

The badge beside the environment name tells you which realm you are in. **Register test
integrations in a sandbox environment**, not production: an environment is a hard
isolation boundary with its own users, keys and issuer, so a client registered in one
does not exist in the other.

## 2. Register the application

**Applications → New application.**

| Field | What to put |
|---|---|
| **Name** | What your users will see on the consent screen. |
| **Type** | `Public` for a browser or mobile app (no secret can be kept). `Confidential` for a server-side app. |
| **Redirect URIs** | Every URI the browser may be returned to, exactly. |
| **Grant types** | The flows this app uses. A client may only use the grants it registers — asking for another returns `unauthorized_client`. |

Two things that trip people up:

- **Redirect URIs are matched exactly.** `https://app.example.com/callback` and
  `https://app.example.com/callback/` are different URIs. The one exception is a
  loopback address (`http://127.0.0.1:PORT/…`), where the **port may differ** from the
  one you registered — native apps bind an ephemeral port on each run (RFC 8252 §7.3).
- **PKCE is required, and only `S256` is accepted.** Every SDK on this platform does it
  for you; if you are hand-rolling, `plain` will be refused.

## 3. Copy the credentials

On the application's page:

- **Client ID** — safe to ship in a browser bundle.
- **Client secret** — shown **once**, at creation. It is stored hashed, so it cannot be
  shown again; if you lose it, rotate it. Confidential clients only.

## 4. Find your issuer

Your issuer is your environment's own base URL. Confirm it — and everything else an SDK
needs — from the discovery document:

```bash
curl https://<your-environment>/.well-known/openid-configuration
```

The `issuer` value in that response is exactly what you pass to the SDK. Use it verbatim:
a conformant client compares it against the `iss` it receives and refuses a mismatch.

## 5. Point an SDK at it

```bash
npm install @cboxdk/id-js      # browser / Next.js
pip install cbox-id-client     # Python
composer require cboxdk/laravel-id-client
```

```js
import { createCboxId } from '@cboxdk/id-js';

export const cbox = createCboxId({
  issuer: 'https://acme.cboxid.com',
  clientId: 'cid_...',
  redirectUri: 'https://app.example.com/callback',
});
```

## When it does not work

The token endpoint returns an RFC-shaped `error` plus an `error_description` that says
what actually went wrong — read the description, not just the code.

| What you see | Usually means |
|---|---|
| `invalid_client` | Wrong client ID or secret — or a confidential client sent no credential at all. |
| `unauthorized_client` | The client is not registered for the grant it asked for (step 2). |
| `invalid_grant` | The code expired, was already used, or the `redirect_uri` on the exchange does not match the one on the authorize request. |
| `invalid_request` with a PKCE mention | No `code_challenge`, or `plain` instead of `S256`. |
| A discovery `iss` mismatch | You passed the apex URL instead of the environment's own issuer (step 4). |
