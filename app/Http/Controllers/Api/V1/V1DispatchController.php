<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Http\Middleware\ResolveApiEnvironment;
use App\Http\Middleware\V1Parameters;
use App\Platform\Api\Problem;
use App\Platform\Api\V1\SpecNode;
use App\Platform\Api\V1\V1Routes;
use Illuminate\Container\Container;
use Illuminate\Http\Request;
use Illuminate\Routing\Contracts\ControllerDispatcher;
use Illuminate\Routing\Pipeline;
use Illuminate\Routing\Route;
use Illuminate\Routing\Router;
use Symfony\Component\HttpFoundation\Response;

/**
 * ONE `/v1` PATH, MORE THAN ONE OPERATION BEHIND IT — each run through its own middleware,
 * exactly as its own route would run it.
 *
 * Two kinds:
 *
 *  - SHARED BY PLANES (`GET /v1/approvals/{id}`, `DELETE /v1/environment-keys/{id}`): the
 *    plane the credential speaks for is tried first, then the others. The first answer that
 *    is not "this credential is not for me" (401, 403, 404) is the answer; when none
 *    succeeds, the most telling refusal is — a 404 or 403 from a door the credential
 *    passed over a 401 from one it did not.
 *  - MADE OF FIELDS (`PATCH /v1/members/{id}`: `roles` or `all_environments`): the one
 *    operation whose fields the request carries runs. A request that carries the fields of
 *    two is refused before either runs: two actions are two commits, two approvals and two
 *    idempotency records, and one response cannot report both honestly. Change one at a
 *    time.
 */
final readonly class V1DispatchController
{
    private const array NOT_MINE = [401, 403, 404];

    public function __construct(
        private Router $router,
        private Container $container,
    ) {}

    public function __invoke(Request $request): Response
    {
        $route = $request->route();
        $operations = $route instanceof Route ? ($route->defaults[V1Routes::VARIANTS] ?? []) : [];

        if (! $route instanceof Route || ! is_array($operations) || $operations === []) {
            return Problem::response('not_found', 'No such resource.', 404);
        }

        $variants = [];
        $byFields = [];

        foreach ($operations as $operation) {
            $operation = SpecNode::map($operation);
            $variants[] = $operation;

            if (($operation['when'] ?? null) !== null) {
                $byFields[] = $operation;
            }
        }

        return $byFields !== []
            ? $this->byFields($request, $route, $byFields)
            : $this->byPlane($request, $route, $variants);
    }

    /**
     * @param  list<array<string, mixed>>  $operations
     */
    private function byPlane(Request $request, Route $route, array $operations): Response
    {
        $plane = $request->attributes->get(ResolveApiEnvironment::PLANE);

        usort($operations, static fn (array $a, array $b): int => (int) ($b['plane'] === $plane) <=> (int) ($a['plane'] === $plane));

        $refusals = [];

        foreach ($operations as $operation) {
            $response = $this->run($request, $route, $operation);

            if (! in_array($response->getStatusCode(), self::NOT_MINE, true)) {
                return $response;
            }

            $refusals[] = $response;
        }

        foreach ([404, 403] as $telling) {
            foreach ($refusals as $refusal) {
                if ($refusal->getStatusCode() === $telling) {
                    return $refusal;
                }
            }
        }

        return $refusals[0];
    }

    /**
     * @param  list<array<string, mixed>>  $operations
     */
    private function byFields(Request $request, Route $route, array $operations): Response
    {
        $asked = [];
        $matched = [];

        foreach ($operations as $operation) {
            $when = SpecNode::string($operation['when'] ?? null);
            $asked[] = $when;

            if (self::matches($request, $when)) {
                $matched[] = $operation;
            }
        }

        if (count($matched) > 1) {
            return Problem::response(
                'validation_failed',
                'This request changes more than one thing at once: '
                    .implode('; ', array_map(static fn (array $operation): string => self::describe(SpecNode::string($operation['when'] ?? null)), $matched))
                    .'. Send one change per request.',
                422,
            );
        }

        return $matched !== []
            ? $this->run($request, $route, $matched[0])
            : Problem::response(
                'validation_failed',
                'Send at least one of the fields this endpoint changes: '.implode('; ', array_map(self::describe(...), $asked)).'.',
                422,
            );
    }

    /** `a+b`: every field present; `!a`: that field absent. */
    private static function matches(Request $request, string $when): bool
    {
        foreach (explode('+', $when) as $term) {
            $absent = str_starts_with($term, '!');
            $field = ltrim($term, '!');

            if ($request->exists($field) === $absent) {
                return false;
            }
        }

        return true;
    }

    private static function describe(string $when): string
    {
        return implode(' with ', array_map(
            static fn (string $term): string => str_starts_with($term, '!') ? 'no `'.ltrim($term, '!').'`' : '`'.$term.'`',
            explode('+', $when),
        ));
    }

    /**
     * One operation, through its own middleware, with its own parameters in place.
     *
     * @param  array<string, mixed>  $operation
     */
    private function run(Request $request, Route $route, array $operation): Response
    {
        $snapshot = $route->parameters();
        $defaults = $route->defaults;

        $route->defaults['action'] = $operation['action'];
        $route->defaults[V1Routes::ID] = $operation['id'];
        $route->defaults[V1Routes::INPUTS] = $operation['inputs'];

        foreach (array_keys($route->parameters()) as $name) {
            $route->forgetParameter($name);
        }

        foreach ($snapshot as $name => $value) {
            if (! in_array($name, ['action', V1Routes::VARIANTS], true)) {
                $route->setParameter($name, self::parameter($value));
            }
        }

        try {
            V1Parameters::place($request, $route);

            $middleware = $this->router->resolveMiddleware(is_array($operation['middleware']) ? $operation['middleware'] : []);

            $response = (new Pipeline($this->container))
                ->send($request)
                ->through($middleware)
                ->then(fn (Request $request): Response => $this->router->prepareResponse($request, $this->call($route, SpecNode::string($operation['uses'] ?? null))));

            return $response instanceof Response ? $response : $this->router->prepareResponse($request, $response);
        } finally {
            $route->defaults = $defaults;

            foreach (array_keys($route->parameters()) as $name) {
                $route->forgetParameter($name);
            }

            foreach ($snapshot as $name => $value) {
                $route->setParameter($name, self::parameter($value));
            }
        }
    }

    /** A route parameter as the route holds one: a bound model, a string, or nothing. */
    private static function parameter(mixed $value): object|string|null
    {
        return is_object($value) || is_string($value) || $value === null ? $value : SpecNode::string($value);
    }

    private function call(Route $route, string $uses): mixed
    {
        [$class, $method] = str_contains($uses, '@') ? explode('@', $uses, 2) : [$uses, '__invoke'];
        $controller = $this->container->make($class);

        return $this->container->make(ControllerDispatcher::class)
            ->dispatch($route, $controller, $method);
    }
}
