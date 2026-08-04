<?php

declare(strict_types=1);

use App\Http\Controllers\AdminPortalController;
use App\Http\Controllers\EmailVerificationController;
use App\Http\Controllers\EnvironmentAdminController;
use App\Http\Controllers\EnvironmentHandoffController;
use App\Http\Controllers\ImpersonationController;
use App\Http\Controllers\InvitationController;
use App\Http\Controllers\MagicLinkController;
use App\Http\Controllers\OperatorController;
use App\Http\Controllers\PasskeyController;
use App\Http\Controllers\SessionController;
use App\Http\Controllers\SocialController;
use App\Http\Controllers\Sso\OAuth2CallbackController;
use App\Http\Controllers\Sso\OAuth2RedirectController;
use App\Http\Controllers\Sso\OidcCallbackController;
use App\Http\Controllers\Sso\SamlAcsController;
use App\Http\Controllers\Sso\SamlIdpSsoController;
use App\Http\Middleware\AuthenticateOperator;
use App\Http\Middleware\BlockDuringImpersonation;
use App\Http\Middleware\EnforceImpersonationWindow;
use App\Http\Middleware\TargetEnvironment;
use App\Platform\PlaneResolver;
use App\Platform\PlatformAuth;
use Illuminate\Support\Facades\Route;
use Livewire\Volt\Volt;

/*
 * FIRST RUN — the only surface an unclaimed deployment serves, and one it stops serving
 * for good the moment it is claimed ({@see \App\Http\Middleware\PointAtFirstRun}, which
 * also points every other web route here while the platform is empty).
 *
 * DELIBERATELY NOT plane-gated, unlike everything below it. The plane bulkheads compare
 * the request host against the platform root — an environment that does not exist yet —
 * so gating this would 404 the one screen that can create it, and the operator would
 * have no door at all. The gate here is possession of the setup token, which does not
 * depend on any of the state being bootstrapped.
 */
Volt::route('/first-run', 'first-run')->name('first-run');

/*
 * The apex — one destination, because there is one console.
 *
 * This used to fork on the plane: the root sent people to the account console and every
 * other host to the tenant one. Both of those are the same console now — the account's
 * projects, keys and billing are an AREA of it ({@see \App\Providers\ConsoleServiceProvider}),
 * shown to whoever's organization owns identity providers — so the fork had nothing left
 * to choose between, and every version of it that ever shipped was a bug: it read
 * multi-tenancy as `base_domains !== []` and 404'd the account console's own front door
 * on a per-domain deployment, and it resolved the platform root config-first while
 * SetEnvironment resolved it database-first, so a deployment setting both had the apex
 * comparing against one root while its own request had resolved to another.
 *
 * `PlaneResolver` still answers "which plane is this?" for the surfaces that genuinely
 * differ — the issuer endpoints, the environment-admin door. The apex is no longer one
 * of them.
 */
Route::get('/', fn () => redirect()->route(
    session()->has(PlatformAuth::SESSION_KEY) ? 'dashboard' : 'login'
))->name('home');

/*
 * SAML 2.0 Identity Provider — the SingleSignOnService endpoint downstream SPs
 * federate to. The host owns the interactive "authenticate the subject" step
 * (this app uses its own session guard, not Laravel's default), so it overrides
 * the framework's thin controller with one wired to PlatformAuth; the package
 * still parses/validates the AuthnRequest and mints/signs the Response.
 *
 * Both bindings are accepted: HTTP-Redirect (GET) and HTTP-POST (cross-site form
 * POST — exempted from CSRF in bootstrap/app.php, as the package documents). The
 * metadata (GET /sso/saml/idp/metadata) and SLO endpoints stay with the package.
 *
 * `plane:issuer`, like every other IdP surface: the platform root serves a console like
 * any other host, but it is an identity provider for nobody, so it must not answer as one.
 * The package's own SAML routes are gated identically via `cbox-id.api.middleware`; these
 * app overrides would otherwise be the one hole left in that wall.
 */
Route::match(['get', 'post'], '/sso/saml/idp/sso', SamlIdpSsoController::class)
    ->middleware('plane:issuer')
    ->name('sso.saml.idp.sso');

/*
 * INBOUND federation — the browser half. The package's own ACS/callback validate the
 * assertion and return the resulting session as JSON, on the explicit understanding
 * that a hosting app turns it into a cookie. Nothing did, so an enterprise user
 * authenticated at their IdP and landed on a raw JSON blob — never signed in. That is
 * the entire value proposition of B2B SSO, so these override the package routes (this
 * file is registered first, so the app's binding wins).
 *
 * Unauthenticated by design on both: the assertion signature / id_token IS the
 * authentication. The SAML ACS is a cross-site form POST from the IdP, so it is
 * CSRF-exempt in bootstrap/app.php exactly as the package's route was.
 *
 * NOT plane-gated, unlike the IdP endpoint above — and that is the point of the split.
 * `plane:issuer` gates the ISSUER surface: a host that is not an identity provider must
 * not advertise or answer as one. Inbound federation is the opposite role (this server as
 * the RELYING party), and the ACCOUNT plane genuinely does it: an account's organization
 * lives in the platform-root environment, so home-realm discovery on `/login` and
 * `/signup` sends the member to `/sso/{oidc,saml}/{connection}/...` on the very host they
 * are standing on. Gating these 404'd that redirect and its callback, and an account org
 * with SsoEnforcement::Required and a verified domain was then locked out of the console
 * it had just secured: the password is refused and the SSO door does not exist.
 *
 * The environment scope on `Connection` is the real boundary, and it holds on either
 * plane: the platform root IS an environment, so a tenant's connection id resolves to
 * nothing here just as it always did.
 */
