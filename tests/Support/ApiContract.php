<?php

declare(strict_types=1);

namespace Tests\Support;

use Illuminate\Http\Request;
use Illuminate\Routing\Route;
use Illuminate\Testing\TestResponse;
use Opis\JsonSchema\Errors\ErrorFormatter;
use Opis\JsonSchema\Validator;
use PHPUnit\Framework\Assert;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Yaml\Yaml;
use Tests\TestCase;

/**
 * The response-body half of the OpenAPI gate.
 *
 * OpenApiCoverageTest keeps the spec honest about PATHS — every route documented or
 * recorded as debt, nothing documented that is not routed, no dangling `$ref`s. It says
 * nothing about SHAPES, so the spec could promise a field the controller never returns
 * and a generated client would happily deserialise into nulls.
 *
 * This closes that. It is wired into {@see TestCase::createTestResponse()}, so
 * EVERY HTTP request any test in the suite makes against `/api/*` is checked — there is
 * no per-test opt-in to forget, and a new endpoint test is covered the day it is written.
 * Two invariants:
 *
 *   1. ONE ERROR SHAPE. Any 4xx/5xx off the API surface carries `error` AND `message`.
 *      The single exception is an RFC 6750 challenge (a response carrying
 *      `WWW-Authenticate`), where `error`/`error_description` is the protocol's own
 *      shape and restating it would be a conformance bug, not a consistency win.
 *
 *   2. THE DOCUMENTED SHAPE. If the route is documented, the status must be documented
 *      too, and the body must validate against that response's schema.
 *
 * Validation is by opis/json-schema (Apache-2.0, `require-dev` only — the standing rule
 * that a new RUNTIME dependency needs owner approval is untouched). OpenAPI 3.1 schemas
 * ARE JSON Schema 2020-12, which is opis's default draft, so the spec node is fed to it
 * verbatim rather than translated — no hand-rolled validator to drift from the standard.
 */
final class ApiContract
{
    /**
     * The parsed specs, keyed by filename. Parsed once per process — the suite makes
     * thousands of requests and YAML parsing is not free.
     *
     * @var array<string, array<mixed>>|null
     */
    private static ?array $specs = null;

    public static function verify(Request $request, Response|TestResponse $response): void
    {
        // Some framework paths (redirect-following, Livewire's test harness) hand the
        // already-wrapped response back through createTestResponse(); unwrap rather than
        // silently skipping, so those requests are checked too.
        $response = $response instanceof TestResponse ? $response->baseResponse : $response;

        $path = '/'.ltrim($request->getPathInfo(), '/');

        if (! str_starts_with($path, '/api/')) {
            return;
        }

        $body = self::json($response);

        self::assertErrorEnvelope($request, $response, $body);
        self::assertDocumentedShape($request, $response, $body);
    }

    // ── 1. One error shape ──────────────────────────────────────────────────────

    private static function assertErrorEnvelope(Request $request, Response $response, mixed $body): void
    {
        if ($response->getStatusCode() < 400 || ! is_array($body)) {
            return;
        }

        $where = $request->getMethod().' '.$request->getPathInfo().' → '.$response->getStatusCode();

        Assert::assertTrue(
            isset($body['error']) && is_string($body['error']) && $body['error'] !== '',
            "{$where}: every API failure must carry a stable machine `error` code. Got: "
            .json_encode($body, JSON_UNESCAPED_SLASHES)
        );

        // RFC 6750 §3 challenges keep the protocol's own `error_description`.
        if ($response->headers->has('WWW-Authenticate')) {
            Assert::assertTrue(
                (isset($body['message']) && is_string($body['message']) && $body['message'] !== '')
                || (isset($body['error_description']) && is_string($body['error_description']) && $body['error_description'] !== ''),
                "{$where}: a bearer challenge must carry `error_description` (RFC 6750) or `message`."
            );

            return;
        }

        Assert::assertTrue(
            isset($body['message']) && is_string($body['message']) && $body['message'] !== '',
            "{$where}: every API failure must carry a human `message` alongside `error` — "
            .'both specs declare the pair. Got: '.json_encode($body, JSON_UNESCAPED_SLASHES)
        );
    }

