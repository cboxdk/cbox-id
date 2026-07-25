# Changelog

All notable changes to Cbox ID (the deployable identity platform app) are documented here.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).
Confirmed security issues and their fixes are cross-referenced under **Security** below.

## [0.22.0] - 2026-07-25

Closes the remaining review findings. Adopts `cboxdk/laravel-id` v0.56.0.

**Upgrading:** behavioural on the environment admin console. It now applies the SSO
mandate, administrative password expiry and account lockout, which it previously did
not, and refuses a temporary password outright. An environment that mandates SSO will
stop admitting local admin passwords there — which is what mandating SSO meant.

### Fixed

- **The environment admin console's three doors disagreed.** The local password form
  checked neither the SSO mandate nor administrative password expiry, so an environment
  mandating SSO could be entered with a local password and an expired hand-off credential
  kept working. The signed handoff re-resolved the membership but never asked whether the
  ACCOUNT behind it was still active, so a token minted before a suspension still opened
  the console. Both now ask `MemberCredentialGate` — the same object the account door
  asks — which also adds the per-subject lockout none of them had.
- **Crafted enum props answered 500 rather than refusing.** Public Livewire props on the
  sign-in rules page, the admin set-password panel and the inline-hooks form reached
  `Enum::from()` unvalidated.
- **The invite form named other accounts.** Account-member emails are globally unique, so
  "that email already belongs to a member" let an admin of one account probe whether an
  address belonged to another. Both cases answer identically now.

## [0.21.0] - 2026-07-25

The sign-in rules page stops being a page of promises. Adopts `cboxdk/laravel-id`
v0.55.1.

**Upgrading:** behavioural, and worth reading before deploying. Every control on the
auth-policy page is now enforced, where three of them previously were not. Review what
your environments and organizations have set — a `maxAgeDays` or `mfa: required` saved
while it did nothing will start doing something on the first request after deploy.

Two new tables arrive from the framework (`password_ages`, `login_attempt_counters`);
`password_ages` is seeded with every existing subject at migration time, so a rotation
policy starts its clock for everyone at once rather than never applying to anyone who
predates it.

### Fixed

- **Every password form used a hardcoded `min:12`.** An environment demanding 24
  characters got 24 from an administrator and 12 from signup, invitation acceptance and
  both self-service resets. The forms apply the tenant's policy now; the framework
  enforces it at the credential primitive regardless, so the rule is what makes a weak
  password impossible and the form is only what makes the refusal land on the field.
- **A temporary password was never actually temporary.** `requiresChange()` was read
  once, to render a line of prose. With "valid until they change it" selected, the
  result was a permanent administrator-known credential — the opposite of what the UI
  promised.

### Added

- A forced password change on both planes, held on every authenticated request rather
  than checked at sign-in: an administrator can hand a temporary password to someone who
  then arrives by magic link, SSO, or an already-open session.
- Rotation (`maxAgeDays`), the MFA mandate (`mfa`) and account lockout
  (`lockoutThreshold`) are enforced. The MFA mandate holds a subject on the security page
  rather than refusing them entry — turning away someone with no factor locks out exactly
  the people who need to enrol. Lockout is checked before the credential, so a locked
  account is not an oracle for which guess was right.
- Operator creation gains the breach screen it never had. Operators sit above every
  environment, so no tenant policy governs them, but they are the most privileged
  accounts on the platform.

## [0.20.0] - 2026-07-25

Second platform-review loop, plus unified account identity. Adopts `cboxdk/laravel-id`
v0.52.0.

**Upgrading:** breaking. An account member now authenticates as a **subject** in the
platform-root environment. Existing account members need their subjects minted before
sign-in works; there is no backfill, because the platform had no external consumers at
the time of the cut.

### Added

- **Set a user's password from the environment console** — temporary (with a deadline) or
  permanent, revealed once or emailed, with the revocation blast radius an explicit
  choice. Reason required and audited; the revealed credential never enters the Livewire
  snapshot.
- **Sign-in rules** page: the environment's authentication baseline (length, breach
  check, rotation, reuse history, two-factor, SSO) with a per-organization table showing
  what each one effectively gets — an organization override can only tighten.
- **Account-plane SSO and magic links.** The workspace door is identifier-first with
  home-realm discovery, so account SSO is an ordinary connection.

### Security

- **Manual permissions are environment-scoped.** An environment admin could previously
  see, edit and delete another environment's manual permission, and the delete cascaded
  `role_permission` across tenants.
- **Member role toggle enforces membership and assignability server-side.** It assigned
  from raw Livewire parameters with no authorization.
- **Minted hook and Admin Portal secrets no longer reach the DOM.** Both were public
  Livewire properties, so an HMAC signing secret and a SCIM bearer token — the latter
  handed to a third-party IT admin — dehydrated into the wire snapshot.
- **SSO enforcement at sign-in**: a tenant that mandates SSO refuses local password auth;
  the strictest membership wins.
- `EnforcePlane` and `SetEnvironment` resolved the platform root in opposite orders; a
  deployment setting both defaults differently would have had `plane:account` 404 on the
  host that is the account root.

### Fixed

- **CSP silently killed five inline handlers**, including Copy on a one-time-shown API key
  (straight data loss) and two impersonation confirmations that fell open.
- **`EnvironmentAdminAuth` had no container binding**, so the middleware, layout and every
  component `boot()` rebuilt it and re-ran the same identity queries — roughly nine per
  page load. Now scoped with an input-keyed memo, so a mid-request environment switch
  cannot return a stale answer.
- **Environments are listed under their project on the launchpad**, with an Open button
  each and an inline create — reaching an environment took three clicks.
- Field errors are linked to their inputs (`aria-invalid` / `aria-describedby`) on the
  environment forms.
- `composer analyse` and `composer test` carry a memory limit; both crashed at PHP's
  128M default, so neither could be run as written.

## [0.19.0] - 2026-07-24

Platform-review remediation. Adopts `cboxdk/laravel-id` v0.49.0 (environment-scoped
permission catalog, idempotent refresh rotation, DPoP-exchange proof-of-possession,
`azp` enforcement, and more — see that release).

### Security

- **Environment-admin privilege escalation closed.** Administering an environment's
  control plane now requires the `AccountRole::canManageEnvironments()` capability at
  the env-admin session chokepoint and at the handoff mint/redeem sites — a `viewer`
  or `billing` account member (who defaults to `all_environments = true`) can reach an
  environment but can no longer administer it. "Accessible" is not "administrable".
- **Org-admin console pages re-authorize on every request.** The read gate on the
  Connections, Directories, Roles and Webhooks pages moved from `mount()` to `boot()`,
  so an admin demoted mid-session cannot keep re-rendering org-wide SSO/SCIM/role/webhook
  configuration from an open Livewire snapshot.
- **One-time secrets no longer dehydrate into the page.** Freshly minted client secrets,
  SCIM/directory tokens, webhook secrets and SIEM signing secrets are held in protected
  (never-dehydrated) Livewire state and surfaced through the render only — they are shown
  once and never serialized into the `wire:snapshot` in the DOM.

### Fixed

- **Organization detail no longer loads the whole environment.** The environment
  organization-detail page scoped its member-name lookup to the org roster instead of
  hydrating every user in the environment on each render.
- **Context switchers use a CSS `:hover`** instead of inline handlers the app's own
  Content-Security-Policy blocked (which never fired and logged a violation on hover).
