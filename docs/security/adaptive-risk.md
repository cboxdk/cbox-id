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
