<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Context;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\Response;

/**
 * One id per request, on the response and in every log line written while serving it.
 *
 * A caller that reports "my call failed" can quote the `X-Request-Id` it got back, and the
 * operator finds every line that request wrote. Every JSON error envelope (`{error,
 * message}`) carries it as `request_id` too — added HERE, once, rather than by each of the
 * renderers, middlewares and controllers that answer with one. It lives in Laravel's
 * {@see Context}, so a job the request dispatches logs under the same id.
 *
 * AN INBOUND ID IS KEPT when it is a plain token — an ingress or the caller's own tracing
 * usually sets one, and keeping it is what joins their logs to ours. Anything else (too
 * long, or with characters a log line or a header should not carry) is replaced, never
 * echoed: the id is correlation, not input.
 */
final class AssignRequestId
{
    public const string HEADER = 'X-Request-Id';

    public const string CONTEXT = 'request_id';

    /**
     * @param  Closure(Request): Response  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        $inbound = $request->headers->get(self::HEADER);
        $id = is_string($inbound) && preg_match('/^[A-Za-z0-9._:-]{8,128}$/', $inbound) === 1
            ? $inbound
            : Str::lower((string) Str::ulid());

        Context::add(self::CONTEXT, $id);

        $response = $next($request);
        $response->headers->set(self::HEADER, $id);

        if ($response instanceof JsonResponse && $response->getStatusCode() >= 400) {
            $body = $response->getData(true);

            if (is_array($body) && is_string($body['error'] ?? null) && ! array_key_exists('request_id', $body)) {
                $response->setData($body + ['request_id' => $id]);
            }
        }

        return $response;
    }

    /** The current request's id, or null outside a request. */
    public static function current(): ?string
    {
        $id = Context::get(self::CONTEXT);

        return is_string($id) ? $id : null;
    }
}
