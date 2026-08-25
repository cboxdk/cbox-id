<?php

declare(strict_types=1);

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\PageController;
use App\Http\Requests\Auth\AcceptInvitationRequest;
use App\Platform\Enums\CredentialVerdict;
use App\Platform\Enums\RefusedFactor;
use App\Platform\PlatformAuth;
use App\Platform\SsoRefusal;
use App\Platform\SubjectCredentialGate;
use Cbox\Id\Identity\Contracts\Subjects;
use Cbox\Id\Organization\Contracts\Invitations;
use Cbox\Id\Organization\Contracts\Organizations;
use Cbox\Id\Platform\PlatformRoot;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\URL;
use Inertia\Response;

/**
 * ACCEPTING AN INVITATION: the invitee sets a password and is signed in.
 *
 * BOTH HALVES ARE SIGNED, and that is not belt-and-braces. The token is the whole
 * credential, and `signed` on the page is what stops a guessed one being tried — so a
 * write that accepted a bare token would hand back exactly what the signature on the page
 * refuses. The form posts to a signed URL minted on the page it was rendered from.
 *
 * It redeems an INVITATION TOKEN, not a member id. The account plane's invitation was a
 * member row in an `invited` state, so the link named the row and "accepting" meant
 * activating it. An invitation is its own record with its own single-use token, and the
 * subject it creates is an ordinary one.
 */
final readonly class InvitationAcceptController extends PageController
{
    public function show(
        string $token,
        Invitations $invitations,
        Organizations $organizations,
        PlatformRoot $platformRoot,
    ): Response|RedirectResponse {
        $invitation = $platformRoot->run(fn () => $invitations->byToken($token));

        /*
         * `isPending()`, not merely "was found". `byToken()` resolves a REDEEMED or
         * revoked invitation just as happily as a live one — it is a lookup by hash, not
         * a validity check — so without this the page renders for a spent link and asks
         * the visitor to choose a password before refusing them. A refusal that arrives
         * after the form is the shape people learn to distrust.
         */
        if ($invitation === null || ! $invitation->isPending()) {
            return to_route('login')
                ->with('status', 'This invitation is no longer valid. Try signing in.');
        }

        return $this->page('auth/accept-invite', 'Accept invitation', [
            'email' => $invitation->email,
            'organizationName' => $platformRoot->run(
                fn () => $organizations->find($invitation->organization_id)?->name,
            ),
            // Signed, and minted here. The token in it is the same one that got them to
            // this page; the signature is what stops the write being reached with another.
            'acceptUrl' => URL::signedRoute('organization.invite.accept.store', ['token' => $token]),
        ]);
    }

    public function store(
        AcceptInvitationRequest $request,
        string $token,
        Invitations $invitations,
        Subjects $subjects,
        SubjectCredentialGate $gate,
        PlatformAuth $auth,
        PlatformRoot $platformRoot,
    ): RedirectResponse {
        $invitation = $platformRoot->run(fn () => $invitations->byToken($token));

        if ($invitation === null || ! $invitation->isPending()) {
            return to_route('login')->with('error', 'That invitation is invalid or has expired.');
        }

        $email = (string) $invitation->email;
        $existing = $platformRoot->run(fn () => $subjects->findByEmail($email));

        $subject = $platformRoot->run(
            fn () => $existing ?? $subjects->create($email, null, $request->password()),
        );

        if ($subject === null) {
            return to_route('login')->with('error', 'That invitation could not be completed.');
        }

        // Single-use by the token itself, so a replayed or racing accept redeems nothing.
        $membership = $platformRoot->run(fn () => $invitations->accept($token, $subject->id));

        if ($membership === null) {
            return to_route('login')->with('error', 'That invitation is invalid or has expired.');
        }

        /*
         * The membership is REAL now and stays real — the invitation was genuine and this
         * person belongs here. What a mandate refuses is the SESSION: an invitation is an
         * emailed bearer token, and the organization has said email possession does not
         * open its console.
         *
         * AFTER acceptance rather than before it, and the awkward part is deliberate. The
         * password they just chose will never sign them in, which reads as a wasted step —
         * but the membership is what an SSO assertion can later land on, so refusing
         * earlier would leave them permanently unable to enter by the very door they are
         * being sent to. Better a spare password than a dead end.
         */
        if ($gate->admitsFactor($subject->id) === CredentialVerdict::SsoRequired) {
            SsoRefusal::hold($subject->id, RefusedFactor::Invitation);

            return to_route('login');
        }

        $platformRoot->run(fn () => $auth->establish(request(), $subject->id, ['invitation']));

        return to_route('projects');
    }
}
