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

    createPlatformEnvironment(['name' => 'Staging'])->assertSessionHasNoErrors();
    $staging = Environment::query()->where('slug', 'staging')->first();
    expect($staging)->not->toBeNull();

    // Operators stand above every plane — switching just repoints the target, under the
    // operator-only environment key (never the end-user environment resolution).
    targetPlatformEnvironment($staging->id);
    expect(session(OperatorEnvironment::SESSION_KEY))->toBe('staging');

    // And the list SAYS which plane it is aimed at, from the same key. Without this the
    // switch above is a session write nothing on the page reflects.
    expect(platformEnvironments()['activeId'])->toBe($staging->id);
});

/**
 * A DOMAIN TYPED HERE IS RECORDED, NOT ROUTED.
 *
 * The per-environment issuer trusts a custom domain only once `domain_verified_at` is
 * stamped, so writing `domain` at creation would route the host while discovery kept
 * advertising the fallback issuer — a mismatch every conformant OIDC client rejects per
 * RFC 8414 §3.3, which makes the plane silently unusable from the moment it exists.
 */
it('creates an environment without routing an unverified domain to it', function (): void {
    actAsOperator();

    createPlatformEnvironment(['name' => 'Acme Prod', 'domain' => 'id.acme.example'])
        ->assertSessionHasNoErrors();

    $environment = Environment::query()->where('slug', 'acme-prod')->first();

    expect($environment)->not->toBeNull()
        ->and($environment->domain)->toBeNull('an unverified domain was routed at creation');
});

it('refuses a domain already routed to another environment', function (): void {
    actAsOperator();

    Environment::query()->create([
        'name' => 'Taken',
        'slug' => 'taken',
        'status' => 'active',
        'domain' => 'id.acme.example',
    ]);

    // Cased differently, because a hostname is case-insensitive and the uniqueness check
    // is not — this used to create a second environment claiming the same host.
    createPlatformEnvironment(['name' => 'Clash', 'domain' => 'ID.Acme.Example'])
        ->assertSessionHasErrors('domain');

    expect(Environment::query()->where('slug', 'clash')->exists())->toBeFalse();
});

it('bootstraps a plane with its first organization and admin', function (): void {
    actAsOperator();
    $env = Environment::query()->create(['name' => 'Prod', 'slug' => 'prod', 'status' => 'active']);

    provisionEnvironmentAdmin($env->id)->assertSessionHasNoErrors();

    // The org and admin exist INSIDE the target plane.
    [$orgExists, $adminExists] = app(EnvironmentContext::class)->runAs($env, fn (): array => [
        app(Organizations::class)->bySlug('acme-inc') !== null,
        app(Subjects::class)->findByEmail('admin@acme.test') !== null,
    ]);

    expect($orgExists)->toBeTrue()->and($adminExists)->toBeTrue();
});

/**
 * THE TARGET IS A ROUTE PARAMETER, and it is resolved unscoped.
 *
 * Under Volt it was a component property — retargetable from the browser after mount,
 * which is why it had to be `#[Locked]`. An id that names nothing must 404 rather than
 * provision into whichever plane the console happens to be sitting on.
 */
it('refuses to provision into an environment that does not exist', function (): void {
    actAsOperator();

    provisionEnvironmentAdmin('env_nothing_by_that_id')->assertNotFound();

    expect(app(Subjects::class)->findByEmail('admin@acme.test'))->toBeNull();
})->group('security');

/**
 * The password floor is the TARGET TENANT's, asked inside that plane.
 *
 * Asked on the operator console's own plane it would be the wrong policy — an operator
 * provisioning into a strict tenant is bound by that tenant's rules, not by the platform
 * root's — and the violation is reported against the field somebody can act on.
 */
it('holds a provisioned admin to the target plane password policy', function (): void {
    actAsOperator();
    $env = Environment::query()->create(['name' => 'Strict', 'slug' => 'strict', 'status' => 'active']);

    provisionEnvironmentAdmin($env->id, ['adminPassword' => 'short'])
        ->assertSessionHasErrors('adminPassword');

    $exists = app(EnvironmentContext::class)->runAs(
        $env,
        fn (): bool => app(Subjects::class)->findByEmail('admin@acme.test') !== null,
    );

    expect($exists)->toBeFalse('a refused password still created the admin');
});

it('refuses a second admin on an address the target plane already holds', function (): void {
    actAsOperator();
    $env = Environment::query()->create(['name' => 'Prod', 'slug' => 'prod', 'status' => 'active']);

    provisionEnvironmentAdmin($env->id)->assertSessionHasNoErrors();

    // Uniqueness is PER-PLANE, so it can only be asked inside the target environment.
    provisionEnvironmentAdmin($env->id, ['orgName' => 'Second Inc'])
        ->assertSessionHasErrors('adminEmail');

    $secondOrg = app(EnvironmentContext::class)->runAs(
        $env,
        fn (): bool => app(Organizations::class)->bySlug('second-inc') !== null,
    );

    expect($secondOrg)->toBeFalse();
});

it('creates operators and toggles their status, but never the current one', function (): void {
    $me = actAsOperator('me@platform.test');

    createOperator(['name' => 'Grace', 'email' => 'grace@platform.test'])
        ->assertSessionHasNoErrors();

    $grace = app(PlatformOperators::class)->findByEmail('grace@platform.test');
    expect($grace)->not->toBeNull();

    toggleOperator($grace->id);
    expect(PlatformOperator::query()->whereKey($grace->id)->value('status')?->value)->toBe('suspended');

    // Cannot suspend yourself mid-session. THE REASON, not just the outcome: a refusal
    // that merely left the row alone would pass here against a controller that silently
    // did nothing at all, and the operator would be left pressing a dead button.
    toggleOperator($me->id)->assertSessionHasErrors('operator');
    expect(PlatformOperator::query()->whereKey($me->id)->value('status')?->value)->toBe('active');

    // …and the control is not drawn for your own row either, so the refusal above is the
    // second door rather than the only one.
    $rows = collect((array) test()->get(route('platform.operators'))->assertOk()->inertiaProps('operators'));

    expect($rows->firstWhere('id', $me->id)['isSelf'])->toBeTrue()
        ->and($rows->firstWhere('id', $grace->id)['isSelf'])->toBeFalse();
});
