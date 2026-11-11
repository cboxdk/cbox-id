<?php

declare(strict_types=1);

namespace App\Platform\Scopes;

use Cbox\Id\Kernel\Authorization\Contracts\ScopeAliases;

/**
 * laravel-id's scope checks, taught the {@see ScopeRegistry}.
 *
 * The framework compares scopes by one canonical name each, so an old name and a new name
 * are the same scope when they share it. That is an equivalence, and the registry's rules
 * are not quite one, so only the renames that ARE plain equivalences are handed over:
 *
 *  - an old name that is the only old name of its new name (`workspace:read` is
 *    `id:organization:read`) maps to it, both ways;
 *  - old names that collapse into one new name (`vault.manage` and `token_vault:write`,
 *    `approvals.read` and `approvals:read`) stay themselves here: mapping both would let
 *    each satisfy the other, which the registry forbids. The app's own checks still let
 *    each reach the new name;
 *  - an old name that split (`keys:write`) maps to its first, canonical name only, as the
 *    registry already does, so `id:organization-keys:write` never stands in for it.
 *
 * Everything left out is narrower than the registry, never wider.
 */
final class RegistryScopeAliases implements ScopeAliases
{
    /** @var array<string, int>|null new name => how many old names stand for it */
    private ?array $oldNamesPerNewName = null;

    public function canonical(string $scope): string
    {
        if (! ScopeRegistry::isLegacy($scope)) {
            return $scope;
        }

        $new = ScopeRegistry::canonical($scope);

        return ($this->oldNamesPerNewName()[$new] ?? 0) === 1 ? $new : $scope;
    }

    /** @return array<string, int> */
    private function oldNamesPerNewName(): array
    {
        if ($this->oldNamesPerNewName !== null) {
            return $this->oldNamesPerNewName;
        }

        $counts = [];
        foreach (ScopeRegistry::RENAMED as $new) {
            foreach ($new as $name) {
                $counts[$name] = ($counts[$name] ?? 0) + 1;
            }
        }

        return $this->oldNamesPerNewName = $counts;
    }
}
