<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Platform\Turnstile;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Baseline security headers for an identity console. The CSP is strict — no
 * inline/remote scripts — which suits a server-rendered Livewire app (its JS is
 * bundled by Vite and served same-origin).
 */
final class SecurityHeaders
{
    public function __construct(private readonly Turnstile $turnstile) {}

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
                "script-src 'self' 'unsafe-eval'".($turnstile ? ' '.Turnstile::ORIGIN : ''),
                "style-src 'self' 'unsafe-inline'",
                // https: allows customer-hosted org logos on the branded login.
                "img-src 'self' data: https:",
                "font-src 'self' data:",
                "connect-src 'self'",
                // Turnstile renders its challenge in an iframe from its own origin.
                // Omitted entirely when Turnstile is off, so default-src 'self' governs.
                $turnstile ? "frame-src 'self' ".Turnstile::ORIGIN : null,
                "frame-ancestors 'none'",
                "base-uri 'self'",
                "form-action 'self'",
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
            if ($name === 'Content-Security-Policy' && $response->headers->has($name)) {
                continue;
            }

            $response->headers->set($name, $value);
        }

        return $response;
    }
}
