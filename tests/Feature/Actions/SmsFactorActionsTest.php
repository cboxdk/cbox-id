<?php

declare(strict_types=1);

use App\Actions\SignIn\UpdateSmsFactorPolicy;
use App\Platform\Actions\ActionContext;
use App\Platform\Actions\Principal\Principal;
use Cbox\Id\Identity\Contracts\SmsFactorPolicies;
use Cbox\Id\Identity\Contracts\SmsFactors;
use Cbox\Id\Identity\Contracts\Subjects;
use Cbox\Id\Identity\ValueObjects\SmsFactorPolicy;
use Cbox\Id\Kernel\Audit\Enums\ActorType;
use Cbox\Id\Kernel\Audit\Models\AuditEntry;
use Cbox\Id\Kernel\Tenancy\Contracts\EnvironmentContext;
use Cbox\Id\Kernel\Tenancy\GenericEnvironment;
use Cbox\Id\Organization\Models\Environment;
use Cbox\Id\Otp\Sms\Contracts\SmsSender;
use Cbox\Id\Otp\Sms\Senders\ArraySmsSender;
use Cbox\Id\Platform\Contracts\EnvironmentApiKeys;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;

/*
|--------------------------------------------------------------------------
| SMS as a second factor, as actions: the policy and the removals.
|--------------------------------------------------------------------------
|
| Turning SMS on, choosing its countries, and taking somebody's phone number away are
| management writes — one action each, the same from a key, an agent and the console, on
| the same trail. Adding a number stays a ceremony on the person's own page (the phone has
| to receive the code), so it has no action.
*/

beforeEach(function (): void {
    Cache::flush();
    config()->set('cbox-id.sms.limits.cooldown_seconds', 0);
    app()->instance(SmsSender::class, $this->sms = new ArraySmsSender);
});

/** @return array{environment: Environment, ownerId: string} */
function smsaTenant(): array
{
    multiTenantDeployment();
    $tenant = provisionAccount();

    serveOnTestHost($tenant['environment']);
    app(EnvironmentContext::class)->set(GenericEnvironment::of($tenant['environment']->id));

    return ['environment' => $tenant['environment']->refresh(), 'ownerId' => $tenant['subjectId']];
}

/**
 * @param  list<string>  $scopes
 * @return array{0: string, 1: string}
 */
function smsaKey(Environment $environment, array $scopes): array
{
    $issued = app(EnvironmentApiKeys::class)->issue($environment->id, 'SMS worker', $scopes);

    return [$issued->plaintext, (string) $issued->key->id];
}

/** A user of the tenant with a confirmed phone number. */
function smsaEnrolled(ArraySmsSender $sms, string $email = 'ada@acme.test'): string
{
    $id = app(Subjects::class)->create($email, Str::before($email, '@'))->id;

    app(SmsFactorPolicies::class)->setForEnvironment(new SmsFactorPolicy(true, ['DK'], false));
    app(SmsFactors::class)->beginEnrolment($id, '+4512345678');
    app(SmsFactors::class)->confirmEnrolment($id, (string) $sms->latestCode());

    return $id;
}

function smsaAudit(string $action): ?AuditEntry
{
    return AuditEntry::query()->where('action', $action)->orderByDesc('sequence')->first();
}

// ------------------------------------------------------------- the policy

it('reads the policy, off by default, and turns SMS on as the key', function (): void {
    ['environment' => $environment] = smsaTenant();
    [$key, $keyId] = smsaKey($environment, ['signin:read', 'signin:write']);
    config()->set('cbox-id.sms.allowed_countries', ['DK', 'SE']);

    $this->withToken($key)->getJson('/api/v1/sign-in/sms')
        ->assertOk()
        ->assertJsonPath('data.enabled', false)
        ->assertJsonPath('data.allowed_countries', [])
        ->assertJsonPath('data.privileged_need_stronger_factor', true)
        ->assertJsonPath('data.deployment_countries', ['DK', 'SE']);

    $this->withToken($key)->patchJson('/api/v1/sign-in/sms', ['enabled' => true, 'allowed_countries' => ['SE', 'DK']])
        ->assertOk()
        ->assertJsonPath('data.enabled', true)
        ->assertJsonPath('data.allowed_countries', ['DK', 'SE'])
        // Left out, so unchanged.
        ->assertJsonPath('data.privileged_need_stronger_factor', true);

    expect(app(SmsFactorPolicies::class)->forEnvironment()->allowsCountry('SE'))->toBeTrue();

    $entry = smsaAudit('auth_policy.sms_updated');

    expect($entry?->actor_type)->toBe(ActorType::Service)
        ->and($entry?->actor_id)->toBe($keyId)
        ->and($entry?->context['from']['enabled'] ?? null)->toBeFalse()
        ->and($entry?->context['to']['allowed_countries'] ?? null)->toBe(['DK', 'SE']);
})->group('security');

it('refuses SMS on with no country, and a country it cannot place a number in', function (): void {
    ['environment' => $environment] = smsaTenant();
    [$key] = smsaKey($environment, ['signin:write']);

    $this->withToken($key)->patchJson('/api/v1/sign-in/sms', ['enabled' => true])
        ->assertUnprocessable()
        ->assertJsonPath('error', 'countries_required');

    $this->withToken($key)->patchJson('/api/v1/sign-in/sms', ['enabled' => true, 'allowed_countries' => ['ZZ']])
        ->assertUnprocessable();

    expect(app(SmsFactorPolicies::class)->forEnvironment()->enabled)->toBeFalse()
        ->and(smsaAudit('auth_policy.sms_updated'))->toBeNull();
});

