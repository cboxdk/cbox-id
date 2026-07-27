<?php

declare(strict_types=1);

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Route;
use PHPUnit\Framework\AssertionFailedError;
use Tests\Support\ApiContract;
use Tests\TestCase;

/**
 * The meta-gate for the response-schema check.
 *
 * OpenApiCoverageTest validates PATHS; {@see ApiContract} validates BODIES, and it runs
 * implicitly on every `/api/*` response the suite produces via
 * {@see TestCase::createTestResponse()}. An implicit check has one failure mode
 * that matters: quietly matching nothing and passing everything. (The `$ref` check in
 * OpenApiCoverageTest was written after exactly that happened — a regex that matched
 * zero refs reported "all refs resolve".)
 *
 * So these tests drive ApiContract directly with bodies that are known-good and
 * known-bad, and assert it can tell them apart.
 */
function contractRequest(string $method, string $path, string $routeUri): Request
{
    $request = Request::create($path, $method);
    $route = new Route([$method], $routeUri, []);
    $request->setRouteResolver(fn (): Route => $route);

    return $request;
}

it('accepts a response that matches the documented schema', function (): void {
    $request = contractRequest('GET', '/api/v1/organizations', 'api/v1/organizations');
    $response = new JsonResponse([
        'data' => [[
            'id' => '01J0000000000000000000000',
            'name' => 'Acme',
            'slug' => 'acme',
            'type' => 'customer',
            'status' => 'active',
            'parent_id' => null,
        ]],
        'meta' => ['limit' => 50, 'has_more' => false, 'next_cursor' => null],
    ], 200);

    expect(fn () => ApiContract::verify($request, $response))->not->toThrow(AssertionFailedError::class);
});

it('rejects a response whose field has the wrong type', function (): void {
    $request = contractRequest('GET', '/api/v1/organizations', 'api/v1/organizations');
    $response = new JsonResponse([
        // `status` is an enum of strings in the spec; a boolean must not pass.
        'data' => [['id' => 'x', 'name' => 'Acme', 'slug' => 'acme', 'type' => 'customer', 'status' => true]],
        'meta' => ['limit' => 50, 'has_more' => false, 'next_cursor' => null],
    ], 200);

    expect(fn () => ApiContract::verify($request, $response))->toThrow(AssertionFailedError::class);
});

it('rejects a response whose enum value is not one the spec allows', function (): void {
    $request = contractRequest('GET', '/api/v1/users/01J', 'api/v1/users/{id}');
    $response = new JsonResponse([
        'data' => ['id' => '01J', 'email' => 'a@b.test', 'name' => null, 'status' => 'banished', 'email_verified_at' => null],
    ], 200);

    expect(fn () => ApiContract::verify($request, $response))->toThrow(AssertionFailedError::class);
});

it('rejects a status the spec does not document', function (): void {
    $request = contractRequest('GET', '/api/v1/organizations', 'api/v1/organizations');

    expect(fn () => ApiContract::verify($request, new JsonResponse(['error' => 'teapot', 'message' => 'no'], 418)))
        ->toThrow(AssertionFailedError::class);
});

it('rejects a failure that is missing the error envelope', function (): void {
    $request = contractRequest('GET', '/api/v1/organizations', 'api/v1/organizations');

    // Laravel's pre-fix default for a validation failure: a `message`, and no `error`.
    expect(fn () => ApiContract::verify($request, new JsonResponse(['message' => 'The name field is required.'], 422)))
        ->toThrow(AssertionFailedError::class);

    // …and the mirror: an `error` with nothing human beside it.
    expect(fn () => ApiContract::verify($request, new JsonResponse(['error' => 'validation_failed'], 422)))
        ->toThrow(AssertionFailedError::class);
});

it('is actually looking at the specs — the operation matcher is not vacuous', function (): void {
    // If the route-URI → spec-path mapping ever breaks, every check above degrades into
    // "no documented operation found, skip" and this file passes while asserting nothing.
    // Both planes must be reachable.
    $reflection = new ReflectionMethod(ApiContract::class, 'operation');

    expect($reflection->invoke(null, '/api/v1/organizations', 'get'))->not->toBeNull()
        ->and($reflection->invoke(null, '/api/v1/account/members', 'post'))->not->toBeNull()
        ->and($reflection->invoke(null, '/api/v1/vault/secrets/{id}/lease', 'post'))->not->toBeNull()
        ->and($reflection->invoke(null, '/api/v1/not-a-route', 'get'))->toBeNull();
});
