<?php

declare(strict_types=1);

use App\Models\OnboardingDismissal;
use App\Models\Radar\RadarDevice;
use App\Models\RiskDecision;
use App\Platform\FrontendApi\LoginTicket;
use App\Platform\Radar\RadarPseudonyms;
use App\Platform\RiskTrail;
use Cbox\Id\Devices\Enums\DevicePlatform;
use Cbox\Id\Devices\Enums\DeviceStatus;
use Cbox\Id\Devices\Models\Device;
use Cbox\Id\Identity\Contracts\SessionManager;
use Cbox\Id\Identity\Contracts\Subjects;
use Cbox\Id\Identity\Models\MfaFactor;
use Cbox\Id\Identity\Models\User;
use Cbox\Id\Kernel\Audit\Contracts\AuditLog;
use Cbox\Id\Kernel\Audit\Models\AuditEntry;
use Cbox\Id\Kernel\Crypto\Contracts\SealedColumns;
use Cbox\Id\Kernel\Crypto\ValueObjects\SealedColumn;
use Cbox\Id\Kernel\Tenancy\Contracts\EnvironmentContext;
use Cbox\Id\Organization\Contracts\Memberships;
use Cbox\Id\Organization\Contracts\Organizations;
use Cbox\Id\Organization\Enums\MembershipRole;
use Cbox\Id\Organization\ValueObjects\NewOrganization;
use Cbox\Id\Platform\Contracts\EnvironmentApiKeys;
use Cbox\Id\RiskPlus\Models\RiskEvent;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;

uses(RefreshDatabase::class);

/*
|--------------------------------------------------------------------------
| Erasing a person (GDPR Art. 17), whichever door asks
|--------------------------------------------------------------------------
|
| `users.erase` runs the framework's SubjectEraser — one transaction — with this app's own
| stores registered as steps of it. The console asks for a fresh credential and the address
| typed out; the API asks for a key holding `users:erase`, which `users:write` is not.
*/

/**
 * A person in the tenant environment with something in every store this app adds: an
 * enrolled handset, an unredeemed embedded sign-in ticket, a dismissed checklist, a risk
 * decision under their address and a flagged sign-in on the review trail. Plus the
 * framework's own — a session and a second factor — so the receipt is not all zeroes.
 */
function erasablePerson(string $email = 'erase-me@acme.example'): User
{
    $subject = app(Subjects::class)->create($email, 'Erase Me', 'a-strong-unbreached-passphrase');

    app(SessionManager::class)->start($subject->id, null, ['pwd']);
    MfaFactor::query()->create(['user_id' => $subject->id, 'type' => 'totp', 'secret_encrypted' => 'sealed', 'confirmed_at' => now()]);

    $device = new Device;
    $device->fill([
        'subject_id' => $subject->id,
        'install_id' => (string) Str::ulid(),
        'platform' => DevicePlatform::Ios,
        'name' => 'Their iPhone',
        'status' => DeviceStatus::Active,
    ]);
    $device->save();

    LoginTicket::query()->create([
        'token_hash' => hash('sha256', Str::random(40)),
        'publishable_key_id' => 'pk_test',
        'subject_id' => $subject->id,
        'stage' => 'complete',
        'amr' => ['pwd'],
        'expires_at' => now()->addMinute(),
    ]);

    $organization = app(Organizations::class)->create(new NewOrganization('Their Co', 'their-co-'.Str::lower(Str::random(4))));
    app(Memberships::class)->add($organization->id, $subject->id, MembershipRole::Member);
    OnboardingDismissal::query()->create(['organization_id' => $organization->id, 'subject_id' => $subject->id]);

    $decision = new RiskDecision;
    $decision->forceFill([
        'environment_id' => app(EnvironmentContext::class)->current()?->environmentKey(),
        'action' => 'login',
        'mode' => 'monitor',
        'outcome' => 'flag',
        'score' => 40,
        'reasons' => [],
        'signals' => [],
        'ip_hash' => str_repeat('a', 64),
        'email_hash' => app(RiskTrail::class)->emailPseudonym($email),
        'email_domain' => 'acme.example',
        'device_hash' => str_repeat('d', 64),
        'assessed_at' => now(),
    ])->save();

    // The browser Radar remembers them signing in from, and where.
    (new RadarDevice)->forceFill([
        'subject_hash' => app(RadarPseudonyms::class)->subject($email),
        'device_hash' => str_repeat('d', 64),
        'fingerprint_hash' => str_repeat('f', 64),
        'country' => 'DK',
        'latitude' => 55.7,
        'longitude' => 12.6,
        'first_seen_at' => now(),
        'last_seen_at' => now(),
    ])->save();

    RiskEvent::query()->create([
        'action' => 'login',
        'outcome' => 'flag',
        'score' => 40.0,
        'ip' => '203.0.113.9',
        'email' => strtoupper($email),
        'reasons' => ['new device'],
    ]);

    return User::query()->whereKey($subject->id)->firstOrFail();
}

