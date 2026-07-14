<?php

declare(strict_types=1);

use App\Http\Controllers\EmailVerificationController;
use App\Http\Controllers\InvitationController;
use App\Http\Controllers\MagicLinkController;
use App\Http\Controllers\OperatorController;
use App\Http\Controllers\PasskeyController;
use App\Http\Controllers\SessionController;
use App\Http\Controllers\SocialController;
use App\Http\Middleware\AuthenticateOperator;
use App\Platform\PlatformAuth;
use Illuminate\Support\Facades\Route;
use Livewire\Volt\Volt;

Route::get('/', function () {
    return redirect()->route(
        session()->has(PlatformAuth::SESSION_KEY) ? 'dashboard' : 'login'
    );
})->name('home');

/*
 * Guest — the sign-in surface.
 */
Route::middleware('platform.guest')->group(function (): void {
    Volt::route('/login', 'auth.login')->name('login');
    Volt::route('/o/{slug}/login', 'auth.login')->name('login.branded');
    Volt::route('/signup', 'auth.signup')->name('signup');
    Route::get('/magic/{token}', [MagicLinkController::class, 'redeem'])->name('magic.redeem');

    // Password reset — request a link, then choose a new password from the token.
    Volt::route('/forgot-password', 'auth.forgot-password')->name('password.request');
    Volt::route('/reset-password/{token}', 'auth.reset-password')->name('password.reset');

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

// Invitation acceptance — the token is the proof; accepting signs the invitee in.
Route::get('/invitations/{token}/accept', [InvitationController::class, 'accept'])->name('invitation.accept');

// Email verification — the token is the proof; clickable while signed in or out.
Route::get('/verify-email/{token}', [EmailVerificationController::class, 'verify'])->name('verification.verify');

Route::post('/logout', [SessionController::class, 'destroy'])->name('logout');

/*
 * Authenticated console.
 */
Route::middleware('platform.auth')->group(function (): void {
    Volt::route('/dashboard', 'dashboard')->name('dashboard');
    Volt::route('/members', 'members')->name('members');
    Volt::route('/connections', 'connections')->name('connections');
    Volt::route('/directories', 'directories')->name('directories');
    Volt::route('/roles', 'roles')->name('roles');
    Volt::route('/clients', 'clients')->name('clients');
    Volt::route('/webhooks', 'webhooks')->name('webhooks');
    Volt::route('/audit', 'audit')->name('audit');
    Volt::route('/settings', 'settings')->name('settings');

    // RFC 8628 device grant: where a signed-in user approves a device's user_code.
    Volt::route('/device', 'device')->name('device');

    // Step-up re-authentication ("sudo mode") gate for sensitive actions.
    Volt::route('/sudo', 'auth.sudo')->name('sudo');

    // Interactive OIDC/OAuth consent — Cbox ID as an identity provider.
    Volt::route('/oauth/authorize', 'oauth.consent')->name('oauth.authorize');

    Route::post('/organization/switch', [SessionController::class, 'switchOrganization'])->name('organization.switch');

    // Passkey enrolment (adds a credential to the signed-in subject). Adding a
    // credential is persistence — gate it behind a fresh step-up, symmetric with
    // the sudo required to REMOVE a passkey in settings.
    Route::post('/passkeys/register/options', [PasskeyController::class, 'registerOptions'])->middleware('sudo')->name('passkeys.register.options');
    Route::post('/passkeys/register', [PasskeyController::class, 'register'])->middleware('sudo')->name('passkeys.register');

    // Explicit account linking — connect a social provider to the signed-in user.
    // Also a new way in, so it likewise requires a fresh step-up.
    Route::get('/settings/connect/{provider}/redirect', [SocialController::class, 'connect'])->middleware('sudo')->name('social.connect');
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
    Route::post('/logout', [OperatorController::class, 'logout'])->name('operator.logout');

    Route::middleware(AuthenticateOperator::class)->group(function (): void {
        Volt::route('/', 'operator.environments')->name('operator.environments');
        Volt::route('/organizations', 'operator.organizations')->name('operator.organizations');
        Volt::route('/operators', 'operator.operators')->name('operator.operators');
        Route::post('/environment/switch', [OperatorController::class, 'switchEnvironment'])->name('operator.environment.switch');
    });
});
