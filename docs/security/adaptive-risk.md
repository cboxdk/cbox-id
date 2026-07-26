---
title: Adaptive risk (risk-based authentication)
weight: 41
description: How the app scores each sign-in and adapts — allow, step-up, or deny — using cboxdk/laravel-risk.
---

# Adaptive risk

Sign-in is **risk-aware**. Every authentication attempt is scored, and under
enforcement the app adapts the flow to the risk: let it through, demand an extra
factor (step-up), or block it. Scoring is an **app-layer** concern — the
`cboxdk/laravel-id` framework deliberately ships no risk engine; the app composes
[`cboxdk/laravel-risk`](https://packagist.org/packages/cboxdk/laravel-risk) for it,
bridged by `App\Platform\RiskGuard`.

## What is scored

`RiskGuard::assess()` runs the configured signals over the request — velocity
(credential-stuffing / bot rate per IP), IP reputation (ipsum blocklists), Tor exit
nodes, user-agent anomalies, and (on signup) disposable-email / MX / honeypot. Every
assessment is logged with its reasons and an HMAC-hashed IP for review and tuning.
The signals produce a score that maps to an outcome: `Allow → Flag → Challenge →
StepUp → Reject` (increasing severity).

## How the app adapts

Behaviour depends on `RISK_MODE`:

- **`monitor` (default)** — score and log only. Nothing is blocked. Ship here and
  **calibrate thresholds against real traffic first**, so you don't lock out
  legitimate users on day one.
- **`enforce`** — the app acts on the outcome:
  - **Reject** → the sign-in is hard-blocked. This gate covers **all** entry points:
    password, magic-link (blocked *before* the single-use token is consumed, so a
    user can retry from a safer network), and passkey.
  - **Challenge / StepUp** → an **additional factor** is required before the session
    is established:
    - if the account has an authenticator (TOTP), it goes through the normal MFA
      challenge (`/mfa`);
    - if it has no second factor, the app **emails a one-time code** (`/login/step-up`)
      — possession of the inbox — rather than letting a risky sign-in through or
      locking the account out. The resulting session records `amr: ['pwd','otp']`, so
      it counts as a two-factor (aal2) login downstream.
  - **Flag / Allow** → the attempt proceeds; Flag is recorded for review.

Because magic-link and passkey are themselves possession / phishing-resistant
factors, elevated-but-not-reject outcomes only trigger a step-up on the **password**
path; those two paths honour the Reject block but need no additional factor.

## Signup, specifically

Signup is scored by the same guard, with two extra signals the form supplies — a
honeypot field a human never fills, and the time between render and submit — and it
acts on the outcome differently from sign-in, because there is no account to step up
*into* yet.

### Challenge → CAPTCHA (Cloudflare Turnstile)

Under enforcement, a **Challenge / StepUp** outcome on signup demands a CAPTCHA before
the account is created. The widget is Cloudflare Turnstile: for almost everyone it is
non-interactive (no images to label), it sets no advertising cookies, and it renders
**only on a submission the scorer already flagged** — an unconditional CAPTCHA would
tax every legitimate signup to stop the small share that isn't one.

- The browser's own success callback is never trusted: the token is verified
  **server-side** against Turnstile's `siteverify` with the secret key.
- A missing, replayed or rejected token is a **field error** on the form (with the
  widget now shown, so the person can satisfy it) — never a 500 and never a silent pass.
- If Cloudflare cannot be reached the submission is refused rather than waved through.
  Only already-elevated submissions reach that path, and they can retry.
- **With no keys configured the feature is inert**: no widget, no third-party script, no
  CSP exception, and signup behaves exactly as it did before. Self-hosters who don't
  want a Cloudflare dependency simply don't set the keys.

The **CSP** is opened for `https://challenges.cloudflare.com` in `script-src` and
`frame-src` **only when Turnstile is configured** — the sole third-party origin this app
ever allows, and only on deployments that asked for it.

### Verification before provisioning

Independently of risk mode, a self-serve signup on the platform root no longer
provisions an environment up front. It creates the **account, its home organization,
its owner member and its first project**; the **environment** — the routable IdP whose
signing key is warmed on creation — is released by `App\Platform\SignupProvisioner`
only when the owner opens the emailed verification link.

This is the control that actually removes the incentive. A CAPTCHA makes bulk signup
harder; deferring the environment makes it **pointless**, because what an unverified
signup walks away with is a row nobody routes to. It is also idempotent: a replayed
link, or a second address verified on the same account, never mints a second
environment, and a suspended account is not un-suspended by clicking a link.

## Enabling and tuning

1. Set `RISK_MODE=enforce` once you've observed traffic in monitor mode.
2. Tune signals, weights, thresholds and allowlists in the risk package config
   (`RISK_MODE`, `risk.thresholds`, `risk.signals`, `risk.allow`). Start permissive
   and prefer friction (step-up) over a hard reject.
3. Keep the reputation feeds fresh: `risk:refresh-ipsum` and `risk:refresh-tor`
   (schedule them).

## Honest scope

- **Step-up requires a deliverable factor.** The emailed code assumes the account's
  email is reachable; email OTP is a *possession-of-inbox* check, not a strong factor
  — TOTP / passkey remain stronger. Encourage authenticator enrolment.
- **SSO logins are gated by the upstream IdP.** A federated (SAML/OIDC) sign-in is
  vouched for by the customer's IdP, which owns that risk decision; the app's risk
  gate covers the local factors (password, magic-link, passkey), not delegated SSO.
- **Monitor first.** The default is deliberately non-blocking. Enforcement with
  untuned thresholds is the main way to lock out real users.
- **The signup CAPTCHA only bites under `enforce`.** In `monitor` mode a challenged
  signup is logged and let through, exactly like every other outcome — the widget never
  appears. Deferred environment provisioning is the half that works regardless of mode.
