<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Platform\AccountAuth;
use App\Platform\Console\ConsoleScope;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Gate the console root: is anybody signed in? That is the whole question.
 *
 * It used to be two — "is this an account member" plus an operator clause bolted on — and
 * the clause was not a courtesy, it was the tell. An operator who owns no account held a
 * SUBJECT session while the account door wrote a MEMBER one, so a member-only gate turned
 * a correct sign-in into a silent loop: the login sent them to the console root, the root
 * refused, the login said nothing because nothing had failed. Both stores are one store
 * now, so there is nothing left to special-case. What a person sees once inside is the
 * rail's business; this gate only decides whether they are anybody.
 *
 * Nothing about STANDING is asked here either. A temporary password, an expired one, an
 * MFA mandate, a federated identity awaiting an answer — those hold every authenticated
 * request on every plane, and they are enforced once, in {@see Authenticate}, which runs
 * immediately before this. A second copy here is how the two planes came to disagree
 * about which of them applied.
 */
final class AuthenticateAccountMember
{
    public function __construct(
        private readonly ConsoleScope $scope,
        private readonly AccountAuth $accounts,
    ) {}

    /**
     * @param  Closure(Request): Response  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        if (! $this->scope->signedIn()) {
            // Remember where they were headed so sign-in returns them there. This is
            // what lets the tenant→root admin handoff round-trip: an unauthenticated
            // admin bounced here to /open/{env} signs in once and lands back on the
            // mint step, which hands off to the environment console.
            $request->session()->put('url.intended', $request->fullUrl());

            return redirect()->route('workspace.login');
        }

        // Signed in is admittance; STANDING is a second question, and it is the one that
        // makes a suspension take effect on a session somebody already holds rather than
        // at their next sign-in. Signing them out is the refusal rather than a bare
        // redirect: they are still authenticated, so a redirect alone would bounce them
        // between a console that will not have them and a sign-in that considers them
        // already in — which is precisely the silent loop this whole change removes.
        //
        // Asked only of a member. Somebody with no membership at all is not a lapsed
        // member; they are a person with no account, which is a smaller console, not a
        // refusal.
        if ($this->accounts->membershipLapsed()) {
            $this->accounts->logout($request);

            return redirect()->route('workspace.login')
                ->withErrors(['email' => 'That workspace is no longer available. Contact your platform operator.']);
        }

        return $next($request);
    }
}
