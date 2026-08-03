<?php

declare(strict_types=1);

use App\Http\Controllers\AdminPortalController;
use App\Http\Controllers\ConsoleOrganizationController;
use App\Http\Controllers\EmailVerificationController;
use App\Http\Controllers\EnvironmentAdminController;
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
use App\Http\Controllers\WorkspaceController;
use App\Http\Controllers\WorkspacePasskeyController;
use App\Http\Middleware\AuthenticateAccountMember;
use App\Http\Middleware\AuthenticateOperator;
use App\Http\Middleware\BlockDuringImpersonation;
use App\Http\Middleware\EnforceImpersonationWindow;
use App\Http\Middleware\RequireWorkspaceSudo;
use App\Platform\AccountAuth;
use App\Platform\PlatformAuth;
use Cbox\Id\Kernel\Tenancy\Contracts\EnvironmentContext;
use Cbox\Id\Kernel\Tenancy\Contracts\EnvironmentResolver;
use Illuminate\Support\Facades\Route;
use Livewire\Volt\Volt;

/*
 * The root is plane-aware, but ONLY in the multi-tenant SaaS shape. When
 * `base_domains` is set (e.g. cboxid.com), the platform-root (is_default) host is the
 * ACCOUNT door — sign in / sign up as an account member to create and manage your IdP
 * — and a tenant's OWN subdomain serves the subject/tenant plane. In the single-tenant
 * / self-hosted shape (no `base_domains`) there is NO account door: the one host is
 * the IdP itself, so the root goes straight to the subject sign-in/dashboard.
 */
Route::get('/', function (EnvironmentContext $environments, EnvironmentResolver $resolver) {
    $bases = config('cbox-id.environments.base_domains', []);
    $multiTenant = is_array($bases) && $bases !== [];

    // The platform-root env — resolved like SetEnvironment: configured default first,
    // else the DB is_default env.
    $configuredDefault = config('cbox-id.environments.default');
    $current = $environments->current()?->environmentKey();
    $default = is_string($configuredDefault) && $configuredDefault !== ''
        ? $configuredDefault
        : $resolver->defaultEnvironment()?->environmentKey();

    if ($multiTenant && $current !== null && $current === $default) {
        return redirect()->route(
            session()->has(AccountAuth::SESSION_KEY) ? 'workspace.home' : 'workspace.login'
        );
    }

    return redirect()->route(
        session()->has(PlatformAuth::SESSION_KEY) ? 'dashboard' : 'login'
    );
})->name('home');

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
 * `plane:subject`, like every other IdP surface: the platform-root host is the account
 * door, not an identity provider, so it must not answer as one. The package's own SAML
 * routes are gated identically via `cbox-id.api.middleware`; these app overrides would
 * otherwise be the one hole left in that wall.
 */
Route::match(['get', 'post'], '/sso/saml/idp/sso', SamlIdpSsoController::class)
    ->middleware('plane:subject')
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
 * `plane:subject` gates the ISSUER surface: a host that is not an identity provider must
 * not advertise or answer as one. Inbound federation is the opposite role (this server as
 * the RELYING party), and the ACCOUNT plane genuinely does it: an account's organization
 * lives in the platform-root environment, so home-realm discovery on `/workspace/login`
 * and `/signup` — both `plane:account`, both root-host only — sends the member to
 * `/sso/{oidc,saml}/{connection}/...` on the very host they are standing on. Gating these
 * 404'd that redirect and its callback, and {@see \App\Platform\FederatedLanding}'s
 * `onAccountPlane()` branch — which exists to land exactly this — became unreachable. An
 * account org with SsoEnforcement::Required and a verified domain was then locked out of
 * its own workspace: the password is refused and the SSO door does not exist.
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
 * Guest — the subject/tenant sign-in surface. `plane:subject` keeps it on tenant
 * subdomains in the SaaS shape; single-tenant serves it on the one host.
 */
