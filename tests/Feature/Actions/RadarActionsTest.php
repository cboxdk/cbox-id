<?php

declare(strict_types=1);

use App\Models\Radar\RadarRule;
use App\Models\RiskDecision;
use App\Platform\Actions\ActionRegistry;
use App\Platform\Actions\Danger;
use App\Platform\Radar\Enums\RadarMode;
use App\Platform\Radar\RadarPolicy;
use App\Platform\RiskGuard;
use Cbox\Id\Kernel\Audit\Enums\ActorType;
use Cbox\Id\Kernel\Audit\Models\AuditEntry;
use Cbox\Id\Kernel\Tenancy\Contracts\EnvironmentContext;
use Cbox\Id\Kernel\Tenancy\GenericEnvironment;
use Cbox\Id\Platform\Contracts\EnvironmentApiKeys;
use Illuminate\Http\Request;

/*
|--------------------------------------------------------------------------
| Radar over the API, MCP and the console — one set of actions, three doors.
|--------------------------------------------------------------------------
*/

const RADAR_SCOPES = ['radar:read', 'radar:write', 'radar:manage'];

/**
 * @param  list<string>  $scopes
 * @return array{0: string, 1: string} the key, and its id
 */
function radarKey(array $scopes = RADAR_SCOPES, string $environment = 'env_test'): array
{
    $issued = app(EnvironmentApiKeys::class)->issue($environment, 'Radar automation', $scopes);

    return [$issued->plaintext, (string) $issued->key->id];
}

function radarAudit(string $action): ?AuditEntry
{
    return AuditEntry::query()->where('action', $action)->orderByDesc('id')->first();
}

/** A scored sign-in attempt in the current environment, for the explorer to show. */
function radarAttempt(string $email, string $ip = '203.0.113.9'): RiskDecision
{
    app(RiskGuard::class)->assess(Request::create('/login', 'POST', server: ['REMOTE_ADDR' => $ip]), 'login', $email);

    return RiskDecision::query()->orderByDesc('id')->firstOrFail();
}

it('writes, reads, reorders and deletes rules over the API, every write on the trail as the key', function (): void {
    [$key, $keyId] = radarKey();

    $nordics = $this->withToken($key)->postJson('/api/v1/radar/rules', [
        'name' => 'Challenge outside the Nordics',
        'action' => 'challenge',
        'applies_to' => 'sign_in',
        'conditions' => [['field' => 'country', 'operator' => 'not_in', 'values' => ['DK', 'SE', 'NO']]],
    ])->assertCreated()
        ->assertJsonPath('data.position', 1)
        ->assertJsonPath('data.conditions.0.value', ['DK', 'SE', 'NO'])
        ->assertJsonPath('data.summary', 'Country is not one of DK, SE, NO')
        ->json('data');

    $office = $this->withToken($key)->postJson('/api/v1/radar/rules', [
        'name' => 'Office network',
        'action' => 'allow',
        'conditions' => [['field' => 'ip', 'operator' => 'in_cidr', 'values' => ['203.0.113.0/24']]],
        'position' => 1,
    ])->assertCreated()->assertJsonPath('data.position', 1)->json('data');

    expect(radarAudit('radar_rule.created')?->actor_type)->toBe(ActorType::Service)
        ->and(radarAudit('radar_rule.created')?->actor_id)->toBe($keyId);

    $this->withToken($key)->getJson('/api/v1/radar/rules')->assertOk()
        ->assertJsonPath('data.0.id', $office['id'])
        ->assertJsonPath('data.1.id', $nordics['id']);

    $this->withToken($key)->putJson('/api/v1/radar/rules/order', ['rule_ids' => [$nordics['id'], $office['id']]])->assertNoContent();
    $this->withToken($key)->getJson('/api/v1/radar/rules')->assertOk()->assertJsonPath('data.0.id', $nordics['id']);
    $this->withToken($key)->putJson('/api/v1/radar/rules/order', ['rule_ids' => [$nordics['id']]])->assertUnprocessable()
        ->assertJsonPath('error', 'invalid_order');

    $this->withToken($key)->patchJson("/api/v1/radar/rules/{$nordics['id']}", ['action' => 'block', 'enabled' => false])->assertOk()
        ->assertJsonPath('data.action', 'block')
        ->assertJsonPath('data.enabled', false);
    expect(radarAudit('radar_rule.updated')?->context['changes']['action'] ?? null)->toEqual(['from' => 'challenge', 'to' => 'block']);

    $this->withToken($key)->getJson("/api/v1/radar/rules/{$office['id']}")->assertOk()->assertJsonPath('data.name', 'Office network');
    $this->withToken($key)->deleteJson("/api/v1/radar/rules/{$office['id']}")->assertNoContent();

    expect(RadarRule::query()->sole()->position)->toBe(1)
        ->and(radarAudit('radar_rule.deleted'))->not->toBeNull();
});

