<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Platform\Api\Problem;
use App\Platform\Api\V1\V1Routes;
use Closure;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Route;
use Symfony\Component\HttpFoundation\Response;

/**
 * THE SHAPES OF `/v1` (standard §4), written once over whatever the handler answered:
 *
 *  - a resource is the object itself, with `object` naming what it is;
 *  - a list is `{"object":"list","data":[…],"has_more":…,"next_cursor":…}` — one cursor for
 *    every list, the lists that paged by number included (their cursor is `page:N`);
 *  - a held action is the approval it waits for, `object: "approval"`;
 *  - a failure is an RFC 9457 problem (`application/problem+json`), `errors` beside it on
 *    a validation failure.
 *
 * The handlers are the ones `/api/v1` runs, unchanged; `/api/v1` keeps its `{data, meta}`
 * and `{error, message}` until it is retired.
 */
final class V1Responses
{
    /**
     * @param  Closure(Request): Response  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        return self::shape($request, $next($request));
    }

    public static function shape(Request $request, Response $response): Response
    {
        if (! $response instanceof JsonResponse) {
            return $response;
        }

        $body = $response->getData(true);

        if (! is_array($body)) {
            return $response;
        }

        $status = $response->getStatusCode();

        if ($status >= 400) {
            $problem = Problem::fromEnvelope($body, $status);

            if ($problem !== null) {
                $response->setData($problem);
            }

            $response->headers->set('Content-Type', Problem::CONTENT_TYPE);

            return $response;
        }

        if (($body['error'] ?? null) === 'approval_required' && is_array($body['approval'] ?? null)) {
            $response->setData(['object' => 'approval', ...$body['approval'], 'detail' => $body['message'] ?? null]);

            return $response;
        }

        if (! array_key_exists('data', $body)) {
            return $response;
        }

        $data = $body['data'];
        $meta = is_array($body['meta'] ?? null) ? $body['meta'] : null;

        if (is_array($data) && array_is_list($data)) {
            $response->setData([
                'object' => 'list',
                'data' => $data,
                'has_more' => (bool) ($meta['has_more'] ?? false),
                'next_cursor' => self::cursor($meta),
            ]);

            return $response;
        }

        if (is_array($data)) {
            $response->setData(['object' => $data['object'] ?? self::objectOf($request), ...$data]);
        }

        return $response;
    }

    /** @param  array<string, mixed>|null  $meta */
    private static function cursor(?array $meta): ?string
    {
        if ($meta === null || ($meta['has_more'] ?? false) !== true) {
            return null;
        }

        if (isset($meta['next_cursor']) && (is_string($meta['next_cursor']) || is_int($meta['next_cursor']))) {
            return (string) $meta['next_cursor'];
        }

        return isset($meta['next_page']) && is_int($meta['next_page']) ? 'page:'.$meta['next_page'] : null;
    }

    private static function objectOf(Request $request): string
    {
        $route = $request->route();
        $object = $route instanceof Route ? ($route->defaults[V1Routes::OBJECT] ?? null) : null;

        return is_string($object) && $object !== '' ? $object : 'object';
    }
}
