<?php

declare(strict_types=1);

namespace App\Platform;

use Cbox\Id\Platform\Enums\EnvironmentApiScope;

/**
 * The environment API scopes this deployment OFFERS on a new key.
 *
 * Narrower than the enum, and the gap is the point. `directories:read` and
 * `directories:write` are reserved by the framework and required by no route in
 * `routes/api.php` — so the form ticked a box that granted nothing, and a reader
 * reasonably concluded the key could manage directories over the API. A reserved scope
 * appears here the release its routes do.
 *
 * Exclusion rather than an allow-list, so a scope the framework adds for a route this app
 * serves is offered without a second edit here; a reserved one is named below with the
 * reason it is held back.
 */
final class EnvironmentKeyScopes
{
    /**
     * Reserved by the framework, required by no route.
     *
     * @var list<EnvironmentApiScope>
     */
    private const array RESERVED = [
        EnvironmentApiScope::DirectoriesRead,
        EnvironmentApiScope::DirectoriesWrite,
    ];

    /**
     * In the enum's order, which pairs each resource's read with its write — the pair a
     * reader compares when deciding how much to hand a credential that can provision
     * people.
     *
     * @return list<EnvironmentApiScope>
     */
    public static function offered(): array
    {
        $offered = [];

        foreach (EnvironmentApiScope::cases() as $scope) {
            if (! in_array($scope, self::RESERVED, true)) {
                $offered[] = $scope;
            }
        }

        return $offered;
    }

    /**
     * @return list<string>
     */
    public static function offeredValues(): array
    {
        return array_map(static fn (EnvironmentApiScope $scope): string => $scope->value, self::offered());
    }

    /** True for anything that is not `:read` — a key carrying it can change data. */
    public static function writes(EnvironmentApiScope $scope): bool
    {
        return ! str_ends_with($scope->value, ':read');
    }
}
