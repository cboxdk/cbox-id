<?php

declare(strict_types=1);

namespace App\Platform\PublicIds;

/**
 * PUBLIC IDS THROUGH A WHOLE RESPONSE OR PAYLOAD, one way or the other.
 *
 * Out ({@see encode()}): a node's `id` takes the prefix of the kind the node is (its `object`,
 * or the key it stands under, or what the caller says the top is); a `<stem>_id` field and a
 * `<stem>_ids` list take their stem's ({@see IdNames}). Only bare ULIDs change.
 *
 * In ({@see decode()}): the same fields, the other way, for a request's query and body.
 *
 * Never into what is somebody else's data: a customer's audit-log actor and targets, metadata,
 * properties and claims keep exactly the ids they were given.
 */
final class PublicIdTree
{
    /** Keys whose contents are not ours to rewrite. */
    private const array OPAQUE = ['metadata', 'custom_attributes', 'properties', 'claims', 'actor', 'targets', 'context', 'settings', 'raw'];

    /**
     * @param  array<array-key, mixed>  $node
     * @return array<array-key, mixed>
     */
    public static function encode(array $node, ?string $type): array
    {
        if (isset($node['object']) && is_string($node['object'])) {
            $type = IdNames::object($node['object']);
        }

        foreach ($node as $key => $value) {
            if (! is_string($key)) {
                if (is_array($value)) {
                    $node[$key] = self::encode($value, $type);
                }

                continue;
            }

            if (in_array($key, self::OPAQUE, true)) {
                continue;
            }

            if ($key === 'id') {
                $node[$key] = PublicId::encodeValue($type, $value);

                continue;
            }

            $fieldType = $key === 'parent_id' && $type === 'organization' ? 'organization' : IdNames::field($key);

            if ($fieldType !== null) {
                $node[$key] = is_array($value)
                    ? array_map(static fn (mixed $one): mixed => PublicId::encodeValue($fieldType, $one), $value)
                    : PublicId::encodeValue($fieldType, $value);

                continue;
            }

            if (is_array($value)) {
                $node[$key] = self::encode($value, IdNames::nested($key));
            }
        }

        return $node;
    }

    /**
     * The stored ids in a request's top-level fields: `user_id: "usr_01j…"` reads as
     * `01j…`, and `environment_ids` item by item.
     *
     * @param  array<array-key, mixed>  $input
     * @return array<string, mixed> only the fields that changed
     */
    public static function decode(array $input): array
    {
        $changed = [];

        foreach ($input as $key => $value) {
            if (! is_string($key) || ($type = IdNames::field($key)) === null) {
                continue;
            }

            $decoded = is_array($value)
                ? array_map(static fn (mixed $one): mixed => PublicId::decodeValue($type, $one), $value)
                : PublicId::decodeValue($type, $value);

            if ($decoded !== $value) {
                $changed[$key] = $decoded;
            }
        }

        return $changed;
    }

    /**
     * The inverse of {@see encode()} over a whole payload: every registered public id in it
     * reads as its bare ULID again, at any depth, except under the keys {@see encode()} never
     * writes to (a customer's own data keeps whatever it said).
     *
     * @param  array<array-key, mixed>  $node
     * @return array<array-key, mixed>
     */
    public static function strip(array $node): array
    {
        foreach ($node as $key => $value) {
            if (is_string($key) && in_array($key, self::OPAQUE, true)) {
                continue;
            }

            if (is_array($value)) {
                $node[$key] = self::strip($value);
            } elseif (is_string($value) && ($parsed = PublicId::parse($value)) !== null) {
                $node[$key] = $parsed[1];
            }
        }

        return $node;
    }
}
