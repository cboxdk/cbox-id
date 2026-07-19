<?php

declare(strict_types=1);

use App\Http\Controllers\AdminPortalController;
use App\Http\Controllers\EmailVerificationController;
use App\Http\Controllers\EnvironmentAdminController;
use App\Http\Controllers\ImpersonationController;
use App\Http\Controllers\InvitationController;
use App\Http\Controllers\MagicLinkController;
use App\Http\Controllers\OperatorController;
use App\Http\Controllers\PasskeyController;
use App\Http\Controllers\SessionController;
use App\Http\Controllers\SocialController;
use App\Http\Controllers\Sso\SamlIdpSsoController;
use App\Http\Controllers\WorkspaceController;
use App\Http\Controllers\WorkspacePasskeyController;
use App\Http\Middleware\AuthenticateAccountMember;
use App\Http\Middleware\AuthenticateOperator;
use App\Http\Middleware\BlockDuringImpersonation;
use App\Http\Middleware\EnforceImpersonationWindow;
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
 */
Route::match(['get', 'post'], '/sso/saml/idp/sso', SamlIdpSsoController::class)->name('sso.saml.idp.sso');

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
 * Admin Portal — a WorkOS-style setup link. An external IT admin opens it with
 * NO platform account and configures one org's SSO/SCIM, nothing else. These live
 * in the guest area and must never be reachable via a platform session; the
 * scoped portal session (distinct key) is the only thing that unlocks /setup.
 */
Route::view('/setup/expired', 'portal.expired')->name('portal.expired');
Volt::route('/setup', 'portal.setup')->middleware('portal.session')->name('portal.setup');
Route::get('/setup/{token}', [AdminPortalController::class, 'enter'])->name('portal.enter');

/*
 * Authenticated console — the subject/tenant plane. `plane:subject` confines it to a
 * tenant subdomain in the SaaS shape (404 on the account-root host, no bleed); in the
 * single-tenant shape the plane gate is a no-op, so the one host serves it normally.
 */