    // ── 2. The documented shape ─────────────────────────────────────────────────

    private static function assertDocumentedShape(Request $request, Response $response, mixed $body): void
    {
        $route = $request->route();

        if (! $route instanceof Route) {
            return;
        }

        $method = strtolower($request->getMethod());

        if (in_array($method, ['head', 'options'], true)) {
            return;
        }

        $uri = '/'.ltrim($route->uri(), '/');
        $found = self::operation($uri, $method);

        if ($found === null) {
            // Undocumented on purpose (see OpenApiCoverageTest::undocumentedByDesign).
            return;
        }

        [$spec, $specPath, $operation] = $found;

        $status = (string) $response->getStatusCode();
        $responses = is_array($operation['responses'] ?? null) ? $operation['responses'] : [];
        $documented = array_map(strval(...), array_keys($responses));

        Assert::assertContains(
            $status,
            $documented,
            "{$method} {$specPath} returned {$status}, which the spec does not document "
            .'(it documents '.implode(', ', $documented).'). Document it, or stop returning it.'
        );

        $schema = $responses[$status]['content']['application/json']['schema'] ?? null;

        if (! is_array($schema)) {
            return;
        }

        self::validate($spec, $schema, $body, "{$method} {$specPath} ({$status})");
    }

    /**
     * @return array{0: array<mixed>, 1: string, 2: array<mixed>}|null
     */
    private static function operation(string $routeUri, string $method): ?array
    {
        foreach (self::specs() as $spec) {
            /** @var array<string, array<string, mixed>> $paths */
            $paths = is_array($spec['paths'] ?? null) ? $spec['paths'] : [];

            foreach ($paths as $specPath => $operations) {
                if ('/api/v1'.$specPath !== $routeUri) {
                    continue;
                }

                $operation = $operations[$method] ?? null;

                if (is_array($operation)) {
                    return [$spec, $specPath, $operation];
                }
            }
        }

        return null;
    }

    /**
     * The response schema is validated as its own little document with the spec's
     * `components` carried alongside, rather than by `$ref`-ing a JSON pointer into the
     * whole spec: opis reads `{`/`}` in a `$ref` as a URI template, and every path
     * parameter (`/users/{id}`) contains braces. Carrying `components` keeps the
     * schema's own `#/components/schemas/…` references resolvable.
     *
     * @param  array<mixed>  $spec
     * @param  array<mixed>  $schema
     */
    private static function validate(array $spec, array $schema, mixed $body, string $where): void
    {
        $schema['components'] = $spec['components'] ?? [];

        $validator = new Validator;

        $result = $validator->validate(
            json_decode((string) json_encode($body), false),
            json_decode((string) json_encode($schema), false),
        );

        if ($result->isValid()) {
            return;
        }

        $error = $result->error();
        $detail = $error === null ? [] : (new ErrorFormatter)->format($error);

        Assert::fail(
            "The response does not match its documented schema — {$where}.\n"
            .'  '.json_encode($detail, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)."\n"
            .'  body: '.json_encode($body, JSON_UNESCAPED_SLASHES)
        );
    }

    /**
     * @return array<string, array<mixed>>
     */
    private static function specs(): array
    {
        if (self::$specs !== null) {
            return self::$specs;
        }

        $specs = [];

        foreach (glob(base_path('resources/openapi/*.yaml')) ?: [] as $file) {
            /** @var array<string, mixed> $parsed */
            $parsed = Yaml::parseFile($file);
            $specs[basename($file)] = $parsed;
        }

        return self::$specs = $specs;
    }

    private static function json(Response $response): mixed
    {
        $type = (string) $response->headers->get('Content-Type', '');

        if (! str_contains($type, 'json')) {
            return null;
        }

        return json_decode((string) $response->getContent(), true);
    }
}
