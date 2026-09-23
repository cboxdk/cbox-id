<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Http\Props\Auth\LinkConfirmationProps;
use App\Http\Props\Auth\LinkFact;
use App\Platform\Enums\RefusedFactor;
use App\Platform\Invitations\AppReturnTargets;
use App\Platform\Invitations\Contracts\OrganizationInvitations;
use App\Platform\PlatformAuth;
use App\Platform\SsoRefusal;
use Cbox\Id\Organization\Exceptions\InvalidInvitation;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\Response as HttpResponse;

/**
 * Accept an organization invitation. The token was emailed to the invitee, so possessing
 * it proves control of that address — the same trust as a magic link. Accepting resolves
 * (or creates) the subject for the invited email, grants the membership, and signs them in.
 * Membership is never created without this action.
 *
 * TWO REQUESTS. The link opens {@see self::show()}, which says who is inviting whom to what
 * and spends nothing; the button POSTs to {@see self::accept()}. Accepting on the GET meant a
 * mail scanner fetching the link accepted the invitation — and was signed in as the invitee.
 *
 * AND THEN BACK TO THE APP. An invitation sent on an app's behalf carries where in that app
 * to land ({@see AppReturnTargets}), re-checked here against the
 * app's registered origins; without one the person lands in this console as before.
 *
 * If that organization mandates SSO, the joining still happens and the session does not:
 * they are a member, and their way in is the identity provider their administrator chose.
 */
final readonly class InvitationController extends PageController
{
    public function show(string $token, OrganizationInvitations $invitations): Response|RedirectResponse
    {
        $preview = $invitations->preview($token);

        if ($preview === null) {
            return redirect()->route('login')->with('error', 'That invitation is invalid or has expired.');
        }

        $facts = [new LinkFact('Organization', $preview->organizationName)];

        if ($preview->inviterName !== null) {
            $facts[] = new LinkFact('Invited by', $preview->inviterName);
        }

        $facts[] = new LinkFact('Role', $preview->role->label());
        $facts[] = new LinkFact('Your email', $preview->email);

        if ($preview->appName !== null) {
            $facts[] = new LinkFact('App', $preview->appName);
        }

        return $this->page('auth/join-organization', 'Join '.$preview->organizationName, [
            'confirmation' => new LinkConfirmationProps(
                heading: 'Join '.$preview->organizationName.'?',
                lead: $preview->appName === null
                    ? 'You have been invited to join this organization. Accept to become a member and sign in.'
                    : 'You have been invited to join this organization. Accept to become a member, and we will take you to '.$preview->appName.'.',
                actionLabel: 'Accept invitation',
                actionUrl: route('invitation.accept.store', $token),
                facts: $facts,
                note: 'Not expecting this? Close this page — nothing happens unless you accept.',
            ),
        ]);
    }

    public function accept(Request $request, string $token, OrganizationInvitations $invitations, PlatformAuth $auth): HttpResponse
    {
        try {
            $accepted = $invitations->accept($token);
        } catch (InvalidInvitation) {
            return redirect()->route('login')->with('error', 'That invitation is invalid or has expired.');
        }

        // The membership is committed and STAYS committed: the invitation was valid, the
        // roles are granted, and this person is a member now. What a mandate refuses is the
        // session, not the joining — an invitation is an emailed bearer token, and an
        // organization that requires SSO has said that is not what opens a session here.
        //
        // Asked after the acceptance rather than before it: the membership that mandates
        // SSO is the one this request just created.
        if (! $auth->localSignInAllowedFor($accepted->subjectId)) {
            SsoRefusal::hold($accepted->subjectId, RefusedFactor::Invitation);

            return redirect()->route('login');
        }

        $auth->establish($request, $accepted->subjectId, ['invitation']);
        $auth->switchOrganization($request, $accepted->organizationId());

        $returnTo = $accepted->returnTarget?->url;

        // `Inertia::location()`, not a redirect: the button posts over XHR, and an XHR
        // cannot follow a redirect to another origin. This answers with a full-page visit.
        if ($returnTo !== null) {
            return Inertia::location($returnTo);
        }

        return redirect()->route('dashboard')->with('status', 'Invitation accepted — welcome aboard.');
    }
}
