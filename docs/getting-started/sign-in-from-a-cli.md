---
title: Sign in from a CLI
weight: 21
description: The device authorization grant — how a terminal, a CI job or a TV signs somebody in without a browser of its own.
---

# Sign in from a CLI

A command-line tool has no browser to redirect. Neither does a CI job, a container, a
TV app, or anything running over SSH. The **device authorization grant** (RFC 8628) is
the flow for all of them: your program prints a short code, the person approves it on
whatever device they already have in their hand, and your program collects the tokens.

It is not a lesser flow. Nothing is weakened to make it work — there is no secret in the
binary, no redirect URI to get wrong, and no password typed into a terminal.

```
  Your CLI                         The person                    Cbox ID
     │  "Go to id.acme.com/device
     │   and enter WDJB-MJHT"  ───────────►  opens it on
     │                                        their phone   ────────►  signs in,
     │                                                                 approves
     │  ◄──── polls every 5s ────────────────────────────────────────  tokens
```

## 1. Register the app

**Apps & API keys → New app**, and answer **CLI or device** to *What kind of app is
this?*

That single answer settles the rest. Cbox ID registers it as a **public** client — a
binary on somebody's laptop cannot keep a secret, so it is issued none — with the device
and refresh grants, and no redirect URI, because there is nothing to redirect. You get a
`client_id` and nothing to protect.

> **The scopes you tick are a ceiling, not a request.** A device request naming a scope
> the app is not registered for is **refused**, not quietly reduced: there is no browser
> in front of it to notice a smaller grant, so a silent downscope would surface later as
> a mysteriously missing claim. Leave `offline_access` ticked unless you want the person
> re-approving every hour.

## 2. Start the flow

```bash
curl -s -X POST https://<tenant>.cboxid.com/oauth/device_authorization \
  -d client_id=cid_… \
  -d scope="openid profile email offline_access"
```

```json
{
  "device_code": "…",
  "user_code": "WDJB-MJHT",
  "verification_uri": "https://<tenant>.cboxid.com/device",
  "verification_uri_complete": "https://<tenant>.cboxid.com/device?user_code=WDJB-MJHT",
  "expires_in": 600,
  "interval": 5
}
```

Print `verification_uri` and `user_code`. If the machine has a desktop, also *open*
`verification_uri_complete` — it fills the code in for them — but print the code
regardless, because the machine that runs your CLI is often not the machine the person
is looking at.

## 3. Poll for the token

```bash
curl -s -X POST https://<tenant>.cboxid.com/oauth/token \
  -d grant_type=urn:ietf:params:oauth:grant-type:device_code \
  -d device_code=… \
  -d client_id=cid_…
```

Poll no faster than `interval` seconds. Three answers matter, and they are the whole
state machine:

| Response | What it means | What to do |
|---|---|---|
| `authorization_pending` | They have not finished yet. | Keep polling at `interval`. |
| `slow_down` | You polled too fast. | Add 5 seconds to your interval, permanently. |
| `access_denied` | They said no. | Stop. Say so, and exit non-zero. |
| `expired_token` | The code aged out (10 minutes). | Stop, and offer to start again. |
| 200 with tokens | Done. | Store them (see below) and carry on. |

## Language support

**Go** — first-class, two calls:

```go
client, _ := cboxid.New(cboxid.Config{Issuer: issuer, ClientID: clientID})

auth, _ := client.RequestDeviceAuthorization(ctx, cboxid.DeviceParams{})
fmt.Printf("Open %s and enter %s\n", auth.VerificationURI, auth.UserCode)

user, err := client.PollDeviceToken(ctx, auth) // blocks, honours interval + slow_down
```

**Everything else** — the two HTTP calls above are the whole protocol; there is no
signing, no PKCE and no callback server to run. The JS, Python and PHP SDKs do not wrap
it yet.

## Where to put the tokens

An access token in a shell history or a world-readable dotfile is a credential you have
published. Write them with mode `0600` under the platform's own config directory
(`~/.config/<yourtool>/` on Linux, `~/Library/Application Support/…` on macOS), or use
the OS keychain if you have one. Our own `cbox` CLI keeps one profile per account and
per environment, because one person is usually several customers.

## When the person is already at a browser

Then this is the wrong flow — use the ordinary authorization code flow with a loopback
redirect (`http://127.0.0.1:PORT/callback`). Cbox ID allows the **port to differ** from
the one you registered, exactly so a native app can bind an ephemeral one (RFC 8252
§7.3). Device flow is for when there is no browser *on that machine*.

## Related

- [Integrate your app](integrate-your-app.md) — where the `client_id` comes from.
- [Agent approvals](../guides/agent-approvals.md) — the same idea for software that acts
  on somebody's behalf and needs a yes first (CIBA).
- [Trusted devices](../guides/trusted-devices.md) — approving from a phone that is
  already enrolled.
