<?php

declare(strict_types=1);

namespace App\Platform\ServiceAccess;

final readonly class Question
{
    /** @param list<string> $options */
    public function __construct(public string $key, public string $label, public string $type, public bool $required, public array $options, public string $validation, public int $minimum, public int $maximum) {}

    public static function from(mixed $value): self
    {
        if (! is_array($value) || ! is_string($value['key'] ?? null) || preg_match('/^[a-z][a-z0-9_]{0,39}$/', $value['key']) !== 1
            || ! is_string($value['label'] ?? null) || ! is_bool($value['required'] ?? null)
            || ! in_array($value['type'] ?? null, ['string', 'textarea', 'select', 'multiselect'], true)
            || ! in_array($value['validation'] ?? null, ['none', 'email', 'url'], true)
            || ! is_int($value['min_length'] ?? null) || ! is_int($value['max_length'] ?? null)
            || $value['min_length'] < 0 || $value['max_length'] > 2000 || $value['max_length'] < max(1, $value['min_length'])
            || ! is_array($value['options'] ?? null) || ! array_is_list($value['options'])) {
            throw new ServiceAccessUnavailable;
        }
        $options = [];
        foreach ($value['options'] as $option) {
            if (! is_string($option) || $option === '') {
                throw new ServiceAccessUnavailable;
            }
            $options[] = $option;
        }

        return new self($value['key'], $value['label'], $value['type'], $value['required'], $options, $value['validation'], $value['min_length'], $value['max_length']);
    }
}
