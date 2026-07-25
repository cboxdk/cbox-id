# Changelog

All notable changes to Cbox ID (the deployable identity platform app) are documented here.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).
Confirmed security issues and their fixes are cross-referenced under **Security** below.

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