it('refuses a rule it would not evaluate, saying why', function (): void {
    [$key] = radarKey();

    $this->withToken($key)->postJson('/api/v1/radar/rules', [
        'name' => 'Bad',
        'action' => 'block',
        'conditions' => [['field' => 'country', 'operator' => 'gt', 'value' => 'DK']],
    ])->assertUnprocessable()
        ->assertJsonPath('error', 'invalid_rule')
        ->assertJsonFragment(['message' => 'Condition 1: `country` takes the operators eq, neq, in, not_in.']);

    $this->withToken($key)->postJson('/api/v1/radar/rules', [
        'name' => 'No field',
        'action' => 'explode',
        'conditions' => [],
    ])->assertUnprocessable();

    expect(RadarRule::query()->count())->toBe(0);
});

it('tunes the built-in rules and switches the mode, the switch being critical', function (): void {
    [$key] = radarKey();

    $this->withToken($key)->getJson('/api/v1/radar/settings')->assertOk()
        ->assertJsonPath('data.mode', 'monitor')
        ->assertJsonPath('data.mode_inherited', true)
        ->assertJsonPath('data.ip_intelligence', 'none');

    $this->withToken($key)->patchJson('/api/v1/radar/settings', ['builtin_rules' => [
        'new_device' => ['enabled' => true],
        'bot_velocity' => ['threshold' => 40, 'action' => 'challenge'],
    ]])->assertOk()->assertJsonFragment(['key' => 'bot_velocity', 'threshold' => 40, 'action' => 'challenge']);

    $this->withToken($key)->patchJson('/api/v1/radar/settings', ['builtin_rules' => ['bot_velocity' => ['threshold' => 1]]])
        ->assertUnprocessable()->assertJsonPath('error', 'invalid_setting');
    $this->withToken($key)->patchJson('/api/v1/radar/settings', ['builtin_rules' => ['made_up' => ['enabled' => true]]])
        ->assertUnprocessable();

    $this->withToken($key)->putJson('/api/v1/radar/mode', ['mode' => 'enforce'])->assertOk()
        ->assertJsonPath('data.mode', 'enforce')
        ->assertJsonPath('data.mode_inherited', false);

    expect(app(RadarPolicy::class)->mode())->toBe(RadarMode::Enforce)
        ->and(radarAudit('radar.mode_changed')?->context['changes']['mode'] ?? null)->toEqual(['from' => 'monitor', 'to' => 'enforce'])
        ->and(radarAudit('radar.builtin_rules_updated'))->not->toBeNull();

    // Tuning thresholds does not hand out the switch.
    [$writer] = radarKey(['radar:read', 'radar:write']);
    $this->withToken($writer)->putJson('/api/v1/radar/mode', ['mode' => 'monitor'])->assertForbidden();
});