Route::post('/sso/saml/{connection}/acs', SamlAcsController::class)->name('sso.saml.acs');
Route::get('/sso/oidc/{connection}/callback', OidcCallbackController::class)->name('sso.oidc.callback');

// The OAuth 2.0 pair — providers that are not OpenID Providers (GitHub, Discord,
// Facebook). Both halves live here rather than in the framework because turning a
// completed federation into a session cookie is this application's job, and because
// there is no id_token, `state` alone carries CSRF on the callback.
Route::get('/sso/oauth2/{connection}/redirect', OAuth2RedirectController::class)->name('sso.oauth2.redirect');
Route::get('/sso/oauth2/{connection}/callback', OAuth2CallbackController::class)->name('sso.oauth2.callback');

/*
 * Account signup — "create your identity platform" — is an ACCOUNT-plane action in the
 * SaaS shape (`plane:account`, root host only): it provisions an account + its first
 * environment. In the single-tenant shape the gate is a no-op and it is a Tier-1 join.
 */
Route::middleware(['plane:account', 'platform.guest'])->group(function (): void {
    Volt::route('/signup', 'auth.signup')->name('signup');
});

/*
 * Guest — the sign-in surface, on `plane:console` and therefore on EVERY host this
 * deployment answers on, the platform root included.
 *
 * It used to be `plane:subject`, which meant "not the platform root", so `cboxid.com/login`
 * was a 404: the one host every account member already has an identity on was the one host
 * they could not sign in to as themselves. The root is a tenant like any other — what it
 * has that acme.cboxid.com does not is the operator area and the account plane standing
 * alongside this console, not the absence of one. The IdP protocol surface it must NOT
 * serve moved to `plane:issuer`, which is the question that was actually being asked here.
 */
Route::middleware(['plane:console', 'platform.guest'])->group(function (): void {
    Volt::route('/login', 'auth.login')->name('login');
    Volt::route('/o/{slug}/login', 'auth.login')->name('login.branded');
    Route::get('/magic/{token}', [MagicLinkController::class, 'redeem'])->name('magic.redeem');

    // Password reset — request a link, then choose a new password from the token.
    // Explicitly closed to an impersonator (the guest guard already bounces an
    // authenticated subject, but a credential change must be a provable no-op).
    Volt::route('/forgot-password', 'auth.forgot-password')->middleware(BlockDuringImpersonation::class)->name('password.request');
    Volt::route('/reset-password/{token}', 'auth.reset-password')->middleware(BlockDuringImpersonation::class)->name('password.reset');

    // Passkey (WebAuthn) sign-in — no session required; the assertion is the proof.
    Route::post('/passkeys/login/options', [PasskeyController::class, 'loginOptions'])->name('passkeys.login.options');
    Route::post('/passkeys/login', [PasskeyController::class, 'login'])->name('passkeys.login');

    // Social sign-in (Google, GitHub, Microsoft) over OAuth.
    Route::get('/auth/{provider}/redirect', [SocialController::class, 'redirect'])->name('social.redirect');
    Route::get('/auth/{provider}/callback', [SocialController::class, 'callback'])->name('social.callback');
});

// The MFA challenge sits between password and a full session, so it is neither
// fully guest nor fully authenticated.
Volt::route('/mfa', 'auth.mfa')->name('mfa');

// The adaptive-risk step-up (emailed one-time code) sits in the same interstitial
// state: primary auth passed, but an elevated risk assessment demands a second
// factor before the session is established.
Volt::route('/login/step-up', 'auth.otp-step-up')->name('login.step-up');

// Invitation acceptance — the token is the proof; accepting signs the invitee in.
// Blocked during impersonation (defense-in-depth: never mutate account state, and
// never re-establish a session, while acting as someone).
Route::get('/invitations/{token}/accept', [InvitationController::class, 'accept'])->middleware(BlockDuringImpersonation::class)->name('invitation.accept');

// Email verification — the token is the proof; clickable while signed in or out.
Route::get('/verify-email/{token}', [EmailVerificationController::class, 'verify'])->middleware(BlockDuringImpersonation::class)->name('verification.verify');

Route::post('/logout', [SessionController::class, 'destroy'])->name('logout');

// Exit impersonation. Gated on the marker (not operator auth) inside the
// controller — while impersonating the browser is purely the subject, with no
// operator key to authenticate against. CSRF-protected via the web group.
Route::post('/impersonation/exit', [ImpersonationController::class, 'exit'])->name('impersonation.exit');

/*
 * Interactive OIDC/OAuth consent — Cbox ID as an identity provider.
 *
 * Deliberately OUTSIDE `platform.auth`. OIDC Core §3.1.2.6 requires an unauthenticated
 * `prompt=none` request to return `error=login_required` TO THE CLIENT, and an auth
 * middleware redirects to /login before the component can answer. That broke silent
 * renew outright: oidc-client-ts, angular-auth-oidc-client and @auth0/auth0-spa-js all
 * load /authorize?prompt=none in a hidden iframe and await a postMessage — instead they
 * got the login page framed (or blocked by X-Frame-Options), the promise never resolved,
 * and the SPA signed the user out on every token refresh.
 *
 * The component authenticates for itself: prompt=none answers the client, anything else
 * redirects to sign-in with the intended URL preserved.
 */
