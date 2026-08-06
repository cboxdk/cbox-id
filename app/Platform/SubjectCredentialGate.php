<?php

declare(strict_types=1);

namespace App\Platform;

use App\Platform\Enums\CredentialVerdict;
use Cbox\Id\Identity\Contracts\AdminPasswords;
use Cbox\Id\Identity\Enums\SsoEnforcement;
use Cbox\Id\Platform\PlatformRoot;

/**
 * The rules that decide whether a proven factor is still a way in.
 *
 * It began as the reconciliation of two password doors that disagreed — the account
 * console's and, on a single-host deployment, the environment admin's. The account door
 * checked the SSO mandate and administrative password expiry; the admin door checked
 * neither, so an environment whose policy mandated SSO could still be entered with a local
 * password there. One class, asked by both, so a rule could not be honoured at one door
 * and skipped at the other.
 *
 * NEITHER OF THOSE DOORS EXISTS NOW, and this is what the argument turns into once the
 * duplication is removed rather than merely reconciled. There is one password door —
 * `/login`, which authenticates the SUBJECT — and it asks the same three rules directly
 * ({@see PlatformAuth::attemptPassword()}: the lockout, `AdminPasswords::hasExpired()`,
 * and the mandate through `localSignInAllowedFor()`). A customer's people are ordinary
 * subjects, so those rules already reach them; a second copy here, keyed on a row beside the
 * subject, would be the divergence this class was written to end, pointing the other way.
 *
 * What is left is the half that is NOT about a password: the doors that ask it have proved
 * some other factor — an accepted invitation, a redeemed magic link, a passkey ceremony —
 * and the mandate was honoured by the password and ignored by every one of those. That
 * divergence is real and still live, which is why this class is still here and still narrow.
 *
 * IT TAKES A SUBJECT ID NOW, and that is the whole of what changed when the account plane
 * went. Every method here took an `Membership` and immediately reduced it to
 * `$member->subject_id`, through a private helper that existed only to do the reduction and
 * to return null when there was none. The member row was never the question — it was a
 * detour to the subject, and it carried a null case (the bootstrap window, an account
 * provisioned before the deployment had a platform root) that cannot happen any more.
 *
 * Every check runs in the PLATFORM ROOT's scope: policies and memberships are
 * environment-owned, and a customer's people live there.
 */
final class SubjectCredentialGate
{
    public function __construct(
        private readonly PlatformRoot $platformRoot,
        private readonly AdminPasswords $adminPasswords,
        private readonly PlatformAuth $platform,
    ) {}

    /**
     * Whether a mandate refuses this member a session for a factor that is NOT a password
     * — a passkey ceremony, a redeemed magic link, an accepted invitation, a reset link.
     *
     * Everything a PASSWORD door checks about the credential itself drops away here, and
     * deliberately: an administratively-issued temporary password whose deadline has
     * passed is a fact about a PASSWORD, and refusing somebody's passkey because of it
     * would be the same category error in the other direction. So this asks the one rule
     * that is not about the credential at all. A mandate says which DIRECTORY decides who
     * gets in; a passkey is a very good answer to a question the organization has said it
     * no longer asks.
     *
     * A passkey is the arguable one, and it is refused on purpose. It is phishing-resistant
     * and device-bound, and a deployment could reasonably decide it stands alongside SSO —
     * but that is the ORGANIZATION's decision and there is nowhere for them to make it:
     * {@see SsoEnforcement} has three cases, and the strongest is documented as "SSO is the
     * only way in" — which is also the sentence the console shows the administrator who
     * turns it on. Permitting passkeys under that would be us making an exception on their
     * behalf, by silence, against the words they were shown. If a per-organization
     * allowance is ever wanted it belongs on the policy, on the sign-in rules page, and in
     * what the console promises — not here, and not by omission.
     *
     * Only two verdicts are reachable, and it still returns the enum: this is the same
     * question the password door answers, and a bool here would be the parallel vocabulary
     * that lets one door start disagreeing with the other again.
     */
    public function admitsFactor(string $subjectId): CredentialVerdict
    {
        if ($subjectId === '') {
            return CredentialVerdict::Admitted;
        }

        return $this->platformRoot->run(
            fn (): CredentialVerdict => $this->platform->localSignInAllowedFor($subjectId)
                ? CredentialVerdict::Admitted
                : CredentialVerdict::SsoRequired,
        ) ?? CredentialVerdict::Admitted;
    }

    /**
     * Whether this person owes a password change before the credential should open
     * anything.
     *
     * The console HOLDS them on a change page. The environment admin console refuses
     * outright instead: it is the highest-privilege surface on a tenant, it has no page on
     * which the password could be changed, and a password the administrator who issued it
     * also knows has no business opening it.
     */
    public function owesPasswordChange(string $subjectId): bool
    {
        if ($subjectId === '') {
            return false;
        }

        return $this->platformRoot->run(
            fn (): bool => $this->adminPasswords->requiresChange($subjectId),
        ) === true;
    }
}
