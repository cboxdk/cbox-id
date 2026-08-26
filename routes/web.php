<?php

declare(strict_types=1);

use App\Http\Controllers\AccountActivityController;
use App\Http\Controllers\AccountController;
use App\Http\Controllers\AdminPortalController;
use App\Http\Controllers\Api\CliBootstrapController;
use App\Http\Controllers\Auth\AccountsController;
use App\Http\Controllers\Auth\ChangePasswordController;
use App\Http\Controllers\Auth\InvitationAcceptController;
use App\Http\Controllers\Auth\LinkConfirmController;
use App\Http\Controllers\Auth\LoginController;
use App\Http\Controllers\Auth\MfaController;
use App\Http\Controllers\Auth\OtpStepUpController;
use App\Http\Controllers\Auth\PasswordResetController;
use App\Http\Controllers\Auth\SignupController;
use App\Http\Controllers\Auth\SudoController;
use App\Http\Controllers\Console\AccessReviewController;
use App\Http\Controllers\Console\AccountSettingsController;
use App\Http\Controllers\Console\ActingOrganizationController;
use App\Http\Controllers\Console\AgentApprovalController;
use App\Http\Controllers\Console\ApiKeyController;
use App\Http\Controllers\Console\AppearanceController;
use App\Http\Controllers\Console\AuditController;
use App\Http\Controllers\Console\AuthPolicyController;
use App\Http\Controllers\Console\ClientController;
use App\Http\Controllers\Console\ConnectionController;
use App\Http\Controllers\Console\DashboardController;
use App\Http\Controllers\Console\DirectoryController;
use App\Http\Controllers\Console\DirectoryMemberController;
use App\Http\Controllers\Console\EnvironmentDomainController;
use App\Http\Controllers\Console\EnvironmentHomeController;
use App\Http\Controllers\Console\EnvironmentKeyController;
use App\Http\Controllers\Console\EnvironmentOrganizationController;
use App\Http\Controllers\Console\EnvironmentUserController;
use App\Http\Controllers\Console\FrontendKeyController;
use App\Http\Controllers\Console\GetStartedController;
use App\Http\Controllers\Console\HookController;
use App\Http\Controllers\Console\LegacyLoginController;
use App\Http\Controllers\Console\LogStreamController;
use App\Http\Controllers\Console\MemberController;
use App\Http\Controllers\Console\MyApprovalController;
use App\Http\Controllers\Console\OperatorRosterController;
use App\Http\Controllers\Console\OutboundSyncController;
use App\Http\Controllers\Console\PermissionController;
use App\Http\Controllers\Console\PlatformCustomerController;
use App\Http\Controllers\Console\PlatformEnvironmentController;
use App\Http\Controllers\Console\PlatformOrganizationController;
use App\Http\Controllers\Console\PlatformSearchController;
use App\Http\Controllers\Console\PlatformUsageController;
use App\Http\Controllers\Console\ProjectController;
use App\Http\Controllers\Console\RoleConflictController;
use App\Http\Controllers\Console\RoleController;
use App\Http\Controllers\Console\ServiceProviderController;
use App\Http\Controllers\Console\SettingsController;
use App\Http\Controllers\Console\SocialProviderController;
use App\Http\Controllers\Console\UsageController;
use App\Http\Controllers\Console\VaultController;
use App\Http\Controllers\Console\WebhookController;
use App\Http\Controllers\Dev\DesignSystemController;
use App\Http\Controllers\DeviceApprovalController;
use App\Http\Controllers\EmailVerificationController;
use App\Http\Controllers\EnvironmentAdminController;
use App\Http\Controllers\EnvironmentHandoffController;
use App\Http\Controllers\FirstRunController;
use App\Http\Controllers\FrontendApi\PasskeySignInController;
use App\Http\Controllers\FrontendApi\SecondFactorController;
use App\Http\Controllers\FrontendApi\SignInController;
use App\Http\Controllers\ImpersonationController;
use App\Http\Controllers\InvitationController;
use App\Http\Controllers\MagicLinkController;
use App\Http\Controllers\OAuthConsentController;
use App\Http\Controllers\OperatorController;
use App\Http\Controllers\PasskeyController;
use App\Http\Controllers\PortalSetupController;
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
use Cbox\Id\Api\Http\Middleware\NoStore;
use Cbox\Id\Api\Http\Middleware\ResolveEnvironment;
use Cbox\Id\Api\Http\Middleware\ResolveEnvironment as ApiResolveEnvironment;
use Cbox\Id\FrontendApi\Http\Middleware\AuthenticateFrontendApi;
use Illuminate\Support\Facades\Route;

// EMBEDDED SIGN-IN — registered at the TOP LEVEL, deliberately outside every group.
//
// Nested inside the console group it inherited `Authenticate`, which refuses an anonymous
// caller — and an anonymous caller offering a password is the entire point of this
// endpoint. It carries the Frontend API's own door instead: a publishable key plus the
// origin allow-list, which is the gate a browser on somebody else's site can pass.
//
// `web` is needed for one narrow reason: `PlatformAuth::attemptPassword()` writes the
// session it establishes, and the controller reads it back in the same request to mint the
// ticket. The cookie itself never has to reach the caller.
//
// BEHIND THE SAME SWITCH AS THE REST OF THE CHANNEL. The framework gates `/config` and
// `/session` on `cbox-id.frontend_api.enabled` and says why: a feature that quietly appears
// on upgrade is one nobody reviewed. These three were registered unconditionally, so on an
// install that had never turned the channel on, an anonymous cross-origin password endpoint
// was live the moment anybody minted a key — and switching the flag off during an incident
// removed the two harmless documents and left these serving.
if (config('cbox-id.frontend_api.enabled') === true) {
    Route::middleware([
        'web',
        ResolveEnvironment::class,
        AuthenticateFrontendApi::class,
    ])->prefix('frontend/v1')->group(function (): void {
        Route::match(['post', 'options'], '/sign-in', SignInController::class)
            ->name('frontend.sign-in');

        // The factor the password did not satisfy. Same door, same key, same origin list —
        // the pending state travels as a token because a cross-origin page carries no
        // session cookie from the first request to this one.
        Route::match(['post', 'options'], '/sign-in/factor', SecondFactorController::class)
            ->name('frontend.sign-in.factor');

        // Passkeys, in the two requests WebAuthn needs. The challenge travels as an opaque
        // handle rather than in a session cookie, for the same reason everything else here
        // does: the caller is on somebody else's origin.
        Route::match(['post', 'options'], '/sign-in/passkey/options', [PasskeySignInController::class, 'challenge'])
            ->name('frontend.sign-in.passkey.options');
        Route::match(['post', 'options'], '/sign-in/passkey', PasskeySignInController::class)
            ->name('frontend.sign-in.passkey');
    });
}

/*
 * THE DESIGN SYSTEM GALLERY. Every primitive the console is built from, drawn on one
 * page in both themes and at every breakpoint.
 *
 * It is registered ONLY on a local install, and that is the whole of its access control
 * — there is nothing here to authorize, because there is nothing here but the components
 * themselves rendering static sample data. `local` is a claim a developer's machine makes
 * about itself and a built image never does; DesignSystemRouteTest holds that the route
 * is absent in every other environment, so this cannot become a door by accident.
 *
 * `web` only — no plane gate, no auth. It has no session to read and nothing to leak, and
 * gating it on a plane would 404 it on exactly the host somebody is developing against.
 */
if (app()->environment('local')) {
    Route::middleware('web')
        ->get('/dev/design-system', DesignSystemController::class)
        ->name('dev.design-system');
}

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
Route::get('/first-run', [FirstRunController::class, 'show'])->name('first-run');
Route::post('/first-run', [FirstRunController::class, 'claim'])->name('first-run.claim');

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
    ->middleware(['plane:issuer', 'throttle:30,1'])
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
//
// THROTTLED AND NoStore, matching the package routes these shadow. Re-registering them
// here to escape `plane:issuer` also escaped `ApiServiceProvider`'s middleware, and the
// two were lost silently: `route:list` showed `web` alone. These do XML signature
// verification and JWT validation on wholly unauthenticated input — the most expensive
// unauthenticated work the platform does — and their responses carry a freshly minted
// session, which no cache may keep.
Route::post('/sso/saml/{connection}/acs', SamlAcsController::class)
    ->middleware(['throttle:30,1', NoStore::class])
    ->name('sso.saml.acs');
