<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Http\Middleware\DeprecateLegacyApi;
use App\Platform\Api\LegacyApiUsage;
use Illuminate\Contracts\Container\Container;
use Illuminate\Http\Request;
use Illuminate\Routing\Router;
use Symfony\Component\HttpFoundation\Response;
use Throwable;

/**
 * AN OLD PATH ON THE NEW BASE, for the transition.
 *
 * `/.well-known/cbox-cli` now advertises `https://api.cboxid.com/v1` as `api_base`. A CLI
 * released before that change appends the paths it knows to whatever base it was given —
 * `…/v1/workspace/projects` — so a person who signs in again with an old CLI would be
 * stranded. Any `/v1/…` the API host has no route for is therefore answered by the
 * `/api/v1/…` operation of the same path, with the credential (not the host) naming the
 * environment, and marked and counted like every other old path. It goes with `/api/v1`.
 */
final readonly class LegacyPathController
{
    public function __construct(
        private Router $router,
        private Container $container,
    ) {}

    public function __invoke(Request $request, string $legacy = ''): Response
    {
        $uri = '/api/v1/'.ltrim($legacy, '/');
        $query = $request->getQueryString();

        $server = $request->server->all();
        $server['REQUEST_URI'] = $uri.($query !== null && $query !== '' ? '?'.$query : '');

        $sub = Request::create($uri, $request->getMethod(), $request->query->all(), $request->cookies->all(), $request->allFiles(), $server, $request->getContent());
        $sub->headers->replace($request->headers->all());

        if ($request->isJson()) {
            $sub->setJson($request->json());
        } else {
            $sub->request->replace($request->request->all());
        }

        $previous = $this->container->make('request');
        $this->container->instance('request', $sub);

        try {
            // A path no old route has either is the router's 404, rendered as a problem.
            $response = $this->router->dispatch($sub);
        } finally {
            $this->container->instance('request', $previous);
        }

        DeprecateLegacyApi::mark($response);

        try {
            LegacyApiUsage::record($sub, $response);
        } catch (Throwable $e) {
            report($e);
        }

        return $response;
    }
}
