<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Platform\Console\ConsoleScope;
use App\Platform\EnvironmentAdminAuth;
use App\Platform\Impersonation;
use Cbox\Id\Identity\Contracts\Subjects;
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
    public function start(Request $request, string $user, ConsoleScope $scope, Memberships $memberships, Impersonation $impersonation): RedirectResponse
    {
        // Re-asked of the session that is actually here, not of a separate operator key:
        // one sign-in, one place that answers whether this person runs the deployment.
        $operatorId = $scope->operator()?->id;
        abort_if($operatorId === null, 403);

        $orgId = $request->string('organization')->toString();
        abort_if($orgId === '', 403);

        $membership = $memberships->of($orgId, $user);
        abort_if($membership === null, 403);

        // An operator may only step into a regular member — never an owner or admin,
        // whose elevated surface would hand durable tenant control to the operator.
        abort_if($membership->role->canManageOrganization(), 403);

        $request->validate([
            'reason' => ['required', 'string', 'max:200'],
        ]);
        $reason = $request->string('reason')->toString();

        $impersonation->start($request, $operatorId, $user, $orgId, $reason);

        return $this->landing();
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
    public function startAsEnvAdmin(Request $request, string $user, EnvironmentAdminAuth $auth, Memberships $memberships, Impersonation $impersonation, Subjects $subjects): RedirectResponse
    {
        // The acting administrator's SUBJECT id, not their membership's row id. The marker
        // records who is impersonating, and on exit that identity is resolved back into a
        // session — a row id in `memberships` names no session, so a resume keyed on one
        // restores nothing and drops the administrator at the sign-in door.
        $actorSubjectId = $auth->subjectId();
        abort_if($actorSubjectId === null, 403);

        // AN ORGANIZATION IS NOT REQUIRED, because a subject need not have one. An
        // environment that does not use organizations has support users like any other,
        // and demanding a membership here meant the only way to help one of them was to
        // invent a tenancy for them. What authorizes this is that the SUBJECT belongs to
        // the environment this administrator administers — the route's env-admin group
        // has already established the second half, and `Subjects` is environment-scoped,
        // so a subject from elsewhere resolves to null.
        $orgId = $request->string('organization')->toString();
        $orgId = $orgId === '' ? null : $orgId;

        if ($orgId !== null) {
            $membership = $memberships->of($orgId, $user);
            abort_if($membership === null, 403);

            // Never step into an owner/admin — that would hand durable tenant control to
            // the account member (defense-in-depth on top of the read-only screen).
            abort_if($membership->role->canManageOrganization(), 403);
        } else {
            // The same refusal, asked of a person who holds no membership anywhere: an
            // owner or admin of ANY organization in this environment is off limits, so
            // omitting the organization cannot become the way around that rule.
            abort_if($subjects->find($user) === null, 403);

            foreach ($memberships->forUser($user) as $held) {
                abort_if($held->role->canManageOrganization(), 403);
            }
        }

        $request->validate(['reason' => ['required', 'string', 'max:200']]);

        $impersonation->startAsMembership($request, $actorSubjectId, $user, $orgId, $request->string('reason')->toString());

        return $this->landing();
    }

    /**
     * Where an impersonation drops the person who started it: the console of the person
     * they now ARE, on the host they are standing on — `/dashboard`, where the banner and
     * its Exit button render.
     *
     * Never the caller's own console. That was the rule — platform admin for an operator,
     * the environment console's home for an environment administrator, on the root host —
     * and it landed every one of them on a 404: both of those consoles are gated on the
     * caller's authority, and the session that arrives there is the impersonated member's,
     * who has none. A 404 has no chrome, so there was no banner and no Exit either; the
     * only way out was to POST `/impersonation/exit` by hand. On a single-tenant install
     * and a tenant's host the fork already answered `dashboard`, which is why it went
     * unnoticed: the suite runs single-tenant.
     *
     * Deliberately not a cross-host redirect to the member's own environment. The session
     * is host-scoped — {@see EnvironmentHandoffController::openEnvironment()} mints a
     * signed handoff token precisely because a session does not travel between hosts — so
     * bouncing there would land the operator with no session at all.
     *
     * Exit is unchanged: it returns to the caller's own console ({@see exit()}), because by
     * then the caller's own session is back.
     */
    private function landing(): RedirectResponse
    {
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
        $wasMembership = $marker->isMembership();

        $impersonation->exit($request);

        return redirect()->route($wasMembership ? 'environment.home' : 'platform.organizations');
    }
}
