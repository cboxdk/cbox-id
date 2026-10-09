<?php

declare(strict_types=1);

namespace App\Platform\Radar;

use App\Platform\Radar\Enums\RadarField;
use App\Platform\Radar\Enums\RadarMethod;
use App\Platform\Radar\Enums\RadarOperator;
use Symfony\Component\HttpFoundation\IpUtils;

/**
 * The condition language of a Radar rule: `{field, operator, value}`, all of them required to
 * hold. Checked and normalised when a rule is SAVED, evaluated on every attempt.
 *
 * Small on purpose. A condition compares one fact with a value, or a short list of values,
 * using one of a closed set of operators. There is no expression syntax to parse, nothing to
 * execute, no regular expression to backtrack — evaluation is a handful of comparisons per
 * condition, bounded by {@see self::MAX_CONDITIONS} and {@see self::MAX_VALUES}.
 *
 * Normalised on save so evaluation needs no judgement: strings are compared case-insensitively
 * (stored lower-case), countries are ISO 3166-1 alpha-2 (stored upper-case), numbers are
 * numbers, booleans are booleans, IPs are canonical and ranges are valid CIDR.
 */
final class RadarConditions
{
    public const int MAX_CONDITIONS = 10;

    public const int MAX_VALUES = 100;

    /**
     * Check and normalise a rule's conditions. Each may carry `value` (one) or `values` (a list,
     * for `in`, `not_in`, `in_cidr`, `not_in_cidr`).
     *
     * @param  array<mixed>  $conditions
     * @return list<array{field: string, operator: string, value: string|int|float|bool|list<string|int|float>}>
     *
     * @throws RadarRuleInvalid
     */
    public static function normalize(array $conditions): array
    {
        if ($conditions === []) {
            throw new RadarRuleInvalid('A rule needs at least one condition.');
        }

        if (count($conditions) > self::MAX_CONDITIONS) {
            throw new RadarRuleInvalid('A rule may have at most '.self::MAX_CONDITIONS.' conditions.');
        }

        $normalized = [];

        foreach (array_values($conditions) as $index => $condition) {
            $position = 'Condition '.($index + 1);

            if (! is_array($condition)) {
                throw new RadarRuleInvalid("{$position} must be an object with a field, an operator and a value.");
            }

            $field = is_string($condition['field'] ?? null) ? RadarField::tryFrom($condition['field']) : null;

            if ($field === null) {
                throw new RadarRuleInvalid("{$position}: the field must be one of ".implode(', ', RadarField::values()).'.');
            }

            $operator = is_string($condition['operator'] ?? null) ? RadarOperator::tryFrom($condition['operator']) : null;

            if ($operator === null || ! in_array($operator, $field->operators(), true)) {
                $allowed = implode(', ', array_map(static fn (RadarOperator $o): string => $o->value, $field->operators()));

                throw new RadarRuleInvalid("{$position}: `{$field->value}` takes the operators {$allowed}.");
            }

            $raw = $operator->takesList()
                ? ($condition['values'] ?? $condition['value'] ?? null)
                : ($condition['value'] ?? null);

            $normalized[] = [
                'field' => $field->value,
                'operator' => $operator->value,
                'value' => $operator->takesList()
                    ? self::normalizeList($field, $operator, $raw, $position)
                    : self::normalizeOne($field, $operator, $raw, $position),
            ];
        }

        return $normalized;
    }

    /**
     * Whether one (normalised) condition holds for these facts. An unknown fact holds for
     * nothing, whatever the operator.
     *
     * @param  array<mixed>  $condition
     */
    public static function matches(array $condition, RadarFacts $facts): bool
    {
        $field = is_string($condition['field'] ?? null) ? RadarField::tryFrom($condition['field']) : null;
        $operator = is_string($condition['operator'] ?? null) ? RadarOperator::tryFrom($condition['operator']) : null;

        if ($field === null || $operator === null) {
            return false;
        }

        $fact = $facts->get($field);

        if ($fact === null) {
            return false;
        }

        $expected = $condition['value'] ?? null;
        $list = is_array($expected) ? array_values($expected) : [$expected];

        return match ($field->type()) {
            'number' => self::compareNumber($operator, is_bool($fact) ? null : (float) $fact, $list),
            'boolean' => self::compareBoolean($operator, $fact === true, $list[0] ?? null),
            'ip' => self::compareIp($operator, (string) $fact, $list),
            'country' => self::compareString($operator, strtoupper((string) $fact), $list),
            default => self::compareString($operator, strtolower((string) $fact), $list),
        };
    }

