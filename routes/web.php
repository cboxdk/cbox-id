<?php

declare(strict_types=1);

use App\Http\Controllers\MagicLinkController;
use App\Http\Controllers\PasskeyController;
use App\Http\Controllers\SessionController;
use App\Http\Controllers\SocialController;
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

    // Interactive OIDC/OAuth consent — Cbox ID as an identity provider.
    Volt::route('/oauth/authorize', 'oauth.consent')->name('oauth.authorize');

    Route::post('/organization/switch', [SessionController::class, 'switchOrganization'])->name('organization.switch');

    // Passkey enrolment (adds a credential to the signed-in subject).
    Route::post('/passkeys/register/options', [PasskeyController::class, 'registerOptions'])->name('passkeys.register.options');
    Route::post('/passkeys/register', [PasskeyController::class, 'register'])->name('passkeys.register');
});
