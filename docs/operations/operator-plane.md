---
title: Passkeys for platform operators
weight: 7
description: Make platform administration (/admin and the root environment's console) require a passkey or security key - what the rule does, its settings, how to turn it on without locking anybody out, and the break-glass enrolment link.
---

# Passkeys for platform operators

Platform operators reach every organization on the install. By default an operator opens
`/admin` after signing in with any second factor, including a code from an authenticator
app. A code can be typed into a look-alike page. A passkey or security key cannot: the
browser ties it to the real address.

`cbox-id.operators.phishing_resistant` makes platform administration require one. It is
**off by default**. Turn it on once every operator has a passkey.

## What changes when it is on

It applies to `/admin` and to the platform root environment's own console
(`/admin/environment/…`). Nothing changes for customers or for other environments.

- **A passkey is required.** An operator without one is held on **My account → Security**
  until they add one. An authenticator app alone no longer qualifies. An operator who has
  an authenticator app is still asked for its code first, then adds the passkey.
- **The plane opens on a passkey proof.** Signing in with the passkey counts. Signing in
  another way (password and code, single sign-on, `/login/direct`) works, and the operator
  then confirms with the passkey when they open platform administration.
- **The proof expires on its own clock**, whatever the deployment's session lifetime is:
  60 minutes after the passkey was used, or 15 minutes without a request on the plane.
  The operator confirms with the passkey again. They are not signed out.
- **Dangerous actions need a fresh proof** (5 minutes): every action classified
  destructive or critical, everything that sits behind a step-up in the root console, and
  starting an impersonation. On the root environment this covers single sign-on
  connections, the authentication policy, social providers, apps and their secrets. The
  password step-up is not accepted there.
- **Passkeys cannot be turned off in the root environment** while an operator relies on
  one. The change is refused and names who would be stranded.
- The passkey must verify the person (PIN, fingerprint or face). If
  `cbox-id.webauthn.user_verification` is turned off, the plane stays closed and says why.

Tokens for the API, the CLI and MCP are not affected by this setting.

## Settings

| Config | Env | Default | Meaning |
|---|---|---|---|
| `cbox-id.operators.phishing_resistant` | `CBOX_ID_OPERATORS_PHISHING_RESISTANT` | `false` | Turns the rule on. |
| `cbox-id.operators.session.absolute_minutes` | `CBOX_ID_OPERATORS_SESSION_ABSOLUTE_MINUTES` | `60` | How long a passkey proof opens the plane. |
| `cbox-id.operators.session.idle_minutes` | `CBOX_ID_OPERATORS_SESSION_IDLE_MINUTES` | `15` | How long the plane may sit unused. |
| `cbox-id.operators.reconfirm_seconds` | `CBOX_ID_OPERATORS_RECONFIRM_SECONDS` | `300` | How fresh the proof must be for a dangerous action. |
| `cbox-id.operators.enrolment_link_minutes` | `CBOX_ID_OPERATORS_ENROLMENT_LINK_MINUTES` | `15` | Default lifetime of a break-glass link (at most 60). |

## Turning it on

1. List the active operators at `/admin/operators`.
2. Each operator signs in and adds a passkey or security key under **My account →
   Security**. Two per person is safer: one on the laptop, one hardware key.
3. Check that passkeys are on in the root environment (**Authentication policy → Sign-in
   methods**).
4. Set `CBOX_ID_OPERATORS_PHISHING_RESISTANT=true` and deploy.
5. Each operator opens `/admin`. They are asked for the passkey once, then it opens.

An operator who missed step 2 is not locked out of their account. They are held on the
Security page and add the passkey there. If they cannot sign in at all, use the link
below.

To turn it off again, unset the variable and deploy. Nothing is stored that needs undoing.

## Break-glass: an operator who is locked out

`/login/direct` still shows the root environment's own sign-in page when its identity
provider is down. For an operator who has lost their passkey, somebody with shell access
issues a one-time enrolment link:

```bash
php artisan cbox-id:operator:passkey-link ada@example.com
php artisan cbox-id:operator:passkey-link ada@example.com --minutes=30
```

It prints an address on the root host. Send it to the operator over a channel you trust.
Whoever opens it adds one passkey to that operator's account. It works once and for 15
minutes by default. It signs nobody in and changes no password. The operator then signs
in at `/login/direct` with the new passkey.

The command refuses anybody who is not an active operator. Issuing the link and using it
are both written to the root environment's audit log (`operator.passkey_link.issued`,
`operator.passkey_link.used`). Afterwards the operator should remove the lost passkey
under **My account → Security**.

## With a login host (`CBOX_ID_LOGIN_HOST`)

A passkey belongs to the address it was created on. With a login host, passkeys live
there, and the console on the app host cannot ask for one itself. So:

- The break-glass link is served on the login host, and the command prints that address.
- The console does not show the passkey prompt. An operator whose proof is missing or has
  expired is told to sign out and sign in again with the passkey.
- The console's sign-in through the login host must carry the proof. Turn on
  `cbox-id.oauth.acr.phishing_resistant` (laravel-id, `CBOX_ID_ACR_PHISHING_RESISTANT`) on
  the deployment so the ID token says `urn:cbox-id:phr`, ask for it with
  `acr_values=urn:cbox-id:phr` (and `max_age` for a fresh one), and have the console's
  sign-in callback call `OperatorAssurance::confirm()` with the token's `auth_time`.
  A console session started with the token's `amr` (`passkey` and `mfa`) is also read as
  a proof from the moment it was created.