    /**
     * A condition as a person reads it: `Country is not one of DK, SE`.
     *
     * @param  array<mixed>  $condition
     */
    public static function describe(array $condition): string
    {
        $field = is_string($condition['field'] ?? null) ? RadarField::tryFrom($condition['field']) : null;
        $operator = is_string($condition['operator'] ?? null) ? RadarOperator::tryFrom($condition['operator']) : null;

        if ($field === null || $operator === null) {
            return '';
        }

        $value = $condition['value'] ?? null;
        $text = is_array($value)
            ? implode(', ', array_map(self::scalarText(...), $value))
            : self::scalarText($value);

        return $field->label().' '.$operator->phrase().' '.$text;
    }

    private static function scalarText(mixed $value): string
    {
        return match (true) {
            $value === true => 'yes',
            $value === false => 'no',
            is_scalar($value) => (string) $value,
            default => '',
        };
    }

    /**
     * @return list<string|int|float>
     */
    private static function normalizeList(RadarField $field, RadarOperator $operator, mixed $raw, string $position): array
    {
        if (is_string($raw)) {
            // A comma-separated list, as a person types it in a form.
            $raw = array_map('trim', explode(',', $raw));
        }

        if (! is_array($raw)) {
            throw new RadarRuleInvalid("{$position}: `{$operator->value}` takes a list of values.");
        }

        $raw = array_values(array_filter($raw, static fn (mixed $value): bool => ! (is_string($value) && trim($value) === '')));

        if ($raw === []) {
            throw new RadarRuleInvalid("{$position}: name at least one value.");
        }

        if (count($raw) > self::MAX_VALUES) {
            throw new RadarRuleInvalid("{$position}: at most ".self::MAX_VALUES.' values.');
        }

        $values = [];

        foreach ($raw as $value) {
            $one = self::normalizeOne($field, $operator, $value, $position);

            if (is_bool($one)) {
                throw new RadarRuleInvalid("{$position}: `{$operator->value}` does not apply to a yes/no field.");
            }

            $values[] = $one;
        }

        return array_values(array_unique($values, SORT_REGULAR));
    }

    private static function normalizeOne(RadarField $field, RadarOperator $operator, mixed $raw, string $position): string|int|float|bool
    {
        if (is_array($raw) || $raw === null || (is_string($raw) && trim($raw) === '')) {
            throw new RadarRuleInvalid("{$position}: name a value.");
        }

        if (! is_scalar($raw)) {
            throw new RadarRuleInvalid("{$position}: the value must be text, a number or true/false.");
        }

        $text = is_bool($raw) ? ($raw ? 'true' : 'false') : trim((string) $raw);

        if (mb_strlen($text) > 255) {
            throw new RadarRuleInvalid("{$position}: a value may be at most 255 characters.");
        }

        return match ($field->type()) {
            'number' => is_numeric($text)
                ? (str_contains($text, '.') ? (float) $text : (int) $text)
                : throw new RadarRuleInvalid("{$position}: `{$field->value}` is a number."),
            'boolean' => match (strtolower($text)) {
                'true', '1', 'yes' => true,
                'false', '0', 'no' => false,
                default => throw new RadarRuleInvalid("{$position}: `{$field->value}` is true or false."),
            },
            'country' => preg_match('/^[A-Za-z]{2}$/', $text) === 1
                ? strtoupper($text)
                : throw new RadarRuleInvalid("{$position}: a country is a two-letter ISO code, such as DK."),
            'ip' => self::normalizeIp($operator, $text, $position),
            default => self::normalizeString($field, $text, $position),
        };
    }

