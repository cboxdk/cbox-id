<?php

declare(strict_types=1);

use App\Http\Middleware\PointAtFirstRun;
use App\Platform\CurrentUser;
use App\Platform\PlatformAuth;
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
use Illuminate\Http\Client\Request;
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
    $this->post(route('product-signup.admit'), ['code' => 'invite-demo', 'organization' => 'other-team', 'subject' => 'other-person', 'email' => 'fake@example.test'])->assertSessionHasNoErrors()->assertRedirect(route('projects'));
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

it('gates direct product creation through workspace API keys before writing', function (int $billingStatus, int $expectedStatus): void {
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
