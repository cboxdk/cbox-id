---
title: Security
weight: 40
description: Operator-facing security surfaces of the Cbox ID app — step-up, org switching, signup lockdown — plus the system-level compliance view.
---

# Security

This section covers the security surfaces the Cbox ID **app** adds on top of the
identity engine, and the system-level compliance view.

- [Adaptive risk](adaptive-risk.md) — risk-based authentication: every sign-in is
  scored and, under enforcement, adapts (allow / step-up / deny).
- [Compliance](compliance.md) — the system-level control mapping (framework controls
  + what this app adds + what remains yours).

The framework-level security posture — tenant isolation, the crypto kernel, the
tamper-evident audit log, and the STRIDE threat model — lives in the
`cboxdk/laravel-id` package docs:
[Security](https://github.com/cboxdk/laravel-id/blob/main/docs/security/_index.md)
and
[Threat model](https://github.com/cboxdk/laravel-id/blob/main/docs/security/threat-model.md).
See also this repository's [`SECURITY.md`](https://github.com/cboxdk/cbox-id/blob/main/SECURITY.md)
for the vulnerability-reporting policy.

## Operator security surfaces

These are behaviours the app ships that an operator should understand.

### Step-up authentication (`/sudo`)

Sensitive actions (managing operators, rotating credentials, changing security
settings) require **re-authentication into a short-lived elevated "sudo" session**
even when the user is already signed in. The user is sent to `/sudo` to confirm a
credential; the elevation is time-boxed and does not persist for the whole session.
This limits the blast radius of a hijacked, already-authenticated session.

### Organization switcher

A user who belongs to several organizations switches the active tenant from the
sidebar. The switch is **server-verified against membership on every request** — the
active org is resolved from the authenticated user's memberships, not from a
client-supplied value, so a user can only ever act within an org they actually
belong to. The role in effect updates with the switch, and switching is audited.

### Self-service signup modes

`CBOX_ID_SIGNUP_MODE` gates the public `/signup` surface (see
[Configuration](../configuration/environment-variables.md#self-service-signup)):

- **`open`** — anyone may create an account + organization (the default).
- **`invite_only`** — public signup is closed; new accounts arrive only through
  admin invitations, which keep working.
- **`closed`** — no self-service signup at all.

Admin- and operator-initiated provisioning (invitations, the operator console) is
**never** gated by this — it is not self-service. Set this to `invite_only` or
`closed` for a private or internal deployment so the internet-facing signup form
cannot be used to create tenants.

### Signup abuse controls

An `open` signup is an internet-facing "create infrastructure" button, so it carries
three layers beyond the mode gate — described in full under
[Adaptive risk](adaptive-risk.md#signup-specifically):

1. **A per-IP rate limit and a honeypot + submit-timing pair**, both fed to the risk
   scorer.
2. **A risk-triggered CAPTCHA** (Cloudflare Turnstile) on a *challenged* signup only —
   never on every signup, and entirely inert unless
   [`CBOX_ID_TURNSTILE_*`](../configuration/environment-variables.md#bot-protection-captcha)
   is configured.
3. **Deferred environment provisioning.** A self-serve signup creates the account, its
   owner and its first project immediately, but the **environment** — the routable IdP
   with its own signing key — is stood up only when the owner opens the verification
   link in their inbox. An unverified signup therefore costs nothing worth farming.

Because (3) puts a real owner's whole account behind one email, the workspace launchpad
carries a **resend** control while the environment is held back. It re-sends only to the
signed-in member's own address (the action accepts a member, never an address), retires
every link issued before it so exactly one is ever live, answers identically whether or
not the address is already confirmed — so it cannot be used to test verification state —
and is throttled to **3 sends per 10 minutes per member**, because outbound mail is the
resource worth abusing here.

## End-user consent surfaces

- **OAuth consent (`/oauth/authorize`)** — registered clients requesting access are
  presented to the signed-in user, who reviews the requested scopes and grants or
  denies them.
- **Device approval (`/device`)** — the Device Authorization Grant confirmation
  page, where a user approves a device (by user code) before it receives tokens.

See [Installation & first run](../getting-started/installation.md#key-surfaces-this-app-ships)
for more on these flows.
