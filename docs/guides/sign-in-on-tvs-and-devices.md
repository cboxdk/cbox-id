---
title: Sign in on TVs and devices
weight: 47
description: Put a code and a QR code on a TV, a console or a kiosk, let people approve it on their phone, and collect the tokens — the device authorization grant, end to end.
---

# Sign in on TVs and devices

A TV app, a games console, a kiosk or a smart display has no keyboard anybody wants to
type a password on. The **device authorization grant** (RFC 8628) is the sign-in made for
them: the screen shows a short code and a QR code, the person points their phone at it,
approves, and the device signs in on its own.

Your app does three things — ask for a code, show it, poll — and Cbox ID does the rest:
the page on the phone, in your brand and the person's language, with every sign-in method
your environment offers.

```
   TV                                  Phone                         Cbox ID
   │ POST /oauth/device_authorization ─────────────────────────────►   issues a code
   │ ◄── user_code, verification_uri(_complete), interval
   │ shows WDJB-MJHT + id.acme.com/device + QR
   │                                    scans the QR ─────────────►   sign in (any way)
   │                                                                 "Sign in to Acme TV?"
   │                                    Approve ──────────────────►   approved
   │ polls POST /oauth/token every `interval` s ───────────────────►
   │ ◄── tokens                                                       "You can return to your TV"
```

## 1. Register the app

**Apps → New app**, and answer **CLI or device** to *What kind of app is this?* Cbox ID
registers it as a **public** client with the device and refresh grants and no redirect
URI: a TV app cannot keep a secret, and there is nothing to redirect to. You get a
`client_id`.

The scopes you tick are a **ceiling**: a device request naming a scope outside it is
refused with `invalid_scope` rather than quietly reduced, because there is no browser in
front of the TV to notice a smaller grant. Keep `offline_access`, or the person approves
again every hour.

## 2. Ask for a code

```bash
curl -s -X POST https://id.acme.com/oauth/device_authorization \
  -d client_id=cid_… \
  -d scope="openid profile email offline_access"
```

```json
{
  "device_code": "dvc_…",
  "user_code": "WDJB-MJHT",
  "verification_uri": "https://id.acme.com/device",
  "verification_uri_complete": "https://id.acme.com/device?user_code=WDJB-MJHT",
  "expires_in": 600,
  "interval": 5
}
```

`device_code` is the device's secret for polling — never show it. Everything else is for
the screen.

## 3. Show it — the TV pattern

Put three things on screen, large enough to read from the sofa:

1. **The code**, `WDJB-MJHT`. People type it as they like: lower case, without the dash,
   with a space — all of it is accepted.
2. **The short address**, `verification_uri` (`id.acme.com/device`) — for anyone who
   would rather type than scan.
3. **A QR code** of `verification_uri_complete`. Scanning it opens the page with the code
   already filled in, so the person goes straight to *Approve*.

You do not need a QR library on the TV. Cbox ID draws it for you:

```html
<img
  src="https://id.acme.com/oauth/device/qr?user_code=WDJB-MJHT"
  alt="Scan to sign in"
  width="320"
  height="320"
/>
```

`GET /oauth/device/qr?user_code=…` answers an SVG of `verification_uri_complete` for a code
that is still **pending**, and `404` for one that is unknown, expired, approved or
denied. It needs no credential, sets no cookie, holds nothing about anybody, and may be
cached for as long as the code lives (its `Cache-Control` says how long). It is served on
your environment's own host, beside the endpoint that issued the code.

## 4. Poll for the tokens

```bash
curl -s -X POST https://id.acme.com/oauth/token \
  -d grant_type=urn:ietf:params:oauth:grant-type:device_code \
  -d device_code=dvc_… \
  -d client_id=cid_…
```

Poll **no faster than `interval`** seconds:

