<?php

declare(strict_types=1);

use App\Http\Middleware\PointAtFirstRun;
use App\Platform\Actions\ActionRefused;
use App\Platform\Billing\Jobs\RefreshBillingSnapshot;
use App\Platform\Billing\ProductBilling;
use App\Platform\CurrentUser;
use App\Platform\PlatformAuth;
use App\Platform\ServiceAccess\ProductAdmission;
use Cbox\Id\Identity\Contracts\SessionManager;
use Cbox\Id\Identity\Contracts\Subjects;
use Cbox\Id\Organization\Contracts\Memberships;
use Cbox\Id\Organization\Contracts\Organizations;
use Cbox\Id\Organization\Enums\MembershipRole;
use Cbox\Id\Organization\Models\Environment;
use Cbox\Id\Organization\Models\Organization;
use Cbox\Id\Organization\ValueObjects\NewOrganization;
use Cbox\Id\Platform\Contracts\OrganizationApiKeys;
use Cbox\Id\Platform\Models\Project;
use Cbox\Id\Platform\PlatformRoot;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Http;

beforeEach(function (): void {
    $this->withoutMiddleware(PointAtFirstRun::class);
    config(['service-access.enabled' => true, 'service-access.product_enabled' => true, 'service-access.identity_environment' => 'env_test', 'service-access.billing_url' => 'https://billing.example.test', 'service-access.billing_token' => 'test-directory-secret', 'service-access.product_billing_token' => 'test-service-secret']);
    Http::preventStrayRequests();
});

function productSignupPolicy(bool $login = false): array
{
    return ['id' => 12, 'mode' => 'waitlist', 'login_required' => $login, 'country_collection' => 'required', 'countries' => ['DK' => 'Denmark'], 'questions' => [['key' => 'use', 'label' => 'Your plans', 'required' => true, 'type' => 'multiselect', 'options' => ['Build', 'Explore'], 'validation' => 'none', 'min_length' => 0, 'max_length' => 2000]]];
}

function productSignupPerson(bool $team = false): array
{
    $person = app(Subjects::class)->create('ada@example.test', 'Ada', 'a-strong-unbreached-passphrase');
    app(Subjects::class)->markEmailVerified($person->id, $person->email);
    $person = app(Subjects::class)->find($person->id);
    $organization = $team ? app(Organizations::class)->create(new NewOrganization('Ada team', 'ada-team')) : null;
    if ($organization !== null) {
        app(Memberships::class)->add($organization->id, $person->id, MembershipRole::Owner);
    }
    $session = app(SessionManager::class)->start($person->id, $organization?->id, ['pwd']);
    session([PlatformAuth::SESSION_KEY => $session->id]);
    app(CurrentUser::class)->set($person, $session, $organization);

    return [$person, $organization];
}

it('renders the ID product story, country names, typed questions and invitation together', function (): void {
    Http::fake(['*/policy' => Http::response(['program' => productSignupPolicy()])]);
    $this->get('/start')->assertOk()->assertSee('Your plans')->assertSee('Denmark')->assertSee('I have an invitation')->assertDontSee('test-service-secret')->assertDontSee('type="password"', false);
});

it('allows guest enrollment without creating an identity or product', function (): void {
    Http::fake(['*/policy' => Http::response(['program' => productSignupPolicy()]), '*/join-guest' => Http::response(['status' => 'confirmation_required'])]);
    $this->post(route('product-signup.join'), ['email' => 'guest@example.test', 'country' => 'DK', 'answers' => ['use' => ['Build', 'Explore']]])->assertSessionHasNoErrors()->assertRedirect(route('product-signup.show'));
    Http::assertSent(fn (Request $request): bool => str_ends_with($request->url(), '/join-guest') && $request['answers'] === ['use' => ['Build', 'Explore']] && $request->hasHeader('Idempotency-Key'));
    expect(app(Subjects::class)->findByEmail('guest@example.test'))->toBeNull();
});

it('requires identity first when configured and never forwards a forged subject', function (): void {
    Http::fake(['*/policy' => Http::response(['program' => productSignupPolicy(true)])]);
    $this->post(route('product-signup.join'), ['email' => 'fake@example.test', 'subject' => 'fake'])->assertRedirect(route('signup'));
    Http::assertSentCount(1);
});

it('takes the verified waitlist identity from its session', function (): void {
    [$person] = productSignupPerson();
    Http::fake(['*/policy' => Http::response(['program' => productSignupPolicy(true)]), '*/12/join' => Http::response(['registration' => ['status' => 'waiting']])]);
    $this->post(route('product-signup.join'), ['email' => 'fake@example.test', 'subject' => 'fake', 'country' => 'DK', 'answers' => ['use' => ['Build']]])->assertSessionHasNoErrors();
    Http::assertSent(fn (Request $request): bool => str_ends_with($request->url(), '/join') && $request['subject'] === $person->id && $request['email'] === 'ada@example.test');
});