// GET AND POST. `response_mode=form_post` means the provider POSTs the callback from
// its own origin instead of redirecting with a query string, and Apple switches to it by
// itself once any scope beyond `openid` is requested — so a GET-only redirect URI answers
// every Sign in with Apple with 405, which the person reads as a cancellation. The
// controller takes `state` and `code` from the query or the body indifferently. CSRF is
// exempted for this URI in bootstrap/app.php, where the reasoning lives.
Route::match(['get', 'post'], '/sso/oidc/{connection}/callback', OidcCallbackController::class)
    ->middleware(['throttle:30,1', NoStore::class])
    ->name('sso.oidc.callback');

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
    Route::get('/signup', [SignupController::class, 'show'])->name('signup');
    Route::post('/signup', [SignupController::class, 'register'])->name('signup.register');
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
    Route::get('/login', [LoginController::class, 'show'])->name('login');
    // Identifier-first: the address alone, so its home realm can be discovered before a
    // password form is drawn. A server step, because the domain map is the server's.
    Route::post('/login/identify', [LoginController::class, 'identify'])->name('login.identify');
    Route::post('/login', [LoginController::class, 'login'])->name('login.attempt');
    Route::post('/login/magic-link', [LoginController::class, 'magicLink'])->name('login.magic-link');

    // The branded door: same page, painted in one organization's colours.
    Route::get('/o/{slug}/login', [LoginController::class, 'show'])->name('login.branded');
    Route::get('/magic/{token}', [MagicLinkController::class, 'redeem'])->name('magic.redeem');

    // Password reset — request a link, then choose a new password from the token.
    // Explicitly closed to an impersonator (the guest guard already bounces an
    // authenticated subject, but a credential change must be a provable no-op).
    Route::middleware(BlockDuringImpersonation::class)->group(function (): void {
        Route::get('/forgot-password', [PasswordResetController::class, 'request'])->name('password.request');
        Route::post('/forgot-password', [PasswordResetController::class, 'send'])->name('password.email');
        Route::get('/reset-password/{token}', [PasswordResetController::class, 'edit'])->name('password.reset');
        // The token travels in the BODY here, not the path. A password in a form whose
        // action carries the token would put both in the same request either way, but a
        // token in a URL is also in the browser's history and in any referrer the page
        // emits — and this one is a live credential until it is spent.
        Route::post('/reset-password', [PasswordResetController::class, 'update'])->name('password.update');
    });

    // Passkey (WebAuthn) sign-in — no session required; the assertion is the proof.
    Route::post('/passkeys/login/options', [PasskeyController::class, 'loginOptions'])->name('passkeys.login.options');
    Route::post('/passkeys/login', [PasskeyController::class, 'login'])->name('passkeys.login');

    // Social sign-in (Google, GitHub, Microsoft) over OAuth.
    Route::get('/auth/{provider}/redirect', [SocialController::class, 'redirect'])->name('social.redirect');
    Route::get('/auth/{provider}/callback', [SocialController::class, 'callback'])->name('social.callback');
});

// The MFA challenge sits between password and a full session, so it is neither
// fully guest nor fully authenticated. The pending sign-in in the session is the
// authorization; there is no guard here that could ask a better question.
Route::get('/mfa', [MfaController::class, 'show'])->name('mfa');
Route::post('/mfa', [MfaController::class, 'verify'])->name('mfa.verify');
Route::post('/mfa/recovery', [MfaController::class, 'recover'])->name('mfa.recover');

// The adaptive-risk step-up (emailed one-time code) sits in the same interstitial
// state: primary auth passed, but an elevated risk assessment demands a second
// factor before the session is established.
Route::get('/login/step-up', [OtpStepUpController::class, 'show'])->name('login.step-up');
Route::post('/login/step-up', [OtpStepUpController::class, 'verify'])->name('login.step-up.verify');
Route::post('/login/step-up/resend', [OtpStepUpController::class, 'resend'])->name('login.step-up.resend');

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
Route::match(['get', 'post'], '/oauth/authorize', [OAuthConsentController::class, 'show'])
    /*
     * ONE ROUTE, BOTH METHODS. OIDC Core §3.1.2.1: "The Authorization Server MUST support
     * the use of the HTTP GET and POST methods." Form-POST is how a client sends a
     * `request` object, a `claims` payload or a long `login_hint` that will not survive a
     * URL length limit, and it is exercised by the OpenID basic-certification suite.
     *
     * The POST is CSRF-exempt by definition — it arrives cross-site from the relying party,
     * which has no Laravel token to send — and nothing is minted on it: the controller
     * re-validates client, redirect_uri, scope and PKCE from scratch on either method, and
     * approving is a separate request of its own.
     *
     * `platform.auth:optional` RESOLVES the signed-in subject without requiring one.
     * Removing the middleware outright was wrong: CurrentUser is populated only there, so
     * check() was permanently false and NO authorization code could be issued.
     *
     * BlockDuringImpersonation because this endpoint MINTS CREDENTIALS. Every other
     * credential-establishing route carries it — password reset, invitation, email
     * verification, sudo, org switch, passkey registration, social connect — and this one
     * issues the longest-lived credential of the set: a refresh token that outlives both
     * the impersonation window and the operator's session, attributed to the person being
     * impersonated.
     */
    ->middleware(['plane:first-party', EnforceImpersonationWindow::class, BlockDuringImpersonation::class, 'platform.auth:optional'])
    ->name('oauth.authorize');

/*
 * The two answers, each its own route.
 *
 * Under Volt these were component methods on the shared update endpoint, which is exactly
 * why the impersonation guard had to hang off Livewire's `call` event — and why it could
 * not see the consent-skip path, which reaches issuance from `mount()`. Both are ordinary
 * requests now, and the id in the URL names one PENDING request held server-side: the
 * browser never receives the client, the redirect URI, the scopes or the challenge, so it
 * cannot influence any of them.
 */
Route::post('/oauth/authorize/{authorization}/approve', [OAuthConsentController::class, 'approve'])
    ->middleware(['plane:first-party', EnforceImpersonationWindow::class, BlockDuringImpersonation::class, 'platform.auth:optional'])
    ->name('oauth.authorize.approve');

