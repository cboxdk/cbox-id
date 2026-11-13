<?php

declare(strict_types=1);

namespace App\Actions\Apis;

use App\Platform\Actions\ActionRefused;
use App\Platform\Actions\Input\Field;
use App\Platform\ScopeCatalog;
use App\Platform\Scopes\ScopeRegistry;
use Cbox\Id\OAuthServer\Contracts\ProtectedResources;
use Cbox\Id\OAuthServer\Support\ReservedScopes;
use Cbox\Id\OAuthServer\ValueObjects\ApiScopeDefinition;
use Cbox\Id\OAuthServer\ValueObjects\ProtectedResource;

/**
 * The `scopes` input APIs are registered and changed with, and how it becomes the
 * framework's definitions. A helper, not an action.
 */
final class ApiScopeFields
{
    public static function field(): Field
    {
        return Field::list('scopes', Field::object('scope', [
            Field::string('key')->required()->max(128)->distinct()->describe('The scope key, unique across the environment: `tax:assess`.'),
            Field::string('description')->nullable()->max(255)->describe('What the scope lets an app do, in plain words.'),
            Field::boolean('tenant_requestable')->describe('Whether organizations\' own apps may request it. Default true.'),
        ]))->max(200);
    }

    /**
     * Refuse a NEW scope key that Cbox ID's own resources already answer to: a scope of a
     * declared protected resource (the `/mcp` management plane: `users:read`,
     * `webhooks:read`…), a reserved scope, or a platform scope only the console grants.
     *
     * One namespace holds both, and a token request names a scope by key alone. A registered
     * API owning `users:read` took that scope away from the management plane: the token was
     * audienced to the API instead of `/mcp`, and a client anybody may self-register for
     * `/mcp` came away with a token for the customer's own API (security finding #51).
     *
     * @param  list<string>  $keys  The keys being ADDED; a key the API already owns stays editable.
     *
     * @throws ActionRefused
     */
    public static function refuseDeclared(array $keys, string $field): void
    {
        if ($keys === []) {
            return;
        }

        $declared = [...ReservedScopes::all(), ...ScopeCatalog::RESERVED_FOR_CONSOLE];

        foreach (app(ProtectedResources::class)->all() as $resource) {
            if ($resource instanceof ProtectedResource) {
                array_push($declared, ...$resource->scopes);
            }
        }

        foreach ($keys as $key) {
            if (array_intersect(ScopeRegistry::equivalents($key), $declared) !== []) {
                throw ActionRefused::because(
                    'invalid_api',
                    "`{$key}` is a scope Cbox ID's own management plane answers to, so an API cannot own it. Give the API's scopes its own prefix, such as `orders:read`.",
                    $field,
                );
            }
        }
    }

    /**
     * @param  array<mixed>  $scopes
     * @return list<ApiScopeDefinition>
     */
    public static function definitions(array $scopes): array
    {
        $out = [];

        foreach ($scopes as $scope) {
            if (! is_array($scope) || ! is_string($scope['key'] ?? null)) {
                continue;
            }

            $description = $scope['description'] ?? null;

            $out[] = new ApiScopeDefinition(
                key: $scope['key'],
                description: is_string($description) ? $description : null,
                tenantRequestable: filter_var($scope['tenant_requestable'] ?? true, FILTER_VALIDATE_BOOLEAN),
            );
        }

        return $out;
    }
}