Route::middleware(['plane:subject', EnforceImpersonationWindow::class, 'platform.auth'])->group(function (): void {
    Volt::route('/dashboard', 'dashboard')->name('dashboard');

    // Multi-account: choose/switch among accounts signed in on this browser, or add
    // another. /accounts/add reuses the login screen but for an already-authenticated
    // user, so a new sign-in is ADDED (a switchable account) rather than replacing.
    Volt::route('/accounts', 'auth.accounts')->name('accounts');
    Volt::route('/accounts/add', 'auth.login')->name('accounts.add');

    // My account — every user's self-service security center (password, 2FA,
    // passkeys, sessions). Available to members and admins alike.
    Volt::route('/account', 'account')->name('account');

    Volt::route('/usage', 'usage')->name('usage');
    Volt::route('/members', 'members')->name('members');
    Volt::route('/connections', 'connections')->name('connections');
    Volt::route('/sso-providers', 'sso-providers')->name('sso-providers');
    Volt::route('/directories', 'directories')->name('directories');
    Volt::route('/roles', 'roles')->name('roles');
    Volt::route('/clients', 'clients')->name('clients');
    Volt::route('/webhooks', 'webhooks')->name('webhooks');
    Volt::route('/audit', 'audit')->name('audit');
    Volt::route('/settings', 'settings')->name('settings');
    Volt::route('/appearance', 'appearance')->name('appearance');

    // Access governance (IGA): certification reviews + Segregation-of-Duties policies.
    Volt::route('/governance', 'governance')->name('governance');
    Volt::route('/sod-policies', 'sod-policies')->name('sod-policies');

    // Outbound SCIM provisioning connections (push users OUT to downstream apps).
    Volt::route('/provisioning', 'provisioning')->name('provisioning');

    // AI token vault + inline-hook (external action) endpoints. Storing/revealing a
    // secret is sensitive, so the vault is behind the sudo step-up gate.
    Volt::route('/vault', 'vault')->middleware('sudo')->name('vault');
    Volt::route('/hooks', 'hooks')->name('hooks');

    // SIEM audit-stream export.
    Volt::route('/audit-streams', 'audit-streams')->name('audit-streams');

    // Agent approvals (OIDC CIBA): where a signed-in user approves/denies a
    // backchannel request an agent started on their behalf.
    Volt::route('/approvals', 'approvals')->name('approvals');

    // RFC 8628 device grant: where a signed-in user approves a device's user_code.
    Volt::route('/device', 'device')->name('device');

    // Step-up re-authentication ("sudo mode") gate for sensitive actions. Blocked
    // while impersonating: an impersonator must never be able to clear the gate
    // that protects credential changes.
    Volt::route('/sudo', 'auth.sudo')->middleware(BlockDuringImpersonation::class)->name('sudo');

    // Interactive OIDC/OAuth consent — Cbox ID as an identity provider.
    Volt::route('/oauth/authorize', 'oauth.consent')->name('oauth.authorize');

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
    Route::get('/settings/connect/{provider}/callback', [SocialController::class, 'connectCallback'])->name('social.connect.callback');
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
        Volt::route('/', 'environment.home')->name('environment.home');

        // Organizations — routable list → create → detail (deep-linkable, WorkOS shape).
        Volt::route('/organizations', 'environment.organizations.index')->name('environment.organizations');
        Volt::route('/organizations/new', 'environment.organizations.create')->name('environment.organizations.create');
        Volt::route('/organizations/{organization}', 'environment.organizations.show')->name('environment.organizations.show');

        // Users — routable list → create → detail.
        Volt::route('/users', 'environment.users.index')->name('environment.users');
        Volt::route('/users/new', 'environment.users.create')->name('environment.users.create');
        Volt::route('/users/{user}', 'environment.users.show')->name('environment.users.show');

        // SSO connections — routable list → create → detail.
        Volt::route('/single-sign-on', 'environment.connections.index')->name('environment.connections');
        Volt::route('/single-sign-on/new', 'environment.connections.create')->name('environment.connections.create');
        Volt::route('/single-sign-on/{connection}', 'environment.connections.show')->name('environment.connections.show');

        // Login methods (SAML service providers) — routable list → create → detail.
        Volt::route('/login-methods', 'environment.sso-providers.index')->name('environment.sso-providers');
        Volt::route('/login-methods/new', 'environment.sso-providers.create')->name('environment.sso-providers.create');
        Volt::route('/login-methods/{provider}', 'environment.sso-providers.show')->name('environment.sso-providers.show');

        // Directories (SCIM) — routable list → create → detail.
        Volt::route('/directories', 'environment.directories.index')->name('environment.directories');
        Volt::route('/directories/new', 'environment.directories.create')->name('environment.directories.create');
        Volt::route('/directories/{directory}', 'environment.directories.show')->name('environment.directories.show');

        // Outbound sync (provisioning connections) — routable list → create → detail.
        Volt::route('/outbound-sync', 'environment.provisioning.index')->name('environment.provisioning');
        Volt::route('/outbound-sync/new', 'environment.provisioning.create')->name('environment.provisioning.create');
        Volt::route('/outbound-sync/{sync}', 'environment.provisioning.show')->name('environment.provisioning.show');

        // Roles — routable list → create → detail (permission editor).
        Volt::route('/roles', 'environment.roles.index')->name('environment.roles');
        Volt::route('/roles/new', 'environment.roles.create')->name('environment.roles.create');
        Volt::route('/roles/{role}', 'environment.roles.show')->name('environment.roles.show');

        // Access reviews (certification campaigns) — routable list → create → detail.
        Volt::route('/access-reviews', 'environment.governance.index')->name('environment.governance');
        Volt::route('/access-reviews/new', 'environment.governance.create')->name('environment.governance.create');
        Volt::route('/access-reviews/{campaign}', 'environment.governance.show')->name('environment.governance.show');

        // Conflict rules (segregation-of-duties) — routable list → create → detail.
        Volt::route('/conflict-rules', 'environment.sod-policies.index')->name('environment.sod-policies');
        Volt::route('/conflict-rules/new', 'environment.sod-policies.create')->name('environment.sod-policies.create');
        Volt::route('/conflict-rules/{policy}', 'environment.sod-policies.show')->name('environment.sod-policies.show');

        // Applications (OAuth clients) — routable list → create → detail (secret rotation).
        Volt::route('/applications', 'environment.clients.index')->name('environment.clients');
        Volt::route('/applications/new', 'environment.clients.create')->name('environment.clients.create');
        Volt::route('/applications/{client}', 'environment.clients.show')->name('environment.clients.show');

        // Webhooks — routable list → create → detail.
        Volt::route('/webhooks', 'environment.webhooks.index')->name('environment.webhooks');
        Volt::route('/webhooks/new', 'environment.webhooks.create')->name('environment.webhooks.create');
        Volt::route('/webhooks/{webhook}', 'environment.webhooks.show')->name('environment.webhooks.show');
        // Event hooks — routable list → create → detail.
        Volt::route('/event-hooks', 'environment.hooks.index')->name('environment.hooks');
        Volt::route('/event-hooks/new', 'environment.hooks.create')->name('environment.hooks.create');
        Volt::route('/event-hooks/{hook}', 'environment.hooks.show')->name('environment.hooks.show');

        // Stored tokens (secret vault) — routable list → create → detail.
        Volt::route('/stored-tokens', 'environment.vault.index')->name('environment.vault');
        Volt::route('/stored-tokens/new', 'environment.vault.create')->name('environment.vault.create');
        Volt::route('/stored-tokens/{secret}', 'environment.vault.show')->name('environment.vault.show');

        Volt::route('/audit', 'environment.audit')->name('environment.audit');

        // Log streaming (SIEM) — routable list → create → detail.
        Volt::route('/log-streaming', 'environment.audit-streams.index')->name('environment.audit-streams');
        Volt::route('/log-streaming/new', 'environment.audit-streams.create')->name('environment.audit-streams.create');
        Volt::route('/log-streaming/{stream}', 'environment.audit-streams.show')->name('environment.audit-streams.show');
        Volt::route('/analytics', 'environment.analytics')->name('environment.analytics');
        Volt::route('/approvals', 'environment.approvals')->name('environment.approvals');
        Volt::route('/settings', 'environment.settings')->name('environment.settings');
        Volt::route('/appearance', 'environment.appearance')->name('environment.appearance');

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
Route::prefix('operator')->group(function (): void {
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

        Volt::route('/members', 'workspace.members')->name('workspace.members');
        Volt::route('/security', 'workspace.security')->name('workspace.security');
        Route::post('/passkeys/register/options', [WorkspacePasskeyController::class, 'registerOptions'])->name('workspace.passkeys.register.options');
        Route::post('/passkeys/register', [WorkspacePasskeyController::class, 'register'])->name('workspace.passkeys.register');
        Volt::route('/api-keys', 'workspace.api-keys')->name('workspace.api-keys');
        Volt::route('/environment-keys', 'workspace.environment-api-keys')->name('workspace.environment-keys');
        Volt::route('/billing', 'workspace.billing')->name('workspace.billing');
        Volt::route('/settings', 'workspace.settings')->name('workspace.settings');
    });
});
