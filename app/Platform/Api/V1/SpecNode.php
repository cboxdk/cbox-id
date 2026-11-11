<?php

declare(strict_types=1);

namespace App\Platform\Api\V1;

/**
 * Reading a parsed OpenAPI document, whose every node is `mixed` until looked at: a node
 * that is not the expected kind reads as empty, never as an error mid-build.
 */
final class SpecNode
{
    /**
     * A node as a map with string keys.
     *
     * @return array<string, mixed>
     */
    public static function map(mixed $node): array
    {
        $map = [];

        foreach (is_array($node) ? $node : [] as $key => $value) {
            $map[(string) $key] = $value;
        }

        return $map;
    }

    /**
     * A node as an array, keys kept (status codes, list positions).
     *
     * @return array<array-key, mixed>
     */
    public static function list(mixed $node): array
    {
        return is_array($node) ? $node : [];
    }

    /** A scalar node as text; anything else is empty. */
    public static function string(mixed $node): string
    {
        return is_string($node) || is_int($node) || is_float($node) ? (string) $node : '';
    }
}
