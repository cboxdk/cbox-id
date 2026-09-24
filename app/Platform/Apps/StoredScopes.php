<?php

declare(strict_types=1);

namespace App\Platform\Apps;

/**
 * An app's stored scopes, split the way its scope picker draws them.
 *
 * Every stored scope lands in exactly one list, so the three together are the whole set
 * and a save of the page as it was drawn writes back exactly what was there.
 */
final readonly class StoredScopes
{
    /**
     * @param  list<string>  $catalogue  ticked in the console's own catalogue
     * @param  list<string>  $api  ticked under a registered API this app may hold
     * @param  list<string>  $custom  typed by hand — free text, and anything else
     */
    public function __construct(
        public array $catalogue,
        public array $api,
        public array $custom,
    ) {}
}
