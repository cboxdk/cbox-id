<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\InvitationRoleGrant;
use App\Platform\PlatformAuth;
use App\Platform\SodGuard;
use Cbox\Id\AccessControl\Contracts\Roles;
use Cbox\Id\AccessControl\Enums\GrantSource;
use Cbox\Id\AccessControl\Exceptions\UnknownRole;
use Cbox\Id\Identity\Contracts\Subjects;
use Cbox\Id\Kernel\Audit\Contracts\AuditLog;
use Cbox\Id\Kernel\Audit\Enums\ActorType;
use Cbox\Id\Kernel\Audit\ValueObjects\AuditEvent;
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
    public function accept(Request $request, string $token, Invitations $invitations, Subjects $subjects, PlatformAuth $auth, Roles $roles, SodGuard $sod, AuditLog $audit): RedirectResponse
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

        /** @var list<string> $withheld */
        $withheld = [];

        // The SoD gate applies to a deferred grant exactly as it does to a live one. The
        // invite form refuses a toxic SET up front, but policies can be defined between
        // the invite and its acceptance, and the invitee may already hold roles here from
        // an earlier membership. A conflicting grant is skipped, not fatal: the person
        // still joins, and the governance screen reports what was withheld.
        foreach ($grants as $grant) {
            if ($sod->refuse($invitation->organization_id, $subject->id, $grant->role_id) !== null) {
                continue;
            }

            try {
                $roles->assign($invitation->organization_id, $subject->id, $grant->role_id, GrantSource::Manual);
            } catch (UnknownRole) {
                // The role was retired or deleted between the invitation and the click —
                // an app ships a manifest without it, or an admin removes it. Skipped for
                // the same reason a conflicting grant is skipped: the person still joins.
                //
                // Uncaught, this was not a 500 but a permanent one. The acceptance above
                // commits in its own transaction, so the invitation is already spent when
                // the throw happens: the invitee is a member holding an arbitrary prefix
                // of their roles, is never signed in, and every retry answers "That
                // invitation is invalid or has expired". The parked grant row survives
                // too, so the next invitation to the same address burns identically. The
                // only exits were database surgery or un-retiring the role.
                //
                // The framework's directory reconcile learned this exact lesson one
                // caller earlier; this is the caller nobody updated.
                $withheld[] = $grant->role_id;
            }
        }

        InvitationRoleGrant::query()
            ->where('organization_id', $invitation->organization_id)
            ->where('email', $invitation->email)
            ->delete();

        if ($withheld !== []) {
            $audit->record(new AuditEvent(
                action: 'role.grant_withheld',
                actorType: ActorType::System,
                targetType: 'user',
                targetId: $subject->id,
                context: ['organization_id' => $invitation->organization_id, 'role_ids' => $withheld, 'reason' => 'role retired before the invitation was accepted'],
            ));
        }

        $auth->establish($request, $subject->id, ['invitation']);
        $auth->switchOrganization($request, $invitation->organization_id);

        return redirect()->route('dashboard')->with('status', 'Invitation accepted — welcome aboard.');
    }
}