it('needs signin:write to change the policy', function (): void {
    ['environment' => $environment] = smsaTenant();
    [$key] = smsaKey($environment, ['signin:read']);

    $this->withToken($key)->patchJson('/api/v1/sign-in/sms', ['enabled' => false])->assertForbidden();
});

it('saves the policy from the Authentication policy page through the same action, as the person', function (): void {
    ['environment' => $environment, 'ownerId' => $ownerId] = smsaTenant();
    actAsEnvironmentAdmin($ownerId, $environment->id);

    $page = $this->get(route('environment.auth-policy'))->assertOk()->inertiaProps();

    expect($page['smsFactor']['enabled'])->toBeFalse()
        ->and(collect($page['smsFactor']['countries'])->firstWhere('value', 'DK'))->toBe(['value' => 'DK', 'label' => 'Denmark']);

    $this->from(route('environment.auth-policy'))
        ->put(route('environment.auth-policy.sms'), ['enabled' => true, 'allowedCountries' => [], 'privilegedNeedStrongerFactor' => true])
        ->assertSessionHasErrors('allowedCountries');

    $this->from(route('environment.auth-policy'))
        ->put(route('environment.auth-policy.sms'), ['enabled' => true, 'allowedCountries' => ['dk', 'no'], 'privilegedNeedStrongerFactor' => false])
        ->assertSessionHasNoErrors();

    $policy = app(SmsFactorPolicies::class)->forEnvironment();
    $entry = smsaAudit('auth_policy.sms_updated');

    expect($policy->enabled)->toBeTrue()
        ->and($policy->allowedCountries)->toBe(['DK', 'NO'])
        ->and($policy->privilegedNeedStrongerFactor)->toBeFalse()
        ->and($entry?->actor_type)->toBe(ActorType::OrganizationMember)
        ->and($entry?->actor_id)->toBe($ownerId);
})->group('security');

it('refuses an organization-confined principal the environment\'s SMS policy', function (): void {
    smsaTenant();

    $principal = Mockery::mock(Principal::class);
    $principal->shouldReceive('confinedToOrganization')->andReturn('org_1');

    $action = app(UpdateSmsFactorPolicy::class);

    expect(fn () => $action->handle(new ActionContext($principal, ['enabled' => false])))
        ->toThrow(AuthorizationException::class);
})->group('security');

// ------------------------------------------------------------- removing a number

it('removes a user\'s phone number from both doors, as whoever asked, and nothing else', function (): void {
    ['environment' => $environment, 'ownerId' => $ownerId] = smsaTenant();
    [$key, $keyId] = smsaKey($environment, ['users:write']);
    $ada = smsaEnrolled($this->sms);

    $this->withToken($key)->deleteJson("/api/v1/users/{$ada}/mfa/sms")->assertNoContent();
    $byKey = smsaAudit('user.mfa_sms_removed');

    expect(app(SmsFactors::class)->details($ada))->toBeNull();

    // Again: idempotent, like the full reset — and nothing is recorded for a no-op.
    $this->withToken($key)->deleteJson("/api/v1/users/{$ada}/mfa/sms")->assertNoContent();
    expect(AuditEntry::query()->where('action', 'user.mfa_sms_removed')->count())->toBe(1);

    app(SmsFactors::class)->beginEnrolment($ada, '+4512345678');
    app(SmsFactors::class)->confirmEnrolment($ada, (string) $this->sms->latestCode());

    actAsEnvironmentAdmin($ownerId, $environment->id);

    expect($this->get(route('environment.users.show', $ada))->inertiaProps()['user']['smsFactor'])
        ->toBe(['maskedNumber' => '+45 ******78', 'country' => 'DK', 'confirmed' => true, 'usable' => true]);

    confirmEnvironmentStepUp();
    $this->from(route('environment.users.show', $ada))->post(route('environment.users.mfa.sms', $ada))->assertSessionHasNoErrors();
    $byConsole = smsaAudit('user.mfa_sms_removed');

    expect($byKey?->actor_type)->toBe(ActorType::Service)
        ->and($byKey?->actor_id)->toBe($keyId)
        ->and($byKey?->target_id)->toBe($ada)
        ->and($byConsole?->actor_type)->toBe(ActorType::OrganizationMember)
        ->and($byConsole?->actor_id)->toBe($ownerId)
        ->and(app(SmsFactors::class)->details($ada))->toBeNull();
})->group('security');

it('asks for a step-up before the console removes a number', function (): void {
    ['environment' => $environment, 'ownerId' => $ownerId] = smsaTenant();
    $ada = smsaEnrolled($this->sms);

    actAsEnvironmentAdmin($ownerId, $environment->id);

    $this->from(route('environment.users.show', $ada))->post(route('environment.users.mfa.sms', $ada));

    expect(app(SmsFactors::class)->isEnrolled($ada))->toBeTrue();
})->group('security');

it('answers a user in another environment as one that does not exist', function (): void {
    ['environment' => $environment] = smsaTenant();
    [$key] = smsaKey($environment, ['users:write']);

    $foreign = app(EnvironmentContext::class)->runAs(GenericEnvironment::of('env_other'), function (): string {
        $id = app(Subjects::class)->create('far@away.test', 'Far')->id;
        app(SmsFactorPolicies::class)->setForEnvironment(new SmsFactorPolicy(true, ['DK'], false));
        app(SmsFactors::class)->beginEnrolment($id, '+4587654321');

        return $id;
    });

    $this->withToken($key)->deleteJson("/api/v1/users/{$foreign}/mfa/sms")->assertNotFound();

    expect(app(EnvironmentContext::class)->runAs(GenericEnvironment::of('env_other'), fn () => app(SmsFactors::class)->details($foreign)))->not->toBeNull();
})->group('security');
