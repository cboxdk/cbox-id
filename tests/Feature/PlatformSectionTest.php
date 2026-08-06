<?php

declare(strict_types=1);

use App\Platform\OperatorEnvironment;
use Cbox\Id\Identity\Contracts\Subjects;
use Cbox\Id\Kernel\Tenancy\Contracts\EnvironmentContext;
use Cbox\Id\Organization\Contracts\Organizations;
use Cbox\Id\Organization\Models\Environment;
use Cbox\Id\Platform\Contracts\PlatformOperators;
use Cbox\Id\Platform\Models\PlatformOperator;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Volt\Volt;

uses(RefreshDatabase::class);

/**
 * The platform pages used to sit behind a door of their own: `/operator/login`, a form
 * that verified an email and a bcrypt hash held in `platform_operators` and wrote
 * `cbox.operator` into the session. Every test in this file that drove that form is gone
 * with it, because the door is gone — an operator is a subject, there is one sign-in, and
 * the question these routes ask is no longer "do you have an operator password?" but "does
 * the person already signed in run this deployment?".
 *
 * What replaced those tests is the set below: the two refusals, and the fact that the
 * answer is re-asked of the live session rather than settled at sign-in.
 */
function makeOperator(string $email = 'op@platform.test'): PlatformOperator
{
    platformRootDeployment();

    return app(PlatformOperators::class)->create($email, 'a-strong-operator-pass', 'Operator');
}

it('sends a signed-out visitor to the one sign-in, not to a door of its own', function (): void {
    // An operator EXISTS — this deployment is installed. Without one the first-run
    // bulkhead points every page at the setup screen and the assertion below would be
    // about that instead.
    makeOperator();

    // `login`, not `workspace.login`. The suite's baseline is a single-host install, and
    // `workspace.login` carries `plane:account` — false when there is no host split, by
    // design — so pointing a self-hosted operator there points them at a 404. The gate
    // asks the deployment shape; see AuthenticateOperator::signInRoute().
    $this->get('/platform')->assertRedirect(route('login'));
    $this->get(route('platform.organizations'))->assertRedirect(route('login'));
});

it('keeps the old operator URLs working, pointed at where those pages went', function (): void {
    makeOperator();

    // `/operator/login` was a door of its own before the sign-in was unified, and
    // `/operator` was this section's address before it stopped being a console. Both are
    // in somebody's bookmark bar, and a 404 there reads as "it moved and nobody said
    // where" rather than as a page that was retired.
    $this->get('/operator')->assertRedirect('/platform');
    $this->get('/operator/login')->assertRedirect('/login');
    $this->get('/operator/login/mfa')->assertRedirect('/login');
});

it('lets a signed-in operator in', function (): void {
    actAsOperator();

    $this->get('/platform')->assertOk();
    $this->get(route('platform.organizations'))->assertOk();
});

/*
 * 404, not 403, and not a redirect loop.
 *
 * A 403 confirms that the page exists and that this deployment has a staff console at
 * that address — which anyone holding any account on the platform could then enumerate.
 * A redirect to sign-in would be worse still: the visitor IS signed in, so they would
 * bounce between the console and a sign-in page that has nothing left to ask them.
 */
it('404s a signed-in subject who does not run this deployment', function (): void {
    platformRootDeployment();

    $subject = app(Subjects::class)->create('ordinary@acme.test', 'Ordinary', 'supersecret123');
    signInAsSubject($subject->id);

    $this->get('/platform')->assertNotFound();
    $this->get(route('platform.organizations'))->assertNotFound();

    // A route with no component behind it, so this is the ROUTE gate answering and not a
    // page's own boot() re-check. Both exist deliberately — Livewire actions all POST to
    // one endpoint, so the page has to re-ask — but a test that only ever hits a Volt page
    // cannot tell which of the two is holding, and passes with either one deleted.
    $this->get(route('platform.search.jump', 'org_whatever'))->assertNotFound();
    $this->post(route('platform.environment.switch'), ['environment' => 'x'])->assertNotFound();
});

/*
 * The reason authority is asked of the session that already exists, rather than written
 * into it at sign-in: suspending an operator does not revoke their subject sessions, so a
 * check that only ran at the door would take away tomorrow's access and leave today's.
 */
it('takes the platform pages away from a suspended operator in a session they already hold', function (): void {
    $op = actAsOperator('rogue@platform.test');

    $this->get(route('platform.organizations'))->assertOk();

    // Suspended out of band by another operator. The cookie, the session row and the CSRF
    // token all stay perfectly valid — the operator does not.
    $op->forceFill(['status' => 'suspended'])->save();
    nextRequest();

    $this->get(route('platform.organizations'))->assertNotFound();

    // The route gate too, on an endpoint with no component to re-check behind it.
    $this->post(route('platform.environment.switch'), ['environment' => 'x'])->assertNotFound();
});

it('creates and freely targets environments — no identity guard', function (): void {
    actAsOperator();

    Volt::test('platform.environments')->set('name', 'Staging')->call('create')->assertHasNoErrors();
    $staging = Environment::query()->where('slug', 'staging')->first();
    expect($staging)->not->toBeNull();

    // Operators stand above every plane — switching just repoints the target, under the
    // operator-only environment key (never the end-user environment resolution).
    Volt::test('platform.environments')->call('switchTo', $staging->id);
    expect(session(OperatorEnvironment::SESSION_KEY))->toBe('staging');
});

it('bootstraps a plane with its first organization and admin', function (): void {
    actAsOperator();
    $env = Environment::query()->create(['name' => 'Prod', 'slug' => 'prod', 'status' => 'active']);

    Volt::test('platform.environments')
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

it('creates operators and toggles their status, but never the current one', function (): void {
    $me = actAsOperator('me@platform.test');

    Volt::test('platform.operators')
        ->set('name', 'Grace')
        ->set('email', 'grace@platform.test')
        ->set('password', 'a-strong-operator-pass')
        ->call('create')
        ->assertHasNoErrors();

    $grace = app(PlatformOperators::class)->findByEmail('grace@platform.test');
    expect($grace)->not->toBeNull();

    Volt::test('platform.operators')->call('toggleStatus', $grace->id);
    expect(PlatformOperator::query()->whereKey($grace->id)->value('status')?->value)->toBe('suspended');

    // Cannot suspend yourself mid-session — refused with a friendly message, and
    // the account stays active (no self-lockout).
    Volt::test('platform.operators')->call('toggleStatus', $me->id)->assertHasNoErrors();
    expect(PlatformOperator::query()->whereKey($me->id)->value('status')?->value)->toBe('active');
});
