<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Http\Controllers\Console\EnvironmentRefusedController;
use App\Http\Middleware\SecurityHeaders;
use App\Platform\Console\ConsoleScope;
use App\Platform\Console\HandoffTarget;
use App\Platform\CspNonce;
use App\Platform\PlaneResolver;
use App\Platform\PlatformAuth;
use App\Platform\SignInLineage;
use Cbox\Id\Organization\Contracts\Memberships;
use Cbox\Id\Organization\Models\Environment;
use Cbox\Id\Platform\Contracts\EnvironmentAdminHandoff;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Inertia\Inertia;
use Symfony\Component\HttpFoundation\Response as SymfonyResponse;

final class EnvironmentHandoffController extends Controller
{
    /**
     * "Open" an environment from the Identity platform area: mint a short-lived signed
     * handoff and POST it to that environment's OWN host, where it is redeemed into an
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
     *
     * THE TOKEN TRAVELS IN A FORM BODY, NOT A URL. This used to redirect to
     * `/admin/handoff?token=…`, which wrote a live bearer credential into the browser
     * history and the access log of everything between the browser and the environment
     * host. It is now a self-submitting POST ({@see self::postTo()}), and the redemption
     * accepts nothing else.
     */
    public function openEnvironment(
        Request $request,
        string $environment,
        ConsoleScope $scope,
        Memberships $members,
        EnvironmentAdminHandoff $handoff,
        CspNonce $nonce,
        PlaneResolver $planes,
        HandoffTarget $targets,
        PlatformAuth $auth,
        SignInLineage $lineage,
    ): SymfonyResponse {
        // An Inertia visit (a <Link>, or a redirect the client followed) cannot mount a
        // self-submitting form: send the browser here for real.
        if ($request->header('X-Inertia')) {
            return Inertia::location($request->fullUrl());
        }

        // A person of the root with no organization at all — they signed in with Google and
        // were never invited anywhere — is asked the same question below and refused the
        // same way, rather than meeting the scope's own refusal as an error page.
        try {
            $organizationId = $scope->organizationId();
        } catch (AuthorizationException) {
            $organizationId = null;
        }

        $subjectId = $scope->actorId();

        if ($subjectId === '') {
            return redirect()->route('login');
        }

        // Fail before a credential is minted: only owner/admin/developer administer
        // environments. A viewer can reach an environment but must not be handed a live
        // env-admin token for it (the anti-escalation gate; the env-admin session guard
        // re-checks the same capability on redemption).
        //
        // SAID, NOT A BLANK 403. This door is where somebody who opened an environment's
        // console signed out lands after signing in at the root — with Google, a passkey,
        // whatever the root offers — and a person who signed in fine but administers
        // nothing there was shown an error page with no words on it. They are told whose
        // console it is, that their sign-in worked, and where they can go instead.
        if ($organizationId === null
            || $scope->capabilities()?->canManageEnvironments() !== true
            || ! in_array($environment, $members->accessibleEnvironmentIds($organizationId, $subjectId), true)) {
            return app(EnvironmentRefusedController::class)->show($request, $environment);
        }

        // The "member with no platform-root subject" branch that used to sit here is gone
        // with the row that made it possible. A member IS a subject: the actor id above is
        // the subject id, so there is no second identity that might be missing.

        $env = Environment::query()->find($environment);
        abort_if($env === null, 404);

        // The handoff carries the SUBJECT — the credential of record. The membership behind
        // it is re-resolved on redemption, not carried in the token.
        $token = $handoff->mint($subjectId, $env->id);
        $query = ['token' => $token];

        // WHICH SIGN-IN THIS IS, sealed to this token: the sessions this browser holds here.
        // The console session the redemption mints is linked to them, so signing out on
        // either host ends both ({@see SignInLineage}) — and a handoff whose sign-in has
        // already ended is refused on arrival.
        $query[SignInLineage::FIELD] = $lineage->seal($token, $auth->heldSessionIds());

        // THE PAGE TO LAND ON, when the topbar's environment switcher asked for one — so
        // Users in staging opens as Users in production. Validated here and again on
        // redemption ({@see HandoffTarget}); an invalid one is dropped rather than refused,
        // and the handoff lands on the environment's home exactly as it did before.
        $to = $targets->sanitize($request->query('to'));

        if ($to !== null) {
            $query['to'] = $to;
            $query['to_sig'] = $targets->sign($token, $to);
        }

        return $this->postTo($env, $query, $nonce, $planes);
    }

    /**
     * The self-submitting form that carries the token, with the headers that keep it
     * from being stored or leaked.
     *
     * ITS OWN CONTENT POLICY, because the global one would refuse it twice. The console's
     * `form-action` deliberately names no environment host — they are tenant-controlled
     * and open-ended — so the post itself is blocked; and nothing in the global policy
     * covers the one inline submit. {@see SecurityHeaders} defers
     * to a response that declares its own policy, which is how the SAML POST binding
     * works too, and this one is narrower than the global one everywhere:
     *
     *   - `default-src 'none'`: the page loads nothing at all;
     *   - `script-src` is the request's nonce and nothing else — the SAME nonce the global
     *     policy would have used, so a CDN that copies it onto a script it injects keeps
     *     working on this page as it does on every other;
     *   - `form-action` is this host, the account hosts, and the ONE environment origin
     *     the token was minted for. The account hosts are not decoration: the browser
     *     checks `form-action` against every hop of the submission's redirect chain, and a
     *     refused redemption bounces through the environment's gate back to the account
     *     host's minting door. Without them a refusal would die on a CSP violation instead
     *     of landing where it can be resolved;
     *   - no framing, no `<base>`.
     *
     * `Cache-Control: no-store` because the body IS a credential until it is spent, and
     * `Referrer-Policy: no-referrer` (also stated in the markup) so the account page this
     * was opened from is not announced to the tenant's host.
     */
    /**
     * @param  array<string, string>  $fields  The token, and the page to land on when the switcher named one.
     */
    private function postTo(Environment $environment, array $fields, CspNonce $nonce, PlaneResolver $planes): Response
    {
        $origin = 'https://'.$this->host($environment);

        $policy = implode('; ', [
            "default-src 'none'",
            "script-src 'nonce-".$nonce->value()."'",
            'form-action '.implode(' ', array_values(array_unique(["'self'", ...$planes->formActionHosts(), $origin]))),
            "base-uri 'none'",
            "frame-ancestors 'none'",
        ]);

        return response()->view('environment-handoff', [
            'action' => $origin.'/admin/handoff',
            'fields' => $fields,
            'nonce' => $nonce->value(),
            'environmentName' => $environment->name,
        ])->withHeaders([
            'Content-Security-Policy' => $policy,
            'Cache-Control' => 'no-store',
            'Pragma' => 'no-cache',
            'Referrer-Policy' => 'no-referrer',
        ]);
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
