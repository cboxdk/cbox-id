<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\InvitationRoleGrant;
use App\Platform\PlatformAuth;
use App\Platform\SodGuard;
use Cbox\Id\AccessControl\Contracts\Roles;
use Cbox\Id\AccessControl\Enums\GrantSource;
use Cbox\Id\Identity\Contracts\Subjects;
use Cbox\Id\Organization\Contracts\Invitations;
use Cbox\Id\Organization\Exceptions\InvalidInvitation;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

/**
 * Accept an organization invitation. The token was emailed to the invitee, so
 * possessing it proves control of that address — the same trust as a magic link.
 * Accepting resolves (or creates) the subject for the invited email, grants the
 * membership, and signs them in. Membership is never created without this action.
 */
final class InvitationController extends Controller
{
    public function accept(Request $request, string $token, Invitations $invitations, Subjects $subjects, PlatformAuth $auth, Roles $roles, SodGuard $sod): RedirectResponse
    {
        $invitation = $invitations->byToken($token);

        if ($invitation === null || ! $invitation->isPending()) {
            return redirect()->route('login')->with('error', 'That invitation is invalid or has expired.');
        }

        $subject = $subjects->findByEmail($invitation->email) ?? $subjects->create($invitation->email);

        try {
            $invitations->accept($token, $subject->id);
        } catch (InvalidInvitation) {
            return redirect()->route('login')->with('error', 'That invitation is invalid or has expired.');
        }

        // Apply any access roles chosen for this invite, then clear them — so the
        // invitee lands already holding the roles the admin picked.
        $grants = InvitationRoleGrant::query()
            ->where('organization_id', $invitation->organization_id)
            ->where('email', $invitation->email)
            ->get();

        // The SoD gate applies to a deferred grant exactly as it does to a live one. The
        // invite form refuses a toxic SET up front, but policies can be defined between
        // the invite and its acceptance, and the invitee may already hold roles here from
        // an earlier membership. A conflicting grant is skipped, not fatal: the person
        // still joins, and the governance screen reports what was withheld.
        foreach ($grants as $grant) {
            if ($sod->refuse($invitation->organization_id, $subject->id, $grant->role_id) !== null) {
                continue;
            }

            $roles->assign($invitation->organization_id, $subject->id, $grant->role_id, GrantSource::Manual);
        }

        InvitationRoleGrant::query()
            ->where('organization_id', $invitation->organization_id)
            ->where('email', $invitation->email)
            ->delete();

        $auth->establish($request, $subject->id, ['invitation']);
        $auth->switchOrganization($request, $invitation->organization_id);

        return redirect()->route('dashboard')->with('status', 'Invitation accepted — welcome aboard.');
    }
}
