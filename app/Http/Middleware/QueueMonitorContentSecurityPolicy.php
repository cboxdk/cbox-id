<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Platform\Queues\QueueMonitorAccess;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * The queue monitor's own Content-Security-Policy — the ONE page in this application that
 * runs inline script and `eval`, and the reason is not ours to fix.
 *
 * The monitor is `cboxdk/laravel-queue-monitor`'s dashboard: a Blade page with an inline
 * bootstrap `<script>` and Alpine.js, whose `x-` attributes are compiled with
 * `new Function` — `'unsafe-eval'` as far as CSP is concerned. Under the console's policy
 * (`script-src 'self' 'nonce-…'`) it renders as a dead page: no tabs, no data, a console
 * full of violations.
 *
 * So this route group carries a policy of its own, which {@see SecurityHeaders} defers to
 * (it leaves a response's own CSP alone). It is widened in EXACTLY one directive —
 * `script-src` gains `'unsafe-inline' 'unsafe-eval'` and loses the nonce, which would
 * otherwise disable `'unsafe-inline'` — and is otherwise TIGHTER than the console's: no
 * third-party origin anywhere, no framing, forms and connections to this origin only.
 *
 * What bounds the risk: these routes answer only on the platform root's host and only to
 * a platform operator (`plane:operator` + {@see AuthenticateOperator} +
 * {@see QueueMonitorAccess}), and the dashboard writes job data
 * through Alpine's `x-text` — the one `x-html` sink, the stack trace, escapes `<`, `>` and
 * `&` before highlighting. The rest of the app keeps the strict policy.
 */
final class QueueMonitorContentSecurityPolicy
{
    /**
     * @param  Closure(Request): Response  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        $response = $next($request);

        $response->headers->set('Content-Security-Policy', implode('; ', [
            "default-src 'self'",
            "script-src 'self' 'unsafe-inline' 'unsafe-eval'",
            "style-src 'self' 'unsafe-inline'",
            "img-src 'self' data:",
            "font-src 'self' data:",
            "connect-src 'self'",
            "frame-ancestors 'none'",
            "base-uri 'self'",
            "form-action 'self'",
            "object-src 'none'",
        ]));

        return $response;
    }
}
