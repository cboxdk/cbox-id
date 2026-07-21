<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Platform\CurrentUser;
use App\Platform\PlatformAuth;
use Cbox\Id\Identity\Contracts\SessionManager;
use Cbox\Id\Identity\Contracts\Subjects;
use Cbox\Id\Organization\Contracts\Memberships;
use Cbox\Id\Organization\Contracts\Organizations;
use Cbox\Id\Organization\Enums\OrganizationStatus;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Resolves the platform session held in the browser cookie into the current
 * subject + organization. A missing or revoked session bounces to login — the
 * framework's session store, not the cookie, is authoritative.
 */
final class Authenticate
{
    public function __construct(
        private readonly SessionManager $sessions,
        private readonly Subjects $subjects,
        private readonly Organizations $organizations,
        private readonly Memberships $memberships,
        private readonly CurrentUser $current,
    ) {}

    /**
     * @param  Closure(Request): Response  $next
     * @param  string|null  $mode  pass `optional` to RESOLVE the subject without requiring one
     */
    public function handle(Request $request, Closure $next, ?string $mode = null): Response
    {
        // `optional`: populate CurrentUser when there IS a valid session, and continue as
        // a guest when there is not — for endpoints that must answer an unauthenticated
        // caller themselves rather than being redirected away.
        //
        // /oauth/authorize is the case: OIDC Core §3.1.2.6 requires prompt=none to return
        // error=login_required TO THE CLIENT. Moving that route out of this middleware
        // entirely was WRONG — CurrentUser is populated ONLY here, so check() became
        // permanently false and no authorization code could be issued at all. Optional
        // mode keeps every re-check below (revoked session, deactivated subject,
        // suspended org) instead of the component re-implementing them badly.
        $optional = $mode === 'optional';
        $sessionId = $request->session()->get(PlatformAuth::SESSION_KEY);

        $session = is_string($sessionId) ? $this->sessions->active($sessionId) : null;

        if ($session === null) {
            $request->session()->forget(PlatformAuth::SESSION_KEY);

            if ($optional) {
                return $next($request);
            }

            // guest() stashes the intended URL, so a user sent here mid-flow (e.g.
            // /oauth/authorize?…) is returned to complete it after logging in.
            return redirect()->guest(route('login'));
        }

        $subject = $this->subjects->find($session->user_id);

        // find() still returns a deactivated/suspended subject, so a live cookie
        // would otherwise outlive the account being disabled. Re-check per request
        // (as OperatorAuth::current() does) and refuse an inactive subject.
        if ($subject === null || ! $this->subjects->isActive($subject->id)) {
            $request->session()->forget(PlatformAuth::SESSION_KEY);

            if ($optional) {
                return $next($request);
            }

            return redirect()->guest(route('login'));
        }

        // Resolve the active org — but ONLY honour one the subject is a member of.
        // A tampered/stale org id in the cookie must never grant a view into
        // another tenant, so an unauthorized id is ignored and we fall back to a
        // real membership.
        $requestedOrgId = $request->session()->get(PlatformAuth::ORG_KEY) ?? $session->organization_id;

        $organization = null;
        $role = null;

        if (is_string($requestedOrgId) && ($membership = $this->memberships->of($requestedOrgId, $subject->id)) !== null) {
            $organization = $this->organizations->find($requestedOrgId);
            $role = $membership->role;
        }

        if ($organization === null) {
            $fallbackOrgId = $this->memberships->forUser($subject->id)->first()?->organization_id;

            if (is_string($fallbackOrgId)) {
                $organization = $this->organizations->find($fallbackOrgId);
                $role = $this->memberships->of($fallbackOrgId, $subject->id)?->role;
                $request->session()->put(PlatformAuth::ORG_KEY, $fallbackOrgId);
            }
        }

        // Deny-by-default on tenant suspension: an operator can suspend an org, and
        // that must take effect on the very next request — no console access, no
        // token minting — not just at the next login.
        if ($organization !== null && $organization->status === OrganizationStatus::Suspended) {
            abort(403, 'This organization has been suspended. Contact your platform operator.');
        }

        $this->current->set($subject, $session, $organization, $role);

        return $next($request);
    }
}