it('redeems an invitation for the actual member team without joining the waitlist', function (): void {
    [$person, $team] = productSignupPerson(true);
    Http::fake(['*/admit' => Http::response(['allowed' => true])]);
    $this->post(route('product-signup.admit'), ['code' => 'invite-demo', 'organization' => 'other-team', 'subject' => 'other-person', 'email' => 'fake@example.test'])->assertSessionHasNoErrors()->assertRedirect(route('dashboard'));
    Http::assertSent(fn (Request $request): bool => str_ends_with($request->url(), '/admit') && $request['subject'] === $person->id && $request['organization'] === $team->id && $request['email'] === 'ada@example.test' && $request->hasHeader('Authorization', 'Bearer test-service-secret'));
    Http::assertSentCount(1);
});

it('cannot switch to a team the person does not belong to', function (): void {
    productSignupPerson();
    $team = app(Organizations::class)->create(new NewOrganization('Other', 'other'));
    Http::fake();
    $this->post(route('product-signup.team'), ['organization' => $team->id])->assertNotFound();
    Http::assertNothingSent();
});

it('keeps invitation links private and makes unavailable policy fail closed', function (): void {
    Http::fake(['*/policy' => Http::response([], 503)]);
    $this->get('/start?invitation=secret-code')->assertRedirect(route('product-signup.show', ['invite' => 1]))->assertHeader('Referrer-Policy', 'no-referrer')->assertDontSee('secret-code');
    $this->get('/start')->assertStatus(503)->assertDontSee('secret-code');
});

it('creates only an identity team before product admission', function (): void {
    [$person] = productSignupPerson();
    Http::fake();
    $projects = Project::query()->withoutGlobalScopes()->count();
    $environments = Environment::query()->withoutGlobalScopes()->count();
    $this->post(route('product-signup.team'), ['name' => 'A new team'])->assertRedirect();
    expect(Project::query()->withoutGlobalScopes()->count())->toBe($projects)
        ->and(Environment::query()->withoutGlobalScopes()->count())->toBe($environments);
    expect(Organization::query()->where('name', 'A new team')->exists())->toBeTrue();
    Http::assertNothingSent();
});

it('gates direct product creation through organization API keys before writing', function (int $billingStatus, int $expectedStatus): void {
    $account = provisionAccount();
    config(['service-access.identity_environment' => platformRootEnvironment()->id]);
    $token = app(OrganizationApiKeys::class)->issue($account['organization']->id, 'Provisioning', MembershipRole::Admin, null, ['projects:write', 'environments:write'])->plaintext;
    Http::fake(['*/check' => Http::response(['allowed' => false], $billingStatus)]);
    $projects = Project::query()->withoutGlobalScopes()->count();
    $environments = Environment::query()->withoutGlobalScopes()->count();
    $this->withToken($token)->postJson('/api/v1/workspace/projects', ['name' => 'Blocked project'])->assertStatus($expectedStatus);
    $this->withToken($token)->postJson('/api/v1/workspace/environments', ['name' => 'Blocked environment', 'project_id' => $account['project']->id])->assertStatus($expectedStatus);
    expect(Project::query()->withoutGlobalScopes()->count())->toBe($projects)
        ->and(Environment::query()->withoutGlobalScopes()->count())->toBe($environments);
    Http::assertSent(fn (Request $request): bool => str_ends_with($request->url(), '/check') && $request['organization'] === $account['organization']->id && $request->hasHeader('Authorization', 'Bearer test-service-secret'));
})->with(['waitlisted' => [200, 403], 'billing unavailable' => [503, 503]]);

it('lets an admitted root team create its first project and revokes a read once the kept answer is refreshed', function (): void {
    multiTenantDeployment();
    $root = platformRootEnvironment();
    serveOnTestHost($root);
    config(['service-access.identity_environment' => $root->id]);
    [$person, $team] = app(PlatformRoot::class)->run(fn (): array => productSignupPerson(true));
    grantProductEnvironmentAllowance($team->id, 2);
    $allowed = true;
    Http::fake(['*/check' => function () use (&$allowed) {
        return Http::response(['allowed' => $allowed]);
    }]);

    $this->post(route('projects.store'), ['name' => 'First product'])->assertSessionHasNoErrors()->assertRedirect();
    $project = Project::query()->where('organization_id', $team->id)->where('name', 'First product')->first();
    expect($project)->not->toBeNull();
    $this->get(route('projects.show', $project->id))->assertOk();
    Http::assertSent(fn (Request $request): bool => $request['subject'] === $person->id && $request['organization'] === $team->id);

    $allowed = false;
    nextRequest();
    // Within the snapshot's lifetime the page renders from Billing's last answer.
    $this->get(route('projects.show', $project->id))->assertOk();
    $this->travel(31)->seconds();
    nextRequest();
    // Stale: still served, and the refresh job asks Billing again.
    $this->get(route('projects.show', $project->id))->assertOk();
    nextRequest();
    $this->get(route('projects.show', $project->id))->assertForbidden();
});