Route::middleware(['plane:subject', 'platform.guest'])->group(function (): void {
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
    ->middleware(['plane:subject', EnforceImpersonationWindow::class, BlockDuringImpersonation::class, 'platform.auth:optional'])
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
    ->middleware(['plane:subject', EnforceImpersonationWindow::class, BlockDuringImpersonation::class, 'platform.auth:optional'])
    ->name('oauth.authorize.post');

/*
 * Admin Portal — a single-use setup link. An external IT admin opens it with
 * NO platform account and configures one org's SSO/SCIM, nothing else. These live
 * in the guest area and must never be reachable via a platform session; the
 * scoped portal session (distinct key) is the only thing that unlocks /setup.
 *
 * `plane:subject` is defence in depth on top of the model's environment scope. A link
 * is minted from /connections, which itself lives on the subject plane, so its URL is
 * always generated on the tenant's own host — the very host the external IT admin
 * opens. The account-root host (cboxid.com) can therefore never mint one and has no
 * business redeeming one. In the single-tenant shape the plane gate is a no-op, so the
 * one host keeps serving the whole flow.
 */
Route::middleware('plane:subject')->group(function (): void {
    Route::view('/setup/expired', 'portal.expired')->name('portal.expired');
    Volt::route('/setup', 'portal.setup')->middleware('portal.session')->name('portal.setup');
    Route::get('/setup/{token}', [AdminPortalController::class, 'enter'])->name('portal.enter');
});

/*
 * Authenticated console — the subject/tenant plane. `plane:subject` confines it to a
 * tenant subdomain in the SaaS shape (404 on the account-root host, no bleed); in the
 * single-tenant shape the plane gate is a no-op, so the one host serves it normally.
 */
Route::middleware(['plane:subject', EnforceImpersonationWindow::class, 'platform.auth'])->group(function (): void {
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
    Volt::route('/roles', 'roles')->name('roles');
    Volt::route('/clients', 'clients')->name('clients');
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

    // AI token vault + inline-hook (external action) endpoints. Storing/revealing a
    // secret is sensitive, so the vault is behind the sudo step-up gate.
    Volt::route('/vault', 'vault')->middleware('sudo')->name('vault');
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
| account→environment handoff, or "sign in as admin" against the account layer.
| Wired for the identity model (control-plane admin, never a subject in the env);
| the console re-gate onto `env.admin` follows once the console components are
| moved off the subject session.
*/
Route::middleware('plane:subject')->prefix('admin')->group(function (): void {
    Volt::route('/login', 'admin.login')->name('admin.login');
    Route::get('/handoff', [EnvironmentAdminController::class, 'handoff'])->name('admin.handoff');
    Route::post('/logout', [EnvironmentAdminController::class, 'logout'])->name('admin.logout');

    // The ENVIRONMENT control plane — the account-member admin's env-scoped console
    // (organizations, users, connections…). Gated by an env-admin session; a subject
    // session grants nothing here.
    Route::middleware('env.admin')->group(function (): void {
        // Which organization the environment console is acting on. The one difference
        // between the planes, modelled as a picker beside the environment switcher.
        Route::post('/acting-organization', ConsoleOrganizationController::class)
            ->name('environment.organization.choose');

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

        // Roles — routable list → create → detail (permission editor).
        Volt::route('/roles', 'environment.roles.index')->name('environment.roles');
        Volt::route('/roles/new', 'environment.roles.create')->name('environment.roles.create');
        Volt::route('/roles/{role}', 'environment.roles.show')->name('environment.roles.show');

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

        // Applications (OAuth clients) — routable list → create → detail (secret rotation).
        Volt::route('/applications', 'environment.clients.index')->name('environment.clients');
        Volt::route('/applications/new', 'environment.clients.create')->name('environment.clients.create');
        Volt::route('/applications/{client}', 'environment.clients.show')->name('environment.clients.show');

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

        // Stored tokens (secret vault) — routable list → create → detail.
        Volt::route('/stored-tokens', 'environment.vault.index')->name('environment.vault');
        Volt::route('/stored-tokens/new', 'environment.vault.create')->name('environment.vault.create');
        Volt::route('/stored-tokens/{secret}', 'environment.vault.show')->name('environment.vault.show');

        // Activity log — the merged component. The route NAME is preserved on both
        // planes; only the component behind it is now shared.
        Volt::route('/audit', 'console.audit')->name('environment.audit');

        // Log streaming (SIEM) — routable list → create → detail.
        Volt::route('/log-streaming', 'environment.audit-streams.index')->name('environment.audit-streams');
        Volt::route('/log-streaming/new', 'environment.audit-streams.create')->name('environment.audit-streams.create');
        Volt::route('/log-streaming/{stream}', 'environment.audit-streams.show')->name('environment.audit-streams.show');
        Volt::route('/analytics', 'environment.analytics')->name('environment.analytics');
        Volt::route('/approvals', 'environment.approvals')->name('environment.approvals');
        // Settings — the merged component. The route NAME is preserved on both planes;
        // only the component behind it is now shared.
        Volt::route('/settings', 'console.settings')->name('environment.settings');
        Volt::route('/sign-in-rules', 'environment.auth-policy')->name('environment.auth-policy');
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
| Operator console — platform operators, the identity above every environment.
|--------------------------------------------------------------------------
|
| A separate world from the org-user console: operators provision and switch
| between environments and manage other operators. An org-user session grants
| nothing here, and vice versa.
*/
Route::middleware('plane:operator')->prefix('operator')->group(function (): void {
    Volt::route('/login', 'operator.login')->name('operator.login');

    // The TOTP challenge sits between password and a full operator session, so it
    // is neither guest nor authenticated — the component itself redirects away
    // unless a pending marker is present.
    Volt::route('/login/mfa', 'operator.login-mfa')->name('operator.login.mfa');

    Route::post('/logout', [OperatorController::class, 'logout'])->name('operator.logout');

    Route::middleware(AuthenticateOperator::class)->group(function (): void {
        Volt::route('/', 'operator.environments')->name('operator.environments');
        Volt::route('/usage', 'operator.usage')->name('operator.usage');
        Volt::route('/search', 'operator.search')->name('operator.search');
        Volt::route('/accounts', 'operator.accounts')->name('operator.accounts');
        Volt::route('/organizations', 'operator.organizations')->name('operator.organizations');
        Volt::route('/organizations/{organization}', 'operator.organization')->name('operator.organization');
        Volt::route('/operators', 'operator.operators')->name('operator.operators');
        Volt::route('/security', 'operator.security')->name('operator.security');
        Route::post('/environment/switch', [OperatorController::class, 'switchEnvironment'])->name('operator.environment.switch');

        // Support impersonation — step into a tenant member's session. Authorized by
        // membership in the operator's currently-pinned plane (see the controller).
        Route::post('/impersonate/{user}', [ImpersonationController::class, 'start'])->name('operator.impersonate');

        // Cross-plane jump: a search result lives in some plane B; the tenant detail
        // page is plane-scoped, so we first re-point the console at the result's
        // environment, then hand off to the (now in-plane) org detail page.
        Route::get('/search/jump/{organization}', [OperatorController::class, 'jumpToOrganization'])->name('operator.search.jump');
    });
});

/*
|--------------------------------------------------------------------------
| Workspace console — account members, the customer's buyer/admin plane.
|--------------------------------------------------------------------------
|
| A third world, distinct from both the org-user console (end-users, who
| authenticate INTO an environment) and the operator console (Cbox staff, above
| every account). An account member signs in once at the root and administers
| the environments their account owns — the Account → Environment relationship.
| Neither an org-user nor an operator session grants anything here.
*/
Route::middleware('plane:account')->prefix('workspace')->group(function (): void {
    Volt::route('/login', 'workspace.login')->name('workspace.login');

    // Two-factor challenge — between password and a full session; the component
    // self-guards on the pending marker, so it's neither guest nor authenticated.
    Volt::route('/login/mfa', 'workspace.login-mfa')->name('workspace.login.mfa');

    // Passwordless passkey sign-in (guest — a passkey is strong auth on its own).
    Route::post('/passkeys/login/options', [WorkspacePasskeyController::class, 'loginOptions'])->name('workspace.passkeys.login.options');
    Route::post('/passkeys/login', [WorkspacePasskeyController::class, 'login'])->name('workspace.passkeys.login');

    // Magic-link sign-in on the ACCOUNT plane. The account plane inherits this from the
    // subject plane for free — account members ARE subjects in the platform root — but it
    // needs its own door: /magic/{token} is `plane:subject` and 404s on this host, and
    // the redemption must bridge into an account session rather than a subject one.
    Route::get('/magic/{token}', [MagicLinkController::class, 'redeemForWorkspace'])->name('workspace.magic.redeem');

    // Invitation acceptance — guest-accessible but gated by a signed URL (the token
    // is the signature; no token table needed). The invitee sets their password and
    // is signed in. The component locks the member id so it can't be swapped after
    // the signed load.
    Volt::route('/invite/{member}/accept', 'workspace.accept-invite')
        ->middleware('signed')
        ->name('workspace.invite.accept');

    // Forgot / reset password (guest, reset gated by a signed URL).
    Volt::route('/forgot-password', 'workspace.forgot-password')->name('workspace.password.request');
    Volt::route('/reset-password/{member}', 'workspace.reset-password')
        ->middleware('signed')
        ->name('workspace.password.reset');

    Route::post('/logout', [WorkspaceController::class, 'logout'])->name('workspace.logout');

    Route::middleware(AuthenticateAccountMember::class)->group(function (): void {
        // The account's Projects (IdP products) — the launchpad. Each project holds
        // its own environments + plan; a project opens to its environments detail.
        Volt::route('/', 'workspace.home')->name('workspace.home');
        Volt::route('/projects/new', 'workspace.projects.create')->name('workspace.projects.create');
        Volt::route('/projects/{project}', 'workspace.projects.show')->name('workspace.projects.show');

        // Open an environment → signed handoff → its own admin console (no second login).
        Route::get('/open/{environment}', [WorkspaceController::class, 'openEnvironment'])->name('workspace.environment.open');

        // The forced password change — the one route the temporary-password hold lets
        // through, so it is reachable only by a member who owes one.
        Volt::route('/password/change', 'workspace.change-password')->name('workspace.password.change');

        Volt::route('/members', 'workspace.members')->name('workspace.members');
        Volt::route('/activity', 'workspace.activity')->name('workspace.activity');
        Volt::route('/security', 'workspace.security')->name('workspace.security');

        // Step-up ("sudo") re-authentication for the account plane. Blocked during
        // impersonation so an impersonator can never satisfy it.
        Volt::route('/sudo', 'workspace.sudo')->middleware(BlockDuringImpersonation::class)->name('workspace.sudo');

        // Enrolling a passkey establishes a new, persistent credential — gate it behind
        // a fresh step-up, exactly as the subject plane gates passkey enrolment.
        Route::post('/passkeys/register/options', [WorkspacePasskeyController::class, 'registerOptions'])
            ->middleware([BlockDuringImpersonation::class, RequireWorkspaceSudo::class])
            ->name('workspace.passkeys.register.options');
        Route::post('/passkeys/register', [WorkspacePasskeyController::class, 'register'])
            ->middleware([BlockDuringImpersonation::class, RequireWorkspaceSudo::class])
            ->name('workspace.passkeys.register');
        Volt::route('/api-keys', 'workspace.api-keys')->name('workspace.api-keys');
        Volt::route('/environment-keys', 'workspace.environment-api-keys')->name('workspace.environment-keys');
        Volt::route('/environment-domains', 'workspace.environment-domains')->name('workspace.environment-domains');
        Volt::route('/billing', 'workspace.billing')->name('workspace.billing');
        Volt::route('/settings', 'workspace.settings')->name('workspace.settings');
    });
});
