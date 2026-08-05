<?php

declare(strict_types=1);

namespace App\Platform;

use App\Platform\Enums\CredentialVerdict;
use Cbox\Id\Identity\Contracts\AdminPasswords;
use Cbox\Id\Identity\Enums\SsoEnforcement;
use Cbox\Id\Platform\Models\AccountMember;
use Cbox\Id\Platform\PlatformRoot;

/**
 * The rules that decide whether an account member's proven factor is still a way in.
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
 * and the mandate through `localSignInAllowedFor()`). An account member is an ordinary
 * subject, so those rules already reach them; a second copy here, keyed on the member row,
 * would be the divergence this class was written to end, pointing the other way.
 *
 * What is left is the half that is NOT about a password, and could not be folded into the
 * subject door because the doors that ask it are not holding a subject. An accepted
 * invitation identifies a MEMBER, not a session — {@see admitsFactor()} — and the mandate
 * was honoured by the password and ignored by every one of those. That divergence is real
 * and still live, which is why this class is still here and still narrow.
 *
 * Every check runs in the PLATFORM ROOT's scope: policies and memberships are
 * environment-owned, and the account's people live there.
 */
final class MemberCredentialGate
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
     * Everything {@see admits()} checks about the credential itself drops away here, and
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
     * question {@see admits()} answers, and a bool here would be the parallel vocabulary
     * that lets one door start disagreeing with the other again.
     */
    public function admitsFactor(AccountMember $member): CredentialVerdict
    {
        return $this->onSubject(
            $member,
            fn (string $id): CredentialVerdict => $this->platform->localSignInAllowedFor($id)
                ? CredentialVerdict::Admitted
                : CredentialVerdict::SsoRequired,
        ) ?? CredentialVerdict::Admitted;
    }

    /**
     * Whether the member owes a password change before this credential should open
     * anything.
     *
     * The console planes HOLD such a member on a change page. The environment admin
     * console refuses outright instead: it is the highest-privilege surface on a tenant,
     * it has no page on which an account member can change an account credential, and a
     * password the administrator who issued it also knows has no business opening it.
     */
    public function owesPasswordChange(AccountMember $member): bool
    {
        return $this->onSubject($member, fn (string $id): bool => $this->adminPasswords->requiresChange($id)) === true;
    }

    /**
     * Run a check against the member's platform-root subject, or return null when there
     * is no subject to check (bootstrap window) or no member at all.
     *
     * Generic in what the check ANSWERS, because the answers stopped all being bools:
     * {@see admits()} returns a {@see CredentialVerdict}, the counters return bools, and
     * a shared helper that narrowed everything to bool would have quietly turned the
     * verdict into `true`.
     *
     * @template TResult
     *
     * @param  callable(string): TResult  $check
     * @return TResult|null
     */
    private function onSubject(?AccountMember $member, callable $check): mixed
    {
        $subjectId = $member?->subject_id;

        if (! is_string($subjectId) || $subjectId === '') {
            return null;
        }

        return $this->platformRoot->run(fn (): mixed => $check($subjectId));
    }
}