it('does not disable admission in production when the configuration flag is false', function (): void {
    $root = platformRootEnvironment();
    $this->app->instance('env', 'production');
    config(['service-access.product_enabled' => false, 'service-access.identity_environment' => $root->id]);
    Http::fake(['*/check' => Http::response(['allowed' => false])]);
    expect(fn () => app(ProductAdmission::class)->assertGranted('actual-person', 'actual-team'))
        ->toThrow(ActionRefused::class);
    Http::assertSent(fn (Request $request): bool => $request['subject'] === 'actual-person' && $request['organization'] === 'actual-team');
});

/*
 * No page waits on Billing: admission and features are read from Billing's last answer,
 * kept locally, and a stale answer is asked again by a queued job.
 */

/** @return array{0: Project, 1: Organization, 2: ArrayObject<string, mixed>} */
function admittedTeamWithProject(): array
{
    multiTenantDeployment();
    $root = platformRootEnvironment();
    serveOnTestHost($root);
    config(['service-access.identity_environment' => $root->id]);
    [, $team] = app(PlatformRoot::class)->run(fn (): array => productSignupPerson(true));
    grantProductEnvironmentAllowance($team->id, 2);
    $billing = new ArrayObject(['up' => true, 'allowed' => true, 'checks' => 0]);
    Http::fake(['*/check' => function () use ($billing) {
        $billing['checks']++;
        if ($billing['up'] !== true) {
            throw new ConnectionException('Billing is down.');
        }

        return Http::response(['allowed' => $billing['allowed']]);
    }]);
    test()->post(route('projects.store'), ['name' => 'First product'])->assertSessionHasNoErrors()->assertRedirect();
    $project = Project::query()->where('organization_id', $team->id)->where('name', 'First product')->firstOrFail();

    return [$project, $team, $billing];
}

it('renders a project page from the kept answers while Billing is down, without asking Billing', function (): void {
    [$project, , $billing] = admittedTeamWithProject();
    $billing['up'] = false;
    centralBillingFixture()->takeDown();
    $checks = $billing['checks'];
    nextRequest();

    $this->get(route('projects.show', $project->id))->assertOk();

    expect($billing['checks'])->toBe($checks);
});

it('serves a stale answer at once and queues one refresh of it', function (): void {
    [$project, $team, $billing] = admittedTeamWithProject();
    $billing['up'] = false;
    centralBillingFixture()->takeDown();
    $checks = $billing['checks'];
    Bus::fake([RefreshBillingSnapshot::class]);
    $this->travel(31)->seconds();
    nextRequest();

    $this->get(route('projects.show', $project->id))->assertOk();

    expect($billing['checks'])->toBe($checks);
    Bus::assertDispatched(RefreshBillingSnapshot::class, fn (RefreshBillingSnapshot $job): bool => $job->kind === RefreshBillingSnapshot::ADMISSION && $job->parts[1] === $team->id);
    Bus::assertDispatched(RefreshBillingSnapshot::class, fn (RefreshBillingSnapshot $job): bool => $job->kind === RefreshBillingSnapshot::FEATURES && $job->parts === [$team->id]);
});

it('asks Billing before a write when the kept answer is stale', function (): void {
    [, $team, $billing] = admittedTeamWithProject();
    $billing['allowed'] = false;
    $checks = $billing['checks'];
    $this->travel(31)->seconds();
    nextRequest();

    $this->post(route('projects.store'), ['name' => 'Second product']);

    expect($billing['checks'])->toBe($checks + 1)
        ->and(Project::query()->where('organization_id', $team->id)->where('name', 'Second product')->exists())->toBeFalse();
});

it('refreshes a kept answer in the background and keeps it when Billing is away', function (): void {
    [$project, $team, $billing] = admittedTeamWithProject();
    $person = app(CurrentUser::class)->id();
    $billing['up'] = false;
    (new RefreshBillingSnapshot(RefreshBillingSnapshot::ADMISSION, [$person, $team->id]))->handle(app(ProductAdmission::class), app(ProductBilling::class));
    nextRequest();
    $this->get(route('projects.show', $project->id))->assertOk();

    $billing['up'] = true;
    $billing['allowed'] = false;
    (new RefreshBillingSnapshot(RefreshBillingSnapshot::ADMISSION, [$person, $team->id]))->handle(app(ProductAdmission::class), app(ProductBilling::class));
    nextRequest();
    $this->get(route('projects.show', $project->id))->assertForbidden();
});

it('renders the product signup page from the kept policy while Billing is down', function (): void {
    $billing = new ArrayObject(['up' => true, 'calls' => 0]);
    Http::fake(['*/policy' => function () use ($billing) {
        $billing['calls']++;
        if ($billing['up'] !== true) {
            throw new ConnectionException('Billing is down.');
        }

        return Http::response(['program' => productSignupPolicy()]);
    }]);
    $this->get('/start')->assertOk()->assertSee('Your plans');
    $billing['up'] = false;

    $this->get('/start')->assertOk()->assertSee('Your plans');

    expect($billing['calls'])->toBe(1);
});