it('keeps allow and deny lists, normalised, and never copies a listed value onto the trail', function (): void {
    [$key] = radarKey();

    $entry = $this->withToken($key)->postJson('/api/v1/radar/lists', [
        'list' => 'deny',
        'kind' => 'email',
        'value' => '  Mallory@Evil.Example ',
        'note' => 'Reported by support',
    ])->assertCreated()->assertJsonPath('data.value', 'mallory@evil.example')->json('data');

    $this->withToken($key)->postJson('/api/v1/radar/lists', ['list' => 'deny', 'kind' => 'email', 'value' => 'mallory@evil.example'])
        ->assertUnprocessable()->assertJsonPath('error', 'invalid_entry');
    $this->withToken($key)->postJson('/api/v1/radar/lists', ['list' => 'allow', 'kind' => 'ip', 'value' => '10.0.0.0/33'])
        ->assertUnprocessable();
    $this->withToken($key)->postJson('/api/v1/radar/lists', ['list' => 'allow', 'kind' => 'ip', 'value' => '2001:DB8::/32'])
        ->assertCreated()->assertJsonPath('data.value', '2001:db8::/32');

    $this->withToken($key)->getJson('/api/v1/radar/lists?list=deny')->assertOk()->assertJsonCount(1, 'data');

    expect(json_encode(radarAudit('radar_list_entry.added')?->context, JSON_THROW_ON_ERROR))->not->toContain('mallory');

    $this->withToken($key)->deleteJson("/api/v1/radar/lists/{$entry['id']}")->assertNoContent();
    $this->withToken($key)->getJson('/api/v1/radar/lists?list=deny')->assertOk()->assertJsonCount(0, 'data');
});

it('explains decisions in the explorer, filtered by pseudonym, and never hands back the IP or address', function (): void {
    [$key] = radarKey();

    $dana = radarAttempt('dana@acme.example', '203.0.113.9');
    radarAttempt('eli@acme.example', '198.51.100.7');

    $page = $this->withToken($key)->getJson('/api/v1/radar/decisions?email=DANA@acme.example')->assertOk();

    $page->assertJsonCount(1, 'data')
        ->assertJsonPath('data.0.id', $dana->id)
        ->assertJsonPath('data.0.verdict', 'allow')
        ->assertJsonPath('data.0.flow', 'sign_in')
        ->assertJsonPath('data.0.email_domain', 'acme.example');

    expect($page->getContent())->not->toContain('203.0.113.9')->not->toContain('dana@');

    $this->withToken($key)->getJson('/api/v1/radar/decisions?ip=198.51.100.7')->assertOk()->assertJsonCount(1, 'data');
    $this->withToken($key)->getJson('/api/v1/radar/decisions?verdict=block')->assertOk()->assertJsonCount(0, 'data');
    $this->withToken($key)->getJson('/api/v1/radar/decisions?limit=1')->assertOk()
        ->assertJsonCount(1, 'data')
        ->assertJsonPath('meta.has_more', true);
    $this->withToken($key)->getJson("/api/v1/radar/decisions/{$dana->id}")->assertOk()->assertJsonPath('data.mode', 'monitor');
});

it('never shows one environment\'s decisions, rules or lists to another', function (): void {
    $context = app(EnvironmentContext::class);
    $context->set(GenericEnvironment::of('env_other'));
    $theirs = radarAttempt('dana@globex.example');
    [$theirKey] = radarKey(environment: 'env_other');
    $context->set(GenericEnvironment::of('env_test'));

    [$key] = radarKey();
    $ours = radarAttempt('dana@acme.example');

    $this->withToken($key)->getJson('/api/v1/radar/decisions')->assertOk()
        ->assertJsonCount(1, 'data')
        ->assertJsonPath('data.0.id', $ours->id);
    $this->withToken($key)->getJson("/api/v1/radar/decisions/{$theirs->id}")->assertNotFound();

    expect($theirs->environment_id)->toBe('env_other')
        ->and($theirKey)->not->toBe($key);
})->group('security');