Route::post('/oauth/authorize/{authorization}/deny', [OAuthConsentController::class, 'deny'])
    ->middleware(['plane:first-party', EnforceImpersonationWindow::class, BlockDuringImpersonation::class, 'platform.auth:optional'])
    ->name('oauth.authorize.deny');

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
    Route::get('/setup/expired', [PortalSetupController::class, 'expired'])->name('portal.expired');

    /*
     * "All set", OUTSIDE the portal session — because finishing ENDS that session. A
     * completion that re-rendered the setup screen would be answered by the middleware's
     * bounce to the expired page, which is the wrong sentence for somebody who has just
     * succeeded.
     */
    Route::get('/setup/done', [PortalSetupController::class, 'done'])->name('portal.done');

    Route::middleware('portal.session')->group(function (): void {
        Route::get('/setup', [PortalSetupController::class, 'show'])->name('portal.setup');

        /*
         * Each write its own route, and each re-asks the session AND the link's scope — a
         * link scoped to SCIM must not be able to add a domain by forming the request.
         * Under Volt all of these arrived at `/livewire/update`, which is why the component
         * had to open every action with the same guard by hand.
         */
        Route::post('/setup/domains', [PortalSetupController::class, 'addDomain'])->name('portal.domains.store');
        Route::post('/setup/domains/{domain}/verify', [PortalSetupController::class, 'verifyDomain'])->name('portal.domains.verify');
        Route::delete('/setup/domains/{domain}', [PortalSetupController::class, 'removeDomain'])->name('portal.domains.destroy');
        Route::post('/setup/connections', [PortalSetupController::class, 'createConnection'])->name('portal.connections.store');
        Route::post('/setup/connections/{connection}/activate', [PortalSetupController::class, 'activateConnection'])->name('portal.connections.activate');
        Route::post('/setup/directories', [PortalSetupController::class, 'registerDirectory'])->name('portal.directories.store');
        Route::post('/setup/finish', [PortalSetupController::class, 'finish'])->name('portal.finish');
    });

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
    Route::get('/dashboard', [DashboardController::class, 'index'])->name('dashboard');
    Route::post('/dashboard/checklist/dismiss', [DashboardController::class, 'dismissChecklist'])->name('dashboard.checklist.dismiss');

    // The guided first run. Deliberately NOT in the nav registry: it is where a fresh
    // organization is sent once, and where the dashboard checklist links back to —
    // an entry that would sit there dead for the rest of the org's life is clutter.
    Route::get('/get-started', [GetStartedController::class, 'index'])->name('get-started');
    Route::post('/get-started/dismiss', [GetStartedController::class, 'dismiss'])->name('get-started.dismiss');

    // Multi-account: choose/switch among accounts signed in on this browser, or add
    // another. /accounts/add reuses the login screen but for an already-authenticated
    // user, so a new sign-in is ADDED (a switchable account) rather than replacing.
    Route::get('/accounts', [AccountsController::class, 'index'])->name('accounts');
    // A POST, because it moves the session. A GET that changes who you are is a GET any
    // image tag on any page can make.
    Route::post('/accounts', [AccountsController::class, 'switchTo'])->name('accounts.switch');
    /*
     * The SAME sign-in page, for somebody already signed in.
     *
     * It is the login controller and not a page of its own because "add another account"
     * IS a sign-in — the only difference is what the session does with the result, which
     * is a decision the sign-in POST makes rather than one the form has to know.
     */
    Route::get('/accounts/add', [LoginController::class, 'show'])->name('accounts.add');

    // The forced password change. Inside the authenticated group on purpose: the hold
    // that sends people here (see {@see \App\Http\Middleware\Authenticate}) exempts this
    // one route, so it is reachable only by someone who is signed in and owes a change.
    Route::get('/password/change', [ChangePasswordController::class, 'edit'])->name('password.change');
    Route::post('/password/change', [ChangePasswordController::class, 'update'])->name('password.change.update');

    // The social link confirmation. Same shape as the password hold above and for the
    // same reason: reachable only by someone signed in who has an identity waiting on
    // their answer, and exempt from the hold so the redirect cannot loop.
    Route::get('/link/confirm', [LinkConfirmController::class, 'show'])->name('link.confirm');
    Route::post('/link/confirm', [LinkConfirmController::class, 'connect'])->name('link.connect');
    Route::post('/link/decline', [LinkConfirmController::class, 'decline'])->name('link.decline');

    // My account — every user's self-service security center (password, 2FA,
    // passkeys, sessions). Available to members and admins alike.
    Route::get('/account', [AccountController::class, 'show'])->name('account');

    /*
     * EVERY WRITE ON THAT PAGE EXCEPT THE NAME IS BEHIND `sudo`, ON THE ROUTE.
     *
     * Under Volt each action opened with a private `requiresSudo()` call, because every one
     * of them arrived at `/livewire/update` where route middleware could not distinguish
     * them. There is a route per write now, so the gate is the stack's — strictly stronger,
     * and impossible to forget in a new action.
     *
     * The display name is deliberately outside it: a name is not a credential and changing
     * it grants nothing, so making somebody re-enter their password to fix a typo would be
     * charging for nothing.
     */
    Route::patch('/account/profile', [AccountController::class, 'updateProfile'])->name('account.profile.update');

    Route::middleware('sudo')->group(function (): void {
        Route::post('/account/password', [AccountController::class, 'updatePassword'])->name('account.password.update');
        Route::post('/account/two-factor/enrol', [AccountController::class, 'enrolMfa'])->name('account.mfa.enrol');
        Route::post('/account/two-factor/confirm', [AccountController::class, 'confirmMfa'])->name('account.mfa.confirm');
        Route::post('/account/two-factor/recovery-codes', [AccountController::class, 'regenerateRecoveryCodes'])->name('account.mfa.recovery-codes');
        Route::delete('/account/passkeys/{passkey}', [AccountController::class, 'removePasskey'])->name('account.passkeys.destroy');
        Route::delete('/account/social/{provider}', [AccountController::class, 'unlinkProvider'])->name('account.social.destroy');
    });

    // WHERE YOU ARE SIGNED IN, AND WHAT CAN ACT AS YOU. Its own page rather than a fourth
    // section on `/account`: that page is about the credentials you hold — password,
    // passkeys, 2FA — and this is about what is currently holding YOU. A person arrives
    // here with a different question ("is any of this not me?") and usually in a hurry.
    Route::get('/account/activity', [AccountActivityController::class, 'index'])->name('account.activity');

    // The three levers on that page, each its own route. Under Volt they shared
    // `/livewire/update` with every other action in the console, which is why each had to
    // re-derive the acting subject and re-check the target by hand; they still resolve
    // their target against the signed-in subject, because a route parameter is the
    // client's too.
    Route::post('/account/sessions/{session}/revoke', [AccountActivityController::class, 'revokeSession'])
        ->name('account.sessions.revoke');
    /*
     * BEHIND `sudo`, unlike its two neighbours, and the difference is the blast radius.
     *
     * Signing out ONE session is targeted and costs its owner a sign-in; withdrawing one
     * application's grant costs it a re-approval. This ends every other session at once —
     * the account-wide lever — which is exactly what somebody at a borrowed, unlocked
     * laptop would pull to lock the real owner out while keeping the tab in front of them.
     *
     * The account page has always gated its copy of this button; the two pages share one
     * route now, so they share one answer, and it is the stricter one.
     */
    Route::post('/account/sessions/revoke-others', [AccountActivityController::class, 'revokeOtherSessions'])
        ->middleware('sudo')
        ->name('account.sessions.revoke-others');
    Route::delete('/account/applications/{client}', [AccountActivityController::class, 'revokeApplication'])
        ->name('account.applications.destroy');

    Route::get('/usage', [UsageController::class, 'index'])->name('usage');
    // THE TENANT DIRECTORY — everyone who can sign in to this organization, plus the
    // invitations nobody has accepted. Its own URI, because it is not the same page as
    // the administrator roster below.
    //
    // Both were registered on `/members` with the name `members`. Laravel keys the route
    // collection on `method|domain|uri`, so the second registration REPLACED this one:
    // the router held one route, this 600-line component was unreachable from any URL,
    // and — because two nav pages then named the same route — clicking "People" in the
    // rail lit up "Identity platform" instead, with an eyebrow to match and "Roles"
    // missing from the sub-nav.
    Route::get('/directory/members', [DirectoryMemberController::class, 'index'])->name('directory.members');
    Route::post('/directory/members/invitations', [DirectoryMemberController::class, 'invite'])->name('directory.members.invite');
    Route::delete('/directory/members/invitations/{invitation}', [DirectoryMemberController::class, 'revokeInvitation'])->name('directory.members.invitations.revoke');
    Route::patch('/directory/members/{member}/role', [DirectoryMemberController::class, 'changeRole'])->name('directory.members.role');
    Route::post('/directory/members/{member}/access', [DirectoryMemberController::class, 'setAccessRole'])->name('directory.members.access');
    Route::delete('/directory/members/{member}', [DirectoryMemberController::class, 'remove'])->name('directory.members.remove');

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
    Route::get('/projects', [ProjectController::class, 'index'])->name('projects');
    Route::get('/projects/new', [ProjectController::class, 'create'])->name('projects.create');
    Route::post('/projects', [ProjectController::class, 'store'])->name('projects.store');
    // Before `/projects/{project}`, so the literal segment is never read as an id.
    Route::post('/projects/verification/resend', [ProjectController::class, 'resendVerification'])
        ->name('projects.verification.resend');
    Route::get('/projects/{project}', [ProjectController::class, 'show'])->name('projects.show');
    Route::patch('/projects/{project}', [ProjectController::class, 'rename'])->name('projects.rename');
    Route::post('/projects/{project}/environments', [ProjectController::class, 'storeEnvironment'])
        ->name('projects.environments.store');
    Route::post('/projects/{project}/suspend', [ProjectController::class, 'suspend'])->name('projects.suspend');
    Route::post('/projects/{project}/reactivate', [ProjectController::class, 'reactivate'])->name('projects.reactivate');

    // Open an environment → signed handoff → its own admin console (no second login).
    Route::get('/open/{environment}', [EnvironmentHandoffController::class, 'openEnvironment'])->name('environment.open');

    Route::get('/members', [MemberController::class, 'index'])->name('members');
    Route::post('/members/invitations', [MemberController::class, 'invite'])->name('members.invite');
    Route::post('/members/invitations/{invitation}/resend', [MemberController::class, 'resendInvite'])
        ->name('members.invitations.resend');
    Route::delete('/members/invitations/{invitation}', [MemberController::class, 'revokeInvite'])
        ->name('members.invitations.revoke');
    Route::patch('/members/{member}/role', [MemberController::class, 'changeRole'])->name('members.role');
    Route::put('/members/{member}/access', [MemberController::class, 'saveAccess'])->name('members.access');
    Route::post('/members/{member}/transfer-ownership', [MemberController::class, 'makeOwner'])
        ->name('members.transfer-ownership');
    Route::delete('/members/{member}', [MemberController::class, 'removeMember'])->name('members.remove');
    Route::get('/api-keys', [ApiKeyController::class, 'index'])->name('api-keys');
    Route::post('/api-keys', [ApiKeyController::class, 'store'])->name('api-keys.store');
    Route::delete('/api-keys/{key}', [ApiKeyController::class, 'destroy'])->name('api-keys.destroy');
    Route::get('/environment-keys', [EnvironmentKeyController::class, 'index'])->name('environment-keys');
    Route::post('/environment-keys', [EnvironmentKeyController::class, 'store'])->name('environment-keys.store');
    Route::delete('/environment-keys/{key}', [EnvironmentKeyController::class, 'destroy'])->name('environment-keys.destroy');
    Route::get('/environment-domains', [EnvironmentDomainController::class, 'index'])->name('environment-domains');
    Route::post('/environment-domains', [EnvironmentDomainController::class, 'store'])->name('environment-domains.store');
    Route::post('/environment-domains/verify', [EnvironmentDomainController::class, 'verify'])->name('environment-domains.verify');
    Route::delete('/environment-domains', [EnvironmentDomainController::class, 'destroy'])->name('environment-domains.destroy');
    // Retired into Logs › Activity log, which reads the SAME hash-chained entries scoped
    // to the same organization — and reads them better: two filters and a search against
    // this page's one, plus a help topic, and it already served both console planes. Two
    // pages over one trail meant an operator could look in the wrong one and conclude
    // nothing had happened.
    //
    // A redirect rather than a deletion: this URL is in bookmarks and in links from the
    // organization pages, and the destination is the same information.
    Route::permanentRedirect('/activity', '/audit')->name('activity');
    // `/billing` is NOT here. It is the billing module's route, registered by
    // {@see \Cbox\Id\Billing\BillingServiceProvider} through the same ConsoleRoutes
    // socket a third-party plugin would use — so a deployment that does not bill, or an
    // operator who turns the module off, has no route rather than a route that 404s
    // somewhere deeper. See modules/billing.
    Route::get('/organization-settings', [AccountSettingsController::class, 'edit'])->name('organization-settings');
    Route::patch('/organization-settings', [AccountSettingsController::class, 'update'])->name('organization-settings.update');
    // Single sign-on: the SAME components the environment plane serves. The routable
    // index/new/show shape wins over the organization plane's single page — a connection
    // URL is something you send to whoever runs the identity provider — and this plane
    // gains the edit, disable and delete it never had, while domain verification and the
    // Admin Portal invite come with it onto the environment plane.
    Route::get('/connections', [ConnectionController::class, 'index'])->name('connections');
    Route::post('/connections/invite', [ConnectionController::class, 'invite'])->name('connections.invite');
    Route::post('/connections/domains', [ConnectionController::class, 'addDomain'])->name('connections.domains.store');
    Route::post('/connections/domains/{domain}/verify', [ConnectionController::class, 'verifyDomain'])->name('connections.domains.verify');
    Route::post('/connections/domains/{domain}/capture', [ConnectionController::class, 'toggleCapture'])->name('connections.domains.capture');
    Route::delete('/connections/domains/{domain}', [ConnectionController::class, 'removeDomain'])->name('connections.domains.destroy');
    Route::get('/connections/new', [ConnectionController::class, 'create'])->name('connections.create');
    Route::post('/connections/import', [ConnectionController::class, 'importMetadata'])->name('connections.import');
    Route::post('/connections', [ConnectionController::class, 'store'])->name('connections.store');
    Route::get('/connections/{connection}', [ConnectionController::class, 'show'])->name('connections.show');
    Route::patch('/connections/{connection}', [ConnectionController::class, 'update'])->name('connections.update');
    Route::post('/connections/{connection}/activate', [ConnectionController::class, 'activate'])->name('connections.activate');
    Route::post('/connections/{connection}/disable', [ConnectionController::class, 'disable'])->name('connections.disable');
    Route::post('/connections/{connection}/require-sso', [ConnectionController::class, 'requireSso'])->name('connections.require-sso');
    Route::delete('/connections/{connection}', [ConnectionController::class, 'destroy'])->name('connections.destroy');

    // The provider catalogue — Google, GitHub, Apple and the rest, per tenant. A sibling
    // of Single sign-on rather than a section inside it: connecting the company's own
    // identity provider and offering consumer accounts as buttons are different jobs,
    // done by different people, at different times.
    Route::get('/social-providers', [SocialProviderController::class, 'index'])->name('social-providers');
    Route::post('/social-providers', [SocialProviderController::class, 'store'])->name('social-providers.store');
    Route::delete('/social-providers/{connection}', [SocialProviderController::class, 'destroy'])->name('social-providers.destroy');

    // Sync users in (inbound directories): the SAME components the environment plane
    // serves. The routable index/new/show shape wins over the organization plane's single
    // page — a directory URL is something you send to whoever runs the identity provider,
    // and the reveal-once bearer token needs somewhere to land that is not the row you
    // just submitted. This plane gains rename, pause, rotate and delete with it; the
    // environment plane gains the two pull providers it never had.
    Route::get('/directories', [DirectoryController::class, 'index'])->name('directories');
    Route::post('/directories/invite', [DirectoryController::class, 'invite'])->name('directories.invite');
    Route::get('/directories/new', [DirectoryController::class, 'create'])->name('directories.create');
    Route::post('/directories', [DirectoryController::class, 'store'])->name('directories.store');
    Route::post('/directories/connect', [DirectoryController::class, 'connect'])->name('directories.connect');
    Route::get('/directories/{directory}', [DirectoryController::class, 'show'])->name('directories.show');
    Route::patch('/directories/{directory}', [DirectoryController::class, 'update'])->name('directories.update');
    Route::post('/directories/{directory}/rotate', [DirectoryController::class, 'rotate'])->name('directories.rotate');
    Route::post('/directories/{directory}/toggle', [DirectoryController::class, 'toggle'])->name('directories.toggle');
    Route::post('/directories/{directory}/map', [DirectoryController::class, 'map'])->name('directories.map');
    Route::delete('/directories/{directory}', [DirectoryController::class, 'destroy'])->name('directories.destroy');
    // Roles: the SAME components the environment plane serves. The routable index/new/show
    // shape wins over the organization plane's single page — a role URL is something you
    // send to whoever owns the access — and this plane gains rename, delete and permission
    // editing with it, none of which a tenant admin could do to their own roles before.
    Route::get('/roles', [RoleController::class, 'index'])->name('roles');
    // The same controller the environment plane routes at `environment.permissions`. It
    // was environment-only, so an organization administrator could assign roles without
    // ever seeing the permissions those roles are made of. What the two planes AUTHOR
    // differs and the plane decides it: a manual permission written here belongs to the
    // acting organization alone, one written there is shared with every tenant.
    Route::get('/permissions', [PermissionController::class, 'index'])->name('permissions');
    Route::post('/permissions', [PermissionController::class, 'store'])->name('permissions.store');
    Route::patch('/permissions/{permission}', [PermissionController::class, 'update'])->name('permissions.update');
    Route::delete('/permissions/{permission}', [PermissionController::class, 'destroy'])->name('permissions.destroy');
    Route::get('/roles/new', [RoleController::class, 'create'])->name('roles.create');
    Route::post('/roles', [RoleController::class, 'store'])->name('roles.store');
    Route::get('/roles/{role}', [RoleController::class, 'show'])->name('roles.show');
    Route::patch('/roles/{role}', [RoleController::class, 'update'])->name('roles.update');
    // Grant or revoke ONE permission, said explicitly rather than toggled. Both the
    // detail page's checkbox and the list's picker post here, and a toggle turns a
    // double-click — or a retried request — into a silent flip back.
    Route::post('/roles/{role}/permissions', [RoleController::class, 'permissions'])->name('roles.permissions');
    Route::delete('/roles/{role}', [RoleController::class, 'destroy'])->name('roles.destroy');
    // Apps & API keys (OAuth clients): the SAME components the environment plane serves.
    // The routable index/new/show shape wins over the organization plane's single page —
    // an app has a lifecycle worth linking to, and the reveal-once client secret needs
    // somewhere to land that is not the form you just submitted. This plane gains editing
    // an app's details and rotating its secret with it; the other gains the roles manifest.
    Route::get('/clients', [ClientController::class, 'index'])->name('clients');
    // Publishable keys and the legacy-login declaration are NOT here, and that is the
    // one deliberate exception to "a capability belongs to both planes". Both are owned
    // by the environment and have no organization column, so on this plane every
    // organization's administrator would be administering every other organization's —
    // revoking their keys, or approving where the whole environment's passwords are sent.
    // They live on the environment plane alone; see ConsoleScope::assertMayAdministerEnvironment().
    Route::get('/clients/new', [ClientController::class, 'create'])->name('clients.create');
    Route::post('/clients', [ClientController::class, 'store'])->name('clients.store');
    Route::get('/clients/{client}', [ClientController::class, 'show'])->name('clients.show');
    Route::patch('/clients/{client}', [ClientController::class, 'update'])->name('clients.update');
    Route::put('/clients/{client}/manifest', [ClientController::class, 'saveManifest'])->name('clients.manifest');
    Route::post('/clients/{client}/sync', [ClientController::class, 'sync'])->name('clients.sync');
    Route::post('/clients/{client}/rotate', [ClientController::class, 'rotate'])->name('clients.rotate');
    Route::delete('/clients/{client}', [ClientController::class, 'destroy'])->name('clients.destroy');
    // Webhooks: the SAME components the environment plane serves. The routable
    // index/new/show shape wins over the organization plane's single page with its inline
    // form, and this plane gains resume, secret rotation, subscription editing and delete
    // with it — a tenant admin who paused an endpoint previously had no way to start it
    // again, and none at all to re-key one whose secret had leaked.
    /*
     * Webhooks — list, create, detail, and the five mutations the detail page offers.
     *
     * Each write is its OWN route now rather than an action on one component posted to a
     * shared endpoint, which is what lets the console-wide guards — the impersonation
     * read-only rule, the step-ups — be enforced by middleware instead of by every page
     * remembering to ask. See {@see \App\Http\Controllers\Console\WebhookController}.
     */
    Route::middleware('console.admin')->group(function (): void {
        Route::get('/webhooks', [WebhookController::class, 'index'])->name('webhooks');
        Route::get('/webhooks/new', [WebhookController::class, 'create'])->name('webhooks.create');
        Route::post('/webhooks', [WebhookController::class, 'store'])->name('webhooks.store');
        Route::get('/webhooks/{webhook}', [WebhookController::class, 'show'])->name('webhooks.show');
        Route::patch('/webhooks/{webhook}', [WebhookController::class, 'update'])->name('webhooks.update');
        Route::post('/webhooks/{webhook}/pause', [WebhookController::class, 'pause'])->name('webhooks.pause');
        Route::post('/webhooks/{webhook}/resume', [WebhookController::class, 'resume'])->name('webhooks.resume');
        Route::post('/webhooks/{webhook}/rotate', [WebhookController::class, 'rotate'])->name('webhooks.rotate');
        Route::delete('/webhooks/{webhook}', [WebhookController::class, 'destroy'])->name('webhooks.destroy');
    });
    // Activity log: the SAME component the environment plane serves. The row scoping is
    // what differs per plane and the component asks ConsoleScope for it — an
    // organization's trail is never another's.
    Route::get('/audit', [AuditController::class, 'index'])->name('audit');
    // Log streaming was environment-plane-only. It ships an environment's audit trail to
    // a SIEM, which is a compliance obligation the organization carries — so the plane
    // that answers for compliance could not see, let alone configure, the shipping.
    Route::get('/log-streaming', [LogStreamController::class, 'index'])->name('audit-streams');
    // Creation behind the step-up, because it mints a signing key and points a copy of the
    // audit trail somewhere new. The rest of the page is ordinary reading.
    Route::get('/log-streaming/new', [LogStreamController::class, 'create'])->middleware('sudo')->name('audit-streams.create');
    Route::post('/log-streaming', [LogStreamController::class, 'store'])->middleware('sudo')->name('audit-streams.store');
    Route::get('/log-streaming/{stream}', [LogStreamController::class, 'show'])->name('audit-streams.show');
    Route::post('/log-streaming/{stream}/toggle', [LogStreamController::class, 'toggle'])->name('audit-streams.toggle');
    Route::delete('/log-streaming/{stream}', [LogStreamController::class, 'destroy'])->name('audit-streams.destroy');
    // Settings: the SAME component the environment plane serves. The organization's own
    // record is on both planes, bounded by whichever organization the scope resolves; the
    // environment's identity is on the environment plane alone.
    Route::get('/settings', [SettingsController::class, 'show'])->name('settings');
    Route::patch('/settings', [SettingsController::class, 'rename'])->name('settings.rename');
    // Appearance: the SAME component the environment plane serves. What is being
    // themed — an organization's own sign-in, or the environment default every
    // organization inherits — is an explicit choice on the page, offered on the
    // environment plane alone.
    Route::get('/appearance', [AppearanceController::class, 'edit'])->name('appearance');
    Route::post('/appearance', [AppearanceController::class, 'update'])->name('appearance.update');
    // Sign-in rules: the SAME component the environment plane serves, and the half of
    // this pair that never existed. `AuthPolicies::setForOrganization()` had no caller
    // anywhere in the product while both sign-in doors enforced what it writes, so a
    // tenant could be governed by a per-organization policy that nobody — not even the
    // operator — had a way to author.
    Route::get('/sign-in-rules', [AuthPolicyController::class, 'edit'])->name('auth-policy');
    Route::put('/sign-in-rules', [AuthPolicyController::class, 'update'])->name('auth-policy.update');
    Route::delete('/sign-in-rules', [AuthPolicyController::class, 'inherit'])->name('auth-policy.inherit');

    // Access governance (IGA): certification reviews + Segregation-of-Duties policies.
    // The SAME components the environment plane serves. The routable index/new/show
    // shape wins over the organization plane's single page: a campaign URL is something
    // you send to a reviewer, and losing it would be a real regression.
    Route::get('/governance', [AccessReviewController::class, 'index'])->name('governance');
    Route::get('/governance/new', [AccessReviewController::class, 'create'])->name('governance.create');
    Route::post('/governance', [AccessReviewController::class, 'store'])->name('governance.store');
    Route::get('/governance/{campaign}', [AccessReviewController::class, 'show'])->name('governance.show');
    // One decision endpoint rather than certify and revoke as separate routes: they are
    // one act with two answers, and a reviewer moves between them.
    Route::post('/governance/{campaign}/items/{item}', [AccessReviewController::class, 'item'])->name('governance.item');
    Route::post('/governance/{campaign}/close', [AccessReviewController::class, 'close'])->name('governance.close');
    Route::get('/sod-policies', [RoleConflictController::class, 'index'])->name('sod-policies');
    Route::get('/sod-policies/new', [RoleConflictController::class, 'create'])->name('sod-policies.create');
    Route::post('/sod-policies', [RoleConflictController::class, 'store'])->name('sod-policies.store');
    Route::get('/sod-policies/{policy}', [RoleConflictController::class, 'show'])->name('sod-policies.show');
    // Activate and deactivate as ONE endpoint: two states, and the record already knows
    // which it is in.
    Route::post('/sod-policies/{policy}/toggle', [RoleConflictController::class, 'toggle'])->name('sod-policies.toggle');
    Route::delete('/sod-policies/{policy}', [RoleConflictController::class, 'destroy'])->name('sod-policies.destroy');

    // Outbound SCIM provisioning connections (push users OUT to downstream apps).
    // The SAME components the environment plane serves. The routable index/new/show
    // shape wins over the organization plane's single page with its inline form, and
    // this plane gains resume and delete with it — a tenant admin who paused a
    // connection previously had no way to start it again from their own console.
    Route::get('/provisioning', [OutboundSyncController::class, 'index'])->name('provisioning');
    Route::get('/provisioning/new', [OutboundSyncController::class, 'create'])->name('provisioning.create');
    Route::post('/provisioning', [OutboundSyncController::class, 'store'])->name('provisioning.store');
    Route::get('/provisioning/{sync}', [OutboundSyncController::class, 'show'])->name('provisioning.show');
    // Pause and resume as ONE endpoint: two states, and the record already knows which it
    // is in.
    Route::post('/provisioning/{sync}/toggle', [OutboundSyncController::class, 'toggle'])->name('provisioning.toggle');
    Route::delete('/provisioning/{sync}', [OutboundSyncController::class, 'destroy'])->name('provisioning.destroy');

    // AI token vault — the SAME components the environment plane serves, on the routable
    // index/new/show shape. Storing, rotating and granting a downstream credential is
    // sensitive, so every page is behind the sudo step-up gate — as is its mirror on the
    // environment plane, which for a long time had no step-up at all.
    /*
     * EVERY ROUTE BEHIND THE STEP-UP, reads included. The list names the downstream
     * providers this organization integrates with and the detail page names the clients
     * authorized to lease each credential — both are worth a fresh password on their own,
     * and gating only the writes would leave the inventory open to a borrowed session.
     */
    Route::middleware('sudo')->group(function (): void {
        Route::get('/vault', [VaultController::class, 'index'])->name('vault');
        Route::get('/vault/new', [VaultController::class, 'create'])->name('vault.create');
        Route::post('/vault', [VaultController::class, 'store'])->name('vault.store');
        Route::get('/vault/{secret}', [VaultController::class, 'show'])->name('vault.show');
        Route::post('/vault/{secret}/rotate', [VaultController::class, 'rotate'])->name('vault.rotate');
        Route::post('/vault/{secret}/grants', [VaultController::class, 'grant'])->name('vault.grants.store');
        Route::delete('/vault/{secret}/grants/{client}', [VaultController::class, 'revokeGrant'])->name('vault.grants.destroy');
        Route::post('/vault/{secret}/revoke', [VaultController::class, 'revoke'])->name('vault.revoke');
    });
    // Inline hooks: the SAME components the environment plane serves. The routable
    // index/new/show shape wins over the organization plane's single page — an endpoint
    // has a lifecycle worth linking to, and the one-time signing secret needs somewhere
    // to land that is not the row you just submitted.
    Route::get('/hooks', [HookController::class, 'index'])->name('hooks');
    Route::get('/hooks/new', [HookController::class, 'create'])->name('hooks.create');
    Route::post('/hooks', [HookController::class, 'store'])->name('hooks.store');
    Route::get('/hooks/{hook}', [HookController::class, 'show'])->name('hooks.show');
    // Pause and resume as ONE endpoint. There are two states and the record already knows
    // which it is in, so a posted intent would only add a way for the button and the row
    // to disagree about what is being asked for.
    Route::post('/hooks/{hook}/toggle', [HookController::class, 'toggle'])->name('hooks.toggle');
    Route::delete('/hooks/{hook}', [HookController::class, 'destroy'])->name('hooks.destroy');

    // SIEM audit-stream export.

    // Agent approvals (OIDC CIBA): where a signed-in user approves/denies a
    // backchannel request an agent started on their behalf.
    Route::get('/approvals', [MyApprovalController::class, 'index'])->name('approvals');
    Route::post('/approvals/{request}/approve', [MyApprovalController::class, 'approve'])->name('approvals.approve');
    Route::post('/approvals/{request}/deny', [MyApprovalController::class, 'deny'])->name('approvals.deny');

    // RFC 8628 device grant: where a signed-in user approves a device's user_code.
    Route::get('/device', [DeviceApprovalController::class, 'show'])->name('device');
    Route::post('/device/lookup', [DeviceApprovalController::class, 'lookup'])->name('device.lookup');
    Route::post('/device/approve', [DeviceApprovalController::class, 'approve'])->name('device.approve');
    Route::post('/device/deny', [DeviceApprovalController::class, 'deny'])->name('device.deny');

    // Step-up re-authentication ("sudo mode") gate for sensitive actions. Blocked
    // while impersonating: an impersonator must never be able to clear the gate
    // that protects credential changes.
    Route::middleware(BlockDuringImpersonation::class)->group(function (): void {
        Route::get('/sudo', [SudoController::class, 'show'])->name('sudo');
        Route::post('/sudo', [SudoController::class, 'confirm'])->name('sudo.confirm');
    });

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

        Route::get('/', [EnvironmentHomeController::class, 'index'])->name('environment.home');

        /*
         * WHICH TENANT THIS CONSOLE IS ACTING ON — chrome rather than a page, so it has no
         * screen of its own. The search is a JSON endpoint because the set is unbounded and
         * the control has to stay bounded whatever it does.
         */
        Route::get('/acting-organization', [ActingOrganizationController::class, 'search'])->name('environment.acting-organization.search');
        Route::post('/acting-organization', [ActingOrganizationController::class, 'choose'])->name('environment.acting-organization.choose');
        Route::delete('/acting-organization', [ActingOrganizationController::class, 'clear'])->name('environment.acting-organization.clear');

        // Organizations — routable list → create → detail (deep-linkable).
        Route::get('/organizations', [EnvironmentOrganizationController::class, 'index'])->name('environment.organizations');
        Route::get('/organizations/new', [EnvironmentOrganizationController::class, 'create'])->name('environment.organizations.create');
        Route::post('/organizations', [EnvironmentOrganizationController::class, 'store'])->name('environment.organizations.store');
        Route::get('/organizations/{organization}', [EnvironmentOrganizationController::class, 'show'])->name('environment.organizations.show');
        Route::patch('/organizations/{organization}', [EnvironmentOrganizationController::class, 'update'])->name('environment.organizations.update');
        Route::post('/organizations/{organization}/suspend', [EnvironmentOrganizationController::class, 'suspend'])->name('environment.organizations.suspend');
        Route::post('/organizations/{organization}/reactivate', [EnvironmentOrganizationController::class, 'reactivate'])->name('environment.organizations.reactivate');
        Route::delete('/organizations/{organization}', [EnvironmentOrganizationController::class, 'destroy'])->name('environment.organizations.destroy');

        // The roster. Every id is in the URL of its own mutation, so each one re-resolves
        // the member INSIDE the organization rather than trusting the page it came from.
        Route::post('/organizations/{organization}/members', [EnvironmentOrganizationController::class, 'addMember'])->name('environment.organizations.members.store');
        Route::patch('/organizations/{organization}/members/{member}/role', [EnvironmentOrganizationController::class, 'changeMemberRole'])->name('environment.organizations.members.role');
        Route::post('/organizations/{organization}/members/{member}/access', [EnvironmentOrganizationController::class, 'setAccessRole'])->name('environment.organizations.members.access');
        Route::delete('/organizations/{organization}/members/{member}', [EnvironmentOrganizationController::class, 'removeMember'])->name('environment.organizations.members.remove');

        Route::post('/organizations/{organization}/invitations', [EnvironmentOrganizationController::class, 'invite'])->name('environment.organizations.invitations.store');
        Route::delete('/organizations/{organization}/invitations/{invitation}', [EnvironmentOrganizationController::class, 'revokeInvitation'])->name('environment.organizations.invitations.revoke');

        Route::post('/organizations/{organization}/domains', [EnvironmentOrganizationController::class, 'addDomain'])->name('environment.organizations.domains.store');
        Route::post('/organizations/{organization}/domains/{domain}/verify', [EnvironmentOrganizationController::class, 'verifyDomain'])->name('environment.organizations.domains.verify');
        Route::post('/organizations/{organization}/domains/{domain}/capture', [EnvironmentOrganizationController::class, 'toggleCapture'])->name('environment.organizations.domains.capture');
        Route::delete('/organizations/{organization}/domains/{domain}', [EnvironmentOrganizationController::class, 'removeDomain'])->name('environment.organizations.domains.remove');

        // Users — routable list → create → detail. Every lifecycle action names the user
        // in its own URL, so each one re-resolves them through the environment-scoped
        // model rather than trusting the page that drew the button.
        Route::get('/users', [EnvironmentUserController::class, 'index'])->name('environment.users');
        Route::get('/users/new', [EnvironmentUserController::class, 'create'])->name('environment.users.create');
        Route::post('/users', [EnvironmentUserController::class, 'store'])->name('environment.users.store');
        Route::get('/users/{user}', [EnvironmentUserController::class, 'show'])->name('environment.users.show');
        Route::patch('/users/{user}', [EnvironmentUserController::class, 'update'])->name('environment.users.update');

        Route::post('/users/{user}/password', [EnvironmentUserController::class, 'setPassword'])->name('environment.users.password');
        Route::post('/users/{user}/password-reset', [EnvironmentUserController::class, 'sendPasswordReset'])->name('environment.users.password-reset');
        Route::post('/users/{user}/verification', [EnvironmentUserController::class, 'resendVerification'])->name('environment.users.verification');
        Route::post('/users/{user}/verify', [EnvironmentUserController::class, 'markVerified'])->name('environment.users.verify');
        Route::post('/users/{user}/two-factor/reset', [EnvironmentUserController::class, 'resetMfa'])->name('environment.users.mfa');
        Route::post('/users/{user}/deactivate', [EnvironmentUserController::class, 'deactivate'])->name('environment.users.deactivate');
        Route::post('/users/{user}/reactivate', [EnvironmentUserController::class, 'reactivate'])->name('environment.users.reactivate');

        Route::delete('/users/{user}/sessions', [EnvironmentUserController::class, 'revokeAllSessions'])->name('environment.users.sessions.revoke-all');
        Route::delete('/users/{user}/sessions/{session}', [EnvironmentUserController::class, 'revokeSession'])->name('environment.users.sessions.revoke');

        Route::post('/users/{user}/organizations', [EnvironmentUserController::class, 'assignOrganization'])->name('environment.users.organizations.store');
        Route::patch('/users/{user}/organizations/{organization}/role', [EnvironmentUserController::class, 'changeMembershipRole'])->name('environment.users.organizations.role');
        Route::post('/users/{user}/organizations/{organization}/access', [EnvironmentUserController::class, 'setAccessRole'])->name('environment.users.organizations.access');
        Route::delete('/users/{user}/organizations/{organization}', [EnvironmentUserController::class, 'removeMembership'])->name('environment.users.organizations.remove');

        Route::post('/users/{user}/roles', [EnvironmentUserController::class, 'setEnvironmentRole'])->name('environment.users.roles');

        // SSO connections — routable list → create → detail, on the merged component. The
        // URL keeps its old spelling so existing links and bookmarks still resolve; the
        // route names are what the two planes disagree on, and both are preserved.
        Route::get('/single-sign-on', [ConnectionController::class, 'index'])->name('environment.connections');
        Route::post('/single-sign-on/invite', [ConnectionController::class, 'invite'])->name('environment.connections.invite');
        Route::post('/single-sign-on/domains', [ConnectionController::class, 'addDomain'])->name('environment.connections.domains.store');
        Route::post('/single-sign-on/domains/{domain}/verify', [ConnectionController::class, 'verifyDomain'])->name('environment.connections.domains.verify');
        Route::post('/single-sign-on/domains/{domain}/capture', [ConnectionController::class, 'toggleCapture'])->name('environment.connections.domains.capture');
        Route::delete('/single-sign-on/domains/{domain}', [ConnectionController::class, 'removeDomain'])->name('environment.connections.domains.destroy');

        // The SAME component the organization plane serves. It shipped on one plane only
        // — reachable, but not by the person who owns the environment, who holds an
        // account session rather than a subject one and would have had to impersonate one
        // of their own users to reach their own feature.
        Route::get('/social-sign-in', [SocialProviderController::class, 'index'])->name('environment.social-providers');
        Route::post('/social-sign-in', [SocialProviderController::class, 'store'])->name('environment.social-providers.store');
        Route::delete('/social-sign-in/{connection}', [SocialProviderController::class, 'destroy'])->name('environment.social-providers.destroy');
        Route::get('/single-sign-on/new', [ConnectionController::class, 'create'])->name('environment.connections.create');
        Route::post('/single-sign-on/import', [ConnectionController::class, 'importMetadata'])->name('environment.connections.import');
        Route::post('/single-sign-on', [ConnectionController::class, 'store'])->name('environment.connections.store');
        Route::get('/single-sign-on/{connection}', [ConnectionController::class, 'show'])->name('environment.connections.show');
        Route::patch('/single-sign-on/{connection}', [ConnectionController::class, 'update'])->name('environment.connections.update');
        Route::post('/single-sign-on/{connection}/activate', [ConnectionController::class, 'activate'])->name('environment.connections.activate');
        Route::post('/single-sign-on/{connection}/disable', [ConnectionController::class, 'disable'])->name('environment.connections.disable');
        Route::post('/single-sign-on/{connection}/require-sso', [ConnectionController::class, 'requireSso'])->name('environment.connections.require-sso');
        Route::delete('/single-sign-on/{connection}', [ConnectionController::class, 'destroy'])->name('environment.connections.destroy');

        // SAML applications (downstream service providers) — routable list → create →
        // detail. The URL keeps its old spelling so existing links still resolve.
        Route::get('/login-methods', [ServiceProviderController::class, 'index'])->name('environment.sso-providers');
        Route::get('/login-methods/new', [ServiceProviderController::class, 'create'])->name('environment.sso-providers.create');
        Route::post('/login-methods', [ServiceProviderController::class, 'store'])->name('environment.sso-providers.store');
        Route::get('/login-methods/{provider}', [ServiceProviderController::class, 'show'])->name('environment.sso-providers.show');
        Route::patch('/login-methods/{provider}', [ServiceProviderController::class, 'update'])->name('environment.sso-providers.update');
        Route::delete('/login-methods/{provider}', [ServiceProviderController::class, 'destroy'])->name('environment.sso-providers.destroy');

        // Sync users in — routable list → create → detail, on the merged component. The
        // URL keeps its old spelling so existing links and bookmarks still resolve; the
        // route names are what the two planes disagree on, and both are preserved.
        Route::get('/directories', [DirectoryController::class, 'index'])->name('environment.directories');
        Route::post('/directories/invite', [DirectoryController::class, 'invite'])->name('environment.directories.invite');
        Route::get('/directories/new', [DirectoryController::class, 'create'])->name('environment.directories.create');
        Route::post('/directories', [DirectoryController::class, 'store'])->name('environment.directories.store');
        Route::post('/directories/connect', [DirectoryController::class, 'connect'])->name('environment.directories.connect');
        Route::get('/directories/{directory}', [DirectoryController::class, 'show'])->name('environment.directories.show');
        Route::patch('/directories/{directory}', [DirectoryController::class, 'update'])->name('environment.directories.update');
        Route::post('/directories/{directory}/rotate', [DirectoryController::class, 'rotate'])->name('environment.directories.rotate');
        Route::post('/directories/{directory}/toggle', [DirectoryController::class, 'toggle'])->name('environment.directories.toggle');
        Route::post('/directories/{directory}/map', [DirectoryController::class, 'map'])->name('environment.directories.map');
        Route::delete('/directories/{directory}', [DirectoryController::class, 'destroy'])->name('environment.directories.destroy');

        // Outbound sync (provisioning connections) — routable list → create → detail.
        Route::get('/outbound-sync', [OutboundSyncController::class, 'index'])->name('environment.provisioning');
        Route::get('/outbound-sync/new', [OutboundSyncController::class, 'create'])->name('environment.provisioning.create');
        Route::post('/outbound-sync', [OutboundSyncController::class, 'store'])->name('environment.provisioning.store');
        Route::get('/outbound-sync/{sync}', [OutboundSyncController::class, 'show'])->name('environment.provisioning.show');
        Route::post('/outbound-sync/{sync}/toggle', [OutboundSyncController::class, 'toggle'])->name('environment.provisioning.toggle');
        Route::delete('/outbound-sync/{sync}', [OutboundSyncController::class, 'destroy'])->name('environment.provisioning.destroy');

        // Roles — routable list → create → detail (permission editor), on the merged
        // component. The route names are what the two planes disagree on, and both are
        // preserved.
        Route::get('/roles', [RoleController::class, 'index'])->name('environment.roles');
        Route::get('/roles/new', [RoleController::class, 'create'])->name('environment.roles.create');
        Route::post('/roles', [RoleController::class, 'store'])->name('environment.roles.store');
        Route::get('/roles/{role}', [RoleController::class, 'show'])->name('environment.roles.show');
        Route::patch('/roles/{role}', [RoleController::class, 'update'])->name('environment.roles.update');
        Route::post('/roles/{role}/permissions', [RoleController::class, 'permissions'])->name('environment.roles.permissions');
        Route::delete('/roles/{role}', [RoleController::class, 'destroy'])->name('environment.roles.destroy');

        // Permissions — the catalog roles draw from. App-declared permissions arrive
        // via an app's manifest (SDK/API); manual ones are authored here for orgs that
        // don't run an SDK integration.
        Route::get('/permissions', [PermissionController::class, 'index'])->name('environment.permissions');
        Route::post('/permissions', [PermissionController::class, 'store'])->name('environment.permissions.store');
        Route::patch('/permissions/{permission}', [PermissionController::class, 'update'])->name('environment.permissions.update');
        Route::delete('/permissions/{permission}', [PermissionController::class, 'destroy'])->name('environment.permissions.destroy');

        // Access reviews (certification campaigns) — routable list → create → detail.
        Route::get('/access-reviews', [AccessReviewController::class, 'index'])->name('environment.governance');
        Route::get('/access-reviews/new', [AccessReviewController::class, 'create'])->name('environment.governance.create');
        Route::post('/access-reviews', [AccessReviewController::class, 'store'])->name('environment.governance.store');
        Route::get('/access-reviews/{campaign}', [AccessReviewController::class, 'show'])->name('environment.governance.show');
        Route::post('/access-reviews/{campaign}/items/{item}', [AccessReviewController::class, 'item'])->name('environment.governance.item');
        Route::post('/access-reviews/{campaign}/close', [AccessReviewController::class, 'close'])->name('environment.governance.close');

        // Conflict rules (segregation-of-duties) — routable list → create → detail.
        Route::get('/conflict-rules', [RoleConflictController::class, 'index'])->name('environment.sod-policies');
        Route::get('/conflict-rules/new', [RoleConflictController::class, 'create'])->name('environment.sod-policies.create');
        Route::post('/conflict-rules', [RoleConflictController::class, 'store'])->name('environment.sod-policies.store');
        Route::get('/conflict-rules/{policy}', [RoleConflictController::class, 'show'])->name('environment.sod-policies.show');
        Route::post('/conflict-rules/{policy}/toggle', [RoleConflictController::class, 'toggle'])->name('environment.sod-policies.toggle');
        Route::delete('/conflict-rules/{policy}', [RoleConflictController::class, 'destroy'])->name('environment.sod-policies.destroy');

        // Apps & API keys (OAuth clients) — routable list → create → detail, on the
        // merged component. The URLs keep their old spelling so existing links and
        // bookmarks still resolve; the route names are what the two planes disagree on,
        // and both are preserved.
        Route::get('/applications', [ClientController::class, 'index'])->name('environment.clients');
        Route::get('/frontend-keys', [FrontendKeyController::class, 'index'])->name('environment.frontend-keys');
        Route::post('/frontend-keys', [FrontendKeyController::class, 'store'])->name('environment.frontend-keys.store');
        Route::put('/frontend-keys/{key}/origins', [FrontendKeyController::class, 'origins'])->name('environment.frontend-keys.origins');
        Route::delete('/frontend-keys/{key}', [FrontendKeyController::class, 'destroy'])->name('environment.frontend-keys.destroy');
        // Behind sudo, like the token vault and log-stream creation: the button on this
        // page decides where every un-migrated address and the password typed with it is
        // sent. The design deliberately put a person in the loop, and a person who has
        // not proved they are still at the keyboard in the last fifteen minutes is a
        // hijacked or clickjacked session, not a person. The component asks again inside
        // the action, because a Livewire call after the first page load never re-runs
        // route middleware.
        // `env.sudo`, not `sudo`. They are different session keys on purpose — a
        // confirmation on one plane must never satisfy the other — and this page is on the
        // ENVIRONMENT plane, where the person is a platform-root subject. Gated on the
        // organization plane's step-up, the page was unreachable: `RequireSudo` sent them
        // to `/sudo`, which resolves the subject under the ambient tenant scope, finds
        // nothing, and bounces to the tenant end-user login. There is no path from there
        // back. The vault two lines below has said this since the planes merged.
        Route::middleware('env.sudo')->group(function (): void {
            Route::get('/legacy-login', [LegacyLoginController::class, 'show'])->name('environment.legacy-login');
            Route::post('/legacy-login/probe', [LegacyLoginController::class, 'probe'])->name('environment.legacy-login.probe');
            Route::post('/legacy-login/approve', [LegacyLoginController::class, 'approve'])->name('environment.legacy-login.approve');
            Route::post('/legacy-login/revoke', [LegacyLoginController::class, 'revoke'])->name('environment.legacy-login.revoke');
        });
        Route::get('/applications/new', [ClientController::class, 'create'])->name('environment.clients.create');
        Route::post('/applications', [ClientController::class, 'store'])->name('environment.clients.store');
        Route::get('/applications/{client}', [ClientController::class, 'show'])->name('environment.clients.show');
        Route::patch('/applications/{client}', [ClientController::class, 'update'])->name('environment.clients.update');
        Route::put('/applications/{client}/manifest', [ClientController::class, 'saveManifest'])->name('environment.clients.manifest');
        Route::post('/applications/{client}/sync', [ClientController::class, 'sync'])->name('environment.clients.sync');
        Route::post('/applications/{client}/rotate', [ClientController::class, 'rotate'])->name('environment.clients.rotate');
        Route::delete('/applications/{client}', [ClientController::class, 'destroy'])->name('environment.clients.destroy');

        // Webhooks — routable list → create → detail, on the merged component. The URLs
        // are unchanged so existing links and bookmarks still resolve; the route names
        // are what the two planes disagree on, and both are preserved.
        Route::middleware('console.admin')->group(function (): void {
            Route::get('/webhooks', [WebhookController::class, 'index'])->name('environment.webhooks');
            Route::get('/webhooks/new', [WebhookController::class, 'create'])->name('environment.webhooks.create');
            Route::post('/webhooks', [WebhookController::class, 'store'])->name('environment.webhooks.store');
            Route::get('/webhooks/{webhook}', [WebhookController::class, 'show'])->name('environment.webhooks.show');
            Route::patch('/webhooks/{webhook}', [WebhookController::class, 'update'])->name('environment.webhooks.update');
            Route::post('/webhooks/{webhook}/pause', [WebhookController::class, 'pause'])->name('environment.webhooks.pause');
            Route::post('/webhooks/{webhook}/resume', [WebhookController::class, 'resume'])->name('environment.webhooks.resume');
            Route::post('/webhooks/{webhook}/rotate', [WebhookController::class, 'rotate'])->name('environment.webhooks.rotate');
            Route::delete('/webhooks/{webhook}', [WebhookController::class, 'destroy'])->name('environment.webhooks.destroy');
        });
        // Inline hooks — routable list → create → detail, on the merged component. The
        // URL keeps its old spelling so existing links and bookmarks still resolve; the
        // route names are what the two planes disagree on, and both are preserved.
        Route::get('/event-hooks', [HookController::class, 'index'])->name('environment.hooks');
        Route::get('/event-hooks/new', [HookController::class, 'create'])->name('environment.hooks.create');
        Route::post('/event-hooks', [HookController::class, 'store'])->name('environment.hooks.store');
        Route::get('/event-hooks/{hook}', [HookController::class, 'show'])->name('environment.hooks.show');
        Route::post('/event-hooks/{hook}/toggle', [HookController::class, 'toggle'])->name('environment.hooks.toggle');
        Route::delete('/event-hooks/{hook}', [HookController::class, 'destroy'])->name('environment.hooks.destroy');

        // Token vault — routable list → create → detail, on the merged component. The URL
        // keeps its old spelling so existing links and bookmarks still resolve; the route
        // names are what the two planes disagree on, and both are preserved.
        //
        // BEHIND `env.sudo`, which did not exist until this pair was merged. These pages
        // rotate, re-grant and revoke downstream provider credentials for any organization
        // in the environment, and the identical actions on the organization plane have
        // always demanded a fresh password. The asymmetry meant the more privileged door
        // was the one with no step-up behind it.
        Route::middleware('env.sudo')->group(function (): void {
            Route::get('/stored-tokens', [VaultController::class, 'index'])->name('environment.vault');
            Route::get('/stored-tokens/new', [VaultController::class, 'create'])->name('environment.vault.create');
            Route::post('/stored-tokens', [VaultController::class, 'store'])->name('environment.vault.store');
            Route::get('/stored-tokens/{secret}', [VaultController::class, 'show'])->name('environment.vault.show');
            Route::post('/stored-tokens/{secret}/rotate', [VaultController::class, 'rotate'])->name('environment.vault.rotate');
            Route::post('/stored-tokens/{secret}/grants', [VaultController::class, 'grant'])->name('environment.vault.grants.store');
            Route::delete('/stored-tokens/{secret}/grants/{client}', [VaultController::class, 'revokeGrant'])->name('environment.vault.grants.destroy');
            Route::post('/stored-tokens/{secret}/revoke', [VaultController::class, 'revoke'])->name('environment.vault.revoke');
        });

        // Step-up re-authentication for this plane. Inside the env-admin group — only an
        // administrator has anything to step up FROM — but never behind `env.sudo` itself,
        // which would be a gate in front of its own key.
        //
        // BlockDuringImpersonation, which both sibling step-ups carry and this one was
        // missing: an impersonator must never be able to CLEAR the gate that protects
        // credential changes. Clearing it here would have opened the widest of the three
        // planes, where one confirmation covers every tenant in the environment.
        Route::middleware(BlockDuringImpersonation::class)->group(function (): void {
            Route::get('/sudo', [SudoController::class, 'showEnvironment'])->name('environment.sudo');
            Route::post('/sudo', [SudoController::class, 'confirmEnvironment'])->name('environment.sudo.confirm');
        });

        // Activity log — the merged component. The route NAME is preserved on both
        // planes; only the component behind it is now shared.
        Route::get('/audit', [AuditController::class, 'index'])->name('environment.audit');

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
        Route::get('/log-streaming', [LogStreamController::class, 'index'])->name('environment.audit-streams');
        Route::get('/log-streaming/new', [LogStreamController::class, 'create'])->middleware('env.sudo')->name('environment.audit-streams.create');
        Route::post('/log-streaming', [LogStreamController::class, 'store'])->middleware('env.sudo')->name('environment.audit-streams.store');
        Route::get('/log-streaming/{stream}', [LogStreamController::class, 'show'])->name('environment.audit-streams.show');
        Route::post('/log-streaming/{stream}/toggle', [LogStreamController::class, 'toggle'])->name('environment.audit-streams.toggle');
        Route::delete('/log-streaming/{stream}', [LogStreamController::class, 'destroy'])->name('environment.audit-streams.destroy');
        // The SHARED usage page — `environment.analytics` was the primitive version of it
        // over the same counters. Route name kept so existing links and the rail entry
        // keep working; only the component behind it changes.
        Route::get('/analytics', [UsageController::class, 'index'])->name('environment.analytics');
        Route::get('/approvals', [AgentApprovalController::class, 'index'])->name('environment.approvals');
        Route::post('/approvals/{request}/deny', [AgentApprovalController::class, 'deny'])->name('environment.approvals.deny');
        // Settings — the merged component. The route NAME is preserved on both planes;
        // only the component behind it is now shared.
        Route::get('/settings', [SettingsController::class, 'show'])->name('environment.settings');
        Route::patch('/settings', [SettingsController::class, 'rename'])->name('environment.settings.rename');
        // Sign-in rules — the merged component. This plane writes the BASELINE every
        // organization inherits; the organization plane writes one organization's
        // override. Same page, same controls, different level — which is the only
        // difference there has ever been between the two, and until now the organization
        // half of it had no surface at all.
        Route::get('/sign-in-rules', [AuthPolicyController::class, 'edit'])->name('environment.auth-policy');
        Route::put('/sign-in-rules', [AuthPolicyController::class, 'update'])->name('environment.auth-policy.update');
        Route::delete('/sign-in-rules', [AuthPolicyController::class, 'inherit'])->name('environment.auth-policy.inherit');
        // Appearance — the merged component. The route NAME is preserved on both
        // planes; only the component behind it is now shared.
        Route::get('/appearance', [AppearanceController::class, 'edit'])->name('environment.appearance');
        Route::post('/appearance', [AppearanceController::class, 'update'])->name('environment.appearance.update');

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
        Route::get('/', [PlatformEnvironmentController::class, 'index'])->name('platform.environments');
        Route::post('/environments', [PlatformEnvironmentController::class, 'store'])->name('platform.environments.store');
        Route::post('/environments/{environment}/provision', [PlatformEnvironmentController::class, 'provision'])->name('platform.environments.provision');
        Route::get('/usage', PlatformUsageController::class)->name('platform.usage');
        Route::get('/search', PlatformSearchController::class)->name('platform.search');
        Route::get('/customers', [PlatformCustomerController::class, 'index'])->name('platform.customers');
        Route::post('/customers', [PlatformCustomerController::class, 'store'])->name('platform.customers.store');

        // `platform.customers.show`, not `platform.account`. The console derives both the
        // eyebrow above the page title and the lit rail entry from the route name by the
        // same prefix rule ({@see \App\Platform\Navigation\NavPage::owns()}), so a detail
        // page named as a CHILD of its list gets "Platform" over its heading and keeps
        // Accounts lit in the rail without a single hand-written label. `platform.organization`
        // predates that rule and has to pass its own eyebrow; this one does not.
        Route::get('/customers/{organization}', [PlatformCustomerController::class, 'show'])->name('platform.customers.show');
        Route::post('/customers/{organization}/status', [PlatformCustomerController::class, 'toggle'])->name('platform.customers.toggle');
        Route::post('/customers/{organization}/environments/{environment}/target', [PlatformCustomerController::class, 'target'])->name('platform.customers.target');
        Route::post('/customers/{organization}/environments/{environment}/open', [PlatformCustomerController::class, 'open'])->name('platform.customers.open');
        Route::get('/organizations', [PlatformOrganizationController::class, 'index'])->name('platform.organizations');
        Route::post('/organizations', [PlatformOrganizationController::class, 'store'])->name('platform.organizations.store');
        Route::get('/organizations/{organization}', [PlatformOrganizationController::class, 'show'])->name('platform.organization');
        Route::post('/organizations/{organization}/status', [PlatformOrganizationController::class, 'toggle'])->name('platform.organizations.toggle');
        Route::post('/organizations/{organization}/parent', [PlatformOrganizationController::class, 'reparent'])->name('platform.organizations.reparent');
        Route::get('/operators', [OperatorRosterController::class, 'index'])->name('platform.operators');
        Route::post('/operators', [OperatorRosterController::class, 'store'])->name('platform.operators.store');
        Route::post('/operators/{operator}/status', [OperatorRosterController::class, 'toggle'])->name('platform.operators.toggle');
        // Retired: it enrolled an operator TOTP factor nothing verified. A permanent
        // redirect rather than a deletion, because operators have this bookmarked and the
        // honest destination exists — their own account security, which the sign-in path
        // actually checks. See the note in ConsoleServiceProvider.
        Route::permanentRedirect('/security', '/account')->name('platform.security');
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
    // locks the token so it cannot be swapped after the signed load.
    Route::get('/invite/{token}/accept', [InvitationAcceptController::class, 'show'])
        ->middleware('signed')
        ->name('organization.invite.accept');

    // SIGNED TOO, and not as belt-and-braces. The token is the whole credential, and the
    // signature on the page above is what stops a guessed one being tried — so a write
    // that accepted a bare token would hand back exactly what that signature refuses.
    // The form posts to a URL signed on the page it was rendered from.
    Route::post('/invite/{token}/accept', [InvitationAcceptController::class, 'store'])
        ->middleware('signed')
        ->name('organization.invite.accept.store');
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

/*
 * What the `cbox` CLI needs before it can sign in here — the environment's
 * issuer and the client id it should present.
 *
 * NOT in routes/api.php: that file is mounted under `/api`, and a `.well-known`
 * document at `/api/.well-known/…` is not a well-known document. Registered the
 * same way the authenticator's bootstrap is, on the environment resolved from
 * the host, unauthenticated because a public client's id is not a credential.
 */
Route::middleware(['api', ApiResolveEnvironment::class, 'throttle:60,1'])
    ->get('/.well-known/cbox-cli', CliBootstrapController::class)
    ->name('cli.bootstrap');
