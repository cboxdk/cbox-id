<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Route;
use Symfony\Component\Yaml\Yaml;

/**
 * The spec-vs-routes gate.
 *
 * The OpenAPI spec covered 8 of ~56 API operations and NOTHING kept it honest: no lint,
 * no drift check, and the only tests asserted the YAML contained its own title. A
 * hand-maintained spec with no gate does not stay accurate — it decays silently, and an
 * integrator generating a client from it builds against fiction.
 *
 * This does not demand that everything be documented today. It demands that the gap only
 * ever SHRINKS: every currently-undocumented route is listed below, and a route that is
 * neither documented nor listed fails the build. Deleting a line from the list as you
 * document it is the intended workflow.
 */

/** @return list<string> "METHOD /uri" for every route on the API surface. */
function apiOperations(): array
{
    $operations = [];

    foreach (Route::getRoutes() as $route) {
        $uri = '/'.ltrim($route->uri(), '/');

        if (! str_starts_with($uri, '/api/')
            && ! str_starts_with($uri, '/oauth/')
            && ! str_starts_with($uri, '/scim/')
            && ! str_starts_with($uri, '/.well-known')) {
            continue;
        }

        foreach ($route->methods() as $method) {
            if (in_array($method, ['HEAD', 'OPTIONS'], true)) {
                continue;
            }

            $operations[] = $method.' '.$uri;
        }
    }

    sort($operations);

    return array_values(array_unique($operations));
}

/** @return list<string> "METHOD /uri" for every operation in the committed specs. */
function documentedOperations(): array
{
    $documented = [];

    foreach (glob(resource_path('openapi/*.yaml')) ?: [] as $file) {
        /** @var array<string, mixed> $spec */
        $spec = Yaml::parseFile($file);

        /** @var array<string, array<string, mixed>> $paths */
        $paths = $spec['paths'] ?? [];

        foreach ($paths as $path => $methods) {
            // A spec path is relative to the server's base URL; the routes carry it.
            $base = str_contains($file, 'account') || str_contains($file, 'environment') ? '/api/v1' : '';

            foreach (array_keys($methods) as $method) {
                if (in_array(strtolower((string) $method), ['parameters', 'summary', 'description'], true)) {
                    continue;
                }

                $documented[] = strtoupper((string) $method).' '.$base.$path;
            }
        }
    }

    sort($documented);

    return array_values(array_unique($documented));
}

/**
 * Routes that are knowingly undocumented. Shrink this list; never grow it.
 *
 * The protocol endpoints (OAuth/OIDC/SCIM) are specified by their RFCs, which is a
 * genuine argument for documenting them by reference rather than restating them — but it
 * is NOT an argument for a generated client being unable to see them, so they stay on
 * this list as debt rather than being quietly excluded.
 *
 * @return list<string>
 */
