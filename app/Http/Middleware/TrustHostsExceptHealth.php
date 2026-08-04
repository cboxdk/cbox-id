<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Platform\MailLinks;
use Illuminate\Http\Middleware\TrustHosts;
use Illuminate\Http\Request;

/**
 * `TrustHosts`, with the health endpoints exempt.
 *
 * WHY. Registering `TrustHosts` closed host-header poisoning — a password-reset link was
 * being minted on an attacker's domain — and would have taken the deployment down on the
 * first rollout. Kubernetes sends the POD IP as the `Host` on an `httpGet` probe unless
 * the manifest overrides it, every host this deployment derives is a public name, and
 * `TrustHosts` runs first in the global stack, ahead of routing. So the liveness probe
 * would have answered 400, the pod would have been killed, and its replacement would have
 * done the same.
 *
 * The first fix was to trust loopback and the private IPv4 ranges. That was the wrong
 * mechanism, and it was wrong in a way worth writing down: it tried to enumerate the
 * shapes an internal caller might arrive as, and the enumeration was immediately
 * incomplete — an IPv6 pod address on a dual-stack cluster, a link-local probe, a bare
 * Kubernetes Service name with no dots. Each miss is the same outage. And it widened the
 * set of hosts trusted for EVERY request in order to fix two paths.
 *
 * The promise is about the PATH. `docs/operations/deployment.md` says `/up` "answers on
 * any host — including a kubelet probing the pod IP directly", and that is the property
 * to keep. Exempting the health paths keeps it exactly, trusts no additional host for
 * anything else, and cannot be made incomplete by a cluster's addressing.
 *
 * Nothing leaks through the exemption: these endpoints build no URLs, mint no tokens and
 * mail nothing. The sink this whole control exists to protect — a `route()` call inside a
 * mailable — is not reachable from either of them, and {@see MailLinks}
 * fences that sink independently.
 */
final class TrustHostsExceptHealth extends TrustHosts
{
    /**
     * The two paths the probes hit. Listed rather than pattern-matched: an exemption from
     * a security control should be enumerable, and "every path under /health" is a wider
     * promise than the manifests actually need.
     *
     * @var list<string>
     */
    private const HEALTH_PATHS = ['up', 'health/ready'];

    /**
     * Every host is acceptable on the health endpoints; the derived list applies to
     * everything else.
     *
     * Overridden HERE rather than in `handle()` deliberately. The parent's `handle()`
     * documents a narrower return type than the middleware pipeline actually produces, so
     * an override there cannot be typed honestly without widening something to make the
     * analyser quiet. `hosts()` has one job and one type, and it says the thing the
     * exemption actually means: on these two paths, any host will do.
     *
     * @return array<int|string, mixed>
     */
    public function hosts()
    {
        return $this->exempt($this->request()) ? ['^.*$'] : parent::hosts();
    }

    /** The request being handled, or a blank one during boot. */
    private function request(): Request
    {
        $request = $this->app->bound('request') ? $this->app->make('request') : null;

        return $request instanceof Request ? $request : Request::create('/');
    }

    /**
     * Whether this request skips the host check.
     *
     * Public so it can be asserted directly, because the enforcement cannot be. Laravel's
     * `TrustHosts` is inert under `runningUnitTests()` AND in the `local` environment, so
     * a test that drives `handle()` and sees a 200 has proven nothing — the parent would
     * have passed the request either way. That is exactly why registering this middleware
     * nearly crash-looped production with a green suite. The half that can be wrong is
     * this list, so this is the half that is tested.
     */
    public function exempt(Request $request): bool
    {
        return in_array($request->path(), self::HEALTH_PATHS, true);
    }
}
