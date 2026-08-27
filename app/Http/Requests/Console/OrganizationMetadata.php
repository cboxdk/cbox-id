<?php

declare(strict_types=1);

namespace App\Http\Requests\Console;

/**
 * The one place a list of key/value rows becomes a metadata map.
 *
 * Shared by the create form and the edit form because they take the same input in the same
 * shape, and two copies of this is how one of them comes to store a row the other drops.
 */
final class OrganizationMetadata
{
    /**
     * Blank keys dropped, everything trimmed.
     *
     * A row with no key is one somebody added and did not fill in; storing it under `''`
     * would put a nameless value in the tenant's settings that no screen can address.
     *
     * @param  array<array-key, mixed>  $rows
     * @return array<string, string>
     */
    public static function from(array $rows): array
    {
        $out = [];

        foreach ($rows as $row) {
            if (! is_array($row)) {
                continue;
            }

            $key = $row['key'] ?? '';
            $value = $row['value'] ?? '';

            // Narrowed rather than cast: a row whose key is not a string is not a row
            // somebody typed, it is a payload somebody built.
            if (! is_string($key) || ! is_string($value)) {
                continue;
            }

            $key = trim($key);

            if ($key === '') {
                continue;
            }

            $out[$key] = trim($value);
        }

        return $out;
    }
}
