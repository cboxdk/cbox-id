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

Open your environment's host — `https://<tenant>.cboxid.com/admin` — and sign in as an
account member with admin access. If you have not created an environment yet, see
[Quickstart](../quickstart.md) first.

Hosted environments live under `cboxid.com`, one subdomain per tenant. If you run Cbox
ID yourself, substitute your own host everywhere this page writes
`<tenant>.cboxid.com`; nothing else on the page changes.

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
curl https://<tenant>.cboxid.com/.well-known/openid-configuration
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
import { createCboxId } from '@cboxdk/id-js/nextjs';

export const cbox = createCboxId({
  issuer: 'https://acme.cboxid.com',
  clientId: 'cid_...',
  redirectUri: 'https://app.example.com/callback',
});
```

## 6. Talking to Cbox ID from the browser

Everything above gives your **server** a client secret. A page cannot hold one, which is
why an embedded sign-in form has historically needed your backend to sit in the middle
and proxy the details it wanted to render.

A **publishable key** removes that middle step. It is public on purpose — it goes in your
JS bundle, it is visible in devtools, and it is safe there because it only works from the
origins you register.

Create one under **Developers → Frontend keys**. Add every origin your app is served
from, one per line:

```
https://acme.com
https://www.acme.com
http://localhost:3000
```

Exact matches only. `https://acme.com` does **not** cover `https://www.acme.com` — that
is deliberate, because every looser comparison anyone has written for this has been
somebody's vulnerability. Plain `http` is refused except on localhost, where a browser
treats it as trustworthy anyway.

Then, in your frontend:

```ts
import { CboxIdFrontend } from '@cboxdk/id-js'

const frontend = new CboxIdFrontend({
  issuer: 'https://acme.cboxid.com',
  publishableKey: 'pk_live_…',
})

const config = await frontend.config()
// → endpoints, social buttons, and this environment's theme
```

That document is everything needed to *draw* a sign-in box and nothing that identifies
anybody: no emails, no counts, no ids. It is the same information a person can already
read by viewing source on the hosted sign-in page — what changes is who can render it.

**The key grants nothing on its own.** To find out who is signed in, the access token
your app already holds is the authority:

```ts
const { user } = await frontend.session(accessToken)  // { id, email, name } or null
```

Signed out answers `{ user: null }` with a 200 rather than an error, so a component that
renders on every page does not have to treat a rejection as a state.

**If it does not work,** the answer is almost always the origin list. A refused request
deliberately carries no CORS headers — the browser shows a CORS failure rather than a
readable error, because a page has no business reading the body of a rejection it was not
authorized to make. Check the exact origin in your browser's network tab against the list
on the key.

## 7. Drawing your own sign-in form

`frontend.config()` tells a page what to draw. `frontend.signIn()` lets it do the signing
in, without sending the person to the hosted page at all:

```ts
const result = await frontend.signIn(email, password)

if (result.status === 'ok') {
  window.location.href = `${config.endpoints.authorization}?${new URLSearchParams({
    client_id, redirect_uri, response_type: 'code',
    code_challenge, code_challenge_method: 'S256',
    login_ticket: result.loginTicket,
  })}`
}
```

**You get a ticket, never a token.** That is the whole design. Handing tokens to a page
that proved a password is the implicit grant, which OAuth 2.1 removes — tokens in a URL, in
history, in `Referer`, with no client authentication and no PKCE binding. The ticket is
single-use, lasts sixty seconds, and is spent on the ordinary authorize flow with your own
PKCE challenge. Nothing about how tokens are issued changes; only how the person arrived.

The credential check itself is the same code the hosted form runs: the same lockout
counter, the same breach check, the same MFA branch, the same audit entries. There is no
second implementation to fall behind.

Handle three other outcomes:

| status | what it means |
|---|---|
| `mfa_required` / `otp_required` | right password, second factor still needed |
| `sso_required` | this organization mandates single sign-on — send them to their IdP |
| `invalid` | wrong password, unknown address, or locked account |

**Present `invalid` the same way every time.** The server refuses to tell those three apart
because that is the enumeration oracle every identity product eventually leaks; a UI that
tells them apart rebuilds it.

Guessing is rate limited per email address, not just per key — an attacker spreading
attempts across pages holding the same key would otherwise sit under a per-key limit.

## When it does not work

The token endpoint returns an RFC-shaped `error`, and for most failures an
`error_description` that says what actually went wrong — read the description, not just
the code.

Two codes are deliberately bare. `invalid_client` and `unauthorized_client` carry no
description, because a description would tell an unauthenticated caller which half of a
credential was wrong and turn the endpoint into a client-enumeration oracle. For those
two, work from the table below rather than waiting for the server to explain itself.

| What you see | Usually means |
|---|---|
| `invalid_client` | Wrong client ID or secret — or a confidential client sent no credential at all. |
| `unauthorized_client` | The client is not registered for the grant it asked for (step 2). |
| `invalid_grant` | The code expired, was already used, or the `redirect_uri` on the exchange does not match the one on the authorize request. |
| `invalid_request` with a PKCE mention | No `code_challenge`, or `plain` instead of `S256`. |
| A discovery `iss` mismatch | You passed the apex URL instead of the environment's own issuer (step 4). |
| **404 from discovery, JWKS or the token endpoint on the apex host** | Not a misconfigured client — on a multi-tenant deployment the apex host **refuses** to act as an identity provider, and 404s the whole protocol surface on purpose. Only an environment's own host serves it. Use the issuer from step 4, and see [the IdP-surface gate](../operations/deployment.md#the-idp-surface-gate-the-apex-host-404s-the-protocol-surface). |