$authorize = Volt::route('/oauth/authorize', 'oauth.consent')
    // `platform.auth:optional` RESOLVES the signed-in subject without requiring one.
    // Removing the middleware outright was wrong: CurrentUser is populated only there,
    // so check() was permanently false and NO authorization code could be issued.
    // BlockDuringImpersonation because this endpoint MINTS CREDENTIALS. Every other
    // credential-establishing route carries it — password reset, invitation, email
    // verification, sudo, org switch, passkey registration, social connect — and this
    // one issues the longest-lived credential of the set: a refresh token that
    // outlives both the impersonation window and the operator's session, attributed
    // to the person being impersonated.
    ->middleware(['plane:issuer', EnforceImpersonationWindow::class, BlockDuringImpersonation::class, 'platform.auth:optional'])
    ->name('oauth.authorize');

/*
 * The SAME component over POST. OIDC Core §3.1.2.1: "The Authorization Server MUST
 * support the use of the HTTP GET and POST methods." Volt::route registers GET only, so
 * a form-POST to /authorize answered 405 — and form-POST is how a client sends a
 * `request` object, a `claims` payload or a long `login_hint` that will not survive a
 * URL length limit. It is also exercised by the OpenID basic-certification suite.
 *
 * CSRF-exempt by definition: the POST arrives cross-site from the relying party, which
 * has no Laravel token to send. Nothing is minted on this request — the component
 * re-validates client, redirect_uri, scope and PKCE from scratch, exactly as it does on
 * the GET path.
 */
$consentAction = $authorize->getAction('uses');

if (! is_string($consentAction) && ! is_callable($consentAction)) {
    // Volt builds this action itself; if its shape ever changes, the POST binding must
    // be rebuilt deliberately rather than silently registering something unroutable.
    throw new RuntimeException('The Volt route action is no longer a callable — /oauth/authorize POST needs updating.');
}

Route::post('/oauth/authorize', $consentAction)
    ->middleware(['plane:issuer', EnforceImpersonationWindow::class, BlockDuringImpersonation::class, 'platform.auth:optional'])
    ->name('oauth.authorize.post');

/*
 * Admin Portal — a single-use setup link. An external IT admin opens it with
 * NO platform account and configures one org's SSO/SCIM, nothing else. These live
 * in the guest area and must never be reachable via a platform session; the
 * scoped portal session (distinct key) is the only thing that unlocks /setup.
 *
 * `plane:console`, which is where the link is minted: /connections is a console page, so
 * the URL is always generated on the host whose console minted it, and redeemed on the
 * same one. That now includes the platform root, and correctly — the root has
 * organizations of its own with connections of their own, and until it had a console it
 * could mint a portal link nobody could open. The environment scope on the token's
 * organization is the real boundary and is unchanged: a tenant's link resolves to nothing
 * on another host, root included.
 */
Route::middleware('plane:console')->group(function (): void {
    Route::view('/setup/expired', 'portal.expired')->name('portal.expired');
    Volt::route('/setup', 'portal.setup')->middleware('portal.session')->name('portal.setup');
    Route::get('/setup/{token}', [AdminPortalController::class, 'enter'])->name('portal.enter');
});

/*
 * The authenticated console — every host, on `plane:console`.
 *
 * It used to be `plane:subject`, which meant "every host except the platform root", so a
 * person who signed in at the root had nowhere to land: `/dashboard`, `/account`,
 * `/settings` — the whole console — 404'd there. The root is a tenant, and its subjects
 * get the same console every other tenant's do. What the root still does not serve is the
 * IdP protocol surface (`plane:issuer`) and the environment-admin door
 * (`plane:environment`); those are different questions and are now asked as such.
 */
