<?php

declare(strict_types=1);

namespace App\Platform\Console;

/**
 * One organization as the acting-organization switcher shows it.
 *
 * A value object rather than the id => name map its neighbour
 * {@see ConsoleScope::availableOrganizations()} returns, because these two answer
 * different questions and the map's shape is what let them be confused for one another.
 * A map is a lookup: it is complete by definition, and asking it for "the first eight"
 * is meaningless. This is an ORDERED, BOUNDED page of results, which is the only shape a
 * chooser over an unbounded set can have.
 */
final readonly class ConsoleOrganization
{
    public function __construct(
        public string $id,
        public string $name,
    ) {}
}
