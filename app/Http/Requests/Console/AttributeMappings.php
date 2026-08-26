<?php

declare(strict_types=1);

namespace App\Http\Requests\Console;

/**
 * The one place a list of key/value rows becomes a SAML attribute map.
 *
 * `samlAttribute => subjectField`: the name the assertion emits, and the field on the
 * person it is read from. Shared by the register form and the edit form because they take
 * the same input in the same shape, and two copies of this is how one of them comes to keep
 * a row the other drops.
 *
 * It replaced a textarea parsed with `explode('=', $line, 2)` on both pages. That shape
 * could not say what it had refused: a line with no `=` was silently dropped, so a typo in
 * an attribute name looked exactly like a mapping that had never been typed, and the
 * assertion went out missing a claim the SP was waiting for.
 */
final class AttributeMappings
{
    /**
     * Blank names dropped, everything trimmed.
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

            $attribute = $row['key'] ?? '';
            $field = $row['value'] ?? '';

            // Narrowed rather than cast: a row whose name is not a string is not a row
            // somebody typed, it is a payload somebody built.
            if (! is_string($attribute) || ! is_string($field)) {
                continue;
            }

            $attribute = trim($attribute);
            $field = trim($field);

            // BOTH SIDES, unlike the metadata map beside it: an attribute mapped to nothing
            // would emit an empty claim, which an SP reads as "this person has no email"
            // rather than as "this was not configured".
            if ($attribute === '' || $field === '') {
                continue;
            }

            $out[$attribute] = $field;
        }

        return $out;
    }

    /**
     * And back again, for a form to edit.
     *
     * @param  array<string, string>  $mappings
     * @return list<array{key: string, value: string}>
     */
    public static function toRows(array $mappings): array
    {
        $rows = [];

        foreach ($mappings as $attribute => $field) {
            $rows[] = ['key' => (string) $attribute, 'value' => $field];
        }

        return $rows;
    }
}
