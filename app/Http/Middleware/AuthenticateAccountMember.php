<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Platform\AccountAuth;
use Cbox\Id\Identity\Contracts\AdminPasswords;
use Cbox\Id\Platform\PlatformRoot;
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
    public function __construct(
        private readonly AccountAuth $auth,
        private readonly AdminPasswords $adminPasswords,
        private readonly PlatformRoot $platformRoot,
    ) {}

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

        // Same standing requirement as the subject plane: a temporary password issued by
        // an administrator holds every authenticated request until it is replaced, not
        // merely the sign-in that used it. The subject is the credential of record here
        // too (see docs/core-concepts/unified-account-identity.md), so the requirement is
        // read against it and satisfied by changing it.
        $subjectId = $this->auth->current()?->subject_id;

        if (is_string($subjectId)
            && ! $request->routeIs('workspace.password.change', 'workspace.logout')
            && $this->platformRoot->run(fn (): bool => $this->adminPasswords->requiresChange($subjectId))
        ) {
            return redirect()->route('workspace.password.change');
        }

        return $next($request);
    }
}