/** Nothing this app or the framework kept names them any more. */
function expectErased(User $person, string $email): void
{
    $row = User::query()->whereKey($person->id)->firstOrFail();

    expect($row->email)->not->toBe($email)
        ->and($row->email)->toEndWith('@erased.invalid')
        ->and(MfaFactor::query()->where('user_id', $person->id)->exists())->toBeFalse()
        ->and(Device::query()->where('subject_id', $person->id)->exists())->toBeFalse()
        ->and(LoginTicket::query()->where('subject_id', $person->id)->exists())->toBeFalse()
        ->and(OnboardingDismissal::query()->where('subject_id', $person->id)->exists())->toBeFalse()
        ->and(RiskDecision::query()->where('email_hash', app(RiskTrail::class)->emailPseudonym($email))->exists())->toBeFalse()
        // The decision itself is kept: it is what thresholds are tuned on, and names nobody now.
        ->and(RiskDecision::query()->count())->toBe(1)
        ->and(RiskDecision::query()->whereNotNull('device_hash')->exists())->toBeFalse()
        ->and(RadarDevice::query()->count())->toBe(0)
        ->and(RiskEvent::query()->count())->toBe(0)
        ->and(AuditEntry::query()->where('action', 'user.erased')->where('target_id', $person->id)->exists())->toBeTrue();
}

/** A management key for the environment the test is in, holding exactly $scopes. */
function erasureKey(string $environmentId, array $scopes): string
{
    return app(EnvironmentApiKeys::class)->issue($environmentId, 'Erasure worker', $scopes)->plaintext;
}

it('erases a person from the console behind a fresh credential and their address typed out', function (): void {
    crudSetup();
    $person = erasablePerson();

    // An open session is not enough: nothing undoes this.
    test()->post(route('environment.users.erase', $person->id), ['confirmation' => 'erase-me@acme.example'])
        ->assertRedirect(route('environment.sudo'));

    expect(User::query()->whereKey($person->id)->value('email'))->toBe('erase-me@acme.example');

    confirmEnvironmentStepUp();

    // The dialog asks for the address; the server asks again, because a crafted POST never
    // opened the dialog.
    test()->from(route('environment.users.show', $person->id))
        ->post(route('environment.users.erase', $person->id), ['confirmation' => 'someone-else@acme.example'])
        ->assertSessionHasErrors('erase');

    expect(User::query()->whereKey($person->id)->value('email'))->toBe('erase-me@acme.example');

    test()->post(route('environment.users.erase', $person->id), ['confirmation' => 'erase-me@acme.example'])
        ->assertSessionHasNoErrors()
        ->assertRedirect(route('environment.users'));

    expectErased($person, 'erase-me@acme.example');
})->group('security');

