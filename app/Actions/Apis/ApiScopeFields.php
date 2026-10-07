<?php

declare(strict_types=1);

namespace App\Actions\Apis;

use App\Platform\Actions\Input\Field;
use Cbox\Id\OAuthServer\ValueObjects\ApiScopeDefinition;

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
