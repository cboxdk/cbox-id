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
            //
            // Unconditional, and it has to be: ApiContract::operation() — the checker
            // that actually validates response bodies — hardcodes '/api/v1'.$specPath.
            // This used to derive the base from a FILENAME heuristic instead, and the
            // two disagreeing was a live trap. A new spec file whose name contained
            // neither "account" nor "environment" either failed this test, or (worse)
            // passed it while ApiContract silently found no operation and skipped
            // schema validation entirely — a green build asserting nothing.
            $base = '/api/v1';

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
        'GET /.well-known/cbox-authenticator',
        'GET /.well-known/jwks.json',
        'GET /.well-known/oauth-authorization-server',
        'GET /.well-known/oauth-protected-resource',
        'GET /.well-known/openid-configuration',

        // The specs themselves.
        'GET /api/v1/openapi.yaml',
        'GET /api/v1/environment/openapi.yaml',

        // OAuth 2.0 / OIDC — RFC-specified. DEBT: no machine-readable contract yet.
        'GET /oauth/authorize',
        'POST /oauth/authorize',
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
        //
        // The seven vault operations used to sit here too, long after environment.yaml
        // grew a `Token Vault` section documenting all of them. Listing an operation as
        // debt AND documenting it is harmless to the diff checks (array_diff subtracts
        // both), which is exactly why it rotted unnoticed — and a debt list with entries
        // that are not debt stops meaning anything. Removed; the fourth test below now
        // holds the list to only-real-routes, and this one to only-real-debt.
        'POST /api/v1/apps/manifest',
    ];
}

it('lists nothing as debt that the specs already document', function (): void {
    // The mirror of the staleness check: an entry that IS documented is not debt.
    // Without this, a documented operation can linger on the list indefinitely — both
    // diffs subtract it, so nothing complains, and the list slowly becomes fiction.
    $notDebt = array_values(array_intersect(undocumentedByDesign(), documentedOperations()));

    expect($notDebt)->toBe(
        [],
        "These operations are documented, so they are no longer debt — delete them from\n"
        ."undocumentedByDesign():\n  ".implode("\n  ", $notDebt)
    );
});

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

/**
 * A spec that names the wrong CREDENTIAL is worse than one that names none: a developer
 * reading it, or a client generated from it, sends `Bearer cbid_env_…` at a route guarded
 * by `scope:vault.manage` and gets a bare 401 with no hint the credential TYPE is wrong.
 *
 * Five of the six Token Vault operations declared an environment API key while the
 * routes introspect an OAuth access token — and the spec contradicted its own prose two
 * hundred lines above. Neither existing gate could see it: one checks that paths are
 * documented, the other that response bodies match.
 */
it('declares a security scheme that matches each route\'s real middleware', function (): void {
    $spec = Yaml::parseFile(resource_path('openapi/environment.yaml'));

    // scope:<name> on the route → the scheme the spec must name for that operation.
    $expected = [
        'vault.manage' => 'VaultManagerToken',
        'vault.lease' => 'AgentToken',
    ];

    $mismatches = [];

    foreach (Route::getRoutes() as $route) {
        $scope = null;

        foreach ($route->gatherMiddleware() as $middleware) {
            if (is_string($middleware) && str_starts_with($middleware, 'scope:')) {
                $scope = substr($middleware, 6);
            }
        }

        if ($scope === null || ! isset($expected[$scope])) {
            continue;
        }

        // Routes are `api/v1/vault/...`; the spec's server carries the `/api/v1` prefix
        // and its paths start at `/vault/...`. Strip it, or the loop silently matches
        // nothing — which is exactly what the first version of this gate did.
        $path = '/'.ltrim((string) $route->uri(), '/');
        $path = (string) preg_replace('#^/api/v\d+#', '', $path);

        foreach ($route->methods() as $method) {
            $method = strtolower($method);

            if ($method === 'head') {
                continue;
            }

            $declared = $spec['paths'][$path][$method]['security'][0] ?? null;
            $declared = is_array($declared) ? array_key_first($declared) : null;

            if ($declared !== null && $declared !== $expected[$scope]) {
                $mismatches[] = strtoupper($method)." {$path}: spec says {$declared}, route requires {$scope} ({$expected[$scope]})";
            }
        }
    }

    expect($mismatches)->toBe([], implode('; ', $mismatches));
});