it('erases a person over REST with a key holding users:erase, and returns the receipt', function (): void {
    ['envId' => $environmentId] = crudSetup();
    $person = erasablePerson();

    $response = $this->withToken(erasureKey($environmentId, ['users:erase']))
        ->postJson("/api/v1/users/{$person->id}/erase")
        ->assertOk()
        ->assertJsonPath('data.subject_id', $person->id)
        ->assertJsonPath('data.subject_pseudonymised', true);

    $steps = collect((array) $response->json('data.steps'))->keyBy('step');

    // The app's own stores ran inside the same erasure, and say what they removed.
    expect($steps['devices.devices']['counts']['devices'] ?? null)->toBe(1)
        ->and($steps['app.frontend_login_tickets']['counts']['login_tickets'] ?? null)->toBe(1)
        ->and($steps['app.onboarding']['counts']['checklist_dismissals'] ?? null)->toBe(1)
        ->and($steps['app.risk_decisions']['counts']['risk_decisions'] ?? null)->toBe(1)
        ->and($steps['app.radar_devices']['counts']['radar_devices'] ?? null)->toBe(1)
        ->and($steps['risk_plus.history']['counts']['risk_events'] ?? null)->toBe(1)
        // And the receipt names nobody.
        ->and($response->getContent())->not->toContain('erase-me@acme.example');

    expectErased($person, 'erase-me@acme.example');
})->group('security');

it('refuses a key that may manage users but not erase them', function (): void {
    ['envId' => $environmentId] = crudSetup();
    $person = erasablePerson();

    $this->withToken(erasureKey($environmentId, ['users:read', 'users:write']))
        ->postJson("/api/v1/users/{$person->id}/erase")
        ->assertForbidden();

    expect(User::query()->whereKey($person->id)->value('email'))->toBe('erase-me@acme.example')
        ->and(Device::query()->where('subject_id', $person->id)->exists())->toBeTrue();
})->group('security');

it('refuses to erase the only owner of an organization, and changes nothing', function (): void {
    ['envId' => $environmentId] = crudSetup();
    $person = erasablePerson();
    $owned = app(Organizations::class)->create(new NewOrganization('Sole Co', 'sole-co'));
    app(Memberships::class)->add($owned->id, $person->id, MembershipRole::Owner);

    $this->withToken(erasureKey($environmentId, ['users:erase']))
        ->postJson("/api/v1/users/{$person->id}/erase")
        ->assertStatus(409)
        ->assertJsonPath('error', 'last_owner');

    // One transaction: the steps that ran before the refusal rolled back with it.
    expect(User::query()->whereKey($person->id)->value('email'))->toBe('erase-me@acme.example')
        ->and(Device::query()->where('subject_id', $person->id)->exists())->toBeTrue()
        ->and(LoginTicket::query()->where('subject_id', $person->id)->exists())->toBeTrue();
});

it('404s a person from another environment rather than erasing them', function (): void {
    ['envId' => $environmentId] = crudSetup();

    $this->withToken(erasureKey($environmentId, ['users:erase']))
        ->postJson('/api/v1/users/01JNOTAUSERINTHISENVIRONMNT/erase')
        ->assertNotFound();
});

it('leaves the audit chain verifiable after an erasure', function (): void {
    ['envId' => $environmentId] = crudSetup();
    $person = erasablePerson();
    $organizationId = (string) app(Memberships::class)->forUser($person->id)->first()?->organization_id;

    $this->withToken(erasureKey($environmentId, ['users:erase']))
        ->postJson("/api/v1/users/{$person->id}/erase")
        ->assertOk();

    // Past entries keep the opaque id; nothing was rewritten, so every chain still verifies.
    $audit = app(AuditLog::class);

    expect($audit->verifyChain()->valid)->toBeTrue()
        ->and($audit->verifyChain($organizationId)->valid)->toBeTrue();
})->group('security');

it('registers the devices module\'s sealed push token, so a master-key rotation reaches it', function (): void {
    $columns = array_map(
        static fn (SealedColumn $column): string => $column->table.'.'.$column->column,
        app(SealedColumns::class)->all(),
    );

    expect($columns)->toContain('id_devices.token_encrypted');
});