Route::middleware(['plane:console', EnforceImpersonationWindow::class, 'platform.auth'])->group(function (): void {
    Volt::route('/dashboard', 'dashboard')->name('dashboard');

    // The guided first run. Deliberately NOT in the nav registry: it is where a fresh
    // organization is sent once, and where the dashboard checklist links back to —
    // an entry that would sit there dead for the rest of the org's life is clutter.
    Volt::route('/get-started', 'get-started')->name('get-started');

    // Multi-account: choose/switch among accounts signed in on this browser, or add
    // another. /accounts/add reuses the login screen but for an already-authenticated
    // user, so a new sign-in is ADDED (a switchable account) rather than replacing.
    Volt::route('/accounts', 'auth.accounts')->name('accounts');
    Volt::route('/accounts/add', 'auth.login')->name('accounts.add');

    // The forced password change. Inside the authenticated group on purpose: the hold
    // that sends people here (see {@see \App\Http\Middleware\Authenticate}) exempts this
    // one route, so it is reachable only by someone who is signed in and owes a change.
    Volt::route('/password/change', 'auth.change-password')->name('password.change');

    // The social link confirmation. Same shape as the password hold above and for the
    // same reason: reachable only by someone signed in who has an identity waiting on
    // their answer, and exempt from the hold so the redirect cannot loop.
    Volt::route('/link/confirm', 'auth.link-confirm')->name('link.confirm');

    // My account — every user's self-service security center (password, 2FA,
    // passkeys, sessions). Available to members and admins alike.
    Volt::route('/account', 'account')->name('account');

    Volt::route('/usage', 'usage')->name('usage');
    Volt::route('/members', 'members')->name('members');

    /*
     * IDENTITY PLATFORM — what an organization has because it OWNS identity providers.
     *
     * This was `/workspace`, a console of its own with its own prefix, its own shell and
     * its own sign-in. None of that was a plane: an account is an organization in tenant 1
     * that happens to own IdPs, and everything below is the same console page it always
     * was, standing in the one console beside the tenant's own.
     *
     * No `plane:account` gate, and that is the point of the move. The host decides which
     * SURFACES exist; whether an organization owns identity providers is a question about
     * the organization, and it has an answer on every host — no on all but the root's own
     * accounts. {@see \App\Platform\Console\ConsoleScope::accountRole()} is that answer,
     * and it is what the rail reads, so on `acme.cboxid.com` the area is simply absent
     * rather than gated: the same mechanism that already drops an area with no pages.
     */
    Volt::route('/projects', 'console.projects.index')->name('projects');
    Volt::route('/projects/new', 'console.projects.create')->name('projects.create');
    Volt::route('/projects/{project}', 'console.projects.show')->name('projects.show');

    // Open an environment → signed handoff → its own admin console (no second login).
    Route::get('/open/{environment}', [EnvironmentHandoffController::class, 'openEnvironment'])->name('environment.open');

    Volt::route('/account-members', 'console.account-members')->name('account-members');
    Volt::route('/api-keys', 'console.api-keys')->name('api-keys');
    Volt::route('/environment-keys', 'console.environment-keys')->name('environment-keys');
    Volt::route('/environment-domains', 'console.environment-domains')->name('environment-domains');
    Volt::route('/account-activity', 'console.account-activity')->name('account-activity');
    Volt::route('/billing', 'console.billing')->name('billing');
    Volt::route('/account-settings', 'console.account-settings')->name('account-settings');
    // Single sign-on: the SAME components the environment plane serves. The routable
    // index/new/show shape wins over the organization plane's single page — a connection
    // URL is something you send to whoever runs the identity provider — and this plane
    // gains the edit, disable and delete it never had, while domain verification and the
    // Admin Portal invite come with it onto the environment plane.
    Volt::route('/connections', 'console.connections.index')->name('connections');
    Volt::route('/connections/new', 'console.connections.create')->name('connections.create');
    Volt::route('/connections/{connection}', 'console.connections.show')->name('connections.show');

    // The provider catalogue — Google, GitHub, Apple and the rest, per tenant. A sibling
    // of Single sign-on rather than a section inside it: connecting the company's own
    // identity provider and offering consumer accounts as buttons are different jobs,
    // done by different people, at different times.
    Volt::route('/social-providers', 'social-providers')->name('social-providers');

    // Sync users in (inbound directories): the SAME components the environment plane
    // serves. The routable index/new/show shape wins over the organization plane's single
    // page — a directory URL is something you send to whoever runs the identity provider,
    // and the reveal-once bearer token needs somewhere to land that is not the row you
    // just submitted. This plane gains rename, pause, rotate and delete with it; the
    // environment plane gains the two pull providers it never had.
    Volt::route('/directories', 'console.directories.index')->name('directories');
    Volt::route('/directories/new', 'console.directories.create')->name('directories.create');
    Volt::route('/directories/{directory}', 'console.directories.show')->name('directories.show');
    // Roles: the SAME components the environment plane serves. The routable index/new/show
    // shape wins over the organization plane's single page — a role URL is something you
    // send to whoever owns the access — and this plane gains rename, delete and permission
    // editing with it, none of which a tenant admin could do to their own roles before.
    Volt::route('/roles', 'console.roles.index')->name('roles');
    Volt::route('/roles/new', 'console.roles.create')->name('roles.create');
    Volt::route('/roles/{role}', 'console.roles.show')->name('roles.show');
    // Apps & API keys (OAuth clients): the SAME components the environment plane serves.
    // The routable index/new/show shape wins over the organization plane's single page —
    // an app has a lifecycle worth linking to, and the reveal-once client secret needs
    // somewhere to land that is not the form you just submitted. This plane gains editing
    // an app's details and rotating its secret with it; the other gains the roles manifest.
    Volt::route('/clients', 'console.clients.index')->name('clients');
    Volt::route('/clients/new', 'console.clients.create')->name('clients.create');
    Volt::route('/clients/{client}', 'console.clients.show')->name('clients.show');
    // Webhooks: the SAME components the environment plane serves. The routable
    // index/new/show shape wins over the organization plane's single page with its inline
    // form, and this plane gains resume, secret rotation, subscription editing and delete
    // with it — a tenant admin who paused an endpoint previously had no way to start it
    // again, and none at all to re-key one whose secret had leaked.
    Volt::route('/webhooks', 'console.webhooks.index')->name('webhooks');
    Volt::route('/webhooks/new', 'console.webhooks.create')->name('webhooks.create');
    Volt::route('/webhooks/{webhook}', 'console.webhooks.show')->name('webhooks.show');
    // Activity log: the SAME component the environment plane serves. The row scoping is
    // what differs per plane and the component asks ConsoleScope for it — an
    // organization's trail is never another's.
    Volt::route('/audit', 'console.audit')->name('audit');
    // Settings: the SAME component the environment plane serves. The organization's own
    // record is on both planes, bounded by whichever organization the scope resolves; the
    // environment's identity is on the environment plane alone.
    Volt::route('/settings', 'console.settings')->name('settings');
    // Appearance: the SAME component the environment plane serves. What is being
    // themed — an organization's own sign-in, or the environment default every
    // organization inherits — is an explicit choice on the page, offered on the
    // environment plane alone.
    Volt::route('/appearance', 'console.appearance')->name('appearance');
    // Sign-in rules: the SAME component the environment plane serves, and the half of
    // this pair that never existed. `AuthPolicies::setForOrganization()` had no caller
    // anywhere in the product while both sign-in doors enforced what it writes, so a
    // tenant could be governed by a per-organization policy that nobody — not even the
    // operator — had a way to author.
    Volt::route('/sign-in-rules', 'console.auth-policy')->name('auth-policy');

    // Access governance (IGA): certification reviews + Segregation-of-Duties policies.
    // The SAME components the environment plane serves. The routable index/new/show
    // shape wins over the organization plane's single page: a campaign URL is something
    // you send to a reviewer, and losing it would be a real regression.
    Volt::route('/governance', 'console.governance.index')->name('governance');
    Volt::route('/governance/new', 'console.governance.create')->name('governance.create');
    Volt::route('/governance/{campaign}', 'console.governance.show')->name('governance.show');
    Volt::route('/sod-policies', 'console.sod-policies.index')->name('sod-policies');
    Volt::route('/sod-policies/new', 'console.sod-policies.create')->name('sod-policies.create');
    Volt::route('/sod-policies/{policy}', 'console.sod-policies.show')->name('sod-policies.show');

    // Outbound SCIM provisioning connections (push users OUT to downstream apps).
    // The SAME components the environment plane serves. The routable index/new/show
    // shape wins over the organization plane's single page with its inline form, and
    // this plane gains resume and delete with it — a tenant admin who paused a
    // connection previously had no way to start it again from their own console.
    Volt::route('/provisioning', 'console.provisioning.index')->name('provisioning');
    Volt::route('/provisioning/new', 'console.provisioning.create')->name('provisioning.create');
    Volt::route('/provisioning/{sync}', 'console.provisioning.show')->name('provisioning.show');

    // AI token vault — the SAME components the environment plane serves, on the routable
    // index/new/show shape. Storing, rotating and granting a downstream credential is
    // sensitive, so every page is behind the sudo step-up gate — as is its mirror on the
    // environment plane, which for a long time had no step-up at all.
    Volt::route('/vault', 'console.vault.index')->middleware('sudo')->name('vault');
    Volt::route('/vault/new', 'console.vault.create')->middleware('sudo')->name('vault.create');
    Volt::route('/vault/{secret}', 'console.vault.show')->middleware('sudo')->name('vault.show');
    // Inline hooks: the SAME components the environment plane serves. The routable
    // index/new/show shape wins over the organization plane's single page — an endpoint
    // has a lifecycle worth linking to, and the one-time signing secret needs somewhere
    // to land that is not the row you just submitted.
    Volt::route('/hooks', 'console.hooks.index')->name('hooks');
    Volt::route('/hooks/new', 'console.hooks.create')->name('hooks.create');
    Volt::route('/hooks/{hook}', 'console.hooks.show')->name('hooks.show');

    // SIEM audit-stream export.

    // Agent approvals (OIDC CIBA): where a signed-in user approves/denies a
    // backchannel request an agent started on their behalf.
    Volt::route('/approvals', 'approvals')->name('approvals');

    // RFC 8628 device grant: where a signed-in user approves a device's user_code.
    Volt::route('/device', 'device')->name('device');

    // Step-up re-authentication ("sudo mode") gate for sensitive actions. Blocked
    // while impersonating: an impersonator must never be able to clear the gate
    // that protects credential changes.
    Volt::route('/sudo', 'auth.sudo')->middleware(BlockDuringImpersonation::class)->name('sudo');

    // Blocked while impersonating: the subject session is pinned to the one org the
    // operator was authorized to enter. Pivoting to another of the subject's orgs
    // would escape that scope, so it is an unambiguous 403 (not a silent no-op).
    Route::post('/organization/switch', [SessionController::class, 'switchOrganization'])->middleware(BlockDuringImpersonation::class)->name('organization.switch');

    // Passkey enrolment (adds a credential to the signed-in subject). Adding a
    // credential is persistence — gate it behind a fresh step-up, symmetric with
    // the sudo required to REMOVE a passkey in settings. BlockDuringImpersonation
    // runs first so an impersonator gets an unambiguous 403, never a step-up prompt.
    Route::post('/passkeys/register/options', [PasskeyController::class, 'registerOptions'])->middleware([BlockDuringImpersonation::class, 'sudo'])->name('passkeys.register.options');
    Route::post('/passkeys/register', [PasskeyController::class, 'register'])->middleware([BlockDuringImpersonation::class, 'sudo'])->name('passkeys.register');

    // Explicit account linking — connect a social provider to the signed-in user.
    // Also a new way in, so it likewise requires a fresh step-up (and is closed to
    // an impersonator).
    Route::get('/settings/connect/{provider}/redirect', [SocialController::class, 'connect'])->middleware([BlockDuringImpersonation::class, 'sudo'])->name('social.connect');
    // The link is a NEW, durable sign-in method, established here at callback time —
    // gate it with the same fresh step-up + impersonation bulkhead as the start, so a
    // flow begun under sudo can't complete after it lapses, and an impersonator can't
    // plant a provider link.
    Route::get('/settings/connect/{provider}/callback', [SocialController::class, 'connectCallback'])
        ->middleware([BlockDuringImpersonation::class, 'sudo'])
        ->name('social.connect.callback');
});

