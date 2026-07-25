<?php

declare(strict_types=1);

namespace App\Platform;

use Cbox\Id\Identity\Contracts\AdminPasswords;
use Cbox\Id\Identity\Contracts\LoginAttempts;
use Cbox\Id\Platform\Models\AccountMember;
use Cbox\Id\Platform\PlatformRoot;

/**
 * The rules that decide whether an account member's verified password is still a way in.
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
     */
    public function admits(AccountMember $member): bool
    {
        return $this->onSubject(
            $member,
            fn (string $id): bool => ! $this->adminPasswords->hasExpired($id)
                && $this->platform->passwordLoginAllowedFor($id),
        ) ?? true;
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
     * @param  callable(string): bool  $check
     */
    private function onSubject(?AccountMember $member, callable $check): ?bool
    {
        $subjectId = $member?->subject_id;

        if (! is_string($subjectId) || $subjectId === '') {
            return null;
        }

        $result = $this->platformRoot->run(fn (): bool => $check($subjectId));

        return is_bool($result) ? $result : null;
    }
}
