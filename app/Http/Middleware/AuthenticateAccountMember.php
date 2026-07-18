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
            return redirect()->route('workspace.login');
        }

        return $next($request);
    }
}