    private static function normalizeString(RadarField $field, string $text, string $position): string
    {
        if ($field === RadarField::Method && RadarMethod::tryFrom(strtolower($text)) === null) {
            throw new RadarRuleInvalid("{$position}: a method is one of ".implode(', ', RadarMethod::values()).'.');
        }

        return strtolower($text);
    }

    private static function normalizeIp(RadarOperator $operator, string $text, string $position): string
    {
        $isRange = $operator === RadarOperator::InCidr || $operator === RadarOperator::NotInCidr;
        $canonical = $isRange ? RadarAddresses::canonicalCidr($text) : RadarAddresses::canonicalIp($text);

        if ($canonical === null) {
            throw new RadarRuleInvalid($isRange
                ? "{$position}: `{$text}` is not an IP range — write it as CIDR, such as 203.0.113.0/24."
                : "{$position}: `{$text}` is not an IP address.");
        }

        return $canonical;
    }

    /**
     * @param  list<mixed>  $expected
     */
    private static function compareNumber(RadarOperator $operator, ?float $fact, array $expected): bool
    {
        if ($fact === null) {
            return false;
        }

        $numbers = array_map(static fn (mixed $value): float => is_numeric($value) ? (float) $value : NAN, $expected);
        $first = $numbers[0] ?? NAN;

        return match ($operator) {
            RadarOperator::Equals => $fact === $first,
            RadarOperator::NotEquals => $fact !== $first,
            RadarOperator::GreaterThan => $fact > $first,
            RadarOperator::GreaterThanOrEqual => $fact >= $first,
            RadarOperator::LessThan => $fact < $first,
            RadarOperator::LessThanOrEqual => $fact <= $first,
            RadarOperator::In => in_array($fact, $numbers, true),
            RadarOperator::NotIn => ! in_array($fact, $numbers, true),
            default => false,
        };
    }

    private static function compareBoolean(RadarOperator $operator, bool $fact, mixed $expected): bool
    {
        if (! is_bool($expected)) {
            return false;
        }

        return match ($operator) {
            RadarOperator::Equals => $fact === $expected,
            RadarOperator::NotEquals => $fact !== $expected,
            default => false,
        };
    }

    /**
     * @param  list<mixed>  $expected
     */
    private static function compareIp(RadarOperator $operator, string $fact, array $expected): bool
    {
        $ip = RadarAddresses::canonicalIp($fact);

        if ($ip === null) {
            return false;
        }

        $values = array_values(array_filter($expected, 'is_string'));

        return match ($operator) {
            RadarOperator::Equals => $ip === ($values[0] ?? null),
            RadarOperator::NotEquals => $ip !== ($values[0] ?? null),
            RadarOperator::In => in_array($ip, $values, true),
            RadarOperator::NotIn => ! in_array($ip, $values, true),
            RadarOperator::InCidr => $values !== [] && IpUtils::checkIp($ip, $values),
            RadarOperator::NotInCidr => $values === [] || ! IpUtils::checkIp($ip, $values),
            default => false,
        };
    }

    /**
     * @param  list<mixed>  $expected
     */
    private static function compareString(RadarOperator $operator, string $fact, array $expected): bool
    {
        $values = array_map(static fn (mixed $value): string => is_scalar($value) ? (string) $value : '', $expected);
        $first = $values[0] ?? '';

        return match ($operator) {
            RadarOperator::Equals => $fact === $first,
            RadarOperator::NotEquals => $fact !== $first,
            RadarOperator::In => in_array($fact, $values, true),
            RadarOperator::NotIn => ! in_array($fact, $values, true),
            RadarOperator::Contains => $first !== '' && str_contains($fact, $first),
            RadarOperator::NotContains => $first === '' || ! str_contains($fact, $first),
            RadarOperator::StartsWith => $first !== '' && str_starts_with($fact, $first),
            RadarOperator::EndsWith => $first !== '' && str_ends_with($fact, $first),
            default => false,
        };
    }
}