/*
|--------------------------------------------------------------------------
| Environment ADMIN door (infrastructure — not yet gating the console).
|--------------------------------------------------------------------------
|
| The account-layer path into a tenant environment's admin: redeem the signed
| account→environment handoff. There is no credential form here — signing in is
| unified on the account plane, and a tenant-controlled host is the last place
| account credentials should be typed.
|
| `plane:environment`, not `plane:console`, and the distinction is what the plane split
| bought: this is a door an ACCOUNT opens INTO an environment from the outside, so it is
| absent on the account plane itself. Left on `plane:console` it would have appeared on
| the platform root when the console did — a second admin console over the root
| environment, which is the operator area's job, reachable only through a handoff nobody
| can mint because the root environment belongs to no account.
*/
Route::middleware(['plane:environment', 'multi.tenant'])->prefix('admin')->group(function (): void {
    Route::get('/handoff', [EnvironmentAdminController::class, 'handoff'])->name('admin.handoff');
    Route::post('/logout', [EnvironmentAdminController::class, 'logout'])->name('admin.logout');

    // The ENVIRONMENT control plane — the account-member admin's env-scoped console
    // (organizations, users, connections…). Gated by an env-admin session; a subject
    // session grants nothing here.
    Route::middleware('env.admin')->group(function (): void {
        // `admin.login` is the name every "send them to the door" redirect uses — the
        // handoff's refusals, logout, the sudo screen. It is BEHIND the gate on purpose:
        // the gate IS the door now. Unauthenticated, `AuthenticateEnvironmentAdmin`
        // bounces to the account host's open-environment handoff; already signed in,
        // there is nothing to sign into and the console's front page is where they meant
        // to be. It used to be a Volt credential form that no correctly-configured
        // deployment could reach — single-tenant 404s this whole prefix, and multi-tenant
        // redirected away from it in mount() — so it was a second account-credential
        // store openable only on a deployment that is already
        // {@see App\Platform\PlaneResolver::misconfigured()}, a state the first-run screen
        // and `cbox-id:doctor` both already name and refuse to install into.
        Route::get('/login', fn () => redirect()->route('environment.home'))->name('admin.login');

        Volt::route('/', 'environment.home')->name('environment.home');

        // Organizations — routable list → create → detail (deep-linkable).
        Volt::route('/organizations', 'environment.organizations.index')->name('environment.organizations');
        Volt::route('/organizations/new', 'environment.organizations.create')->name('environment.organizations.create');
        Volt::route('/organizations/{organization}', 'environment.organizations.show')->name('environment.organizations.show');

        // Users — routable list → create → detail.
        Volt::route('/users', 'environment.users.index')->name('environment.users');
        Volt::route('/users/new', 'environment.users.create')->name('environment.users.create');
        Volt::route('/users/{user}', 'environment.users.show')->name('environment.users.show');

        // SSO connections — routable list → create → detail, on the merged component. The
        // URL keeps its old spelling so existing links and bookmarks still resolve; the
        // route names are what the two planes disagree on, and both are preserved.
        Volt::route('/single-sign-on', 'console.connections.index')->name('environment.connections');

        // The SAME component the organization plane serves. It shipped on one plane only
        // — reachable, but not by the person who owns the environment, who holds an
        // account session rather than a subject one and would have had to impersonate one
        // of their own users to reach their own feature.
        Volt::route('/social-sign-in', 'social-providers')->name('environment.social-providers');
        Volt::route('/single-sign-on/new', 'console.connections.create')->name('environment.connections.create');
        Volt::route('/single-sign-on/{connection}', 'console.connections.show')->name('environment.connections.show');

        // Login methods (SAML service providers) — routable list → create → detail.
        Volt::route('/login-methods', 'environment.sso-providers.index')->name('environment.sso-providers');
        Volt::route('/login-methods/new', 'environment.sso-providers.create')->name('environment.sso-providers.create');
        Volt::route('/login-methods/{provider}', 'environment.sso-providers.show')->name('environment.sso-providers.show');

        // Sync users in — routable list → create → detail, on the merged component. The
        // URL keeps its old spelling so existing links and bookmarks still resolve; the
        // route names are what the two planes disagree on, and both are preserved.
        Volt::route('/directories', 'console.directories.index')->name('environment.directories');
        Volt::route('/directories/new', 'console.directories.create')->name('environment.directories.create');
        Volt::route('/directories/{directory}', 'console.directories.show')->name('environment.directories.show');

        // Outbound sync (provisioning connections) — routable list → create → detail.
        Volt::route('/outbound-sync', 'console.provisioning.index')->name('environment.provisioning');
        Volt::route('/outbound-sync/new', 'console.provisioning.create')->name('environment.provisioning.create');
        Volt::route('/outbound-sync/{sync}', 'console.provisioning.show')->name('environment.provisioning.show');

        // Roles — routable list → create → detail (permission editor), on the merged
        // component. The route names are what the two planes disagree on, and both are
        // preserved.
        Volt::route('/roles', 'console.roles.index')->name('environment.roles');
        Volt::route('/roles/new', 'console.roles.create')->name('environment.roles.create');
        Volt::route('/roles/{role}', 'console.roles.show')->name('environment.roles.show');

        // Permissions — the catalog roles draw from. App-declared permissions arrive
        // via an app's manifest (SDK/API); manual ones are authored here for orgs that
        // don't run an SDK integration.
        Volt::route('/permissions', 'environment.permissions.index')->name('environment.permissions');

        // Access reviews (certification campaigns) — routable list → create → detail.
        Volt::route('/access-reviews', 'console.governance.index')->name('environment.governance');
        Volt::route('/access-reviews/new', 'console.governance.create')->name('environment.governance.create');
        Volt::route('/access-reviews/{campaign}', 'console.governance.show')->name('environment.governance.show');

        // Conflict rules (segregation-of-duties) — routable list → create → detail.
        Volt::route('/conflict-rules', 'console.sod-policies.index')->name('environment.sod-policies');
        Volt::route('/conflict-rules/new', 'console.sod-policies.create')->name('environment.sod-policies.create');
        Volt::route('/conflict-rules/{policy}', 'console.sod-policies.show')->name('environment.sod-policies.show');

        // Apps & API keys (OAuth clients) — routable list → create → detail, on the
        // merged component. The URLs keep their old spelling so existing links and
        // bookmarks still resolve; the route names are what the two planes disagree on,
        // and both are preserved.
        Volt::route('/applications', 'console.clients.index')->name('environment.clients');
        Volt::route('/applications/new', 'console.clients.create')->name('environment.clients.create');
        Volt::route('/applications/{client}', 'console.clients.show')->name('environment.clients.show');

        // Webhooks — routable list → create → detail, on the merged component. The URLs
        // are unchanged so existing links and bookmarks still resolve; the route names
        // are what the two planes disagree on, and both are preserved.
        Volt::route('/webhooks', 'console.webhooks.index')->name('environment.webhooks');
        Volt::route('/webhooks/new', 'console.webhooks.create')->name('environment.webhooks.create');
        Volt::route('/webhooks/{webhook}', 'console.webhooks.show')->name('environment.webhooks.show');
        // Inline hooks — routable list → create → detail, on the merged component. The
        // URL keeps its old spelling so existing links and bookmarks still resolve; the
        // route names are what the two planes disagree on, and both are preserved.
        Volt::route('/event-hooks', 'console.hooks.index')->name('environment.hooks');
        Volt::route('/event-hooks/new', 'console.hooks.create')->name('environment.hooks.create');
        Volt::route('/event-hooks/{hook}', 'console.hooks.show')->name('environment.hooks.show');

        // Token vault — routable list → create → detail, on the merged component. The URL
        // keeps its old spelling so existing links and bookmarks still resolve; the route
        // names are what the two planes disagree on, and both are preserved.
        //
        // BEHIND `env.sudo`, which did not exist until this pair was merged. These pages
        // rotate, re-grant and revoke downstream provider credentials for any organization
        // in the environment, and the identical actions on the organization plane have
        // always demanded a fresh password. The asymmetry meant the more privileged door
        // was the one with no step-up behind it.
        Volt::route('/stored-tokens', 'console.vault.index')->middleware('env.sudo')->name('environment.vault');
        Volt::route('/stored-tokens/new', 'console.vault.create')->middleware('env.sudo')->name('environment.vault.create');
        Volt::route('/stored-tokens/{secret}', 'console.vault.show')->middleware('env.sudo')->name('environment.vault.show');

        // Step-up re-authentication for this plane. Inside the env-admin group — only an
        // administrator has anything to step up FROM — but never behind `env.sudo` itself,
        // which would be a gate in front of its own key.
        //
        // BlockDuringImpersonation, which both sibling step-ups carry and this one was
        // missing: an impersonator must never be able to CLEAR the gate that protects
        // credential changes. Clearing it here would have opened the widest of the three
        // planes, where one confirmation covers every tenant in the environment.
        Volt::route('/sudo', 'environment.sudo')->middleware(BlockDuringImpersonation::class)->name('environment.sudo');

        // Activity log — the merged component. The route NAME is preserved on both
        // planes; only the component behind it is now shared.
        Volt::route('/audit', 'console.audit')->name('environment.audit');

        // Log streaming (SIEM) — routable list → create → detail.
        //
        // The create page is BEHIND `env.sudo`, because registering a stream mints its
        // HMAC signing key (or echoes the token you supply) and reveals it once, which is
        // the same class of credential every other console create page now asks for a
        // password before minting. Gated on the ROUTE rather than in the component, and
        // deliberately so: this page is the environment plane's alone — it is not one of
        // the merged pair-per-plane components — and ConsoleStepUp resolves the plane from
        // the session, which answers "organization" for a browser that happens to hold a
        // subject session on this host. Naming the plane here is the only way to be sure
        // the widest of the three windows is the one that has to be open.
        Volt::route('/log-streaming', 'environment.audit-streams.index')->name('environment.audit-streams');
        Volt::route('/log-streaming/new', 'environment.audit-streams.create')->middleware('env.sudo')->name('environment.audit-streams.create');
        Volt::route('/log-streaming/{stream}', 'environment.audit-streams.show')->name('environment.audit-streams.show');
        Volt::route('/analytics', 'environment.analytics')->name('environment.analytics');
        Volt::route('/approvals', 'environment.approvals')->name('environment.approvals');
        // Settings — the merged component. The route NAME is preserved on both planes;
        // only the component behind it is now shared.
        Volt::route('/settings', 'console.settings')->name('environment.settings');
        // Sign-in rules — the merged component. This plane writes the BASELINE every
        // organization inherits; the organization plane writes one organization's
        // override. Same page, same controls, different level — which is the only
        // difference there has ever been between the two, and until now the organization
        // half of it had no surface at all.
        Volt::route('/sign-in-rules', 'console.auth-policy')->name('environment.auth-policy');
        // Appearance — the merged component. The route NAME is preserved on both
        // planes; only the component behind it is now shared.
        Volt::route('/appearance', 'console.appearance')->name('environment.appearance');

        // Step into a subject's session for support (env-admin actor). Authorized in
        // the controller by env-scoped membership; owners/admins refused; reason required.
        Route::post('/impersonate/{user}', [ImpersonationController::class, 'startAsEnvAdmin'])->name('environment.impersonate');
    });
});

