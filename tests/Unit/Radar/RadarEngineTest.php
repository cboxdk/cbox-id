<?php

declare(strict_types=1);

use App\Platform\Radar\Enums\RadarAction;
use App\Platform\Radar\Enums\RadarFlow;
use App\Platform\Radar\Enums\RadarList;
use App\Platform\Radar\Enums\RadarListKind;
use App\Platform\Radar\Enums\RadarMethod;
use App\Platform\Radar\Enums\RadarMode;
use App\Platform\Radar\Enums\RadarRuleScope;
use App\Platform\Radar\RadarConditions;
use App\Platform\Radar\RadarEngine;
use App\Platform\Radar\RadarFacts;
use App\Platform\Radar\RadarPolicy;
use App\Platform\Radar\RadarPolicySnapshot;
use Cbox\Risk\Enums\Outcome;

/*
|--------------------------------------------------------------------------
| The order Radar asks its questions in.
|--------------------------------------------------------------------------
|
| Deny list, allow list, the environment's own rules (first match decides), the built-in
| rules (strictest decides), and allow when nothing matched.
*/

/** @param  array<string, string|int|float|bool|null>  $values */
function radarEngineFacts(array $values = [], RadarFlow $flow = RadarFlow::SignIn, ?Outcome $outcome = Outcome::Allow, ?string $device = null): RadarFacts
{
    return new RadarFacts($flow, $flow === RadarFlow::SignUp ? RadarMethod::SignUp : RadarMethod::Password, [
        'ip' => '203.0.113.9',
        'email' => 'dana@acme.example',
        'email_domain' => 'acme.example',
        'risk_score' => 0,
        ...$values,
    ], $device, $outcome);
}

/**
 * @param  list<array{0: string, 1: RadarAction, 2: list<array<string, mixed>>, 3?: RadarRuleScope}>  $rules
 * @param  list<array{0: RadarList, 1: RadarListKind, 2: string}>  $entries
 * @param  array<string, array{enabled?: bool, action?: RadarAction, threshold?: int|null}>  $builtins
 */
function radarEnginePolicy(array $rules = [], array $entries = [], array $builtins = []): RadarPolicySnapshot
{
    $settings = RadarPolicy::defaultBuiltins();

    foreach ($builtins as $key => $change) {
        $settings[$key] = [...$settings[$key], ...$change];
    }

    return new RadarPolicySnapshot(
        RadarMode::Enforce,
        $settings,
        array_map(static fn (array $rule, int $index): array => [
            'id' => 'r'.$index,
            'name' => $rule[0],
            'scope' => $rule[3] ?? RadarRuleScope::All,
            'action' => $rule[1],
            'conditions' => RadarConditions::normalize($rule[2]),
        ], $rules, array_keys($rules)),
        array_map(static fn (array $entry): array => ['list' => $entry[0], 'kind' => $entry[1], 'value' => $entry[2]], $entries),
    );
}

it('allows when nothing matches', function (): void {
    $verdict = RadarEngine::evaluate(radarEngineFacts(), radarEnginePolicy());

    expect($verdict->action)->toBe(RadarAction::Allow)
        ->and($verdict->rule)->toBeNull()
        ->and($verdict->triggered)->toBe([]);
});

it('blocks on the deny list before any rule is asked, and deny beats allow', function (): void {
    $policy = radarEnginePolicy(
        rules: [['Let the office in', RadarAction::Allow, [['field' => 'ip', 'operator' => 'in_cidr', 'values' => ['203.0.113.0/24']]]]],
        entries: [
            [RadarList::Allow, RadarListKind::Ip, '203.0.113.0/24'],
            [RadarList::Deny, RadarListKind::EmailDomain, 'acme.example'],
        ],
    );

    $verdict = RadarEngine::evaluate(radarEngineFacts(['email_domain' => 'mail.acme.example']), $policy);

    expect($verdict->action)->toBe(RadarAction::Block)
        ->and($verdict->rule)->toBe('deny_list:email_domain');
});

it('lets an allow-list entry skip every rule, built-in ones included, and still records what fired', function (): void {
    $policy = radarEnginePolicy(entries: [[RadarList::Allow, RadarListKind::Ip, '203.0.113.9']]);

    $verdict = RadarEngine::evaluate(radarEngineFacts(['ip_distinct_emails_10m' => 50]), $policy);

    expect($verdict->action)->toBe(RadarAction::Allow)
        ->and($verdict->rule)->toBe('allow_list:ip')
        ->and($verdict->triggered)->toBe(['allow_list:ip', 'builtin:credential_stuffing']);
});

it('matches a device on the deny list only by its cookie pseudonym', function (): void {
    $device = str_repeat('ab', 32);
    $policy = radarEnginePolicy(entries: [[RadarList::Deny, RadarListKind::Device, $device]]);

    expect(RadarEngine::evaluate(radarEngineFacts(device: $device), $policy)->action)->toBe(RadarAction::Block)
        ->and(RadarEngine::evaluate(radarEngineFacts(device: null), $policy)->action)->toBe(RadarAction::Allow);
});

