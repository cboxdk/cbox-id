<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Platform\Api\V1\SpecNode;
use App\Platform\Api\V1\V1Routes;
use App\Platform\PublicIds\PublicId;
use App\Platform\PublicIds\RouteIds;
use App\Platform\PublicIds\WebhookIdFormat;
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
 * Every id is read in either form, `usr_01j…` or `01j…` (plan §3.2), and reaches the handler as
 * the stored id; a public id of the wrong kind is a 404.
 *
 * Also reads a page-number cursor (`page:2`) as the page it stands for, for the lists that
 * page by number: on `/v1` every list pages by an opaque `next_cursor`.
 */
final class V1Parameters
{
    /**
     * What an action's input means on `/v1` when the caller leaves it out, where that differs
     * from every other surface. Put into the input as the request arrives, so it is part of
     * what an approval is asked for (the digest covers it) and the action never has to ask
     * which surface it runs on.
     *
     * @var array<string, array<string, string>>
     */
    public const array INPUT_DEFAULTS = [
        'webhooks.create' => ['id_format' => WebhookIdFormat::PREFIXED],
    ];

    /**
     * @param  Closure(Request): Response  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        $route = $request->route();

        if ($route instanceof Route) {
            self::place($request, $route);
        }

        AcceptPublicIds::decodeInput($request);

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

        if (is_string($id)) {
            $id = PublicId::decodeString(RouteIds::v1Id('/'.substr($route->uri(), strlen('v1')), is_string($idName) ? $idName : null), $id);

            if ($route->hasParameter('id')) {
                $route->setParameter('id', $id);
            }
        }

        $values = [];

        if (is_string($idName) && $idName !== 'id' && $id !== null) {
            $values[$idName] = $id;
        }

        foreach (is_array($inputs) ? $inputs : [] as $handlerName => $publicName) {
            // The public name, or — for a caller that still says it the old way — the
            // handler's own, unless that is `id`, which on `/v1` is the path's.
            $value = $request->input(SpecNode::string($publicName)) ?? ($handlerName !== 'id' ? $request->input((string) $handlerName) : null);

            if (is_scalar($value) && (string) $value !== '') {
                $values[(string) $handlerName] = PublicId::decodeString(RouteIds::parameter(SpecNode::string($publicName)) ?? RouteIds::parameter((string) $handlerName), (string) $value);
            }
        }

        if (is_string($idName) && $idName !== 'id' && ! array_key_exists('id', $values) && $route->hasParameter('id')) {
            $route->forgetParameter('id');
        }

        foreach ($values as $name => $value) {
            $route->setParameter($name, $value);
        }

        $action = $route->defaults['action'] ?? null;

        foreach (is_string($action) ? self::INPUT_DEFAULTS[$action] ?? [] : [] as $field => $default) {
            if (! $request->exists($field)) {
                $request->merge([$field => $default]);
            }
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
