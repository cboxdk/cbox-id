<?php

declare(strict_types=1);

namespace App\Http\Middleware;

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
    /**
     * @param  Closure(Request): Response  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        $response = $next($request);

        $headers = [
            'X-Frame-Options' => 'DENY',
            'X-Content-Type-Options' => 'nosniff',
            'Referrer-Policy' => 'same-origin',
            'Cross-Origin-Opener-Policy' => 'same-origin',
            'X-Permitted-Cross-Domain-Policies' => 'none',
            'Permissions-Policy' => 'geolocation=(), microphone=(), camera=(), payment=()',
            'Content-Security-Policy' => implode('; ', [
                "default-src 'self'",
                // 'unsafe-eval' is required by Livewire's bundled Alpine; scripts
                // are still same-origin only (no inline, no remote). Tightening to
                // Alpine's CSP build is a follow-up.
                "script-src 'self' 'unsafe-eval'",
                "style-src 'self' 'unsafe-inline'",
                // https: allows customer-hosted org logos on the branded login.
                "img-src 'self' data: https:",
                "font-src 'self' data:",
                "connect-src 'self'",
                "frame-ancestors 'none'",
                "base-uri 'self'",
                "form-action 'self'",
                'object-src \'none\'',
            ]),
        ];

        if ($request->isSecure()) {
            // `preload` opts the domain into browser HSTS preload lists, closing
            // the first-visit TOFU window. Requires includeSubDomains + a ≥1y
            // max-age, both present here.
            $headers['Strict-Transport-Security'] = 'max-age=63072000; includeSubDomains; preload';
        }

        foreach ($headers as $name => $value) {
            $response->headers->set($name, $value);
        }

        return $response;
    }
}
