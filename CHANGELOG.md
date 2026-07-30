# Changelog

All notable changes to Cbox ID (the deployable identity platform app) are documented here.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).
Confirmed security issues and their fixes are cross-referenced under **Security** below.

## [Unreleased]

### Added

- **Personal Trusted devices page** (`My account → Trusted devices`) — enrolment QR and
  the caller's own handsets, with self-service removal. Enrolment was previously only on
  the org-admin fleet page, which plain members cannot open, so members had no way to
  enrol at all. The admin page keeps the fleet inventory and delivery health, and links
  here instead of showing a code.
- **The authenticator OAuth client provisions itself** on first view of the Trusted
  devices page. `php artisan cbox-id:devices:client` is no longer a required setup step;
  it remains for pre-provisioning an environment or adding extra redirect URIs.

### Changed

- **Module env vars renamed `ID_*` → `CBOX_ID_*`** (`ID_DEVICES_*`, `ID_ANALYTICS_*`,
  `ID_COMPLIANCE_*`, `ID_CONNECTORS_*`), matching the prefix the rest of the deployment
  uses. The old names still work as a fallback; see UPGRADING.md.

## [0.33.0] - 2026-07-30

Requires `cboxdk/laravel-id` v0.65.0.

CIBA could always *ask* a user to approve something out of band; nothing ever told them.
This adds the notification surface the framework's own docblock says belongs to the host.

### Added

- **Trusted devices and push approvals** (`modules/devices`) — a registry of a user's
  enrolled handsets and an FCM delivery pipeline, modelled on the webhook dispatcher:
  durable row before enqueue, gap-free per-device sequence, exponential backoff, a
  per-device circuit breaker and a stranded-job rescue. Off by default
  (`ID_DEVICES_ENABLED=false`); with no transport configured it records notifications and
  sends nothing.
- **The CIBA approval push is synchronous.** The domain event goes to a transactional
  outbox relayed once a minute, against a 300-second CIBA TTL, so approvals are pushed
  from a decorator around `BackchannelAuthentication` and reach the handset in the same
  request. Security alerts take the opposite trade and keep the relay, staying off the
  login path. Note the decorator is installed even when the module is disabled, where it
  is a pass-through.
- **A device API for a first-party mobile client** — enrol, list, deregister, and read,
  approve or deny pending CIBA requests. Behind `scope:` with DPoP sender-constraining;
  the approving subject comes from the verified token, never the body.
- **`cbox-id:devices:client`** provisions the authenticator's public OAuth client per
  environment, and `GET /.well-known/cbox-authenticator` lets one mobile binary discover
  it. That endpoint is load-bearing rather than convenient: `oauth_clients.client_id`
  carries a global unique index, so no single client id can serve every environment.
- **An enrolment QR code** on the Trusted devices page. It carries only the host — no
  token, nothing time-limited — so it is safe on a screen.

### Fixed

- **The retry sweep never selected a row.** It runs from the scheduler, which has no
  environment context, so the deny-by-default tenancy scope compiled it to `WHERE 1 = 0`.
  The durability guarantee was inert in production while passing every test that set an
  environment first. It now spans environments under `withoutScope()` and lets each job
  re-enter its own.
- **A mistyped FCM project id would have wiped every push token in the estate.** A
  project-level 404 carries no `errorCode`, and a bare 404 was treated as token death.
  Only `UNREGISTERED` and `SENDER_ID_MISMATCH` now retire a device; `INVALID_ARGUMENT` is
  a malformed message, not a dead handset.
- Delivery no longer clobbers a push token rotated while a send was in flight, and
  circuit-breaker state is written atomically instead of read-modify-write.
- Notifications parked by an open breaker are bounded by a deadline, so one dead handset
  cannot accumulate a backlog that starves every other tenant's approval retries.
- `OpenApiCoverageTest` derived its path base from a filename heuristic `ApiContract` does
  not share, so a new spec file would either fail the build or pass it while silently
  skipping response validation.

### Security

