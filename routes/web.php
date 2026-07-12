<?php

declare(strict_types=1);

use App\Http\Controllers\MagicLinkController;
use App\Http\Controllers\SessionController;
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
    Volt::route('/signup', 'auth.signup')->name('signup');
    Route::get('/magic/{token}', [MagicLinkController::class, 'redeem'])->name('magic.redeem');
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

    Route::post('/organization/switch', [SessionController::class, 'switchOrganization'])->name('organization.switch');
});
