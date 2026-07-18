<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Platform\EnvironmentAdminAuth;
use Cbox\Id\Kernel\Tenancy\Contracts\EnvironmentContext;
use Cbox\Id\Platform\Contracts\AccountMembers;
use Cbox\Id\Platform\Contracts\EnvironmentAdminHandoff;
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
        AccountMembers $members,
        EnvironmentAdminAuth $auth,
    ): RedirectResponse {
        $token = $request->query('token');
        $grant = is_string($token) ? $handoff->verify($token) : null;

        $hostEnv = $environments->current()?->environmentKey();

        // The token must have been minted for THIS environment's host — a handoff for
        // env A is worthless on env B, closing any cross-environment replay.
        if ($grant === null || $hostEnv === null || $grant->environmentId !== $hostEnv) {
            return redirect()->route('admin.login');
        }

        $member = $members->find($grant->accountMemberId);

        if ($member === null
            || ! $member->isActive()
            || ! in_array($hostEnv, $members->accessibleEnvironmentIds($member), true)) {
            return redirect()->route('admin.login');
        }

        $auth->establish($member->id, $hostEnv);

        return redirect()->intended(route('dashboard'));
    }

    public function logout(Request $request, EnvironmentAdminAuth $auth): RedirectResponse
    {
        $auth->logout($request);

        return redirect()->route('admin.login');
    }
}