- The Trusted devices console page had no authorization gate. Any authenticated member
  could read the environment's entire device inventory — who is enrolled, on what, and
  which devices are failing — by typing the URL. Now gated on admin in `boot()`, so the
  check is re-run on every hydration rather than only at mount.
- `approve()`/`deny()` collapse unknown, expired, already-answered and wrong-subject into
  one response, and the API preserves that collapse, so a stolen handset cannot probe
  which request ids exist.
- The CIBA binding message is never pushed. A lock screen is readable by anyone holding
  the phone, and that message is the transaction detail CIBA exists to protect; it is
  fetched over TLS after the app opens.

### Not yet verified

- The mobile sign-in flow has not been exercised end to end. `Browser::auth()`'s callback
  delivery is untested, so enrolment from a real handset is unproven. Enabling
  `ID_DEVICES_ENABLED` in production makes the console surface visible before that is
  established.
- Push has never left the building: no deployment has FCM credentials configured, so the
  transport is inert and only the Null path has run outside tests.

## [0.32.0] - 2026-07-27

Requires `cboxdk/laravel-id` v0.65.0.

The open-core split is gone. Cbox ID is now one application that self-hosts with no
licence key, no limits, and every capability present; billing becomes something you add
where you actually bill someone.

### Added

- **Five console modules are now part of the application** — analytics, compliance,
  connectors, risk-plus and whitelabel, previously five separate proprietary packages.
  They live under `modules/` and still register themselves the way an external package
  would, through the public console-kit sockets with no edit to `app/`. See
  [Console modules](docs/core-concepts/modules.md).
- **`CBOX_ID_ENTITLEMENTS=open` (the new default)** — an unset entitlement is granted.
  It is a floor, not an override: an explicit entitlement still wins in both directions,
  so per-organization differentiation by hand keeps working and a revocation still
  revokes. Set `metered` where a billing transport is wired to restore deny-by-default.
  See [Entitlements](docs/core-concepts/entitlements.md).
- **A relational analytics event store** (`ID_ANALYTICS_STORE=database`). Analytics could
  previously stream to ClickHouse or nowhere; this is the third option, for deployments
  with no column store. Idempotent through the engine — `event_id` is the primary key and
  writes are `insertOrIgnore` — and swept by the daily `model:prune`, which it needs:
  this is the one table that grows with traffic rather than with tenants. See
  [Analytics storage](docs/operations/analytics.md).
- **CI runs the suite against MySQL 8.4 and PostgreSQL 17.** Until now this application
  had only ever executed against sqlite, while production ran PostgreSQL.

### Fixed

Four defects the new engine coverage surfaced on its first run, two of them live bugs on
an engine that ran in production:

- **The Apps & API keys console 500s on MySQL.** It filtered `oauth_clients` on
  `orphaned_at`, a column that lives on `roles`/`permissions` and exists on
  `oauth_clients` on no engine. sqlite hid it by falling back to treating the
  unresolvable double-quoted identifier as a string literal, so the query matched nothing
  instead of failing.
- **The environment-scope guard refused legitimate writes on PostgreSQL.** Thirteen
  `ulid()` declarations across this app's own migrations and two modules compiled to
  `char(26)`, which PostgreSQL blank-pads — so a row's `environment_id` came back padded
  and compared false against the very environment that owned it. `SchemaPortabilityTest`
  is ported from the framework, widened to scan `modules/`, so it cannot return.
- **The compliance audit export failed outright on PostgreSQL**, which ordered a
  `SELECT DISTINCT` by an expression not in the select list.
- **Analytics read across every environment** when none was in context: the ClickHouse
  reader dropped the environment clause entirely. Both readers now deny by default.

- `docs/security/adaptive-risk.md` told operators to run `CAST(… AS INTEGER)`, which
  MySQL spells `SIGNED`. Query 1 is now portable; queries 2–5 are marked PostgreSQL-only
  rather than left silently wrong.

### Changed

