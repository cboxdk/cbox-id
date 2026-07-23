<?php

declare(strict_types=1);

use App\Platform\OperatorAuth;
use Cbox\Id\Identity\Contracts\Subjects;
use Cbox\Id\Kernel\Tenancy\Contracts\EnvironmentContext;
use Cbox\Id\Organization\Contracts\Organizations;
use Cbox\Id\Organization\Models\Environment;
use Cbox\Id\Platform\Contracts\PlatformOperators;
use Cbox\Id\Platform\Models\PlatformOperator;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Volt\Volt;

uses(RefreshDatabase::class);

function makeOperator(string $email = 'op@platform.test'): PlatformOperator
{
    return app(PlatformOperators::class)->create($email, 'a-strong-operator-pass', 'Operator');
}

function actingAsOperator(string $email = 'op@platform.test'): PlatformOperator
{
    $op = makeOperator($email);
    session([OperatorAuth::SESSION_KEY => $op->id]);

    return $op;
}

it('bootstraps the first operator on a fresh install and signs in', function (): void {
    Volt::test('operator.login')
        ->set('name', 'Root')
        ->set('email', 'root@platform.test')
        ->set('password', 'a-strong-operator-pass')
        ->call('createFirst')
        ->assertRedirect(route('operator.environments'));

    expect(app(PlatformOperators::class)->findByEmail('root@platform.test'))->not->toBeNull()
        ->and(session(OperatorAuth::SESSION_KEY))->not->toBeNull();
});

it('closes the bootstrap once any operator exists', function (): void {
    makeOperator();

    Volt::test('operator.login')
        ->set('name', 'Second')
        ->set('email', 'second@platform.test')
        ->set('password', 'another-strong-pass')
        ->call('createFirst')
        ->assertForbidden();

    expect(app(PlatformOperators::class)->findByEmail('second@platform.test'))->toBeNull();
});

it('signs an operator in with the right password and rejects the wrong one', function (): void {
    makeOperator('login@platform.test');

    Volt::test('operator.login')
        ->set('email', 'login@platform.test')
        ->set('password', 'wrong')
        ->call('login')
        ->assertHasErrors('email');

    Volt::test('operator.login')
        ->set('email', 'login@platform.test')
        ->set('password', 'a-strong-operator-pass')
        ->call('login')
        ->assertRedirect(route('operator.environments'));
});

it('rate-limits operator login after repeated failures', function (): void {
    makeOperator('brute@platform.test');

    // Five wrong attempts consume the window (5/min, keyed on email + IP).
    foreach (range(1, 5) as $ignored) {
        Volt::test('operator.login')
            ->set('email', 'brute@platform.test')
            ->set('password', 'wrong')
            ->call('login')
            ->assertHasErrors('email');
    }

    // Locked out — even the CORRECT password is refused, and no session starts.
    Volt::test('operator.login')
        ->set('email', 'brute@platform.test')
        ->set('password', 'a-strong-operator-pass')
        ->call('login')
        ->assertHasErrors('email')
        ->assertNoRedirect();

    expect(session(OperatorAuth::SESSION_KEY))->toBeNull();
});

it('guards the operator console behind an operator session', function (): void {
    $this->get('/operator')->assertRedirect(route('operator.login'));

    $op = makeOperator();
    $this->withSession([OperatorAuth::SESSION_KEY => $op->id])->get('/operator')->assertOk();
});

it('creates and freely targets environments — no identity guard', function (): void {
    actingAsOperator();

    Volt::test('operator.environments')->set('name', 'Staging')->call('create')->assertHasNoErrors();
    $staging = Environment::query()->where('slug', 'staging')->first();
    expect($staging)->not->toBeNull();

    // Operators stand above every plane — switching just repoints the target,
    // under the operator-only env key (never the end-user environment key).
    Volt::test('operator.environments')->call('switchTo', $staging->id);
    expect(session(OperatorAuth::ENV_KEY))->toBe('staging');
});

it('bootstraps a plane with its first organization and admin', function (): void {
    actingAsOperator();
    $env = Environment::query()->create(['name' => 'Prod', 'slug' => 'prod', 'status' => 'active']);

    Volt::test('operator.environments')
        ->set('provisioningEnvId', $env->id)
        ->set('orgName', 'Acme Inc')
        ->set('adminName', 'Ada Lovelace')
        ->set('adminEmail', 'admin@acme.test')
        ->set('adminPassword', 'a-strong-admin-pass')
        ->call('provisionAdmin')
        ->assertHasNoErrors();

    // The org and admin exist INSIDE the target plane.
    [$orgExists, $adminExists] = app(EnvironmentContext::class)->runAs($env, fn (): array => [
        app(Organizations::class)->bySlug('acme-inc') !== null,
        app(Subjects::class)->findByEmail('admin@acme.test') !== null,
    ]);

    expect($orgExists)->toBeTrue()->and($adminExists)->toBeTrue();
});

it('treats a suspended operator as unauthenticated — the basis of the per-action boot re-check', function (): void {
    $op = actingAsOperator('rogue@platform.test');
    expect(app(OperatorAuth::class)->check())->toBeTrue();

    // Suspended out-of-band by another operator; the session/CSRF stay valid, but
    // every operator component's boot() calls check() on each request, so the
    // suspended operator is refused on the next action (403), not just the next GET.
    $op->update(['status' => 'suspended']);

    expect(app(OperatorAuth::class)->check())->toBeFalse()
        ->and(app(OperatorAuth::class)->current())->toBeNull();
});

it('redirects a suspended operator to sign-in on the next page load', function (): void {
    $op = actingAsOperator('rogue2@platform.test');
    $op->update(['status' => 'suspended']);

    $this->get(route('operator.environments'))->assertRedirect(route('operator.login'));
});

it('creates operators and toggles their status, but never the current one', function (): void {
    $me = actingAsOperator('me@platform.test');

    Volt::test('operator.operators')
        ->set('name', 'Grace')
        ->set('email', 'grace@platform.test')
        ->set('password', 'a-strong-operator-pass')
        ->call('create')
        ->assertHasNoErrors();

    $grace = app(PlatformOperators::class)->findByEmail('grace@platform.test');
    expect($grace)->not->toBeNull();

    Volt::test('operator.operators')->call('toggleStatus', $grace->id);
    expect(PlatformOperator::query()->whereKey($grace->id)->value('status')?->value)->toBe('suspended');

    // Cannot suspend yourself mid-session — refused with a friendly message, and
    // the account stays active (no self-lockout).
    Volt::test('operator.operators')->call('toggleStatus', $me->id)->assertHasNoErrors();
    expect(PlatformOperator::query()->whereKey($me->id)->value('status')?->value)->toBe('active');
});
