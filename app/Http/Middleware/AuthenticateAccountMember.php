<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Platform\AccountAuth;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Gate the workspace console (the account-member plane). Only an authenticated
 * account member may pass; everyone else is sent to the workspace sign-in. This
 * is a distinct boundary from both the org-user console and the operator console
 * — a session on either of those grants nothing here.
 */
final class AuthenticateAccountMember
{
    public function __construct(private readonly AccountAuth $auth) {}

    /**
     * @param  Closure(Request): Response  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        if (! $this->auth->check()) {
            // Remember where they were headed so sign-in returns them there. This is
            // what lets the tenant→root admin handoff round-trip: an unauthenticated
            // admin bounced here to /open/{env} signs in once and lands back on the
            // mint step, which hands off to the environment console.
            $request->session()->put('url.intended', $request->fullUrl());

            return redirect()->route('workspace.login');
        }

        return $next($request);
    }
}
