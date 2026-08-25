<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Platform\CspNonce;
use App\Platform\PlaneResolver;
use App\Platform\Turnstile;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Baseline security headers for an identity console. The CSP is strict — no inline and
 * no remote scripts — which the app can afford because its whole client is one Vite
 * bundle served same-origin, and Inertia hands a page its props in a `data-page`
 * attribute rather than in an inline script tag.
 *
 * The one exception is the Vite DEV SERVER, which lives on its own origin and cannot be
 * bundled by definition. See {@see self::viteDevOrigin()}: it is admitted only when this
 * is a local install AND Vite has actually written its hot file, so no deployment can
 * reach that branch by configuration alone.
 */
final class SecurityHeaders
{
    public function __construct(
        private readonly Turnstile $turnstile,
        private readonly PlaneResolver $planes,
        private readonly CspNonce $nonce,
    ) {}

    /**
     * @param  Closure(Request): Response  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        $response = $next($request);

        // The ONLY third-party origin this app ever allows, and only when Turnstile
        // is actually configured: its script, and the iframe that script creates.
        // A deployment without Turnstile keys keeps the tighter same-origin policy —
        // no directive here changes shape unless the feature is switched on.
        $turnstile = $this->turnstile->configured();

        // The Vite dev server, and ONLY on a local install that is actually running it.
        // Empty everywhere else, so the deployed policy is byte-for-byte what it was.
        $devOrigin = $this->viteDevOrigin();
        $dev = $devOrigin !== null ? ' '.$devOrigin : '';
        $devSocket = $devOrigin !== null ? ' '.str_replace(['http://', 'https://'], ['ws://', 'wss://'], $devOrigin) : '';

        $headers = [
            'X-Frame-Options' => 'DENY',
            'X-Content-Type-Options' => 'nosniff',
            'Referrer-Policy' => 'same-origin',
            'Cross-Origin-Opener-Policy' => 'same-origin',
            'X-Permitted-Cross-Domain-Policies' => 'none',
            'Permissions-Policy' => 'geolocation=(), microphone=(), camera=(), payment=()',
            'Content-Security-Policy' => implode('; ', array_values(array_filter([
                "default-src 'self'",
                // 'unsafe-eval' is required by Livewire's bundled Alpine; scripts
                // are still same-origin only (no inline, no remote). Tightening to
                // Alpine's CSP build is a follow-up.
                // The nonce is not for our own markup — this app emits no inline script.
                // It is here because a CDN in front of us does: Cloudflare's JavaScript
                // Detections injects `/cdn-cgi/challenge-platform/scripts/jsd/main` as an
                // INLINE script after the response has left PHP, and a policy of
                // `'self' 'unsafe-eval'` refuses it on every page the console serves.
                //
                // Cloudflare parses this header and copies the nonce onto the script it
                // injects, which is why the value has to be in the HEADER — a nonce set
                // via a `<meta>` tag is documented as unsupported and would silently keep
                // failing. The alternative is `'unsafe-inline'`, which would permit every
                // inline script on every page in order to permit one, and pinning the
                // hash instead breaks the day Cloudflare ships a new build of it.
                "script-src 'self' 'unsafe-eval' 'nonce-".$this->nonce->value()."'".($turnstile ? ' '.Turnstile::ORIGIN : '').$dev,
                "style-src 'self' 'unsafe-inline'".$dev,
                // https: allows customer-hosted org logos on the branded login.
                "img-src 'self' data: https:",
                "font-src 'self' data:",
                // …plus the dev server's websocket, which is how Fast Refresh reaches the
                // page. Empty in every environment that is not a local install.
                "connect-src 'self'".$dev.$devSocket,
                // Turnstile renders its challenge in an iframe from its own origin.
                // Omitted entirely when Turnstile is off, so default-src 'self' governs.
                $turnstile ? "frame-src 'self' ".Turnstile::ORIGIN : null,
                "frame-ancestors 'none'",
                "base-uri 'self'",
                // `'self'` alone is wrong for this app, and logout is where it showed.
                //
                // Signing in is unified on the platform root, so a session that ends on an
                // environment host finishes on another one: POST /admin/logout redirects to
                // /admin/login, which redirects to the root's /login. The browser
                // checks `form-action` against EVERY hop of a submission's redirect chain,
                // not just the address on the form — so the post was refused, and Chrome
                // reported the original same-origin URL, which made it read as impossible.
                //
                // Named from the same config that decides which hosts ARE the platform
                // root, so the policy cannot drift from the topology it describes. Hosts,
                // not origins: a bare host-source inherits the document's scheme, which
                // keeps http in local development without permitting it in production.
                //
                // Environment and white-label hosts are deliberately NOT here. They are
                // tenant-controlled and open-ended, and a `form-action` that names them
                // would permit posting credentials to any domain a tenant can point at us.
                'form-action '.implode(' ', ["'self'", ...$this->planes->formActionHosts()]),
                'object-src \'none\'',
            ]))),
        ];

        if ($request->isSecure()) {
            // `preload` opts the domain into browser HSTS preload lists, closing
            // the first-visit TOFU window. Requires includeSubDomains + a ≥1y
            // max-age, both present here.
            $headers['Strict-Transport-Security'] = 'max-age=63072000; includeSubDomains; preload';
        }

        foreach ($headers as $name => $value) {
            // A response that already declared its own policy keeps it.
            //
            // This middleware is appended globally, which is what makes it trustworthy —
            // no route can be forgotten. But it also means it stamped the console's
            // policy onto the SAML POST binding, where `form-action 'self'` refuses the
            // cross-origin post to the service provider's ACS and a script-src without
            // 'unsafe-inline' refuses the submit. Federation died on a blank page, with
            // no PHP-level symptom at all.
            //
            // The tempting fix is to widen this policy for everyone. Instead the SAML
            // response carries a policy of its own — STRICTER than this one everywhere
            // except the single ACS origin it names (see SamlPostBinding in the
            // framework) — and this loop defers to it. Only CSP is deferred: everything
            // else in the list is unconditional, so a response cannot quietly drop
            // frame-ancestors or nosniff by setting one header.
            // `has()` is true for an EMPTY header too, which would mean a response could
            // opt out of the policy entirely by setting nothing. Nothing does that today;
            // the check costs one comparison.
            if ($name === 'Content-Security-Policy' && ($response->headers->get($name) ?? '') !== '') {
                continue;
            }

            $response->headers->set($name, $value);
        }

        return $response;
    }

    /**
     * The Vite dev server's origin, or null — which is the answer everywhere that is not
     * a developer's own machine with `npm run dev` running.
     *
     * WHY THIS HAS TO EXIST. `@vite` in development points the page at a SECOND origin
     * (`http://localhost:5173` by default): the module graph, the Fast Refresh client and
     * its websocket all come from there. Under `script-src 'self'` the browser refuses
     * every one of them, so the console rendered a blank page with a wall of CSP
     * violations the moment anybody ran the dev server — and the workaround people reach
     * for is to switch the whole middleware off locally, which is how a machine ends up
     * testing a policy it does not run.
     *
     * TWO CONDITIONS, both required. `local` is a deployment's own claim about itself, so
     * it is necessary but not sufficient. The hot file is written by Vite when it starts
     * and removed when it stops, and it is not in the built image at all — so a
     * production container cannot reach this branch even if `APP_ENV` were wrong.
     */
    private function viteDevOrigin(): ?string
    {
        if (! app()->environment('local')) {
            return null;
        }

        $hot = public_path('hot');

        if (! is_file($hot)) {
            return null;
        }

        $origin = trim((string) file_get_contents($hot));

        // Vite writes the origin it is serving on. Anything that is not an http(s) origin
        // is not something to widen a policy with, so it is refused rather than trusted.
        return preg_match('#^https?://[A-Za-z0-9.\-]+(:\d+)?$#', $origin) === 1 ? $origin : null;
    }
}
