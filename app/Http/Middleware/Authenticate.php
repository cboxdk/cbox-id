<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Platform\CurrentUser;
use App\Platform\PlatformAuth;
use Cbox\Id\Identity\Contracts\SessionManager;
use Cbox\Id\Identity\Contracts\Subjects;
use Cbox\Id\Organization\Contracts\Memberships;
use Cbox\Id\Organization\Contracts\Organizations;
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

    public function handle(Request $request, Closure $next): Response
    {
        $sessionId = $request->session()->get(PlatformAuth::SESSION_KEY);

        $session = is_string($sessionId) ? $this->sessions->active($sessionId) : null;

        if ($session === null) {
            $request->session()->forget(PlatformAuth::SESSION_KEY);

            return redirect()->route('login');
        }

        $subject = $this->subjects->find($session->user_id);

        if ($subject === null) {
            return redirect()->route('login');
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
            $fallbackOrgId = $this->memberships->forUser($subject->id)->value('organization_id');

            if (is_string($fallbackOrgId)) {
                $organization = $this->organizations->find($fallbackOrgId);
                $role = $this->memberships->of($fallbackOrgId, $subject->id)?->role;
                $request->session()->put(PlatformAuth::ORG_KEY, $fallbackOrgId);
            }
        }

        $this->current->set($subject, $session, $organization, $role);

        return $next($request);
    }
}
