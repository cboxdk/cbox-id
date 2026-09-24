<?php

declare(strict_types=1);

namespace App\Http\Requests\Api\Environment;

use Cbox\Id\OAuthServer\ValueObjects\ApiScopeDefinition;

/**
 * The `scopes` array both API requests accept, parsed into the framework's
 * {@see ApiScopeDefinition}s. Validated by the request's rules first; this only reads.
 */
final class ApiScopeInput
{
    /**
     * @return array<string, list<string>>
     */
    public static function rules(): array
    {
        return [
            'scopes' => ['sometimes', 'array', 'max:200'],
            'scopes.*' => ['array:key,description,tenant_requestable'],
            'scopes.*.key' => ['required', 'string', 'max:128', 'distinct'],
            'scopes.*.description' => ['sometimes', 'nullable', 'string', 'max:255'],
            'scopes.*.tenant_requestable' => ['sometimes', 'boolean'],
        ];
    }

    /**
     * @return list<ApiScopeDefinition>
     */
    public static function from(mixed $scopes): array
    {
        if (! is_array($scopes)) {
            return [];
        }

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
