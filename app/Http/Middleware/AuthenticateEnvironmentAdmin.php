<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Platform\EnvironmentAdminAuth;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Gate for a tenant environment's ADMIN console. Access requires an environment-admin
 * session — an ACCOUNT-layer member ({@see EnvironmentAdminAuth}) who holds access to
 * THIS environment. A subject (end-user) session grants nothing here, and vice versa:
 * the admin of a tenant is never a subject inside it.
 *
 * A request without a valid admin session is sent to the subdomain "sign in as admin"
 * door, which authenticates against the account layer.
 */
final class AuthenticateEnvironmentAdmin
{
    public function __construct(private readonly EnvironmentAdminAuth $auth) {}

    /**
     * @param  Closure(Request): Response  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        if ($this->auth->current() === null) {
            $request->session()->put('url.intended', $request->fullUrl());

            return redirect()->route('admin.login');
        }

        return $next($request);
    }
}