/*
|--------------------------------------------------------------------------
| Platform section — the pages that need authority over the deployment.
|--------------------------------------------------------------------------
|
| Not a console. A SECTION of the one console, and the distinction is the whole
| point: these pages sign in through the same door, render in the same shell and
| appear in the same rail as every other page — they are simply the ones that
| need authority over the deployment, the way Billing needs authority over an
| account.
|
| No plane gate. Every other group here asks which host it is on, because the
| host decides whether a surface exists. This one asks who you are, which is a
| question with the same answer everywhere, so the section is reachable from
| whichever console a person is standing in.
*/
/*
 * Bookmarks. `/operator` was this section's address until the pages stopped being a
 * console of their own, and `/operator/login` was a door of its own before that. Kept as
 * redirects rather than deleted, for the reason the login redirect was kept when the door
 * went: somebody who has had one of these in their bar for a year should land on the page
 * that works, not on a 404 that reads as "it moved and nobody said where".
 *
 * No plane gate, matching the section they point at — and they disclose nothing a
 * `Location` header to a public sign-in does not.
 */
Route::redirect('/operator', '/platform');
Route::redirect('/operator/login', '/login');
Route::redirect('/operator/login/mfa', '/login');

Route::prefix('platform')->group(function (): void {
    // `platform.auth:optional` RESOLVES the one session without requiring one, then
    // AuthenticateOperator asks whether the person it resolved runs this deployment.
    // Both are needed and in this order — the authority question is asked of CurrentUser,
    // which only the first populates.
    //
    // Optional, not required, because the two refusals are different and only the gate
    // knows which is which: no session at all is a step the visitor can take (the sign-in
    // page), while a session that simply is not an operator's must 404 rather than
    // confirm that this deployment has a staff console at that address. A required
    // `platform.auth` would answer the first case itself, with the subject plane's
    // redirect, and the gate's own reasoning would never run.
    //
    // TargetEnvironment comes last: the console's chosen plane must not be ambient while
    // the operator's own (platform-root) session is being looked up.
    Route::middleware(['platform.auth:optional', AuthenticateOperator::class, TargetEnvironment::class])->group(function (): void {
        Volt::route('/', 'platform.environments')->name('platform.environments');
        Volt::route('/usage', 'platform.usage')->name('platform.usage');
        Volt::route('/search', 'platform.search')->name('platform.search');
        Volt::route('/accounts', 'platform.accounts')->name('platform.accounts');

        // `platform.accounts.show`, not `platform.account`. The console derives both the
        // eyebrow above the page title and the lit rail entry from the route name by the
        // same prefix rule ({@see \App\Platform\Navigation\NavPage::owns()}), so a detail
        // page named as a CHILD of its list gets "Platform" over its heading and keeps
        // Accounts lit in the rail without a single hand-written label. `platform.organization`
        // predates that rule and has to pass its own eyebrow; this one does not.
        Volt::route('/accounts/{account}', 'platform.account')->name('platform.accounts.show');
        Volt::route('/organizations', 'platform.organizations')->name('platform.organizations');
        Volt::route('/organizations/{organization}', 'platform.organization')->name('platform.organization');
        Volt::route('/operators', 'platform.operators')->name('platform.operators');
        Volt::route('/security', 'platform.security')->name('platform.security');
        Route::post('/environment/switch', [OperatorController::class, 'switchEnvironment'])->name('platform.environment.switch');

        // Support impersonation — step into a tenant member's session. Authorized by
        // membership in the operator's currently-pinned plane (see the controller).
        Route::post('/impersonate/{user}', [ImpersonationController::class, 'start'])->name('platform.impersonate');

        // Cross-plane jump: a search result lives in some plane B; the tenant detail
        // page is plane-scoped, so we first re-point the console at the result's
        // environment, then hand off to the (now in-plane) org detail page.
        Route::get('/search/jump/{organization}', [OperatorController::class, 'jumpToOrganization'])->name('platform.search.jump');
    });
});

