<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Platform\OperatorAuth;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Gate the operator console. Only an authenticated platform operator may pass;
 * everyone else is sent to the operator sign-in. This is a distinct boundary
 * from the org-user console — an org-user session grants nothing here.
 */
final class AuthenticateOperator
{
    public function __construct(private readonly OperatorAuth $auth) {}

    /**
     * @param  Closure(Request): Response  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        if (! $this->auth->check()) {
            return redirect()->route('operator.login');
        }

        return $next($request);
    }
}