- `BrandProfile`'s `palette` and `email_templates` leave the `array` cast for `Palette`
  and `EmailTemplates` value objects. The palette was previously validated at *render* —
  an invalid colour could reach storage and was only rejected on the way out.
- The brand asset stores depend on the `Cloud` filesystem contract rather than
  `Filesystem`, which does not declare the `url()` they were calling.
- `ReportSink` and `AuditExportSink` gained `isInert()`. The providers were sniffing the
  concrete class to decide whether a feature was live, which missed a host binding its
  own no-op sink.
- Module Volt components converted from the functional API to the class API used
  everywhere else in this application, so they carry typed properties and pass analysis.

### Removed

- The licensing layer. `cboxdk/laravel-id-licensing` is retired and
  `EntitlementSource::License` is gone from the framework; there is nothing left to
  unlock.

## [0.31.0] - 2026-07-27

Requires `cboxdk/laravel-id` v0.64.0.

### Changed

- The operator accounts screen no longer writes its own audit entry. `Accounts::suspend()`
  and `reactivate()` now take an actor and record internally, so the four lines that did it
  at the call site are gone. That is the point of the upstream change: an audit written at
  the call site is one a second caller can silently forget, and this screen was the only
  caller there had ever been.
- **Known cost, accepted and tracked.** The package scopes that entry to the **system**
  chain, because an account sits above the tenancy boundary — consistent with
  `PlatformOperators`, which does the same. `App\Platform\AccountActivity` reads
  `where('scope', $accountId)`, so an account's own activity view no longer surfaces its
  suspension. The package's scoping is the right one and the test follows it rather than
  forcing the package to match the app; `OperatorAccountsTest` now asserts both that the
  entries land on the system chain **and** that the account's own chain is empty for those
  actions, so the gap cannot quietly disappear.

## [0.30.0] - 2026-07-27

Requires `cboxdk/laravel-id` v0.63.0.

### Security

- Picks up two privilege fixes in the framework. `POST /user-tokens/introspect` checked no
  scope at all, so any valid environment API key — however narrowly issued — could
  introspect every personal access token in its environment; it now requires `users:read`.
  And an **empty** resource-family allow-list was stored as `null`, which means
  *unrestricted*, so the most restrictive request possible produced a token permitted on
  everything.
- **Both are breaking upstream, and both have zero blast radius here** — verified against
  the production database rather than assumed: it holds **0** environment API keys and
  **0** user API tokens, and this app has no caller of `UserApiTokens::issue()` or any
  reference to `resource_families`.

### Changed

- The framework renamed `password_reset_tokens` to `cbox_id_password_reset_tokens`,
  because the old name collided with Laravel's own skeleton migration and made a
  greenfield install fail to migrate at all. **Requires a migration**; on PostgreSQL it is
  a rename, not a table rewrite, so it is far cheaper than the v0.62.0 conversion.

## [0.29.0] - 2026-07-27

Requires `cboxdk/laravel-id` v0.62.0.

### Changed

- **Requires a migration.** laravel-id v0.62.0 moves 225 identifier columns across 81
  tables off PostgreSQL's blank-padded `char` to `varchar`, because `char(26)` handed any
  identifier shorter than 26 characters back to PHP padded — so a strict comparison
  against the unpadded value was false. This deployment is **not** currently affected:
  every id in production is a full 26-character ULID, verified by
  `SELECT count(*) … WHERE octet_length(id) <> length(id)` returning 0 on every table
  checked. The migration is therefore preventive and changes no data. On PostgreSQL it
  rewrites each table but keeps every foreign key throughout, so there is no integrity
  window; set `lock_timeout` so the `ACCESS EXCLUSIVE` queue fails fast rather than
  blocking readers behind it.

## [0.28.0] - 2026-07-27

Requires `cboxdk/laravel-id` v0.61.0.

### Security

