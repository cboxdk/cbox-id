<?php

declare(strict_types=1);

use App\Http\Controllers\Auth\ConsoleSignInController;
use Illuminate\Support\Facades\Route;

/*
 * LOGIN-HOST MODE: what the login host calls on the console, server to server.
 *
 * OIDC Back-Channel Logout 1.0: the login host POSTs a signed logout token here when an
 * SSO session ends, and the console ends the sessions handed down from it
 * ({@see \App\Platform\ConsoleSignIn\ConsoleBackchannelLogout}). A file of its own and
 * not inside `web`, like `/mcp`: the caller is a server with no cookie, so there is no
 * session to start and no CSRF token to ask for. The token's signature is the control.
 * It answers 404 in single-host mode.
 */
Route::post('/auth/backchannel-logout', [ConsoleSignInController::class, 'backchannelLogout'])
    ->middleware(['plane:console', 'throttle:120,1'])
    ->name('console.auth.backchannel-logout');
