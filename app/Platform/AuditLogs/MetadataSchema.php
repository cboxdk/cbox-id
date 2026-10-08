<?php

declare(strict_types=1);

namespace App\Platform\AuditLogs;

use App\Models\AuditLogs\AuditLogSchema;

/**
 * What an audit event's metadata may hold, said in a SUBSET of JSON Schema — the subset a
 * flat map of names to values needs, and nothing a reader would expect to work that does
 * not.
 *
 * An event's metadata (its own, its actor's, each target's) is a flat object: names to
 * strings, numbers, booleans or null ({@see EventShape}). A schema for one is an object
 * schema whose properties are scalars:
 *
 *     { "type": "object",
 *       "properties": { "invoice_total": { "type": "number", "minimum": 0 },
 *                       "currency":      { "type": "string", "enum": ["EUR", "DKK"] } },
 *       "required": ["currency"],
 *       "additionalProperties": false }
 *
 * Understood: `type` (one of string, number, integer, boolean, null, or a list of them),
 * `enum`, `minLength`, `maxLength`, `pattern`, `minimum`, `maximum`, `format` (date-time,
 * email, uri, uuid) and the annotations `title` and `description`. Anything else is
 * REFUSED when the schema is saved ({@see self::problems()}) rather than ignored when an
 * event is checked — a keyword that silently does nothing is a rule somebody believes is
 * enforced. The whole of JSON Schema would need a runtime dependency this deployment does
 * not carry, and would validate shapes an audit event can never have.
 *
 * Stored on {@see AuditLogSchema} per action.
 */
final class MetadataSchema
{
    private const array ROOT_KEYWORDS = ['type', 'properties', 'required', 'additionalProperties', 'title', 'description'];

    private const array PROPERTY_KEYWORDS = ['type', 'enum', 'minLength', 'maxLength', 'pattern', 'minimum', 'maximum', 'format', 'title', 'description'];

    private const array TYPES = ['string', 'number', 'integer', 'boolean', 'null'];

    private const array FORMATS = ['date-time', 'email', 'uri', 'uuid'];

    /**
     * What is wrong with $schema as a metadata schema — empty when it is one.
     *
     * @param  array<mixed>  $schema
     * @return list<string>
     */
    public static function problems(array $schema): array
    {
        $problems = [];

        foreach (array_keys($schema) as $keyword) {
            if (! in_array($keyword, self::ROOT_KEYWORDS, true)) {
                $problems[] = "`{$keyword}` is not supported at the top of a metadata schema.";
            }
        }

        if (array_key_exists('type', $schema) && $schema['type'] !== 'object') {
            $problems[] = 'A metadata schema describes an object: `type` must be "object".';
        }

        $properties = $schema['properties'] ?? [];

        if (! is_array($properties) || ($properties !== [] && array_is_list($properties))) {
            $problems[] = '`properties` must be an object of property names to schemas.';
            $properties = [];
        }

        foreach ($properties as $name => $property) {
            $name = (string) $name;

            if (! EventShape::validMetadataKey($name)) {
                $problems[] = "`{$name}` is not a metadata key an event could send.";
            }

            if (! is_array($property) || ($property !== [] && array_is_list($property))) {
                $problems[] = "The schema of `{$name}` must be an object.";

                continue;
            }

            foreach (self::propertyProblems($name, $property) as $problem) {
                $problems[] = $problem;
            }
        }

        $required = $schema['required'] ?? [];

        if (! is_array($required) || ! array_is_list($required) || array_filter($required, static fn (mixed $item): bool => ! is_string($item)) !== []) {
            $problems[] = '`required` must be a list of property names.';
        }

        if (array_key_exists('additionalProperties', $schema) && ! is_bool($schema['additionalProperties'])) {
            $problems[] = '`additionalProperties` must be true or false.';
        }

        return $problems;
    }

    /**
     * Where $value breaks $schema, keyed by the dotted path under $path. $value is metadata
     * an event sent — null when it sent none, which a schema with `required` refuses.
     *
     * @param  array<mixed>  $schema
     * @param  array<mixed>|null  $value
     * @return array<string, string>
     */
    public static function violations(array $schema, ?array $value, string $path): array
    {
        $value ??= [];
        $errors = [];
        /** @var array<string, array<string, mixed>> $properties */
        $properties = is_array($schema['properties'] ?? null) ? $schema['properties'] : [];
        $required = is_array($schema['required'] ?? null) ? $schema['required'] : [];

        foreach ($required as $name) {
            if (is_string($name) && ! array_key_exists($name, $value)) {
                $errors["{$path}.{$name}"] = "`{$name}` is required by this action's schema.";
            }
        }

        foreach ($value as $name => $item) {
            $name = (string) $name;
            $property = $properties[$name] ?? null;

            if ($property === null) {
                if (($schema['additionalProperties'] ?? true) === false) {
                    $errors["{$path}.{$name}"] = "`{$name}` is not in this action's schema.";
                }

                continue;
            }

            $problem = self::violation($property, $item);

            if ($problem !== null) {
                $errors["{$path}.{$name}"] = "`{$name}` {$problem}.";
            }
        }

        return $errors;
    }