- **A "deleted" organization kept authenticating its members.** The console wrote
  `OrganizationStatus::Deleted`, but all three enforcement points tested only
  `=== Suspended`, and every other reference to `Deleted` was cosmetic — list filtering
  and badge colours. Its members kept signing in, kept consenting, and kept minting
  tokens. Production had no organization in that state, so this was latent rather than
  breached. `Deleted` is now enforced everywhere `Suspended` is, via
  `App\Platform\OrganizationAccess`, whose `match` is exhaustive with no `default` — so a
  status added upstream fails static analysis instead of defaulting to "allowed", which
  is exactly how this slipped through. The delete path now writes an audit entry.

### Fixed

- **The console claimed to delete a user and did not.** `deleteUser()` removed
  memberships, called `$user->delete()`, and caught `Throwable` with "they still have
  linked records — deactivate instead". There is no foreign key on `user_id` anywhere in
  the schema, so that catch was structurally unreachable: the delete always succeeded and
  always reported "User deleted", while the person's sessions, passkeys, MFA factors,
  TOTP seeds, password history, `identities.raw`, magic links, OAuth tokens, role
  assignments and `directory_users.resource` (their full SCIM payload, including phone,
  title and manager) stayed behind. It emitted no domain event, so nothing propagated
  downstream, and wrote no audit entry. The action is removed rather than repaired:
  deactivation is the honest capability today, and erasure is a designed programme that
  must not be faked with a `->delete()`.
- `docs/security/compliance.md` inherited the framework's Art. 17 claim wholesale without
  restating it, so a reader had no signal to go and check. There is no erasure
  implementation. It now states what exists — deactivate, revoke, soft-delete — and that
  erasure is manual and out-of-band.
- Operators can suspend and reactivate an account. `Accounts::suspend()` was fully
  implemented in the package with zero callers and no screen anywhere, so five junk
  signups could not even be disabled.

### Added

- **Response-schema validation for the REST management API.** Every `/api/*` response
  the test suite produces is now validated against the documented OpenAPI response
  schema for that operation, and against the status list the spec declares
  (`Tests\Support\ApiContract`, wired into `Tests\TestCase::createTestResponse()` so
  there is no per-test opt-in). `OpenApiCoverageTest` kept the spec honest about paths;
  this keeps it honest about bodies. Validation is by `opis/json-schema` — **dev-only**,
  so the production dependency surface and the SBOM are unchanged.
- `OpenApiCoverageTest` gained a fourth check: an operation that is documented can no
  longer sit on the known-debt list.

### Changed

