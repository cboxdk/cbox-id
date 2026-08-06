# Trusted devices on the account plane — findings

Last updated: 2026-07-30

## The ask

"Det giver da ingen mening at man ikke kan have authenticator på account layer" —
an account member (the buyer/operator persona in the workspace console at
`cboxid.com`) should be able to enrol a phone the same way a tenant user can.

## What is already true

| Piece | State |
|---|---|
| `GET /.well-known/cbox-authenticator` on the apex | **Works** — not plane-gated. Verified live: returns the module's own `{"error":"not_found","message":"This host has no authenticator client."}`, not a plane 404. |
| `POST /api/v1/devices` and the approvals API on the apex | **Reachable** — `['api', ResolveEnvironment, throttle]`, no plane gate. |
| Account member ↔ subject | **Already unified.** `AccountMember.subject_id` points at an ordinary subject in the platform-root environment; that subject is what authenticates. |
| Platform root is an environment | **Yes** — the `is_default` `Environment` row. A `Device` row with `environment_id = <root>` is coherent with the schema. |
| Account-plane sign-in audit | **Fixed** in `614c078` — `account.signed_in` on the account activity chain. |

## The two real blockers

### 1. No issuer surface on the account plane

`config/cbox-id.php` puts the whole IdP protocol surface — discovery, JWKS, every
`/oauth/*`, SAML, SCIM — behind `plane:subject`. Verified live: `/.well-known/openid-configuration`,
`/oauth/authorize` and `/login` all 404 on `cboxid.com`, so the deployment is
multi-tenant and the gate is active.

Enrolment is an ordinary authorization-code sign-in, so it needs `/oauth/authorize`
and `/oauth/token` on the host the QR names.

**`PlaneResolver` decides the plane from the RESOLVED ENVIRONMENT, not the host**
(`app/Platform/PlaneResolver.php:65-71` — `onPlatformRootHost()` compares
`environments->current()->environmentKey()` to the root key). So simply pointing a
second hostname at the platform-root environment does **not** produce an issuer — it
produces a second *account*-plane host. Any fix has to change how the plane is decided.

### 2. Account-plane sign-in emits no domain event

`AccountAuth::establish()` is a pure Laravel session — it never touches
`SessionManager`, so no outbox event is emitted and `SendSecurityAlert` can never fire.
Exceptions: the magic-link and SSO doors *do* go through `SessionManager` (via
`adoptSubject()`) and emit `user.session_started` / `user.login` stamped with the
platform-root environment — but those find zero devices, because no device can be
enrolled in that environment (blocker 1). So the plane is currently inconsistent:
three native doors emit nothing, two inherited ones emit into a void.

## Options considered

### A — Give the platform-root environment its own issuer host (recommended)

`accounts.cboxid.com` (or similar) serves the subject plane for the root environment;
`cboxid.com` stays the account door and stays a non-issuer.

- `PlaneResolver` changes from "which environment" to "which host role": `onAccountPlane()`
  = the root env's *account* host; `onSubjectPlane()` = any *issuer* host, including the
  root env's issuer alias.
- Preserves every documented property: the apex still advertises no issuer (the
  "half an IdP is discoverable" objection stays satisfied), and a subject session on
  the apex still carries no weight because the subject surface is still absent there.
- **Needs no change to `cboxdk/laravel-id`** — the member signs in on the issuer host
  as an ordinary platform-root subject, with the password they already have. This is
  what makes A cheaper than it looks.
- Cost: `PlaneResolver` + host mapping in the app; DNS record, TLS cert and a Cloud
  host binding in infra.

### B — Serve a narrow OAuth carve-out on the apex

Just `/oauth/authorize` + `/oauth/token`, no discovery, restricted to the authenticator
client.

- The documented objection was specifically about *advertising* an issuer whose
  authorize 404s; serving authorize without discovery does not recreate it.
- **But** `/oauth/authorize` authenticates a *subject* session via `platform.auth`, and
  on the apex the user holds an `AccountAuth` (member) session. Teaching authorize to
  accept it is a change inside `vendor/cboxdk/laravel-id`, not in this repo.
- Rejected: package change + it genuinely weakens "the subject surface is absent here".

### C — Don't put devices on the account plane

Account members already have TOTP, passkeys and `WorkspaceSudo` step-up. What devices
would add is push approvals and push alerts — and there are no CIBA flows to approve at
the account level, so the real value is sign-in alerts alone.

- Cheapest, and defensible if the answer is "the account plane is administration, not
  an identity surface".
- Does not satisfy the ask.

## Remaining work if A is chosen

1. `PlaneResolver` — decide plane by host role; keep one implementation (its docblock
   is explicit that two would drift).
2. Host mapping so the root environment resolves on its issuer alias.
3. Infra: DNS, cert, Cloud host binding.
4. Emit a domain event on account-plane sign-in so `SendSecurityAlert` has something to
   listen to (blocker 2) — a new `account.signed_in` outbox type rather than reusing
   `user.session_started`, since no `id_sessions` row exists.
5. Devices panel in the workspace console — note `components/layouts/workspace.blade.php`
   has a **hardcoded** `$groups` nav, not the console-kit registry, so the module cannot
   self-register there; the "Personal" group currently has one page, and adding a second
   makes a tier-2 subnav appear automatically.
6. Decide what the account-level approval prompts actually gate (candidate: destructive
   workspace actions currently behind `WorkspaceSudo`).

## Open question for Sylvester

Is the account plane meant to become a real identity surface for its members (A), or
is it administration-only with personal security living in a tenant (C)? Everything
else follows from that.
