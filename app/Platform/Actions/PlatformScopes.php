<?php

declare(strict_types=1);

namespace App\Platform\Actions;

use App\Platform\Actions\Principal\OperatorPrincipal;
use App\Platform\Scopes\ScopeRegistry;

/**
 * Every scope the platform plane ({@see ActionPlane::Platform}) asks for — the `operator:*`
 * scopes a platform operator delegates to a token ({@see OperatorPrincipal}).
 *
 * ONE LOCK ABOVE THEM, NOT A ROLE. A workspace key's scopes narrow its role; here the
 * thing being narrowed is the operator themself, and being an operator is all-or-nothing:
 * a person who is not one is refused before any scope is read, however the token is
 * scoped. Scopes then split the operator's authority by blast radius, so a token that only
 * ever stands up customers cannot add a second operator.
 *
 * No management key carries any of these, and no key form offers them.
 */
final class PlatformScopes
{
    /**
     * @var array<string, array{label: string, description: string}>
     */
    public const array SCOPES = [
        'operator:workspaces:write' => [
            'label' => 'Manage organizations',
            'description' => 'Create an organization with its owner, first project and first environment; suspend and reactivate organizations.',
        ],
        'operator:environments:write' => [
            'label' => 'Manage environments',
            'description' => 'Create environments on the deployment, and bootstrap one with its first organization and administrator.',
        ],
        'operator:organizations:write' => [
            'label' => 'Manage organizations',
            'description' => 'Create organizations inside any environment, suspend and reactivate them, and move them in the hierarchy.',
        ],
        'operator:operators:write' => [
            'label' => 'Manage operators',
            'description' => 'Add platform operators, and suspend or reactivate them.',
        ],
    ];

    /** @return list<string> */
    public static function all(): array
    {
        return array_keys(self::SCOPES);
    }

    public static function knows(string $scope): bool
    {
        return self::resolve($scope) !== null;
    }

    public static function label(string $scope): string
    {
        return self::SCOPES[self::resolve($scope) ?? $scope]['label'] ?? $scope;
    }

    /**
     * The name this vocabulary files $scope under — itself, or the old name a new one stands
     * for ({@see ScopeRegistry}) — or null when it is not one of these.
     */
    public static function resolve(string $scope): ?string
    {
        foreach (ScopeRegistry::equivalents($scope) as $name) {
            if (isset(self::SCOPES[$name])) {
                return $name;
            }
        }

        return null;
    }
}