- **The REST API rate limit is keyed on the API key, not the client IP.** The routes
  carried a bare `throttle:240,1` (Laravel's default IP key) and the repo had no
  `RateLimiter::for()` at all, so every customer whose CI egressed through one NAT
  shared a single bucket — one noisy tenant throttled the rest. Named limiters
  (`api-account`, `api-environment`, `api-vault`, `api-apps`) now bucket per credential,
  with a per-IP backstop for floods of invalid credentials. Budgets are configurable via
  `CBOX_ID_API_RATE_LIMIT_*` (see `config/api.php`).

### Fixed

- **One error shape on the REST API, actually honoured.** `bootstrap/app.php` had an
  empty `withExceptions()`, so `$request->validate()` rendered Laravel's default
  `{"message": …, "errors": {…}}` — with no `error` key, which both specs declare
  REQUIRED. A generated client that switched on `error` broke on the most common failure
  there is. `App\Http\ApiErrorRenderer` now maps every exception off the API surface into
  `{error, message}` (plus `errors` on a validation failure), preserving `Retry-After` /
  `X-RateLimit-*` on a 429. The RFC-specified surfaces are untouched: OAuth's
  `{error, error_description}` bearer challenges and SCIM's Error envelope are protocol
  conformance, not inconsistency.
- The two stragglers that spelled the envelope differently: `AppManifestController`
  returned `{error, detail}`, and `VaultController` returned a bare `{error}` for
  `not_found` and `lease_denied`. Both now carry `message` — constant strings in the
  vault's case, so the no-enumeration property of a uniform refusal is preserved.
- **Spec/controller drift, found by the new schema gate.** `environment.yaml` documented
  a `revoked_at` timestamp on a vault secret that the API has never returned (it returns
  a boolean `revoked`); the lease response omitted the `provider` field it does return;
  the vault's 401/403 were documented as the standard error envelope when they are
  RFC 6750 bearer challenges; and every nullable field in both specs used OpenAPI 3.0's
  `nullable: true`, which a 3.1 document (these declare `openapi: 3.1.0`) ignores — so
  eight fields that are routinely null were typed as non-nullable. Undocumented statuses
  (403 on the vault, 404/422 on several account operations, 429 everywhere) are now
  documented.

## [0.27.0] - 2026-07-27

### Added

- **Risk decisions are now persisted and queryable.** Every `RiskGuard::assess()` call
  writes a row to the new `risk_decisions` table (`App\Models\RiskDecision`, via
  `App\Platform\RiskTrail`) with the score, outcome, mode, reasons, per-signal weighted
  points and pseudonymised identity. `docs/security/adaptive-risk.md` documents the
  queries that turn that corpus into an enforcement threshold — a score histogram, the
  outcome mix, a threshold sweep scored against a known-bad cohort, a per-signal
  contribution breakdown, and a provider-level aggregate for the Gmail dot-abuse shape.
- Retention for the trail: `CBOX_ID_RISK_TRAIL_RETENTION_DAYS` (default 90), swept
  daily by `model:prune`, scheduled in `routes/console.php`.

### Fixed

- **Monitor mode produced no evidence.** `RiskGuard` logged each decision with
  `Log::info` and its docblock called that "the audit trail for tuning and review", but
  production runs `LOG_CHANNEL=stderr` with no aggregation and no `log_streams` sink —
  every decision went to pod stdout and was destroyed by the next rollout. Weeks of
  monitor mode left nothing to set a threshold from, which is why the CAPTCHA wired to
  `RiskGuard::shouldStepUp()` could not responsibly be armed. The `Log::info` is
  replaced by the durable write; a failed write is logged at `warning` and never blocks
  a sign-in.

### Security

- The trail stores an HMAC-SHA256 **pseudonym** of the email alongside the existing
  hashed IP, never the address — a pre-auth table fed by unauthenticated traffic must
  not become the platform's largest plaintext store of personal data. The mail domain
  is kept in the clear (it names a provider, not a person) because provider-abuse
  patterns are unreadable without it. Both pseudonyms are keyed under `app.key` and
  domain-separated.
- `RISK_MODE` is deliberately **unchanged** (`monitor`). Enforcement is the tuning
  decision this data exists to inform.

### Changed

- Requires `cboxdk/laravel-id` v0.60.0, which fixes a concurrency defect that silently
  dropped audit entries when two writers appended to the same chain. Relevant here: it
  is the same measurement that ruled the audit chain out as the home for this trail.

## [0.26.0] - 2026-07-27

Requires `cboxdk/laravel-id ^0.59.1`.

### Added

- Five more inline hook points are now registrable from the console — `post_login`,
  `pre_registration`, `post_registration`, `pre_password_change`,
  `post_password_change`, alongside the existing `token_minting`. The console reads
  `HookPoint::cases()`, so they appeared as soon as the framework shipped them.

### Fixed

- The hook picker rendered the raw PHP case name, so an admin chose between
  "TokenMinting" and "PrePasswordChange", and the list showed the machine value
  `token_minting`. Both now use the framework's `label()`, and the picker shows
  `description()` beside each option — which states whether that hook can refuse the
  operation, the fact you need before wiring a URL that can stop people signing in.

## [0.25.0] - 2026-07-27

Signup had no bot protection, and production showed the bill: five of eight accounts
were Gmail dot-abuse bot signups, each of which had provisioned a full environment and
none of which ever verified an address.

### Security

- **A self-serve signup no longer provisions an environment up front.** The account, its
  home organization, its owner and its first project are created immediately; the
  environment — the routable IdP whose signing key is warmed on creation — is released
  only when the owner opens the emailed verification link (new
  `App\Platform\SignupProvisioner`, consumed by `EmailVerificationController`). This does
  not make bulk signup harder, it makes it worthless. Idempotent: a replayed link never
  mints a second environment, and a suspended account is not revived by one.
- **A risk-triggered CAPTCHA on signup.** An `Outcome::Challenge` / `StepUp` from the
  risk scorer now demands a Cloudflare Turnstile token, verified server-side against
  `siteverify` (new `App\Platform\Turnstile`); previously the signup form consulted only
  `shouldBlock()`, so a Challenge outcome did nothing at all. A missing or rejected token
  is a field error, never a 500. Deliberately **not** an always-on CAPTCHA — the friction
  lands on the submissions the scorer flagged.
- Both keys unset (`CBOX_ID_TURNSTILE_SITE_KEY` / `CBOX_ID_TURNSTILE_SECRET_KEY`) means
  the feature does not exist: no widget, no Cloudflare script, and the CSP keeps its
  strict `script-src 'self' 'unsafe-eval'`. `https://challenges.cloudflare.com` is added
  to `script-src` and `frame-src` **only** on a deployment that configured it.

### Changed

- `risk.mode` is untouched and still ships as `monitor`. Flipping it to `enforce` is a
  production tuning decision that needs the monitor-mode data behind it; this release
  only makes the Challenge branch mean something once it is flipped.
- The workspace launchpad explains the wait, rather than showing a project with no
  environments and no reason why. It now also names the address the confirmation went to
  and the sender to look for, and says the link is good for 24 hours.

### Added

- **A resend control on the launchpad banner** (`App\Platform\MemberEmailVerification`,
  `App\Platform\Enums\VerificationResendOutcome`). Holding the environment back until the
  address is proven put a real owner's whole account behind one email; without a resend,
  losing it or letting the 24-hour token lapse left an account with a member, a project
  and no way forward. The action takes an `AccountMember`, never an address, so there is
  no input to steer it at someone else's inbox; it retires every previously-issued link
  before minting the next one (single-use is not the same as single-*live*); it answers
  identically whether or not the address is already confirmed, so the button is not a
  verification oracle; and it is a no-op once the environment is up. Throttled at **3 per
  10 minutes per member** — outbound mail is the abusable resource, and the member id is
  the key nobody can rotate.

## [0.24.0] - 2026-07-26

Requires `cboxdk/laravel-id ^0.58`, which made `MembershipRole` a type rather than a
string on the membership and invitation contracts. Adopting it surfaced four latent
bugs the type change had nothing to do with.

### Fixed

- **Any page listing pending invitations would have 500'd.** `Invitation::$role` is now
  cast to the enum upstream, and two templates still called `ucfirst()` on it —
  `environment/organizations/show.blade.php` and `members.blade.php`. Neither had a test.
- **The role dropdowns never pre-selected a member's current role**, because
  `@selected($m['role'] === $val)` compared an enum case to a string. Pre-existing.
- Role validation is now deny-by-default against the *assignable* set rather than merely
  a valid enum case. `MembershipRole::tryFrom('viewer')` returns a real case that this
  console must never assign, so a non-null check was not sufficient — `'viewer'` is now
  refused exactly like `'archduke'`.
- The three JS-invoked role actions had no field to report into and previously used
  `in_array()`; they now go through the same deny-by-default parse.

### Changed

- New `App\Platform\OrgRoles` — the subject-plane analogue of the framework's
  `AccountRole::assignable()`, exposing the assignable set, a validation rule, a message
  naming the accepted roles, and a parse for actions with no field. All four role selects
  now render from it instead of hand-written option triples, and each gained an `@error`
  sink so the message is visible.
- `cboxdk/laravel-id` floor raised to `>=0.58` — the app does not run below it.

### Testing

- Four regression tests drive each affected form with both an unknown value and a
  real-but-unassignable enum case, asserting a field error rather than a 500, no side
  effect, and no mail. Mutation-checked: swapping the guard back to `from()` makes them
  fail with the `ValueError` at the component line, so they pin the distinction rather
  than the presence of a rule.

## [0.23.0] - 2026-07-26

Requires `cboxdk/laravel-id ^0.57`. Output of a whole-platform review loop plus a
re-review that caught eight regressions the fixes themselves introduced. See
`UPGRADING.md` — **this release refuses things earlier versions accepted**, and one
change requires infrastructure work before deploying.

### Security

- **Admin portal links are environment-scoped.** The token lookup was unscoped and the
  `/setup` routes carried no plane gate, so a link minted in one environment could be
  redeemed on another's host — and the entitlement re-check in front of it read a cache
  keyed without the environment, which an attacker could warm on demand. Reachable
  deterministically, not as a race, because `redeem()` checks entitlement before burning
  the link. It allowed writing SSO connections and SCIM directories into another
  customer's environment; it did not allow taking over a domain, which still needs DNS.
- Every destructive console action that destroys a credential, revokes someone else's
  access or transfers ownership now requires type-to-confirm naming the resource.
- An org admin can no longer deactivate an environment-wide segregation-of-duties policy.
- The console now consults the SoD gate it ships the UI for, so it can no longer create
  on one screen the violation it reports on another.

### Changed — read UPGRADING.md before deploying

- **The platform-root host no longer serves the IdP surface.** Discovery, JWKS, all
  `/oauth/*`, SCIM and the SAML IdP endpoints 404 on the apex. Anything pointing at the
  apex as an issuer will now fail at discovery instead of half-working. Account-plane
  federation entry and callback deliberately still answer there.
- `/up` returns JSON, not HTML.
- The app no longer sets security headers that the nginx layer also sets. **This needs
  four `NGINX_HEADER_*` env vars set empty at deploy time** — see UPGRADING.md; the
  base image refills them otherwise, so the app's stricter Referrer-Policy was being
  silently downgraded.

### Fixed

- OIDC step-up worked in neither direction: `max_age` was arithmetically unsatisfiable
  (so `max_age=0` always failed), and `acr_values` was advertised and ignored.
- SSO failure paths redirected to a subject-plane route that 404s on the account host —
  after a successful IdP authentication, so the user believed SSO had worked. The error
  bag key also did not match what either sign-in screen renders, so the message was
  dropped on both planes.
- Published config merged block-by-block, so partial overrides silently discarded package
  defaults — `CBOX_ID_ACCESS_TOKEN_TTL`, `CBOX_ID_REQUIRE_PAR`, `CBOX_ID_DCR_MODE` and
  others were inert.
- The permissions page counted the whole platform-wide pivot in PHP, showing other
  environments' role usage and loading every row on each render.

### Accessibility

- Seven contrast pairs failed AA in the light theme, including the one-time API-key
  warning and the password-strength affordance. An eighth was a hover state weaker than
  its own resting stroke. A stray brace was closing `@layer components` early, leaving
  ~240 lines unlayered.
- Search inputs labelled, list results announced, section headers promoted to real
  headings, login step two focuses and announces itself.

### Testing

- 70% of `assertNoRedirect()` calls asserted nothing: it inspects the Livewire effect,
  not the response, so it was silent on mount-time redirects — the exact class the
  `max_age` bug belonged to. Replaced with `assertRenderedNotRedirected()`, which checks
  both, and every converted site now carries a positive assertion.
- PHPStan now analyses `resources/views/livewire`, which was never covered despite most
  of the app's logic living there.

## [0.22.1] - 2026-07-25

### Fixed

- The CycloneDX SBOM still named `laravel-id v0.52.0` after four dependency bumps, so
  every release from 0.21.0 onward carried a supply-chain record that misstated what it
  actually ran. No dependency was added or removed — the file was simply never
  regenerated. Releases 0.21.0 and 0.22.0 should be treated as having an inaccurate SBOM;
  this is the first one whose record is true.

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
