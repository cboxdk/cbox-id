<?php

declare(strict_types=1);

namespace Cbox\Id\Whitelabel\Casts;

use Cbox\Id\Whitelabel\ValueObjects\Palette;
use Illuminate\Contracts\Database\Eloquent\CastsAttributes;
use Illuminate\Database\Eloquent\Model;

/**
 * Casts the `palette` JSON column to {@see Palette}.
 *
 * Deliberately NOT the built-in `array` cast. Under that cast every read handed back
 * `mixed` out of an array and each call site had to re-validate before letting a value
 * near the shell's `<style>` tag — and the one that mattered validated LATE, at render,
 * rather than at the boundary. Going through the value object on the way in AND out
 * means an invalid colour never reaches storage and never leaves it.
 *
 * @implements CastsAttributes<Palette, Palette|array<array-key, mixed>|null>
 */
class PaletteCast implements CastsAttributes
{
    /**
     * @param  array<string, mixed>  $attributes
     */
    public function get(Model $model, string $key, mixed $value, array $attributes): Palette
    {
        $decoded = is_string($value)
            ? json_decode($value, true, 512, JSON_THROW_ON_ERROR)
            : $value;

        return is_array($decoded) ? Palette::fromArray($decoded) : Palette::empty();
    }

    /**
     * @param  array<string, mixed>  $attributes
     * @return array<string, string>
     */
    public function set(Model $model, string $key, mixed $value, array $attributes): array
    {
        $palette = match (true) {
            $value instanceof Palette => $value,
            is_array($value) => Palette::fromArray($value),
            default => Palette::empty(),
        };

        return [$key => json_encode($palette->toArray(), JSON_THROW_ON_ERROR)];
    }
}
