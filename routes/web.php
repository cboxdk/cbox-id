<?php

declare(strict_types=1);

use App\Http\Controllers\AdminPortalController;
use App\Http\Controllers\EmailVerificationController;
use App\Http\Controllers\ImpersonationController;
use App\Http\Controllers\InvitationController;
use App\Http\Controllers\MagicLinkController;
use App\Http\Controllers\OperatorController;
use App\Http\Controllers\PasskeyController;
use App\Http\Controllers\SessionController;
use App\Http\Controllers\SocialController;
use App\Http\Controllers\Sso\SamlIdpSsoController;
use App\Http\Middleware\AuthenticateOperator;
use App\Http\Middleware\BlockDuringImpersonation;
use App\Http\Middleware\EnforceImpersonationWindow;
use App\Platform\PlatformAuth;
use Illuminate\Support\Facades\Route;
use Livewire\Volt\Volt;

Route::get('/', function () {
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
 * Guest — the sign-in surface.
 */
Route::middleware('platform.guest')->group(function (): void {
    Volt::route('/login', 'auth.login')->name('login');
    Volt::route('/o/{slug}/login', 'auth.login')->name('login.branded');
    Volt::route('/signup', 'auth.signup')->name('signup');
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
 * Authenticated console.
 */
Route::middleware([EnforceImpersonationWindow::class, 'platform.auth'])->group(function (): void {
    Volt::route('/dashboard', 'dashboard')->name('dashboard');

    // Multi-account: choose/switch among accounts signed in on this browser, or add
    // another. /accounts/add reuses the login screen but for an already-authenticated
    // user, so a new sign-in is ADDED (a switchable account) rather than replacing.
    Volt::route('/accounts', 'auth.accounts')->name('accounts');
    Volt::route('/accounts/add', 'auth.login')->name('accounts.add');

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
