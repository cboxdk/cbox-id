<?php

declare(strict_types=1);

namespace App\Platform;

use App\Http\Controllers\EnvironmentAdminController;
use App\Http\Middleware\Authenticate;
use Cbox\Id\Identity\Contracts\AuthPolicies;
use Cbox\Id\Identity\Contracts\SessionManager;
use Cbox\Id\Identity\Models\Session;
use Cbox\Id\Identity\ValueObjects\AuthPolicy;
use Cbox\Id\Organization\Contracts\Memberships;

/**
 * Ends the sessions a policy change has just made illegitimate.
 *
 * Every other rule an {@see AuthPolicy} carries is re-asked of a LIVE session: the MFA
 * mandate and the password-age requirement are holds in {@see Authenticate}, so switching
 * either on takes effect on the next request of everybody already signed in. The SSO
 * mandate is the one rule asked only at a DOOR — {@see PlatformAuth::passwordLoginAllowedFor()}
 * is consulted when a password is typed and never again — so turning it on governed the
 * next sign-in and nothing that was already inside. An operator declared "a password is no
 * longer a way in" and every password session stayed open, for as long as its ttl allowed.
 *
 * There is no hold that could fix that, which is why this is revocation. A hold sends
 * somebody to the page that satisfies the requirement; the requirement here is "you
 * authenticated the wrong way", and no page satisfies it. The only honest answer is to end
 * the session and let the identity provider the tenant mandated issue the next one.
 *
 * DECORATING THE CONTRACT, NOT THE SURFACE THAT WRITES IT. The alternative was to revoke
 * in the console page's `save()`, and that is a rule attached to whichever writer happened
 * to exist when it was written. The account organization's override — the policy that
 * governs an account MEMBER's password, and the one that
 * {@see EnvironmentAdminController::handoff()} leans on — has no writer at all today, so a
 * fix aimed at the surfaces would have covered the population it was not written for and
 * missed the one it was written for. Here every writer passes through, the console page
 * that exists and the API path that does not yet.
 */
final class RevokingAuthPolicies implements AuthPolicies
{
    public function __construct(
        private readonly AuthPolicies $inner,
        private readonly SessionManager $sessions,
        private readonly Memberships $memberships,
    ) {}

    public function resolve(?string $organizationId = null): AuthPolicy
    {
        return $this->inner->resolve($organizationId);
    }

    public function forEnvironment(): AuthPolicy
    {
        return $this->inner->forEnvironment();
    }

    public function overrideFor(string $organizationId): ?AuthPolicy
    {
        return $this->inner->overrideFor($organizationId);
    }

    public function overridesFor(array $organizationIds): array
    {
        return $this->inner->overridesFor($organizationIds);
    }

    /**
     * The environment baseline governs every subject in the environment, so a tightening
     * ends every session in it.
     *
     * Including the acting administrator's, when they are one of those subjects. They have
     * just declared that a password is no longer a way in; exempting the one person best
     * placed to notice would make the declaration false where it is most visible, and
     * signing back in through the mandated provider is the same click it now is for
     * everyone else. On the environment console they are usually NOT one of those subjects
     * — an environment admin is an account member, a subject of the platform root, and the
     * baseline they write here is not the policy that governs their own password. Their
     * session surviving is that scoping, not an exemption: the same person tightening the
     * ROOT baseline loses their session with everybody else's.
     */
    public function setForEnvironment(AuthPolicy $policy): void
    {
        // BEFORE the write. Reading it after compares the new value with itself, which is
        // an unconditional revocation wearing a condition.
        $wasAllowed = $this->inner->resolve()->sso->allowsPasswordLogin();

        $this->inner->setForEnvironment($policy);

        // …and AFTER it, so a password login attempted in the window between the two is
        // already refused. Revoking first would leave that window open in the one
        // direction that matters: a revoked session could be replaced by a fresh password
        // session under the policy we are ending it for.
        if ($wasAllowed && ! $this->inner->resolve()->sso->allowsPasswordLogin()) {
            $this->revokeEveryLiveSessionHere();
        }
    }

    /**
     * An organization's override governs its MEMBERS — the population
     * {@see PlatformAuth::passwordLoginAllowedFor()} walks — so a tightening ends their
     * sessions, all of them, not only the ones stamped with this organization. That rule
     * refuses the subject's password outright when ANY of their organizations mandates
     * SSO, so a member of a mandating tenant has no password door left anywhere in the
     * environment, and no session of theirs predates a policy that still admits it.
     */
    public function setForOrganization(string $organizationId, AuthPolicy $policy): void
    {
        // The EFFECTIVE policy, not the stored override. An organization with no override
        // inherits the baseline, so "no override" is not "password allowed" — and where
        // the environment already mandates SSO, an override restating it changes nothing
        // for anybody and must not log a tenant out to say so.
        $wasAllowed = $this->inner->resolve($organizationId)->sso->allowsPasswordLogin();

        $this->inner->setForOrganization($organizationId, $policy);

        if ($wasAllowed && ! $this->inner->resolve($organizationId)->sso->allowsPasswordLogin()) {
            foreach ($this->memberships->forOrganization($organizationId) as $membership) {
                $this->sessions->revokeAllForUser($membership->user_id);
            }
        }
    }

    /**
     * No revocation, and it needs no condition: an override may only ever TIGHTEN the
     * baseline ({@see AuthPolicy::tightenedWith()}), so dropping one can only return the
     * organization to a policy at least as loose as the one it had.
     */
    public function clearForOrganization(string $organizationId): void
    {
        $this->inner->clearForOrganization($organizationId);
    }

    /**
     * Every subject holding a live session in the CURRENT environment scope.
     *
     * Scope is the whole population question. `auth_sessions` is environment-owned, so
     * this reaches exactly the environment the policy was written in and no other — a
     * tenant's baseline cannot end an account member's session in the platform root, and
     * the root's baseline ends every session there, the redeemed handoffs included.
     *
     * Per subject rather than one UPDATE, because {@see SessionManager::revokeAllForUser()}
     * is the contract that also emits the domain event and the audit entry. A bulk update
     * would be one query and no record that anybody was signed out.
     */
    private function revokeEveryLiveSessionHere(): void
    {
        $userIds = Session::query()
            ->whereNull('revoked_at')
            ->where('expires_at', '>', now())
            ->distinct()
            ->pluck('user_id');

        foreach ($userIds as $userId) {
            if (is_string($userId) && $userId !== '') {
                $this->sessions->revokeAllForUser($userId);
            }
        }
    }
}
