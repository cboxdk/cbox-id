<?php

declare(strict_types=1);

namespace App\Mcp;

use App\Platform\Actions\Input\Field;

/**
 * A tool's input schema in the form every MCP client's schema dialect takes.
 *
 * {@see Field::schema()} writes a nullable field the JSON Schema 2020-12 way,
 * `type: ["string", "null"]` — right for the REST API's OpenAPI 3.1 document, and valid
 * for MCP, whose default dialect is 2020-12 too. But a tool schema is read by the CLIENT,
 * and clients hand it on to a model provider in their own dialect: an OpenAPI 3.0-style
 * one (Gemini's function declarations among them) has no type arrays, and the MCP
 * Inspector warned on every such field — 185 of them. A client that refuses the schema
 * refuses the tool.
 *
 * So for MCP each nullable field becomes an `anyOf` of the field and `null`:
 *
 *     {description, anyOf: [{type: "string", maxLength: 64, …}, {type: "null"}]}
 *
 * The description stays beside `anyOf`, where a client shows it; every constraint moves
 * into the non-null branch, which is the only one it constrains. Null stays sendable —
 * dropping it and relying on "optional" would have been as portable, but a merge-patch
 * sends null to REMOVE a value (a log stream's option, a stored secret), and an agent
 * whose client checks arguments against the schema could no longer say so.
 *
 * Nested schemas — an array's `items`, an object's `properties` — are rewritten the same
 * way. Nothing else about the schema changes.
 */
final class PortableSchema
{
    /**
     * @param  array<string, mixed>  $schema
     * @return array<string, mixed>
     */
    public static function of(array $schema): array
    {
        if (is_array($schema['properties'] ?? null)) {
            $schema['properties'] = array_map(
                static fn (mixed $property): mixed => is_array($property) ? self::of(self::keyed($property)) : $property,
                $schema['properties'],
            );
        }

        if (is_array($schema['items'] ?? null)) {
            $schema['items'] = self::of(self::keyed($schema['items']));
        }

        $type = $schema['type'] ?? null;

        if (! is_array($type)) {
            return $schema;
        }

        $types = array_values(array_filter($type, static fn (mixed $name): bool => $name !== 'null'));
        $nullable = count($types) !== count($type);

        if (! $nullable && count($types) === 1) {
            $schema['type'] = $types[0];

            return $schema;
        }

        $outer = array_key_exists('description', $schema) ? ['description' => $schema['description']] : [];
        unset($schema['description'], $schema['type']);

        $branches = array_map(static fn (mixed $name): array => ['type' => $name, ...$schema], $types);

        if ($nullable) {
            $branches[] = ['type' => 'null'];
        }

        return [...$outer, 'anyOf' => $branches];
    }

    /**
     * A nested schema, keyed by its keywords.
     *
     * @param  array<mixed>  $schema
     * @return array<string, mixed>
     */
    private static function keyed(array $schema): array
    {
        $keyed = [];

        foreach ($schema as $keyword => $value) {
            $keyed[(string) $keyword] = $value;
        }

        return $keyed;
    }
}
