---
title: Authentication policy and sign-in methods
weight: 34
description: Password rules, two-factor and SSO requirements, which sign-in methods your environment offers, how long sessions last — and which of those an organization can change, and which the deployment decides.
---

# Authentication policy and sign-in methods

**Console pages (environment console):** Authentication › Sign-in methods, and Authentication ›
Authentication policy. An organization's own rules are on its **Authentication policy** tab.

On a **single-tenant install** there is no environment console, so the environment's
settings are changed from the organization console — but only by **platform operators** and
by **owners of the install's own organization**. A single-tenant install can host customer
organizations, and their owners and admins never change passkeys, sessions or social
providers for everybody: they see those rows read-only. Name the install's own organization
once, as whoever runs the install:

```bash
php artisan cbox-id:installation-organization acme      # by slug or id
php artisan cbox-id:installation-organization           # show it
php artisan cbox-id:installation-organization --clear   # only platform operators again
```

Until one is named, only platform operators can change them. Its owners then get
**Sign-in › Authentication policy** with the **Sign-in methods and sessions** and
**Text-message codes** panels under *For the whole environment*, and **Sign-in › Social
login** with the environment's providers. These are the same actions with the same limits.
On a multi-tenant deployment an organization console belongs to one customer, and none of
this is shown there.

**Sign-in methods** is the overview: every way in, whether it is on, and which page changes
it. **Authentication policy** is where you change them.

## Three levels, and who decides what

| Setting | Decided by | An organization can |
|---|---|---|
| Password length, breach check, reuse, rotation, lockout | The environment | Make it stricter |
| Two-factor requirement, SSO requirement | The environment | Make it stricter |
| Passkeys on or off | The environment, under the deployment | Nothing |
| Magic link on or off | The environment, under the deployment | Nothing |
| Bot challenge on or off | The environment, if the deployment has Turnstile keys | Nothing |
| Session idle timeout and maximum length | The environment, up to the deployment's limits | Nothing |
| Social login providers | The environment | Use its own credentials instead, or turn one off for its page — see [Social login](social-sign-in.md) |
| Text-message codes, self-service sign-up | The environment (sign-up on a single-tenant install: the deployment's `CBOX_ID_SIGNUP_MODE`) | Nothing |

Passkeys, magic links, the bot challenge and session lengths apply to the whole environment.
They are decided before anybody has said which organization they belong to — on the sign-in
page, before an address is typed — so they cannot differ per organization. The management
API refuses them on an organization's override (`environment_only`).

## Sign-in methods and sessions

On **Authentication policy**, the **Sign-in methods and sessions** panel has:

- **Passkeys.** Face ID, Touch ID, Windows Hello or a security key. Turning passkeys off
  removes the button from the sign-in page and the **Add passkey** button from
  **My account**, and refuses passkey sign-in and enrolment. Passkeys people already added
  are kept, and work again when you turn this back on. People can still see and remove them.
- **Magic link.** A one-time sign-in link by email. Turning it off removes the button, and
  links already sent stop working too. The management API's `users.create` refuses
  `send_sign_in_link` while it is off.
- **Bot challenge.** Whether a sign-up that [Radar](radar.md) flags is asked to prove it is a
  person, with Cloudflare Turnstile. Off, a flagged sign-up confirms its email address
  instead. Only available when the deployment has Turnstile keys.
- **End a session after this long without activity**, in minutes. Empty uses the
  deployment's idle timeout.
- **End a session after this long, however active**, in minutes. Empty uses the
  deployment's maximum. Shortening it also ends longer sessions that are already running.

### The deployment is the ceiling

Whoever runs the deployment can switch a method off for every environment, and limits how
long a session may last. Their setting wins:

| Variable | What it limits |
|---|---|
| `CBOX_ID_PASSKEYS_ENABLED=false` | Passkeys are off in every environment |
| `CBOX_ID_MAGIC_LINK_ENABLED=false` | Magic links are off in every environment |
| `CBOX_ID_SESSION_TTL_MINUTES` | The longest a session may last (default 480) |
| `CBOX_ID_SESSION_IDLE_MINUTES` | The longest idle timeout (default 30; `0` sets none, and the session length bounds it) |
| `CBOX_ID_TURNSTILE_SITE_KEY`, `CBOX_ID_TURNSTILE_SECRET_KEY` | Without both, there is no bot challenge to turn on |

A method the deployment switched off is drawn switched off, cannot be turned on, and says
which variable decides it. A session length longer than the deployment allows is refused
with the limit named (`past_deployment_limit`). **Sign-in methods** shows the deployment's
limits under each row.

## Through the management API

The same settings are fields of `PATCH /v1/sign-in/policy` (`signin.policy.update`), on the
environment baseline only:

```bash
curl -X PATCH https://your-environment.example/api/v1/sign-in/policy \
  -H "Authorization: Bearer $CBOX_KEY" -H 'Content-Type: application/json' \
  -d '{"passkeys": true, "magic_link": false, "session_idle_minutes": 15, "session_absolute_minutes": 240}'
```

`GET /v1/sign-in/policy` returns what is stored, what is `in_force` once the deployment's
ceiling is applied, and the `deployment`'s own limits. The embedded sign-in's
`/frontend/v1/config` lists `methods.passkeys` and `methods.magic_link`, so an embedded
sign-in box draws the same buttons the hosted page does.

## Password, two-factor and SSO rules

The rest of the page is the password rules, lockout, the two-factor requirement and the
SSO requirement. The environment sets the baseline and every organization inherits it. An
organization's **Authentication policy** tab can make any of them stricter, never looser;
**Per organization** at the bottom of the environment's page shows what each organization
ends up with.

Requiring SSO refuses every other way in, including passkeys and magic links, and signs
out the sessions that used one. The page asks before it does that.

## Related

- [Social login](social-sign-in.md) — providers for the whole environment, and an organization's own.
- [SMS as a second factor](sms-mfa.md)
- [Radar](radar.md)
- [Finding your way](../getting-started/finding-your-way.md)
