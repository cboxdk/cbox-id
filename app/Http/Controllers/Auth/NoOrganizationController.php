<?php

declare(strict_types=1);

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\PageController;
use App\Platform\CurrentUser;
use App\Platform\Enums\RefusedFactor;
use App\Platform\IntendedUrl;
use App\Platform\Invitations\Contracts\OrganizationInvitations;
use App\Platform\Invitations\ValueObjects\OpenInvitation;
use App\Platform\Organizations\OrganizationRequired;
use App\Platform\Organizations\PersonalOrganizations;
use App\Platform\PlatformAuth;
use App\Platform\SsoRefusal;
use Cbox\Id\Organization\Exceptions\InvalidInvitation;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Response;

/**
 * The one screen a signed-in person with no organization meets in the Cbox realm
 * ({@see OrganizationRequired}): the invitations waiting for their address, and
 * "continue with a personal organization". Either answer puts them in an organization and
 * sends them on to where they were going: the authorization that was interrupted, or the
 * console.
 */
final readonly class NoOrganizationController extends PageController
{
    public function show(OrganizationRequired $required, OrganizationInvitations $invitations, CurrentUser $me): Response|RedirectResponse
    {
        // Somebody who has an organization (again) has nothing to answer here.
        if (! $required->appliesTo($me)) {
            return $this->resume();
        }

        $confirmed = $me->emailVerified() && $me->email() !== null;

        return $this->page('auth/no-organization', __('auth.no_organization.title'), [
            'account' => $me->email() ?? $me->name(),
            // Only for a CONFIRMED address: an invitation trusts whoever holds the mailbox,
            // and an unconfirmed account has not shown that it does.
            'invitations' => $confirmed ? array_map(static fn (OpenInvitation $invitation): array => [
                'id' => $invitation->id,
                'organization' => $invitation->organizationName,
                'inviter' => $invitation->inviterName,
                'acceptHref' => route('organization.required.accept', $invitation->id),
            ], $invitations->openFor((string) $me->email())) : [],
            'needsConfirmation' => ! $confirmed,
            'personalHref' => route('organization.required.personal'),
            'signOutHref' => route('logout'),
        ]);
    }

    /** "Continue with a personal organization": found it, stand in it, carry on. */
    public function personal(
        Request $request,
        OrganizationRequired $required,
        PersonalOrganizations $personal,
        PlatformAuth $auth,
        CurrentUser $me,
    ): RedirectResponse {
        $subject = $me->subject();

        if ($subject !== null && $required->appliesTo($me)) {
            $auth->switchOrganization($request, $personal->ensureFor($subject)->id);
        }

        return $this->resume();
    }

    /** Accept one of the invitations listed, as the signed-in person it was sent to. */
    public function accept(
        Request $request,
        string $invitation,
        OrganizationRequired $required,
        OrganizationInvitations $invitations,
        PlatformAuth $auth,
        CurrentUser $me,
    ): RedirectResponse {
        $subject = $me->subject();

        if ($subject === null || ! $required->appliesTo($me)) {
            return $this->resume();
        }

        try {
            $accepted = $invitations->acceptAs($invitation, $subject);
        } catch (InvalidInvitation) {
            return back()->withErrors(['invitation' => __('auth.no_organization.invitation_invalid')]);
        }

        // The same rule the emailed link meets ({@see \App\Http\Controllers\InvitationController}):
        // the joining stands, and an organization that requires SSO is entered through its
        // identity provider, not on the session that happened to be open.
        if (! $auth->localSignInAllowedFor($subject->id)) {
            $auth->logout($request);
            SsoRefusal::hold($subject->id, RefusedFactor::Invitation);

            return redirect()->route('login');
        }

        $auth->switchOrganization($request, $accepted->organizationId());

        return $this->resume();
    }

    private function resume(): RedirectResponse
    {
        return redirect()->to(IntendedUrl::pullForSubject() ?? route('dashboard'));
    }
}