it('offers every Radar action as an MCP tool, with the danger and scope it declares', function (): void {
    $registry = app(ActionRegistry::class);

    expect($registry->named('radar.mode.set')->danger)->toBe(Danger::Critical)
        ->and($registry->named('radar.mode.set')->scope)->toBe('radar:manage')
        ->and($registry->named('radar.rules.delete')->danger)->toBe(Danger::Destructive)
        ->and($registry->named('radar.lists.remove')->danger)->toBe(Danger::Destructive)
        ->and($registry->named('radar.rules.create')->danger)->toBe(Danger::Write)
        ->and($registry->named('radar.decisions.list')->danger)->toBe(Danger::Read);

    [$key] = radarKey();

    expect(mcpTools($key))->toHaveKeys([
        'radar_settings_get',
        'radar_settings_update',
        'radar_mode_set',
        'radar_rules_list',
        'radar_rules_create',
        'radar_rules_update',
        'radar_rules_reorder',
        'radar_rules_delete',
        'radar_lists_add',
        'radar_lists_remove',
        'radar_decisions_list',
        'radar_decisions_get',
    ]);
});

it('runs the same actions from the console, as the person, with the mode switch behind a fresh password', function (): void {
    craftedEnvAdmin();

    $this->get(route('environment.radar'))->assertOk();
    $this->get(route('environment.radar.rules'))->assertOk();
    $this->get(route('environment.radar.rules.create'))->assertOk();
    $this->get(route('environment.radar.lists'))->assertOk();

    $this->from(route('environment.radar.rules.create'))->post(route('environment.radar.rules.store'), [
        'name' => 'Nordics only',
        'action' => 'challenge',
        'appliesTo' => 'sign_in',
        'conditions' => [['field' => 'country', 'operator' => 'not_in', 'value' => 'DK, SE']],
    ])->assertRedirect(route('environment.radar.rules'))->assertSessionHasNoErrors();

    $rule = RadarRule::query()->sole();

    expect($rule->conditions)->toEqual([['field' => 'country', 'operator' => 'not_in', 'value' => ['DK', 'SE']]])
        ->and(radarAudit('radar_rule.created')?->actor_type)->not->toBe(ActorType::Service);

    $this->get(route('environment.radar.rules.edit', $rule->id))->assertOk();
    $this->from(route('environment.radar.rules.edit', $rule->id))->patch(route('environment.radar.rules.update', $rule->id), [
        'name' => 'Nordics only',
        'action' => 'block',
        'appliesTo' => 'all',
        'conditions' => [['field' => 'country', 'operator' => 'gt', 'value' => 'DK']],
    ])->assertSessionHasErrors('conditions');

    $this->from(route('environment.radar.rules'))->patch(route('environment.radar.settings.update'), [
        'builtinRules' => ['new_device' => ['enabled' => true, 'action' => 'challenge', 'threshold' => null]],
    ])->assertSessionHasNoErrors();

    $this->from(route('environment.radar.lists'))->post(route('environment.radar.lists.store'), [
        'list' => 'deny', 'kind' => 'ip', 'value' => '192.0.2.0/24', 'expiresAt' => now()->addWeek()->format('Y-m-d'),
    ])->assertSessionHasNoErrors();

    // The mode is the environment's security posture: a fresh password first.
    $this->from(route('environment.radar.rules'))->put(route('environment.radar.mode.update'), ['mode' => 'enforce'])
        ->assertRedirect();
    expect(app(RadarPolicy::class)->mode())->toBe(RadarMode::Monitor);

    confirmEnvironmentStepUp();
    $this->from(route('environment.radar.rules'))->put(route('environment.radar.mode.update'), ['mode' => 'enforce'])
        ->assertRedirect(route('environment.radar.rules'))->assertSessionHasNoErrors();
    expect(app(RadarPolicy::class)->mode())->toBe(RadarMode::Enforce);

    $this->delete(route('environment.radar.rules.destroy', $rule->id))->assertRedirect(route('environment.radar.rules'));
    expect(RadarRule::query()->count())->toBe(0);
});