| Response | What it means | What the TV does |
|---|---|---|
| `authorization_pending` | Not approved yet. | Keep the code on screen; poll again after `interval`. |
| `slow_down` | You polled too fast. | Add 5 seconds to `interval`, and keep it. |
| `access_denied` | They pressed Deny. | Say so, and offer a new code. |
| `expired_token` | Ten minutes passed. | Get a new code and redraw the screen. |
| `200` with tokens | Approved. | Store the refresh token and move on. |

### With id-js

```ts
import { CboxIdClient } from '@cboxdk/id-js';

const cbox = new CboxIdClient({
  issuer: 'https://id.acme.com',
  clientId: 'cid_…',
  scopes: ['openid', 'profile', 'email', 'offline_access'],
});

const auth = await cbox.requestDeviceAuthorization();

showOnScreen({
  code: auth.userCode,
  address: auth.verificationUri,
  qr: `https://id.acme.com/oauth/device/qr?user_code=${encodeURIComponent(auth.userCode)}`,
});

// Honours `interval`, backs off on `slow_down`, stops on a decline or an expired code.
const user = await cbox.pollDeviceToken(auth);
```

### With plain HTTP

```js
const issuer = 'https://id.acme.com';
const clientId = 'cid_…';

const start = await fetch(`${issuer}/oauth/device_authorization`, {
  method: 'POST',
  body: new URLSearchParams({ client_id: clientId, scope: 'openid profile offline_access' }),
}).then((r) => r.json());

showOnScreen({
  code: start.user_code,
  address: start.verification_uri,
  qr: `${issuer}/oauth/device/qr?user_code=${encodeURIComponent(start.user_code)}`,
});

let interval = start.interval;

for (;;) {
  await new Promise((resolve) => setTimeout(resolve, interval * 1000));

  const answer = await fetch(`${issuer}/oauth/token`, {
    method: 'POST',
    body: new URLSearchParams({
      grant_type: 'urn:ietf:params:oauth:grant-type:device_code',
      device_code: start.device_code,
      client_id: clientId,
    }),
  });
  const body = await answer.json();

  if (answer.ok) return body; // access_token, refresh_token, id_token
  if (body.error === 'authorization_pending') continue;
  if (body.error === 'slow_down') { interval += 5; continue; }
  throw new Error(body.error); // access_denied, expired_token
}
```

## What the person sees

The page at `/device` is one of your hosted pages, like sign-in: your environment's
**name and uploaded logo** ([Branding](../getting-started/screens.md)), in the person's
**language** ([Languages](languages.md)), and laid out for a phone first.

1. **Sign in**, if they are not already — with any method your environment offers:
   password, passkey, a magic link, social login or enterprise SSO. Whichever they use,
   they come straight back to the code they scanned.
2. **"Sign in to Acme TV?"** — the app's name, the account it will sign in as, what it
   will be allowed to do, and **the code itself**, so they can check it matches the one on
   their screen. *Deny* comes first.
3. **"You can return to your TV or device."**

Somebody who scanned a code they did not start — the shape of a device-code phishing
attempt — is told to deny it, and the page shows the code so the mismatch is visible.

## Security

- **Radar looks at the approval.** Approving a code signs a *different* device in, so it
  is assessed like a sign-in: under **enforce**, a block refuses it, and a challenge emails
  the person a one-time code and asks for it on the same page before the device is
  connected — a proof every account can give, whether it signs in with a password, a
  passkey or Google. Their sign-in already went through Radar and any second factor your
  sign-in rules require. See [Radar](radar.md).
- **Nothing is approved by following a link.** The QR code fills the code in; approving is
  always a separate tap.
- **The browser cannot swap the request.** The code being approved is kept on the server
  between the screen that showed it and the tap that approves it.
- **Guessing codes is throttled** on the approval page per person, and on the QR endpoint
  per address.

## Related

- [Sign in from a CLI](../getting-started/sign-in-from-a-cli.md) — the same grant from a
  terminal, and where to keep the tokens.
- [Applications](apps-and-api-keys.md) — registering the app.
- [Approvals](agent-approvals.md) — when software acting for somebody needs a yes first
  (CIBA).
