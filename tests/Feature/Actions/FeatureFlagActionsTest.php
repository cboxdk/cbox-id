<?php

declare(strict_types=1);

use Cbox\Id\FeatureFlags\Contracts\FeatureFlags;
use Cbox\Id\FeatureFlags\Models\FeatureFlag;
use Cbox\Id\FeatureFlags\ValueObjects\FlagTargeting;
use Cbox\Id\FeatureFlags\ValueObjects\NewFeatureFlag;
use Cbox\Id\Identity\Contracts\Subjects;
use Cbox\Id\Kernel\Audit\Enums\ActorType;
use Cbox\Id\Kernel\Audit\Models\AuditEntry;
use Cbox\Id\Kernel\Events\Models\Event;
use Cbox\Id\Organization\Contracts\Organizations;
use Cbox\Id\Organization\ValueObjects\NewOrganization;
use Cbox\Id\Platform\Contracts\EnvironmentApiKeys;
use Illuminate\Support\Str;

/*
|--------------------------------------------------------------------------
| Feature flags, as actions: define, retarget, delete, and evaluate for an app's backend.
|--------------------------------------------------------------------------
|
| One action per change, run by the management API, MCP and the console; recorded once by
| the framework under the actor the door names, and announced as `feature_flag.*`.
*/

/**
 * @param  list<string>  $scopes
 * @return array{0: string, 1: string} the key, and its id
 */
function flagsKey(array $scopes = ['feature_flags:read', 'feature_flags:write']): array
{
    $issued = app(EnvironmentApiKeys::class)->issue('env_test', 'Flags worker', $scopes);

    return [$issued->plaintext, (string) $issued->key->id];
}

function flagsOrg(string $name = 'Acme'): string
{
    return app(Organizations::class)->create(new NewOrganization($name, Str::slug($name).'-'.Str::lower(Str::random(4))))->id;
}

it('defines, reads, retargets and deletes a flag over the API, on one trail with the key as actor', function (): void {
    [$key, $keyId] = flagsKey();
    $acme = flagsOrg();
    $ada = app(Subjects::class)->create('ada@flags.example', 'Ada')->id;

    $created = $this->withToken($key)->postJson('/api/v1/feature-flags', [
        'key' => 'new-dashboard',
        'description' => 'The redesigned dashboard',
        'organizations' => [['id' => $acme]],
        'rollout_percentage' => 10,
    ])->assertCreated()->json('data');

    expect($created)->toMatchArray([
        'key' => 'new-dashboard',
        'enabled' => true,
        'default_value' => false,
        'rollout_percentage' => 10,
        'organizations' => [['id' => $acme, 'enabled' => true]],
        'users' => [],
    ]);

    $this->withToken($key)->getJson('/api/v1/feature-flags')->assertOk()->assertJsonPath('data.0.key', 'new-dashboard');
    $this->withToken($key)->getJson("/api/v1/feature-flags/{$created['id']}")->assertOk()->assertJsonPath('data.description', 'The redesigned dashboard');

    // A part sent replaces that part; the parts not sent are kept.
    $updated = $this->withToken($key)->patchJson("/api/v1/feature-flags/{$created['id']}", [
        'users' => [['id' => $ada, 'enabled' => false]],
        'default_value' => true,
    ])->assertOk()->json('data');

    expect($updated['users'])->toBe([['id' => $ada, 'enabled' => false]])
        ->and($updated['organizations'])->toBe([['id' => $acme, 'enabled' => true]])
        ->and($updated['rollout_percentage'])->toBe(10)
        ->and($updated['default_value'])->toBeTrue();

    $this->withToken($key)->deleteJson("/api/v1/feature-flags/{$created['id']}")->assertNoContent();
    $this->withToken($key)->getJson("/api/v1/feature-flags/{$created['id']}")->assertNotFound();

    foreach (['feature_flag.created', 'feature_flag.updated', 'feature_flag.deleted'] as $action) {
        $entry = AuditEntry::query()->where('action', $action)->sole();

        expect($entry->actor_type)->toBe(ActorType::Service, $action)
            ->and($entry->actor_id)->toBe($keyId, $action)
            ->and($entry->target_id)->toBe($created['id'], $action);

        expect(Event::query()->where('type', $action)->exists())->toBeTrue($action);
    }
});

