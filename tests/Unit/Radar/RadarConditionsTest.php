<?php

declare(strict_types=1);

use App\Platform\Radar\Enums\RadarField;
use App\Platform\Radar\Enums\RadarFlow;
use App\Platform\Radar\Enums\RadarMethod;
use App\Platform\Radar\RadarConditions;
use App\Platform\Radar\RadarFacts;
use App\Platform\Radar\RadarRuleInvalid;

/*
|--------------------------------------------------------------------------
| The Radar condition language — checked on save, evaluated on every attempt.
|--------------------------------------------------------------------------
*/

/** @param  array<string, string|int|float|bool|null>  $values */
function radarConditionFacts(array $values): RadarFacts
{
    return new RadarFacts(RadarFlow::SignIn, RadarMethod::Password, $values);
}

/** @param  array<string, mixed>  $condition */
function radarConditionHolds(array $condition, RadarFacts $facts): bool
{
    return RadarConditions::matches(RadarConditions::normalize([$condition])[0], $facts);
}

it('normalises values to the field type on save', function (): void {
    $normalized = RadarConditions::normalize([
        ['field' => 'country', 'operator' => 'not_in', 'values' => ['dk', ' se ', 'DK']],
        ['field' => 'risk_score', 'operator' => 'gte', 'value' => '60'],
        ['field' => 'is_vpn', 'operator' => 'eq', 'value' => 'yes'],
        ['field' => 'email_domain', 'operator' => 'ends_with', 'value' => 'Example.COM'],
        ['field' => 'ip', 'operator' => 'in_cidr', 'value' => '2001:DB8::/32, 203.0.113.0/24'],
        ['field' => 'travel_kmh', 'operator' => 'gt', 'value' => '850.5'],
    ]);

    expect($normalized)->toBe([
        ['field' => 'country', 'operator' => 'not_in', 'value' => ['DK', 'SE']],
        ['field' => 'risk_score', 'operator' => 'gte', 'value' => 60],
        ['field' => 'is_vpn', 'operator' => 'eq', 'value' => true],
        ['field' => 'email_domain', 'operator' => 'ends_with', 'value' => 'example.com'],
        ['field' => 'ip', 'operator' => 'in_cidr', 'value' => ['2001:db8::/32', '203.0.113.0/24']],
        ['field' => 'travel_kmh', 'operator' => 'gt', 'value' => 850.5],
    ]);
});

it('refuses what it would not evaluate, saying which condition and why', function (array $conditions, string $message): void {
    expect(fn () => RadarConditions::normalize($conditions))->toThrow(RadarRuleInvalid::class, $message);
})->with([
    'no conditions' => [[], 'at least one condition'],
    'unknown field' => [[['field' => 'password', 'operator' => 'eq', 'value' => 'x']], 'Condition 1: the field must be one of'],
    'operator the field does not take' => [[['field' => 'country', 'operator' => 'gt', 'value' => 'DK']], '`country` takes the operators eq, neq, in, not_in'],
    'not a country' => [[['field' => 'country', 'operator' => 'eq', 'value' => 'Denmark']], 'two-letter ISO code'],
    'not a number' => [[['field' => 'asn', 'operator' => 'eq', 'value' => 'AS15169']], '`asn` is a number'],
    'not a range' => [[['field' => 'ip', 'operator' => 'in_cidr', 'values' => ['10.0.0.0/33']]], 'is not an IP range'],
    'not a method' => [[['field' => 'method', 'operator' => 'eq', 'value' => 'telepathy']], 'a method is one of'],
    'empty list' => [[['field' => 'country', 'operator' => 'in', 'values' => []]], 'name at least one value'],
    'too many' => [array_fill(0, 11, ['field' => 'is_tor', 'operator' => 'eq', 'value' => 'true']), 'at most 10 conditions'],
    'second condition' => [[['field' => 'is_tor', 'operator' => 'eq', 'value' => 'true'], ['field' => 'asn', 'operator' => 'gt']], 'Condition 2: name a value'],
]);

it('compares each type the way its operators say', function (): void {
    $facts = radarConditionFacts([
        'country' => 'DK',
        'risk_score' => 62.5,
        'is_vpn' => false,
        'email_domain' => 'mail.example.com',
        'user_agent' => 'Mozilla/5.0 HeadlessChrome/120',
        'ip' => '203.0.113.9',
        'method' => 'password',
    ]);

    expect(radarConditionHolds(['field' => 'country', 'operator' => 'in', 'values' => ['DK', 'SE']], $facts))->toBeTrue()
        ->and(radarConditionHolds(['field' => 'country', 'operator' => 'not_in', 'values' => ['DK', 'SE']], $facts))->toBeFalse()
        ->and(radarConditionHolds(['field' => 'risk_score', 'operator' => 'gte', 'value' => '60'], $facts))->toBeTrue()
        ->and(radarConditionHolds(['field' => 'risk_score', 'operator' => 'lt', 'value' => '60'], $facts))->toBeFalse()
        ->and(radarConditionHolds(['field' => 'is_vpn', 'operator' => 'eq', 'value' => 'false'], $facts))->toBeTrue()
        ->and(radarConditionHolds(['field' => 'email_domain', 'operator' => 'ends_with', 'value' => 'EXAMPLE.com'], $facts))->toBeTrue()
        ->and(radarConditionHolds(['field' => 'user_agent', 'operator' => 'contains', 'value' => 'headlesschrome'], $facts))->toBeTrue()
        ->and(radarConditionHolds(['field' => 'ip', 'operator' => 'in_cidr', 'values' => ['203.0.113.0/24']], $facts))->toBeTrue()
        ->and(radarConditionHolds(['field' => 'ip', 'operator' => 'not_in_cidr', 'values' => ['203.0.113.0/24']], $facts))->toBeFalse()
        ->and(radarConditionHolds(['field' => 'ip', 'operator' => 'eq', 'value' => '203.0.113.9'], $facts))->toBeTrue()
        ->and(radarConditionHolds(['field' => 'method', 'operator' => 'neq', 'value' => 'passkey'], $facts))->toBeTrue();
});

it('matches no condition on an unknown fact, whatever the operator', function (): void {
    // No IP intelligence: the country is unknown. A rule meaning "challenge everyone outside
    // the Nordics" must not challenge everyone merely because nobody can be placed.
    $unknown = radarConditionFacts(['country' => null]);

    expect(radarConditionHolds(['field' => 'country', 'operator' => 'not_in', 'values' => ['DK', 'SE']], $unknown))->toBeFalse()
        ->and(radarConditionHolds(['field' => 'country', 'operator' => 'neq', 'value' => 'DK'], $unknown))->toBeFalse()
        ->and(radarConditionHolds(['field' => 'is_vpn', 'operator' => 'neq', 'value' => 'true'], $unknown))->toBeFalse()
        ->and(radarConditionHolds(['field' => 'ip', 'operator' => 'not_in_cidr', 'values' => ['10.0.0.0/8']], $unknown))->toBeFalse();
});

it('reads a condition back the way a person would write it', function (): void {
    $condition = RadarConditions::normalize([['field' => 'country', 'operator' => 'not_in', 'values' => ['DK', 'SE']]])[0];

    expect(RadarConditions::describe($condition))->toBe('Country is not one of DK, SE')
        ->and(RadarField::from('ip_distinct_emails_10m')->type())->toBe('number');
});
