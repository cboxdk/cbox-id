<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Platform\AccountAuth;
use Cbox\Id\Organization\Models\Environment;
use Cbox\Id\Platform\Contracts\AccountMembers;
use Cbox\Id\Platform\Contracts\EnvironmentAdminHandoff;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

final class WorkspaceController extends Controller
{
    public function logout(Request $request, AccountAuth $auth): RedirectResponse
    {
        $auth->logout($request);

        return redirect()->route('workspace.login')->with('status', 'Signed out of your workspace.');
    }

    /**
     * "Open" an environment from the account console: mint a short-lived signed
     * handoff and bounce to that environment's OWN host, where it is redeemed into an
     * env-admin session. No second login — the account member lands straight in the
     * environment's control plane. Access is re-checked here (never mint for an
     * environment the member can't reach) AND on redemption.
     */
    public function openEnvironment(
        string $environment,
        AccountAuth $auth,
        AccountMembers $members,
        EnvironmentAdminHandoff $handoff,
    ): RedirectResponse {
        $member = $auth->current();

        if ($member === null) {
            return redirect()->route('workspace.login');
        }

        abort_unless(in_array($environment, $members->accessibleEnvironmentIds($member), true), 403);

        $env = Environment::query()->find($environment);
        abort_if($env === null, 404);

        $token = $handoff->mint($member->id, $env->id);

        return redirect()->away('https://'.$this->host($env).'/admin/handoff?token='.urlencode($token));
    }

    /** The environment's own host — its custom domain, else {slug}.{base_domain}. */
    private function host(Environment $environment): string
    {
        if (is_string($environment->domain) && $environment->domain !== '') {
            return $environment->domain;
        }

        $bases = config('cbox-id.environments.base_domains', []);
        $base = is_array($bases) && isset($bases[0]) && is_string($bases[0]) ? $bases[0] : request()->getHost();

        return $environment->slug.'.'.$base;
    }
}