/*
|--------------------------------------------------------------------------
| Account invitations — the one door the account plane still owns.
|--------------------------------------------------------------------------
|
| Everything else that lived under `/workspace` is gone: the console pages moved into
| the one console, and the sign-in doors were duplicates of doors that already serve on
| every host — `/login`, `/mfa`, `/forgot-password`, `/reset-password/{token}`,
| `/password/change`, `/sudo`. An account member is an ordinary subject in the platform
| root and the SUBJECT is the credential of record, so those doors were the same doors
| wearing account clothes.
|
| This one is not a duplicate, and it cannot become one yet. An account invitation is
| its own aggregate — a signed link naming an `AccountMember` row with an `AccountRole`
| — while `/invitations/{token}/accept` mints an organization `Membership`. Reconciling
| them means folding AccountMember into Membership, which is the next step and a data
| migration, not a route change. So it keeps its own door and loses only its prefix.
|
| `plane:console`, like every other door: the host decides which surfaces exist, and
| this one exists wherever the console does.
*/
Route::middleware('plane:console')->group(function (): void {
    // Guest-accessible but gated by a signed URL (the token IS the signature; no token
    // table needed). The invitee sets their password and is signed in. The component
    // locks the member id so it cannot be swapped after the signed load.
    Volt::route('/invite/{member}/accept', 'auth.accept-account-invite')
        ->middleware('signed')
        ->name('account.invite.accept');
});

/*
 * Bookmarks into the retired `/workspace` prefix.
 *
 * Two, not thirty. Nobody is running against this yet, so a path is preserved only where
 * a link plausibly exists OUTSIDE the browser — the console root somebody pinned, and the
 * sign-in address that has been in mails and docs. Everything else is deleted rather than
 * redirected: a redirect table is a second, silent statement of what the console contains,
 * and it outlives the memory of why each line is there.
 *
 * No plane gate, matching the pages they point at, and they disclose nothing a `Location`
 * header to a public sign-in does not.
 */
Route::redirect('/workspace', '/projects');
Route::redirect('/workspace/login', '/login');
