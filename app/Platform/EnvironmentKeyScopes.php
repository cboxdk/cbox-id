<?php

declare(strict_types=1);

namespace App\Platform;

use Cbox\Id\Platform\Enums\EnvironmentApiScope;
use Illuminate\Support\Facades\Route as Router;

/**
 * The environment API scopes this deployment OFFERS on a new key.
 *
 * The framework's {@see EnvironmentApiScope::offerable()} — which already holds back the
 * reserved `directories:*` scopes — narrowed once more, to the scopes some route in
 * `routes/api.php` actually requires (`env.api:<scope>`). The framework catalogues a scope
 * before every host serves an endpoint for it, and a box that grants nothing is one an
 * administrator ticks to no effect, then reasonably concludes the key can do something
 * over the API that it cannot. So a scope appears on the form the moment a route asks for
 * it, and not before — with no second list here to keep in step.
 */
final class EnvironmentKeyScopes
{
    /** The middleware alias that names a route's required scope as its parameter. */
    private const string MIDDLEWARE = 'env.api:';

    /**
     * In the enum's order, which pairs each resource's read with its write — the pair a
     * reader compares when deciding how much to hand a credential that can provision
     * people.
     *
     * @return list<EnvironmentApiScope>
     */
    public static function offered(): array
    {
        $required = self::requiredByARoute();

        return array_values(array_filter(
            EnvironmentApiScope::offerable(),
            static fn (EnvironmentApiScope $scope): bool => isset($required[$scope->value]),
        ));
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
        return $scope->writes();
    }

    /**
     * Every scope some registered route requires, as a set.
     *
     * @return array<string, true>
     */
    private static function requiredByARoute(): array
    {
        $required = [];

        foreach (Router::getRoutes()->getRoutes() as $route) {
            foreach ($route->middleware() as $middleware) {
                if (str_starts_with($middleware, self::MIDDLEWARE)) {
                    $required[substr($middleware, strlen(self::MIDDLEWARE))] = true;
                }
            }
        }

        return $required;
    }
}
