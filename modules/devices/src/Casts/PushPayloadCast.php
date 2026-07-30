<?php

declare(strict_types=1);

namespace Cbox\Id\Devices\Casts;

use Cbox\Id\Devices\ValueObjects\PushPayload;
use Illuminate\Contracts\Database\Eloquent\CastsAttributes;
use Illuminate\Database\Eloquent\Model;

/**
 * Hydrates the `payload` json column into a {@see PushPayload} rather than a loose
 * array. The json column is the serialization boundary and is fine; the PHP side
 * getting an untyped array back is the part the house rules forbid, because every
 * caller then re-derives the shape by guessing at keys.
 *
 * @implements CastsAttributes<PushPayload, PushPayload>
 */
final class PushPayloadCast implements CastsAttributes
{
    /**
     * @param  array<string, mixed>  $attributes
     */
    public function get(Model $model, string $key, mixed $value, array $attributes): PushPayload
    {
        if (! is_string($value) || $value === '') {
            return new PushPayload(title: '', body: '');
        }

        $decoded = json_decode($value, true);

        return is_array($decoded)
            ? PushPayload::fromArray($decoded)
            : new PushPayload(title: '', body: '');
    }

    /**
     * @param  array<string, mixed>  $attributes
     * @return array<string, string>
     */
    public function set(Model $model, string $key, mixed $value, array $attributes): array
    {
        $payload = $value instanceof PushPayload ? $value : new PushPayload(title: '', body: '');

        return [$key => json_encode($payload->toArray(), JSON_THROW_ON_ERROR)];
    }
}
