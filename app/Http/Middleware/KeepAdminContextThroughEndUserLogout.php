<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Platform\EnvironmentAdminAuth;
use App\Platform\PlatformAuth;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * AN APP'S SIGN-OUT ENDS THE END USER, NOT THE CONSOLE.
 *
 * On a tenant's host one browser session carries two contexts: the tenant's END USER
 * (hosted sign-in, My account, the SSO session for the tenant's apps) and the environment
 * console's ADMINISTRATOR ({@see EnvironmentAdminAuth::CONTEXT_KEYS}). OIDC RP-initiated
 * logout (`/oauth/logout`, the framework's end-session endpoint) ends the end user by
 * invalidating the whole browser session — which also signed the administrator out of the
 * console on the same host, because an app the tenant's users sign in to asked.
 *
 * This puts the admin context back into the fresh session afterwards, as it was. Only on
 * that endpoint, and only on a tenant's host: on the account host the root's session is
 * the admin context, and an app's sign-out there is a sign-out of it.
 */
final class KeepAdminContextThroughEndUserLogout
{
    public function __construct(private readonly PlatformAuth $auth) {}

    /**
     * @param  Closure(Request): Response  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        if (! $request->is('oauth/logout') || ! $request->hasSession() || $this->auth->onPlatformRoot()) {
            return $next($request);
        }

        $admin = EnvironmentAdminAuth::snapshot();

        $response = $next($request);

        foreach ($admin as $key => $value) {
            if (! $request->session()->has($key)) {
                $request->session()->put($key, $value);
            }
        }

        return $response;
    }
}