    /**
     * @param  array<mixed>  $property
     * @return list<string>
     */
    private static function propertyProblems(string $name, array $property): array
    {
        $problems = [];

        foreach (array_keys($property) as $keyword) {
            if (! in_array($keyword, self::PROPERTY_KEYWORDS, true)) {
                $problems[] = "`{$keyword}` (on `{$name}`) is not supported — metadata values are flat scalars.";
            }
        }

        $types = $property['type'] ?? null;
        $types = is_string($types) ? [$types] : $types;

        if ($types !== null && (! is_array($types) || $types === [] || array_filter($types, static fn (mixed $type): bool => ! in_array($type, self::TYPES, true)) !== [])) {
            $problems[] = "The `type` of `{$name}` must be one of ".implode(', ', self::TYPES).', or a list of them.';
        }

        if (array_key_exists('enum', $property) && (! is_array($property['enum']) || ! array_is_list($property['enum']) || $property['enum'] === []
            || array_filter($property['enum'], static fn (mixed $item): bool => ! is_scalar($item) && $item !== null) !== [])) {
            $problems[] = "The `enum` of `{$name}` must be a non-empty list of values.";
        }

        foreach (['minLength', 'maxLength'] as $keyword) {
            if (array_key_exists($keyword, $property) && (! is_int($property[$keyword]) || $property[$keyword] < 0)) {
                $problems[] = "`{$keyword}` of `{$name}` must be a whole number, 0 or more.";
            }
        }

        foreach (['minimum', 'maximum'] as $keyword) {
            if (array_key_exists($keyword, $property) && ! is_int($property[$keyword]) && ! is_float($property[$keyword])) {
                $problems[] = "`{$keyword}` of `{$name}` must be a number.";
            }
        }

        if (array_key_exists('pattern', $property) && (! is_string($property['pattern']) || @preg_match(self::regex($property['pattern']), '') === false)) {
            $problems[] = "The `pattern` of `{$name}` is not a valid regular expression.";
        }

        if (array_key_exists('format', $property) && ! in_array($property['format'], self::FORMATS, true)) {
            $problems[] = "The `format` of `{$name}` must be one of ".implode(', ', self::FORMATS).'.';
        }

        return $problems;
    }

    /**
     * What is wrong with $value under $property, as the end of a sentence — or null.
     *
     * @param  array<mixed>  $property
     */
    private static function violation(array $property, mixed $value): ?string
    {
        $types = $property['type'] ?? null;
        $types = is_string($types) ? [$types] : (is_array($types) ? $types : null);

        if ($types !== null && ! self::ofAnyType($value, $types)) {
            return 'must be '.implode(' or ', array_filter($types, is_string(...)));
        }

        if (is_array($property['enum'] ?? null) && ! in_array($value, $property['enum'], true)) {
            return 'must be one of the values the schema lists';
        }

        if (is_string($value)) {
            $length = mb_strlen($value);

            if (is_int($property['minLength'] ?? null) && $length < $property['minLength']) {
                return "must be at least {$property['minLength']} characters";
            }

            if (is_int($property['maxLength'] ?? null) && $length > $property['maxLength']) {
                return "must be at most {$property['maxLength']} characters";
            }

            if (is_string($property['pattern'] ?? null) && preg_match(self::regex($property['pattern']), $value) !== 1) {
                return 'does not match the pattern the schema sets';
            }

            if (is_string($property['format'] ?? null) && ! self::ofFormat($value, $property['format'])) {
                return "must be a valid {$property['format']}";
            }
        }

        if (is_int($value) || is_float($value)) {
            $minimum = $property['minimum'] ?? null;
            $maximum = $property['maximum'] ?? null;

            if ((is_int($minimum) || is_float($minimum)) && $value < $minimum) {
                return "must be at least {$minimum}";
            }

            if ((is_int($maximum) || is_float($maximum)) && $value > $maximum) {
                return "must be at most {$maximum}";
            }
        }

        return null;
    }

    /**
     * @param  array<mixed>  $types
     */
    private static function ofAnyType(mixed $value, array $types): bool
    {
        foreach ($types as $type) {
            $matches = match ($type) {
                'string' => is_string($value),
                'number' => is_int($value) || is_float($value),
                'integer' => is_int($value) || (is_float($value) && floor($value) === $value),
                'boolean' => is_bool($value),
                'null' => $value === null,
                default => false,
            };

            if ($matches) {
                return true;
            }
        }

        return false;
    }

    private static function ofFormat(string $value, string $format): bool
    {
        return match ($format) {
            'date-time' => preg_match('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}(\.\d+)?(Z|[+-]\d{2}:\d{2})$/', $value) === 1 && strtotime($value) !== false,
            'email' => filter_var($value, FILTER_VALIDATE_EMAIL) !== false,
            'uri' => filter_var($value, FILTER_VALIDATE_URL) !== false,
            'uuid' => preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i', $value) === 1,
            default => false,
        };
    }

    /** A JSON Schema pattern (ECMA-262, unanchored) as a PCRE. */
    private static function regex(string $pattern): string
    {
        // `~` rather than `/`: a URL path in a pattern is common, a tilde is not.
        return '~'.str_replace('~', '\~', $pattern).'~u';
    }
}
