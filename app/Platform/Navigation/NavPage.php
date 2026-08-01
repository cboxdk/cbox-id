<?php

declare(strict_types=1);

namespace App\Platform\Navigation;

/**
 * One entry in the console's second-tier navigation: a named route and the words a
 * person reads for it.
 */
readonly class NavPage
{
    public function __construct(
        public string $route,
        public string $label,
    ) {}

    /**
     * Whether this page owns the current request.
     *
     * A page stays lit on its own detail and create routes (`environment.users` →
     * `environment.users.show`) but NOT on a sibling that merely shares a prefix:
     * `environment.audit` must not light up on `environment.audit-streams`. Hence the
     * two explicit patterns rather than one `str_starts_with`.
     */
    public function isCurrent(): bool
    {
        return request()->routeIs($this->route) || request()->routeIs($this->route.'.*');
    }

    public function owns(string $route): bool
    {
        return $route === $this->route || str_starts_with($route, $this->route.'.');
    }

    public function href(): string
    {
        return route($this->route);
    }
}
