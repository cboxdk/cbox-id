<?php

declare(strict_types=1);

namespace App\Platform;

use App\Platform\Enums\CredentialVerdict;
use Cbox\Id\Identity\Contracts\AdminPasswords;
use Cbox\Id\Identity\Contracts\LoginAttempts;
use Cbox\Id\Identity\Enums\SsoEnforcement;
use Cbox\Id\Platform\Models\AccountMember;
use Cbox\Id\Platform\PlatformRoot;

/**
 * The rules that decide whether an account member's verified credential is still a way in.
 *
 * There are two doors that authenticate an account member by password — the account
 * console ({@see AccountAuth}) and, on a single-host deployment, the environment admin
 * console — and they had different answers. The account door checked the SSO mandate and
 * administrative password expiry; the admin door checked neither, so an environment whose
 * policy mandated SSO could still be entered with a local password there, and an expired
 * hand-off credential kept working. Neither door counted failed attempts per subject.
 *
 * One class, asked by both, so a rule cannot be honoured at one door and skipped at the
 * other. Every check runs in the PLATFORM ROOT's scope — policies, memberships and
 * counters are environment-owned, and the account's people live there.
 *
 * There are doors here that are not passwords at all — an accepted invitation, a
 * magic link, an invitation, a reset link — and they were the same divergence one level
 * along: the mandate was honoured by the password and ignored by every one of them.
 * {@see admitsFactor()} is what they ask now, and it lives here rather than at each door
 * for precisely the reason this class exists.
 */
final class MemberCredentialGate
{
    public function __construct(
        private readonly PlatformRoot $platformRoot,
        private readonly AdminPasswords $adminPasswords,
        private readonly LoginAttempts $loginAttempts,
        private readonly PlatformAuth $platform,
    ) {}

    /**
     * Whether this member is locked out and must not be allowed to attempt a password at
     * all. Asked BEFORE the credential is checked, or a locked account still answers
     * differently for a right guess than a wrong one.
     */
    public function isLockedOut(?AccountMember $member): bool
    {
        return $this->onSubject($member, fn (string $id): bool => $this->loginAttempts->isLockedOut($id)) === true;
    }

    /** Count a failed attempt toward the tenant's lockout threshold. */
    public function recordFailure(?AccountMember $member): void
    {
        $this->onSubject($member, function (string $id): bool {
            $this->loginAttempts->recordFailure($id);

            return true;
        });
    }

    /** Forget the failures — the member proved who they are. */
    public function clearFailures(?AccountMember $member): void
    {
        $this->onSubject($member, function (string $id): bool {
            $this->loginAttempts->clear($id);

            return true;
        });
    }

    /**
     * Whether a VERIFIED password is still admissible.
     *
     *  - An administratively-issued temporary password stops admitting anyone once its
     *    deadline passes, even though the hash still matches — otherwise a hand-off
     *    credential lingers as a permanent second way in.
     *  - A tenant that mandates SSO means it. The account's organization lives in the
     *    platform root like any other, so its policy applies here; without this, "require
     *    SSO" could be sidestepped by picking a different door.
     *
     * A member with no subject is the first-install bootstrap window — nowhere for the
     * subject to live yet, and no policy to consult, so the founder gets in.
     *
     * ASKED OF A PASSWORD, and only of one. The account→environment handoff briefly asked
     * it too — of a token minted from a session that had already got past whichever door
     * the account's policy governs — so an account mandating SSO was refused its own
     * tenant console forever, in a loop rather than with a reason. The whole argument is
     * in `EnvironmentAdminController::handoff()`, and it generalises: a rule that belongs
     * to a credential does not automatically belong to everything that credential opened.
     * The corollary is {@see admitsFactor()}: the mandate is NOT one of those rules, and
     * the doors that prove something other than a password have to ask it too.
     *
     * The two refusals are told apart because only one of them is actionable. An SSO
     * mandate is a door the member can walk through; an expired hand-off credential is a
     * fact about a password they were given, and naming it would tell whoever is holding
     * that password something about the account it belongs to.
     */
    public function admits(AccountMember $member): CredentialVerdict
    {
        return $this->onSubject(
            $member,
            fn (string $id): CredentialVerdict => match (true) {
                $this->adminPasswords->hasExpired($id) => CredentialVerdict::Refused,
                ! $this->platform->localSignInAllowedFor($id) => CredentialVerdict::SsoRequired,
                default => CredentialVerdict::Admitted,
            },
        ) ?? CredentialVerdict::Admitted;
    }

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
