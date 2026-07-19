<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Platform\EnvironmentAdminAuth;
use App\Platform\Impersonation;
use App\Platform\OperatorAuth;
use Cbox\Id\Organization\Contracts\Memberships;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

/**
 * Thin entry points for support impersonation — all state and transitions live in
 * the {@see Impersonation} service. Start is operator-gated (the console group);
 * exit is gated on the marker's presence, because while impersonating there is no
 * operator key to authenticate against.
 */
final class ImpersonationController extends Controller
{
    /**
     * Step into a tenant member's session. Reached from the operator org-detail
     * member list. The route sits in the operator group, so an operator session is
     * already required; we re-assert it, then AUTHORIZE by membership: the target
     * must be a real member of the posted org, resolved in the operator's
     * currently-pinned plane. {@see Memberships::of} is plane-scoped (SetEnvironment
     * pinned the operator's ENV), so a user or org outside the current plane
     * resolves to null → 403. The org id is the only client input, and it can only
     * ever widen to an org the operator can already see.
     *
     * Two further gates make this safe as privileged access:
     *  - The target must be a REGULAR member. Stepping into an owner/admin would
     *    inherit the tenant's entire admin surface, so those roles are refused (403)
     *    — defense-in-depth on top of the read-only Livewire guard.
     *  - A justification is mandatory (PAM): start is rejected (422) without a
     *    reason, which is stored in the marker and recorded on the audit trail.
     */
    public function start(Request $request, string $user, OperatorAuth $auth, Memberships $memberships, Impersonation $impersonation): RedirectResponse
    {
        $operatorId = $auth->id();
        abort_if($operatorId === null, 403);

        $orgId = $request->string('organization')->toString();
        abort_if($orgId === '', 403);

        $membership = $memberships->of($orgId, $user);
        abort_if($membership === null, 403);

        // An operator may only step into a regular member — never an owner or admin,
        // whose elevated surface would hand durable tenant control to the operator.
        abort_if(in_array($membership->role, ['owner', 'admin'], true), 403);

        $request->validate([
            'reason' => ['required', 'string', 'max:200'],
        ]);
        $reason = $request->string('reason')->toString();

        $impersonation->start($request, $operatorId, $user, $orgId, $reason);

        return redirect()->route('dashboard');
    }

    /**
     * Step into a subject's session as an ENVIRONMENT ADMIN (an account member who
     * administers this environment) rather than a platform operator. The route sits
     * in the env-admin group, so an env-admin session is already required; we
     * re-assert it, then AUTHORIZE by membership resolved in the current (host-pinned)
     * environment — {@see Memberships::of} is env-scoped, so a user or org outside
     * this environment resolves to null → 403. Owners/admins are refused, and a
     * justification is mandatory, exactly as for operator impersonation.
     */
    public function startAsEnvAdmin(Request $request, string $user, EnvironmentAdminAuth $auth, Memberships $memberships, Impersonation $impersonation): RedirectResponse
    {
        $memberId = $auth->current()?->id;
        abort_if($memberId === null, 403);

        $orgId = $request->string('organization')->toString();
        abort_if($orgId === '', 403);

        $membership = $memberships->of($orgId, $user);
        abort_if($membership === null, 403);

        // Never step into an owner/admin — that would hand durable tenant control to
        // the account member (defense-in-depth on top of the read-only screen).
        abort_if(in_array($membership->role, ['owner', 'admin'], true), 403);

        $request->validate(['reason' => ['required', 'string', 'max:200']]);

        $impersonation->startAsAccountMember($request, $memberId, $user, $orgId, $request->string('reason')->toString());

        return redirect()->route('dashboard');
    }

    /**
     * Leave impersonation and return to whichever control plane started it. Guarded
     * on the marker rather than any auth session — the browser is purely the subject
     * here. A missing marker means there is nothing to exit (403), so a stray POST
     * can neither forge a session nor act.
     */
    public function exit(Request $request, Impersonation $impersonation): RedirectResponse
    {
        // Read the marker before exit() clears it, so we return to the right console
        // — the env-admin home for an account member, else the operator. A missing
        // marker means there is nothing to exit (403).
        $marker = $impersonation->active();
        abort_if($marker === null, 403);
        $wasAccountMember = $marker->isAccountMember();

        $impersonation->exit($request);

        return redirect()->route($wasAccountMember ? 'environment.home' : 'operator.organizations');
    }
}