it('refuses a malformed or taken key, an out-of-range rollout and a rule naming nobody here', function (): void {
    [$key] = flagsKey();

    $this->withToken($key)->postJson('/api/v1/feature-flags', ['key' => 'Not A Key'])
        ->assertUnprocessable()->assertJsonPath('error', 'invalid_key');

    $this->withToken($key)->postJson('/api/v1/feature-flags', ['key' => 'beta'])->assertCreated();
    $this->withToken($key)->postJson('/api/v1/feature-flags', ['key' => 'beta'])
        ->assertUnprocessable()->assertJsonPath('error', 'key_taken');

    $this->withToken($key)->postJson('/api/v1/feature-flags', ['key' => 'ramp', 'rollout_percentage' => 101])
        ->assertUnprocessable()->assertJsonPath('error', 'validation_failed');

    $this->withToken($key)->postJson('/api/v1/feature-flags', ['key' => 'ghost', 'organizations' => [['id' => '01JNOSUCHORGANIZATION00000']]])
        ->assertUnprocessable()->assertJsonPath('error', 'unknown_organization');

    $this->withToken($key)->postJson('/api/v1/feature-flags', ['key' => 'ghost', 'users' => [['id' => 'nobody']]])
        ->assertUnprocessable()->assertJsonPath('error', 'unknown_user');
});

it('needs feature_flags:write to change a flag and feature_flags:read to evaluate', function (): void {
    [$writer] = flagsKey();
    [$reader] = flagsKey(['feature_flags:read']);
    [$stranger] = flagsKey(['users:read']);

    $id = $this->withToken($writer)->postJson('/api/v1/feature-flags', ['key' => 'beta'])->json('data.id');

    $this->withToken($reader)->postJson('/api/v1/feature-flags', ['key' => 'other'])->assertForbidden();
    $this->withToken($reader)->patchJson("/api/v1/feature-flags/{$id}", ['enabled' => false])->assertForbidden();
    $this->withToken($reader)->deleteJson("/api/v1/feature-flags/{$id}")->assertForbidden();
    $this->withToken($reader)->getJson('/api/v1/feature-flags/evaluate')->assertOk();

    $this->withToken($stranger)->getJson('/api/v1/feature-flags/evaluate')->assertForbidden();
});

it('evaluates every flag for a user in an organization, with the rule that decided', function (): void {
    [$key] = flagsKey(['feature_flags:read']);
    $acme = flagsOrg('Acme');
    $globex = flagsOrg('Globex');
    $ada = app(Subjects::class)->create('ada@flags.example', 'Ada')->id;

    $flags = app(FeatureFlags::class);

    $flags->create(new NewFeatureFlag('acme-beta', targeting: FlagTargeting::organizations([$acme])));
    $flags->create(new NewFeatureFlag('ada-only', targeting: FlagTargeting::users([$ada])));
    $flags->create(new NewFeatureFlag('everyone', defaultValue: true));
    $flags->create(new NewFeatureFlag('killed', enabled: false, defaultValue: true));

    $answer = $this->withToken($key)
        ->getJson('/api/v1/feature-flags/evaluate?'.http_build_query(['user_id' => $ada, 'organization_id' => $acme]))
        ->assertOk()
        ->json('data');

    expect($answer['feature_flags'])->toBe(['acme-beta', 'ada-only', 'everyone'])
        ->and($answer['user_id'])->toBe($ada)
        ->and($answer['organization_id'])->toBe($acme);

    expect(collect($answer['evaluations'])->keyBy('key')->map(fn (array $a): array => [$a['enabled'], $a['reason']])->all())->toBe([
        'acme-beta' => [true, 'organization_target'],
        'ada-only' => [true, 'user_target'],
        'everyone' => [true, 'default'],
        'killed' => [false, 'disabled'],
    ]);

    $globexAnswers = collect($this->withToken($key)
        ->getJson('/api/v1/feature-flags/evaluate?'.http_build_query(['organization_id' => $globex]))
        ->assertOk()
        ->json('data.evaluations'))->keyBy('key');

    expect($globexAnswers['acme-beta']['enabled'])->toBeFalse()
        ->and($globexAnswers['ada-only']['enabled'])->toBeFalse()
        ->and($globexAnswers['everyone']['enabled'])->toBeTrue();
});

it('records the person as the actor when the console makes the change', function (): void {
    ['subjectId' => $adminId] = crudSetup();

    test()->post(route('environment.feature-flags.store'), ['key' => 'console-made', 'description' => '', 'defaultValue' => false])
        ->assertSessionHasNoErrors();

    $flag = FeatureFlag::query()->where('key', 'console-made')->sole();
    $entry = AuditEntry::query()->where('action', 'feature_flag.created')->where('target_id', $flag->id)->sole();

    expect($entry->actor_type)->toBe(ActorType::OrganizationMember)
        ->and($entry->actor_id)->toBe($adminId);
});