function undocumentedByDesign(): array
{
    return [
        // Discovery documents — served, and self-describing by definition.
        'GET /.well-known/jwks.json',
        'GET /.well-known/oauth-authorization-server',
        'GET /.well-known/oauth-protected-resource',
        'GET /.well-known/openid-configuration',

        // The specs themselves.
        'GET /api/v1/openapi.yaml',
        'GET /api/v1/environment/openapi.yaml',

        // OAuth 2.0 / OIDC — RFC-specified. DEBT: no machine-readable contract yet.
        'GET /oauth/authorize',
        'POST /oauth/backchannel_authentication',
        'POST /oauth/decisions',
        'POST /oauth/device_authorization',
        'POST /oauth/introspect',
        'GET /oauth/logout',
        // RP-Initiated Logout allows both bindings.
        'POST /oauth/logout',
        'POST /oauth/par',
        'POST /oauth/register',
        'GET /oauth/register/{client}',
        'PUT /oauth/register/{client}',
        'DELETE /oauth/register/{client}',
        'POST /oauth/revoke',
        'POST /oauth/token',
        'GET /oauth/userinfo',
        // OIDC Core §5.3 permits POST as well as GET.
        'POST /oauth/userinfo',

        // SCIM 2.0 — RFC 7644-specified. DEBT: same.
        'GET /scim/v2/Groups',
        'POST /scim/v2/Groups',
        'GET /scim/v2/Groups/{id}',
        'PUT /scim/v2/Groups/{id}',
        'PATCH /scim/v2/Groups/{id}',
        'DELETE /scim/v2/Groups/{id}',
        'GET /scim/v2/ResourceTypes',
        'GET /scim/v2/Schemas',
        'GET /scim/v2/ServiceProviderConfig',
        'GET /scim/v2/Users',
        'POST /scim/v2/Users',
        'GET /scim/v2/Users/{id}',
        'PUT /scim/v2/Users/{id}',
        'PATCH /scim/v2/Users/{id}',
        'DELETE /scim/v2/Users/{id}',

        // Management API — DEBT, and the most valuable to close: these are OURS, not an
        // RFC's, so nothing else describes them.
        'POST /api/v1/apps/manifest',
        'POST /api/v1/vault/secrets',
        'DELETE /api/v1/vault/secrets/{id}',
        'POST /api/v1/vault/secrets/{id}/grants',
        'DELETE /api/v1/vault/secrets/{id}/grants/{clientId}',
        'POST /api/v1/vault/secrets/{id}/lease',
        'POST /api/v1/vault/secrets/{id}/rotate',
    ];
}

it('documents every API route, or records it as known debt', function (): void {
    $missing = array_values(array_diff(apiOperations(), documentedOperations(), undocumentedByDesign()));

    expect($missing)->toBe(
        [],
        'These API routes are neither documented in resources/openapi/*.yaml nor listed as '
        ."known debt in undocumentedByDesign():\n  ".implode("\n  ", $missing)
        ."\n\nDocument them, or add them to the list with a reason."
    );
});

it('does not document a route that no longer exists', function (): void {
    // The other direction: a spec promising an endpoint we do not serve makes a
    // generated client call a 404.
    $phantom = array_values(array_diff(documentedOperations(), apiOperations()));

    expect($phantom)->toBe(
        [],
        "These operations are documented but not routed:\n  ".implode("\n  ", $phantom)
    );
});

it('keeps the debt list honest — every entry is a real route', function (): void {
    // A stale entry here would mask a genuinely undocumented route with the same name.
    $stale = array_values(array_diff(undocumentedByDesign(), apiOperations()));

    expect($stale)->toBe(
        [],
        "These entries in undocumentedByDesign() are no longer routes:\n  ".implode("\n  ", $stale)
    );
});

/**
 * Every $ref must resolve. A dangling one is the same class of defect as a documented
 * route that does not exist: a generated client builds against something that is not there.
 *
 * Written after a throwaway version of this check reported "all refs resolve" while a
 * dangling one was present — json_encode() escapes `/` as `\/`, so the pattern matched
 * nothing and the check was vacuous. It asserts the ref COUNT as well as the result, so
 * a future regex that matches nothing fails loudly instead of passing silently.
 */
it('resolves every $ref in the OpenAPI specs', function (): void {
    foreach (glob(resource_path('openapi/*.yaml')) ?: [] as $file) {
        $spec = Yaml::parseFile($file);
        $json = json_encode($spec, JSON_UNESCAPED_SLASHES) ?: '';

        preg_match_all('~#/components/(\w+)/(\w+)~', $json, $matches, PREG_SET_ORDER);

        expect($matches)->not->toBeEmpty("No \$refs found in {$file} — the matcher is broken, not the spec.");

        $dangling = [];

        foreach ($matches as [$ref, $section, $name]) {
            if (! isset($spec['components'][$section][$name])) {
                $dangling[] = $ref;
            }
        }

        expect(array_values(array_unique($dangling)))->toBe(
            [],
            basename($file).' has dangling $refs: '.implode(', ', array_unique($dangling))
        );
    }
});
