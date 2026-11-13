<?php

declare(strict_types=1);

namespace App\Platform;

use Cbox\Id\Identity\Contracts\AuthPolicies;
use Cbox\Id\Identity\Contracts\SessionManager;
use Cbox\Id\Identity\Models\Session;
use Cbox\Id\Organization\Enums\MembershipStatus;
use Cbox\Id\Organization\Models\Membership;

/**
 * JOINING AN ORGANIZATION THAT REQUIRES SSO IS THE MANDATE TURNING ON, FOR THAT PERSON.
 *
 * While mandates are per person ({@see SsoMandates::perOrganization()} off), any membership
 * of an organization that requires SSO closes every local door to that person
 * ({@see PlatformAuth::localSignInAllowedFor()}). The doors ask at sign-in, and
 * {@see RevokingAuthPolicies} ends the sessions that predate the mandate being switched on.
 * Neither covered the person who already held a local session and THEN joined such an
 * organization — an accepted invitation, an administrator adding them, a directory
 * provisioning them. Their password, magic-link or passkey session stayed live, switched
 * into the new organization, and acted there with the role it was granted: the mandated
 * identity provider (its MFA, its conditional access, its deprovisioning) never asked.
 *
 * So a membership that becomes active in a mandating organization ends the person's local
 * sessions, exactly as switching the mandate on ends everybody's. A session that came
 * through enterprise SSO (`amr` `sso`) is the door the mandate asks for and stays; so does
 * a support session (`impersonation`), which is an operator's authorized view and not the
 * person's own sign-in.
 *
 * Per organization, nothing is revoked: there the mandate is asked each time an
 * organization is entered ({@see SsoMandates::admits()}), so a local session simply never
 * enters it.
 */
final readonly class SsoMandateOnJoin
{
    public function __construct(
        private SsoMandates $mandates,
        private SessionManager $sessions,
        private AuthPolicies $policies,
    ) {}

    public function joined(Membership $membership): void
    {
        if (SsoMandates::perOrganization()
            || $membership->status !== MembershipStatus::Active
            || ! $this->mandates->mandates($membership->organization_id)
            // A mandate the whole environment already imposes is not new to this person:
            // joining changed nothing, and switching that one on revoked what it had to.
            || ! $this->policies->resolve()->sso->allowsPasswordLogin()) {
            return;
        }

        $live = Session::query()
            ->where('user_id', $membership->user_id)
            ->whereNull('revoked_at')
            ->where('expires_at', '>', now())
            ->get();

        foreach ($live as $session) {
            $amr = (array) $session->amr;

            if (in_array('sso', $amr, true) || in_array('impersonation', $amr, true)) {
                continue;
            }

            $this->sessions->revoke($session->id);
        }
    }
}
