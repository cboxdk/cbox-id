<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Platform\Api\V1\V1Routes;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Routing\Route;
use Symfony\Component\HttpFoundation\Response;

/**
 * A `/v1` path names one thing, `{id}`; the handler behind it may know that thing by another
 * name (`user_id`), and the parent it hangs under (`organization_id`) now comes in the query
 * or the body. This puts each back where the handler reads it, so the same controller and the
 * same action serve the old path and the new one.
 *
 * Also reads a page-number cursor (`page:2`) as the page it stands for, for the lists that
 * page by number: on `/v1` every list pages by an opaque `next_cursor`.
 */
final class V1Parameters
{
    /**
     * @param  Closure(Request): Response  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        $route = $request->route();

        if ($route instanceof Route) {
            self::place($request, $route);
        }

        $after = $request->query('after');

        if (is_string($after) && preg_match('/\Apage:(\d+)\z/', $after, $match) === 1) {
            $request->query->remove('after');
            $request->query->set('page', (int) $match[1]);
        }

        return $next($request);
    }

    /** Put the handler's parameters where it reads them, from the route's `/v1` bookkeeping. */
    public static function place(Request $request, Route $route): void
    {
        $idName = $route->defaults[V1Routes::ID] ?? null;
        $inputs = $route->defaults[V1Routes::INPUTS] ?? [];
        $id = $route->parameter('id');

        $values = [];

        if (is_string($idName) && $idName !== 'id' && $id !== null) {
            $values[$idName] = $id;
        }

        foreach (is_array($inputs) ? $inputs : [] as $handlerName => $publicName) {
            // The public name, or — for a caller that still says it the old way — the
            // handler's own, unless that is `id`, which on `/v1` is the path's.
            $value = $request->input((string) $publicName) ?? ($handlerName !== 'id' ? $request->input((string) $handlerName) : null);

            if (is_scalar($value) && (string) $value !== '') {
                $values[(string) $handlerName] = (string) $value;
            }
        }

        if (is_string($idName) && $idName !== 'id' && ! array_key_exists('id', $values) && $route->hasParameter('id')) {
            $route->forgetParameter('id');
        }

        foreach ($values as $name => $value) {
            $route->setParameter($name, $value);
        }

        // The route's own bookkeeping is not a parameter of the handler: a controller receives
        // its scalar parameters by position, so these must not sit in front of them.
        foreach ([V1Routes::ID, V1Routes::INPUTS, V1Routes::OBJECT, V1Routes::VARIANTS] as $meta) {
            if ($route->hasParameter($meta)) {
                $route->forgetParameter($meta);
            }
        }
    }
}
