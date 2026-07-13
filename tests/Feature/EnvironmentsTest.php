<?php

declare(strict_types=1);

use App\Http\Middleware\SetEnvironment;
use App\Platform\CurrentUser;
use Cbox\Id\Identity\Contracts\SessionManager;
use Cbox\Id\Identity\Contracts\Subjects;
use Cbox\Id\Identity\Models\User;
use Cbox\Id\Kernel\Crypto\Models\SigningKey;
use Cbox\Id\Kernel\Tenancy\Contracts\EnvironmentContext;
use Cbox\Id\Organization\Contracts\Memberships;
use Cbox\Id\Organization\Contracts\Organizations;
use Cbox\Id\Organization\Models\Environment;
use Cbox\Id\Organization\ValueObjects\NewOrganization;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Volt\Volt;

uses(RefreshDatabase::class);

/** Sign an operator into the current (test) environment so switchTo can run. */
function actAsOperator(string $email = 'op@acme.test'): void
{
    $subject = app(Subjects::class)->create($email, 'Operator', 'a-strong-unbreached-passphrase');
    $org = app(Organizations::class)->create(new NewOrganization('Op Co', 'op-co-'.uniqid()));
    app(Memberships::class)->add($org->id, $subject->id, 'owner');
    $session = app(SessionManager::class)->start($subject->id, $org->id, ['pwd']);
    app(CurrentUser::class)->set($subject, $session, $org, 'owner');
}

it('creates an environment with a slug and warms its own signing key', function (): void {
    Volt::test('environments')
        ->set('name', 'Production')
        ->call('create')
        ->assertHasNoErrors();

    $env = Environment::query()->where('slug', 'production')->first();
    expect($env)->not->toBeNull()
        ->and($env->name)->toBe('Production');

    // A key was provisioned INSIDE the new plane, not the current one.
    $keys = app(EnvironmentContext::class)->runAs($env, fn (): int => SigningKey::query()->count());
    expect($keys)->toBeGreaterThan(0);
});

it('deduplicates slugs across environments', function (): void {
    Environment::query()->create(['name' => 'Prod', 'slug' => 'production', 'status' => 'active']);

    Volt::test('environments')->set('name', 'Production')->call('create')->assertHasNoErrors();

    expect(Environment::query()->where('slug', 'production-2')->exists())->toBeTrue();
});

it('rejects a domain already routed to another environment', function (): void {
    Environment::query()->create(['name' => 'A', 'slug' => 'a', 'domain' => 'id.acme.com', 'status' => 'active']);

    Volt::test('environments')
        ->set('name', 'B')
        ->set('domain', 'id.acme.com')
        ->call('create')
        ->assertHasErrors('domain');

    expect(Environment::query()->where('slug', 'b')->exists())->toBeFalse();
});

it('validates the domain shape', function (): void {
    Volt::test('environments')
        ->set('name', 'C')
        ->set('domain', 'not a domain')
        ->call('create')
        ->assertHasErrors('domain');
});

it('switches into an environment where the operator has an identity', function (): void {
    actAsOperator('op@acme.test');
    $staging = Environment::query()->create(['name' => 'Staging', 'slug' => 'staging', 'status' => 'active']);

    // Give the operator a matching identity in the target plane.
    app(EnvironmentContext::class)->runAs($staging, fn () => User::query()->create([
        'name' => 'Operator', 'email' => 'op@acme.test', 'password' => 'x', 'status' => 'active',
    ]));

    Volt::test('environments')
        ->call('switchTo', $staging->id)
        ->assertRedirect(route('dashboard'));

    expect(session(SetEnvironment::SESSION_KEY))->toBe('staging');
});

it('refuses to switch into an environment where the operator has no identity', function (): void {
    actAsOperator('op@acme.test');
    $staging = Environment::query()->create(['name' => 'Staging', 'slug' => 'staging', 'status' => 'active']);

    Volt::test('environments')
        ->call('switchTo', $staging->id)
        ->assertRedirect(route('environments'));

    // Session is untouched — the operator stays where they are, not orphaned.
    expect(session(SetEnvironment::SESSION_KEY))->not->toBe('staging');
});

it('counts organizations and users per environment without leaking across planes', function (): void {
    $a = Environment::query()->create(['name' => 'Plane A', 'slug' => 'plane-a', 'status' => 'active']);
    $b = Environment::query()->create(['name' => 'Plane B', 'slug' => 'plane-b', 'status' => 'active']);

    $ctx = app(EnvironmentContext::class);
    $ctx->runAs($a, fn () => app(Organizations::class)
        ->create(new NewOrganization('A Co', 'a-co')));
    $ctx->runAs($b, fn () => app(Organizations::class)
        ->create(new NewOrganization('B Co', 'b-co')));

    $rows = Volt::test('environments')->viewData('environments');
    $byId = collect($rows)->keyBy('id');

    expect($byId[$a->id]['orgs'])->toBe(1)
        ->and($byId[$b->id]['orgs'])->toBe(1);
});
