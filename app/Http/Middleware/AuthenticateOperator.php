<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Platform\Console\ConsoleScope;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Gate the platform pages on operator AUTHORITY, not on a second sign-in.
 *
 * This used to check `OperatorAuth::check()` — a session key written by a login form of
 * its own, against a credential store of its own. That was never a security boundary
 * worth having: `platform_operators` held an email and a bcrypt hash and nothing else, so
 * the widest reach in the product sat behind the weakest door, and it was weakest
 * *because* it was separate. Everything that protects a sign-in here — password policy,
 * breached-password refusal, lockout, TOTP, passkeys, step-up, session revocation — lives
 * on the subject, and the operator had none of it.
 *
 * The operator is a subject now. So there is one sign-in, and this asks the question the
 * separate door was standing in for: does the person already signed in run this
 * deployment?
 *
 * Two different refusals, deliberately:
 *
 *  - Nobody signed in → the sign-in page, because that is a step the visitor can take.
 *  - Signed in, not an operator → 404, not 403. A 403 confirms the page exists and that
 *    this deployment has a staff console at that address; anyone with any account on the
 *    platform could enumerate it. 404 is what a route they may not see should look like,
 *    and it is what the plane bulkheads already answer with.
 *
 * Both questions go to {@see ConsoleScope}, and that is the point rather than tidiness.
 * The first version asked `CurrentUser::check()` — the SUBJECT session — while the scope
 * resolved operators from a second, account-member one. The gate pointed at the account
 * door, which wrote only the member key, so an operator who did exactly what it told them
 * to was refused and sent straight back to it. Forever. Two places answering "who is
 * signed in" is how that happens; there is one session and one door now, and this sends
 * people to it without asking what shape the deployment is — `/login` answers on every
 * host, which is the whole of what the account door's `plane:account` gate got wrong.
 */
final class AuthenticateOperator
{
    public function __construct(private readonly ConsoleScope $scope) {}

    /**
     * @param  Closure(Request): Response  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        if (! $this->scope->signedIn()) {
            return redirect()->route('login');
        }

        abort_unless($this->scope->isPlatformOperator(), 404);

        return $next($request);
    }
}
