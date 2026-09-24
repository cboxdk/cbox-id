<?php

declare(strict_types=1);

namespace App\Platform\Membership;

use App\Platform\PlatformAuth;
use Cbox\Id\Organization\Contracts\Memberships;
use Cbox\Id\Organization\Contracts\Organizations;
use Cbox\Id\Organization\Models\Membership;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

/**
 * Where somebody goes once the organization their session was acting in is no longer
 * theirs — they left it, or they closed it.
 *
 * The session still names that organization, and an organization-plane session with no
 * organization is refused everywhere (deliberately: "no organization" must never read as
 * "every organization"). So the session is moved to another organization this person
 * belongs to that is still open, or — when there is none — this account is signed out,
 * with a sentence saying why rather than a 403 on the next click.
 */
final readonly class AfterLeaving
{
    public function __construct(
        private Memberships $memberships,
        private Organizations $organizations,
        private PlatformAuth $auth,
    ) {}

    public function land(Request $request, string $subjectId, string $leftOrganizationId, string $message): RedirectResponse
    {
        $next = $this->memberships->forUser($subjectId)
            ->reject(fn (Membership $membership): bool => $membership->organization_id === $leftOrganizationId)
            ->first(fn (Membership $membership): bool => $this->organizations->find($membership->organization_id)?->status->revokesAccess() === false);

        if ($next !== null) {
            $this->auth->switchOrganization($request, $next->organization_id);

            return redirect()->route('dashboard')->with('status', $message);
        }

        $this->auth->logout($request);

        return redirect()->route('login')->with('status', $message.' You are not a member of any other organization here, so you have been signed out.');
    }
}
