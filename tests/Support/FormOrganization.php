<?php

declare(strict_types=1);

namespace Tests\Support;

use Tests\TestCase;

/**
 * The organization an environment-console create form names in a test — its "For which
 * organization?" field, posted as `organization` the way the picker posts it.
 *
 * The environment console used to take the organization from an "acting organization" the
 * session remembered, and the suite chose one up front; the form helpers in tests/Pest.php
 * then posted nothing about it. There is no such state any more: the form says which
 * organization, so a helper posting to an `environment.*` form says it too, unless the test
 * states otherwise (an explicit `organization`, or the form left without one to see it
 * refused). Reset before every test ({@see TestCase::setUp()}).
 */
final class FormOrganization
{
    public static ?string $id = null;

    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    public static function apply(array $payload, string $plane): array
    {
        if (self::$id === null || ! str_starts_with($plane, 'environment.') || array_key_exists('organization', $payload)) {
            return $payload;
        }

        return ['organization' => self::$id, ...$payload];
    }
}
