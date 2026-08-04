<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Platform\AccountAuth;
use App\Platform\AccountCapabilities;
use Cbox\Id\Organization\Models\Environment;
use Cbox\Id\Platform\Contracts\AccountMembers;
use Cbox\Id\Platform\Contracts\EnvironmentAdminHandoff;
use Illuminate\Http\RedirectResponse;

final class EnvironmentHandoffController extends Controller
{
    /**
     * "Open" an environment from the Identity platform area: mint a short-lived signed
     * handoff and bounce to that environment's OWN host, where it is redeemed into an
     * env-admin session. No second login — the account member lands straight in the
     * environment's control plane. Access is re-checked here (never mint for an
     * environment the member can't reach) AND on redemption.
     *
     * THIS DOOR IS ALSO WHERE A REFUSED REDEMPTION COMES BACK TO. The tenant has no
     * session for an account member and, by design, no credential form, so every refusal
     * it makes redirects to `admin.login` → the env-admin gate → here. Which makes the
     * invariant: whatever the redemption refuses, this door must refuse or RESOLVE, or the
     * two of them mint and refuse each other forever. The reasons it refuses are checked
     * here — role, environment access, a subject to name — and the standing credential
     * requirements are held one layer up, in the `Authenticate` middleware, which takes a
     * member owing a password change to the change page before this method is ever
     * reached. A refusal added to the redemption alone is a redirect loop.
     */
    public function openEnvironment(
        string $environment,
        AccountAuth $auth,
        AccountMembers $members,
        EnvironmentAdminHandoff $handoff,
    ): RedirectResponse {
        $member = $auth->current();

        if ($member === null) {
            return redirect()->route('login');
        }

        // Fail before a credential is minted: only owner/admin/developer administer
        // environments. A viewer/billing member can reach an environment but must not
        // be handed a live env-admin token for it (the anti-escalation gate; the
        // env-admin session guard re-checks the same capability on redemption).
        abort_unless(
            AccountCapabilities::of($member->role)->canManageEnvironments()
            && in_array($environment, $members->accessibleEnvironmentIds($member), true),
            403,
        );

        // …and a member with no platform-root subject has no control-plane identity to
        // hand off. That is the first-install bootstrap window only; refusing is the
        // safe reading, because the alternative is minting a token naming an identity
        // the target environment cannot resolve.
        $subjectId = $member->subject_id;
        abort_if($subjectId === null, 403);

        $env = Environment::query()->find($environment);
        abort_if($env === null, 404);

        // The handoff carries the SUBJECT — the credential of record. The account
        // membership behind it is re-resolved on redemption, not carried in the token.
        $token = $handoff->mint($subjectId, $env->id);

        return redirect()->away('https://'.$this->host($env).'/admin/handoff?token='.urlencode($token));
    }

    /** The environment's own host — its VERIFIED custom domain, else {slug}.{base_domain}. */
    private function host(Environment $environment): string
    {
        // The verification stamp, not just the column. This method mints a bearer handoff
        // token and redirects to the result, so an unverified domain would send a live
        // credential to a host nobody has proven control of. Round 2 stopped the operator
        // console from WRITING an unverified domain; this is the READER, which still
        // trusted any legacy row. Same invariant, both ends.
        if (is_string($environment->domain) && $environment->domain !== ''
            && $environment->domain_verified_at !== null) {
            return $environment->domain;
        }

        $bases = config('cbox-id.environments.base_domains', []);
        $base = is_array($bases) && isset($bases[0]) && is_string($bases[0]) ? $bases[0] : request()->getHost();

        return $environment->slug.'.'.$base;
    }
}
