<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Platform\EnvironmentAdminAuth;
use App\Platform\OrganizationCapabilities;
use App\Platform\SubjectCredentialGate;
use Cbox\Id\Kernel\Tenancy\Contracts\EnvironmentContext;
use Cbox\Id\Organization\Contracts\Memberships;
use Cbox\Id\Organization\Contracts\Organizations;
use Cbox\Id\Organization\Models\Membership;
use Cbox\Id\Platform\Contracts\EnvironmentAdminHandoff;
use Cbox\Id\Platform\PlatformRoot;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

/**
 * Redeems the signed account→environment handoff on the tenant subdomain, and logs
 * an environment admin out. This is the seam that lets an account member land in the
 * environment's admin console with NO second login — the environment trusts the
 * platform's signature (verified here) instead of holding an admin subject.
 */
final class EnvironmentAdminController extends Controller
{
    /**
     * Redeem a one-time handoff token → establish an environment-admin session.
     * Deny-by-default at every step: a bad signature, an expired token, a token
     * minted for a DIFFERENT environment than this host, or a member without access
     * to this environment all fall through to the admin sign-in.
     */
    public function handoff(
        Request $request,
        EnvironmentAdminHandoff $handoff,
        EnvironmentContext $environments,
        Memberships $members,
        Organizations $organizations,
        PlatformRoot $platformRoot,
        EnvironmentAdminAuth $auth,
        SubjectCredentialGate $gate,
    ): RedirectResponse {
        $token = $request->query('token');
        $grant = is_string($token) ? $handoff->verify($token) : null;

        $hostEnv = $environments->current()?->environmentKey();

        // The token must have been minted for THIS environment's host — a handoff for
        // env A is worthless on env B, closing any cross-environment replay.
        if ($grant === null || $hostEnv === null || $grant->environmentId !== $hostEnv) {
            return redirect()->route('admin.login');
        }

        // The token carries the platform-root SUBJECT. Resolving it back to an account
        // membership here — rather than trusting the token to name one — is what keeps
        // a minted handoff from outliving the access it implies: a membership revoked,
        // or a role downgraded, in the seconds since the mint is caught right here (and
        // again on every request, at the session chokepoint).
        // IN THE PLATFORM ROOT: memberships and organizations are environment-owned, and
        // this request is standing on a TENANT host. Read under the ambient scope, the
        // membership simply is not there and a legitimate administrator is bounced.
        $membership = $platformRoot->run(
            fn (): ?Membership => $members->forUser($grant->subjectId)->first(),
        );

        // The ORGANIZATION, not just the membership. A customer suspended in the seconds
        // since the mint — or by an operator while the tab sat open — must not still redeem
        // into a live admin console.
        $organizationActive = $membership === null ? false : $platformRoot->run(
            fn (): bool => $organizations->find($membership->organization_id)?->status->revokesAccess() === false,
        ) === true;

        if ($membership === null
            || ! $organizationActive
            || ! OrganizationCapabilities::of($membership->role)->canManageEnvironments()
            || ! in_array($hostEnv, $platformRoot->run(
                fn (): array => $members->accessibleEnvironmentIds($membership->organization_id, $grant->subjectId),
            ) ?? [], true)) {
            return redirect()->route('admin.login');
        }

        // A standing requirement to replace an administratively-issued password outlives
        // the token. A handoff is not a credential the member just proved, but it stands
        // in for one, and a password the administrator who issued it also knows has no
        // business opening the highest-privilege surface on a tenant. (It subsumes the
        // expiry rule the password doors also apply: an expired temporary password IS a
        // requirement row, so it is already refused here.)
        //
        // WHAT IS NOT ASKED, AND WHY. This used to ask `admits()` as well — the PASSWORD
        // question — and an account that mandates SSO answers it "no" forever. The refusal
        // went to `admin.login`, which sits behind the env-admin gate, which bounces to
        // the account console's minting door, which minted again: three hops and back to
        // the first, for as long as the browser would follow. So the enterprise
        // configuration this product is sold on could not reach its own tenant console,
        // and got a redirect loop instead of a reason.
        //
        // It was never this door's question. The mandate governs how a person
        // authenticates to the ACCOUNT; a handoff is minted from a session that has
        // already satisfied whatever the account's own door asked of it, and this host
        // cannot see which door that was. Nor does refusing protect anything: the same
        // session administers billing, members and API keys at the root.
        //
        // That argument leans on a session predating a mandate being ENDED rather than
        // tolerated, and for a while it leaned on nothing: no such revocation existed, so
        // dropping the check here left every live password session reaching this console.
        // It exists now, at the write — {@see \App\Platform\RevokingAuthPolicies} ends
        // every session governed by a policy the moment that policy stops admitting
        // passwords, in the environment the policy was written in. An admin session is the
        // member's ordinary platform-root subject session, and
        // {@see EnvironmentAdminAuth::membership()} re-reads that row on every request, so a
        // revoked one closes this console on the next click rather than at the next mint.
        //
        // The general rule, because `admin.login` bounces back to the mint: a refusal here
        // is only a refusal if the ROOT refuses or RESOLVES it too. This one qualifies —
        // the same requirement row holds the member on the account plane's change page,
        // which is where the bounce lands and stops.
        if ($gate->owesPasswordChange($grant->subjectId)) {
            return redirect()->route('admin.login');
        }

        $auth->establish($grant->subjectId, $hostEnv);

        return redirect()->intended(route('environment.home'));
    }

    public function logout(Request $request, EnvironmentAdminAuth $auth): RedirectResponse
    {
        $auth->logout($request);

        return redirect()->route('admin.login');
    }
}
