<?php

declare(strict_types=1);

namespace App\Platform\Console;

/**
 * One console page contributed by a module, and the planes it serves.
 *
 * `only` is null for the ordinary case — BOTH planes — so a module that says nothing
 * about planes gets the right answer. That default is the whole point: the previous API
 * took a route name and put it in one rail, and six modules in a row registered on the
 * organization plane alone without ever choosing to.
 */
final readonly class ConsolePage
{
    public function __construct(
        public ConsoleArea $area,
        public string $route,
        public string $label,
        public string $feature,
        public int $order = 100,
        public ?ConsolePlane $only = null,
    ) {}

    public function serves(ConsolePlane $plane): bool
    {
        return $this->only === null || $this->only === $plane;
    }

    /**
     * This page's route name on a given plane.
     *
     * The same prefix rule {@see ConsoleScope::routeName()} applies, and deliberately the
     * same one: a component asks the scope which name to link to, and this asks which
     * name to register and check for. Two spellings of that rule would drift.
     */
    public function routeOn(ConsolePlane $plane): string
    {
        return $plane === ConsolePlane::Environment ? 'environment.'.$this->route : $this->route;
    }
}
