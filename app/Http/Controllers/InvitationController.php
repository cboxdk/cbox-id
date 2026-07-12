<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Platform\PlatformAuth;
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
    public function accept(Request $request, string $token, Invitations $invitations, Subjects $subjects, PlatformAuth $auth): RedirectResponse
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

        $auth->establish($request, $subject->id, ['invitation']);
        $auth->switchOrganization($request, $invitation->organization_id);

        return redirect()->route('dashboard')->with('status', 'Invitation accepted — welcome aboard.');
    }
}
