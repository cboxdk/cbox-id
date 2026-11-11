<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Platform\Api\V1\V1Routes;
use App\Platform\PublicIds\PublicId;
use App\Platform\PublicIds\PublicIdTree;
use App\Platform\PublicIds\RouteIds;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Routing\Route;
use Symfony\Component\HttpFoundation\Response;

/**
 * AN ID IS READ IN EITHER FORM (plan §3.2): `usr_01j…` and `01j…` name the same user, in a
 * console URL, on `/api/v1` and in a form or query field. What reaches the handler is always
 * the stored id, so no controller, action or binding needs to know there are two forms.
 *
 * A public id of the wrong kind (`org_…` where a user belongs) is a 404, raised here before
 * any handler could read the ULID inside it as something it is not.
 *
 * `/v1` reads its ids in {@see V1Parameters}, which knows each operation's own names.
 */
final class AcceptPublicIds
{
    /**
     * @param  Closure(Request): Response  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        $route = $request->route();

        if ($route instanceof Route && ! array_key_exists(V1Routes::OBJECT, $route->defaults)) {
            foreach ($route->parameters() as $name => $value) {
                if (! is_string($value) || ! is_string($name)) {
                    continue;
                }

                $decoded = PublicId::decodeString(RouteIds::typeFor($route, $name), $value);

                if ($decoded !== $value) {
                    $route->setParameter($name, $decoded);
                }
            }

            self::decodeInput($request);
        }

        return $next($request);
    }

    /** The `<kind>_id` fields of a request's query and body, read as stored ids. */
    public static function decodeInput(Request $request): void
    {
        $query = PublicIdTree::decode($request->query->all());

        if ($query !== []) {
            $request->query->add($query);
        }

        if ($request->isJson()) {
            $body = PublicIdTree::decode($request->json()->all());

            if ($body !== []) {
                $request->json()->add($body);
            }

            return;
        }

        $body = PublicIdTree::decode($request->request->all());

        if ($body !== []) {
            $request->request->add($body);
        }
    }
}