it('takes the first of the environment\'s own rules that matches, in order', function (): void {
    $policy = radarEnginePolicy(rules: [
        ['Nordics are fine', RadarAction::Allow, [['field' => 'country', 'operator' => 'in', 'values' => ['DK', 'SE']]]],
        ['Everyone else is challenged', RadarAction::Challenge, [['field' => 'country', 'operator' => 'not_in', 'values' => ['DK', 'SE']]]],
        ['Never reached for DE', RadarAction::Block, [['field' => 'country', 'operator' => 'eq', 'value' => 'DE']]],
    ]);

    expect(RadarEngine::evaluate(radarEngineFacts(['country' => 'SE']), $policy)->rule)->toBe('rule:r0')
        ->and(RadarEngine::evaluate(radarEngineFacts(['country' => 'DE']), $policy)->action)->toBe(RadarAction::Challenge)
        ->and(RadarEngine::evaluate(radarEngineFacts(['country' => 'DE']), $policy)->ruleName)->toBe('Everyone else is challenged');
});

it('lets an own rule override a built-in block, and says the built-in fired', function (): void {
    $policy = radarEnginePolicy(rules: [['Load test from our CI', RadarAction::Allow, [['field' => 'asn', 'operator' => 'eq', 'value' => '64500']]]]);

    $verdict = RadarEngine::evaluate(radarEngineFacts(['asn' => 64500, 'ip_attempts_1m' => 500]), $policy);

    expect($verdict->action)->toBe(RadarAction::Allow)
        ->and($verdict->rule)->toBe('rule:r0')
        ->and($verdict->triggered)->toContain('builtin:bot_velocity');
});

it('asks a rule only on the flows it applies to', function (): void {
    $policy = radarEnginePolicy(rules: [['Sign-ups from Tor', RadarAction::Block, [['field' => 'is_tor', 'operator' => 'eq', 'value' => 'true']], RadarRuleScope::SignUp]], builtins: [
        'anonymous_network' => ['enabled' => false],
    ]);

    expect(RadarEngine::evaluate(radarEngineFacts(['is_tor' => true]), $policy)->action)->toBe(RadarAction::Allow)
        ->and(RadarEngine::evaluate(radarEngineFacts(['is_tor' => true], RadarFlow::SignUp), $policy)->action)->toBe(RadarAction::Block);
});

it('decides by the strictest built-in rule that fires', function (): void {
    $verdict = RadarEngine::evaluate(radarEngineFacts([
        'new_device' => true,
        'is_vpn' => true,
        'ip_distinct_emails_10m' => 12,
    ]), radarEnginePolicy(builtins: ['new_device' => ['enabled' => true]]));

    expect($verdict->action)->toBe(RadarAction::Block)
        ->and($verdict->rule)->toBe('builtin:credential_stuffing')
        ->and($verdict->triggered)->toBe(['builtin:credential_stuffing', 'builtin:new_device', 'builtin:anonymous_network'])
        ->and($verdict->reasons)->toContain('12 different addresses tried from this IP in 10 minutes (threshold 10).');
});

it('honours a built-in rule\'s own action, threshold and switch', function (): void {
    $facts = radarEngineFacts(['ip_attempts_1m' => 30]);

    expect(RadarEngine::evaluate($facts, radarEnginePolicy())->action)->toBe(RadarAction::Block)
        ->and(RadarEngine::evaluate($facts, radarEnginePolicy(builtins: ['bot_velocity' => ['action' => RadarAction::Challenge]]))->action)->toBe(RadarAction::Challenge)
        ->and(RadarEngine::evaluate($facts, radarEnginePolicy(builtins: ['bot_velocity' => ['threshold' => 31]]))->action)->toBe(RadarAction::Allow)
        ->and(RadarEngine::evaluate($facts, radarEnginePolicy(builtins: ['bot_velocity' => ['enabled' => false]]))->action)->toBe(RadarAction::Allow);
});

it('keeps the risk score\'s verdicts as built-in rules, so nothing that blocked before stops', function (): void {
    expect(RadarEngine::evaluate(radarEngineFacts(outcome: Outcome::Reject), radarEnginePolicy())->action)->toBe(RadarAction::Block)
        ->and(RadarEngine::evaluate(radarEngineFacts(outcome: Outcome::StepUp), radarEnginePolicy())->action)->toBe(RadarAction::Challenge)
        ->and(RadarEngine::evaluate(radarEngineFacts(outcome: Outcome::Challenge), radarEnginePolicy())->action)->toBe(RadarAction::Challenge)
        ->and(RadarEngine::evaluate(radarEngineFacts(outcome: Outcome::Flag), radarEnginePolicy())->action)->toBe(RadarAction::Allow);
});

it('asks the disposable-address rule on sign-up only', function (): void {
    $facts = ['disposable_email' => true];

    expect(RadarEngine::evaluate(radarEngineFacts($facts, RadarFlow::SignUp), radarEnginePolicy())->action)->toBe(RadarAction::Block)
        ->and(RadarEngine::evaluate(radarEngineFacts($facts), radarEnginePolicy())->action)->toBe(RadarAction::Allow);
});

it('leaves the two friction-heavy built-in rules off until switched on', function (): void {
    $facts = radarEngineFacts(['new_device' => true, 'is_hosting' => true]);

    expect(RadarEngine::evaluate($facts, radarEnginePolicy())->action)->toBe(RadarAction::Allow)
        ->and(RadarEngine::evaluate($facts, radarEnginePolicy(builtins: ['hosting_network' => ['enabled' => true]]))->rule)->toBe('builtin:hosting_network');
});
