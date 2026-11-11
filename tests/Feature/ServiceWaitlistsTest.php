<?php

declare(strict_types=1);

use App\Platform\CurrentUser;
use App\Platform\PlatformAuth;
use Cbox\Id\Identity\Contracts\SessionManager;
use Cbox\Id\Identity\Contracts\Subjects;
use Cbox\Id\Organization\Contracts\Memberships;
use Cbox\Id\Organization\Contracts\Organizations;
use Cbox\Id\Organization\Enums\MembershipRole;
use Cbox\Id\Organization\ValueObjects\NewOrganization;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Tests\Support\FakeDelegatedTokens;

uses(RefreshDatabase::class);

function waitlistPerson(bool $verified = true): string
{
    $subject = app(Subjects::class)->create('ada@example.test', 'Ada', 'a-strong-unbreached-passphrase');
    if ($verified) {
        app(Subjects::class)->markEmailVerified($subject->id, $subject->email);
    }
    $session = app(SessionManager::class)->start($subject->id, null, ['pwd']);
    session([PlatformAuth::SESSION_KEY => $session->id]);
    app(CurrentUser::class)->set($subject, $session, null);

    return $subject->id;
}

function waitlistService(int $id = 12, string $key = 'telemetry'): array
{
    return ['id' => $id, 'key' => $key, 'name' => ucfirst($key), 'description' => 'A service to try.', 'mode' => 'waitlist', 'questions' => [['key' => 'use_case', 'label' => 'What will you build?', 'required' => true]], 'public_url' => 'https://billing.example.test/billing/waitlists/'.Str::uuid()];
}

beforeEach(function (): void {
    config(['service-access.enabled' => true, 'service-access.identity_environment' => 'env_test', 'service-access.billing_url' => 'https://billing.example.test', 'service-access.billing_token' => 'directory-test-token']);
    Http::preventStrayRequests();
});

it('lists published services and the current person status without requiring a team', function (): void {
    $subject = waitlistPerson();
    Http::fake([
        'https://billing.example.test/api/v1/access/directory?*' => Http::response(['data' => [waitlistService()], 'current_page' => 1, 'last_page' => 2]),
        'https://billing.example.test/api/v1/access/directory/registrations' => Http::response(['data' => [['program_id' => 12, 'registration' => ['status' => 'waiting', 'joined_at' => '2026-11-06T10:00:00Z']]]]),
    ]);
    $response = $this->get('/settings/services')->assertOk();
    $props = $response->inertiaProps();
    expect($response->inertiaPage()['component'])->toBe('account/services')
        ->and($props['services'][0]['name'])->toBe('Telemetry')
        ->and($props['services'][0]['status'])->toBe('waiting')
        ->and($props['emailVerified'])->toBeTrue()
        ->and($props['nextHref'])->toContain('page=2')
        ->and($props['unavailable'])->toBeFalse();
    $response->assertDontSee('directory-test-token');
    Http::assertSent(fn (Request $request): bool => $request->method() === 'POST' && $request['subject'] === $subject && $request['email'] === 'ada@example.test' && $request['program_ids'] === [12]);
});

it('derives identity and verified email from the session, never the submitted body', function (): void {
    $subject = waitlistPerson();
    Http::fake(['https://billing.example.test/api/v1/access/directory/12/join' => Http::response(['registration' => ['status' => 'waiting']])]);
    $reference = (string) Str::uuid();
    $this->post('/settings/services/12/waitlist', ['subject' => 'somebody-else', 'email' => 'other@example.test', 'country' => 'dk', 'answers' => ['use_case' => 'A project'], 'reference' => $reference])
        ->assertRedirect(route('account.services'))->assertSessionHas('status', __('service_access.joined'));
    Http::assertSent(fn (Request $request): bool => $request['subject'] === $subject && $request['email'] === 'ada@example.test' && $request['country'] === 'DK'
        && $request['answers'] === ['use_case' => 'A project'] && $request->hasHeader('Idempotency-Key', $reference)
        && $request->hasHeader('Authorization', 'Bearer directory-test-token'));
});

it('does not enrol or reveal registration state for an unverified address', function (): void {
    waitlistPerson(false);
    Http::fake(['https://billing.example.test/api/v1/access/directory?*' => Http::response(['data' => [waitlistService()], 'current_page' => 1, 'last_page' => 1])]);
    $props = $this->get('/settings/services')->assertOk()->inertiaProps();
    expect($props['emailVerified'])->toBeFalse()->and($props['services'][0]['status'])->toBeNull();
    $this->from('/settings/services')->post('/settings/services/12/waitlist', ['reference' => (string) Str::uuid()])->assertRedirect('/settings/services')->assertSessionHasErrors('waitlist');
    Http::assertSentCount(1);
});

it('makes an unavailable directory a recoverable page and never reports a failed signup as joined', function (): void {
    waitlistPerson();
    Http::fake(['https://billing.example.test/*' => Http::response([], 503)]);
    $props = $this->get('/settings/services')->assertOk()->inertiaProps();
    expect($props['unavailable'])->toBeTrue()->and($props['services'])->toBe([]);
    $this->from('/settings/services')->post('/settings/services/12/waitlist', ['reference' => (string) Str::uuid()])->assertRedirect('/settings/services')->assertSessionHasErrors('waitlist')->assertSessionMissing('status');
});

it('refuses malformed success responses and does not follow a backend redirect', function (): void {
    waitlistPerson();
    Http::fake(['https://billing.example.test/*' => Http::response(['registration' => null])]);
    $this->from('/settings/services')->post('/settings/services/12/waitlist', ['reference' => (string) Str::uuid()])->assertSessionHasErrors('waitlist')->assertSessionMissing('status');
    Http::fake(['https://billing.example.test/*' => Http::response([], 302, ['Location' => 'https://unrelated.example/collect'])]);
    $props = $this->get('/settings/services')->assertOk()->inertiaProps();
    expect($props['unavailable'])->toBeTrue();
    Http::assertNotSent(fn (Request $request): bool => str_contains($request->url(), 'unrelated.example'));
});

it('returns question validation errors to the form without saving a local registration', function (): void {
    waitlistPerson();
    Http::fake(['https://billing.example.test/*' => Http::response(['errors' => ['answers.use_case' => ['Please answer this question.']]], 422)]);
    $this->from('/settings/services')->post('/settings/services/12/waitlist', ['reference' => (string) Str::uuid()])->assertSessionHasErrors('answers.use_case')->assertSessionMissing('status');
});

it('is optional and confined to the configured identity environment', function (): void {
    waitlistPerson();
    Http::fake();
    config(['service-access.enabled' => false]);
    $this->get('/settings/services')->assertNotFound();
    $this->post('/settings/services/12/waitlist', [])->assertNotFound();
    config(['service-access.enabled' => true, 'service-access.identity_environment' => 'customer-env']);
    $this->get('/settings/services')->assertNotFound();
    Http::assertNothingSent();
});

it('supports a direct link from any service and preserves the filter in pagination', function (): void {
    waitlistPerson();
    Http::fake([
        'https://billing.example.test/api/v1/access/directory?*' => Http::response(['data' => [waitlistService()], 'current_page' => 1, 'last_page' => 2]),
        'https://billing.example.test/api/v1/access/directory/registrations' => Http::response(['data' => [['program_id' => 12, 'registration' => null]]]),
    ]);
    $props = $this->get('/settings/services?service=telemetry')->assertOk()->inertiaProps();
    expect($props['allHref'])->toBe(route('account.services'))->and($props['nextHref'])->toContain('service=telemetry');
    Http::assertSent(fn (Request $request): bool => $request->method() === 'GET' && $request['service'] === 'telemetry');
});

it('requires a signed-in person and keeps the intended service link', function (): void {
    Http::fake();
    $this->get('/settings/services?service=telemetry')->assertRedirect();
    $this->post('/settings/services/12/waitlist', [])->assertRedirect();
    Http::assertNothingSent();
});

it('exposes the same signup action to a narrowly delegated person token and keeps identity server-side', function (): void {
    $subject = waitlistPerson();
    FakeDelegatedTokens::install()->person('waitlist-person', $subject, ['account:waitlists:write'])
        ->person('wrong-scope', $subject, ['account:profile:write']);
    Http::fake(['https://billing.example.test/api/v1/access/directory/12/join' => Http::response(['registration' => ['status' => 'waiting']])]);
    $body = ['reference' => (string) Str::uuid(), 'subject' => 'another-person', 'email' => 'another@example.test', 'answers' => ['use_case' => 'My project']];
    $this->withToken('wrong-scope')->postJson('/api/v1/me/services/12/waitlist', $body)->assertForbidden();
    Http::assertNothingSent();
    $this->withToken('waitlist-person')->postJson('/api/v1/me/services/12/waitlist', $body)->assertOk()->assertJsonPath('data.outcome', 'joined');
    Http::assertSent(fn (Request $request): bool => $request['subject'] === $subject && $request['email'] === 'ada@example.test');
    config(['service-access.enabled' => false]);
    $this->withToken('waitlist-person')->postJson('/api/v1/me/services/12/waitlist', $body)->assertNotFound();
    Http::assertSentCount(1);
});

it('requires email verification through the delegated API as well', function (): void {
    $subject = waitlistPerson(false);
    FakeDelegatedTokens::install()->person('unverified-person', $subject, ['account:waitlists:write']);
    Http::fake();
    $this->withToken('unverified-person')->postJson('/api/v1/me/services/12/waitlist', ['reference' => (string) Str::uuid()])
        ->assertUnprocessable()->assertJsonPath('error', 'email_unverified');
    Http::assertNothingSent();
});

function featureWaitlistTeam(string $subject, string $slug = 'studio-team'): string
{
    $team = app(Organizations::class)->create(new NewOrganization('Studio team', $slug));
    app(Memberships::class)->add($team->id, $subject, MembershipRole::Member);

    return $team->id;
}

it('shows feature interests for the selected active team and preserves direct service links', function (): void {
    $subject = waitlistPerson();
    $team = featureWaitlistTeam($subject);
    $feature = array_replace(waitlistService(), ['kind' => 'feature', 'scope' => 'person', 'name' => 'New editor', 'service_name' => 'Telemetry', 'feature_key' => 'editor', 'public_url' => null]);
    Http::fake([
        'https://billing.example.test/api/v1/access/directory?*' => Http::response(['data' => [$feature], 'current_page' => 1, 'last_page' => 2]),
        'https://billing.example.test/api/v1/access/directory/registrations' => Http::response(['data' => [['program_id' => 12, 'registration' => ['status' => 'following']]]]),
    ]);
    $props = $this->get('/settings/services?kind=feature&service=telemetry&organization='.$team)->assertOk()->inertiaProps();
    expect($props['organization'])->toBe($team)->and($props['teams'])->toHaveCount(1)
        ->and($props['services'][0]['status'])->toBe('following')->and($props['services'][0]['publicUrl'])->toBeNull()
        ->and($props['services'][0]['scope'])->toBe('person')->and($props['nextHref'])->toContain('kind=feature', 'organization='.$team, 'service=telemetry');
    Http::assertSent(fn (Request $request): bool => $request->method() === 'POST' && $request['organization'] === $team && $request['subject'] === $subject);
});

it('accepts notification and testing preferences only for a current membership and real identity', function (): void {
    $subject = waitlistPerson();
    $team = featureWaitlistTeam($subject);
    Http::fake(['https://billing.example.test/api/v1/access/directory/12/join' => Http::response(['registration' => ['status' => 'following']])]);
    $this->post('/settings/services/12/waitlist?kind=feature', ['organization' => $team, 'interest' => 'notify', 'reference' => (string) Str::uuid(), 'subject' => 'forged', 'email' => 'forged@example.test'])
        ->assertRedirect(route('account.services', ['kind' => 'feature', 'organization' => $team]))->assertSessionHas('status', __('service_access.feature_joined'));
    Http::assertSent(fn (Request $request): bool => $request['organization'] === $team && $request['interest'] === 'notify' && $request['subject'] === $subject && $request['email'] === 'ada@example.test');
});

it('refuses another team before sending any registration data to Billing', function (): void {
    waitlistPerson();
    Http::fake();
    $this->get('/settings/services?kind=feature&organization=foreign-team')->assertNotFound();
    $this->post('/settings/services/12/waitlist', ['organization' => 'foreign-team', 'interest' => 'test', 'reference' => (string) Str::uuid()])->assertNotFound();
    $this->post('/settings/services/12/waitlist/leave', ['organization' => 'foreign-team', 'reference' => (string) Str::uuid()])->assertNotFound();
    Http::assertNothingSent();
});

it('withdraws only the signed-in person interest with durable idempotency', function (): void {
    $subject = waitlistPerson();
    $team = featureWaitlistTeam($subject);
    $reference = (string) Str::uuid();
    Http::fake(['https://billing.example.test/api/v1/access/directory/12/leave' => Http::response(['status' => 'withdrawn'])]);
    $this->post('/settings/services/12/waitlist/leave', ['organization' => $team, 'reference' => $reference, 'subject' => 'somebody-else'])
        ->assertSessionHas('status', __('service_access.withdrawn'));
    Http::assertSent(fn (Request $request): bool => $request['subject'] === $subject && $request['organization'] === $team && $request->hasHeader('Idempotency-Key', $reference));
});

it('refuses suspended members even when their old session still exists', function (): void {
    $subject = waitlistPerson();
    $team = featureWaitlistTeam($subject);
    app(Memberships::class)->of($team, $subject)->update(['status' => 'suspended']);
    Http::fake();
    $this->post('/settings/services/12/waitlist', ['organization' => $team, 'interest' => 'test', 'reference' => (string) Str::uuid()])->assertNotFound();
    Http::assertNothingSent();
});

it('does not present service programmes as features during a staggered Billing rollout', function (): void {
    waitlistPerson();
    Http::fake(['https://billing.example.test/api/v1/access/directory?*' => Http::response(['data' => [waitlistService()], 'current_page' => 1, 'last_page' => 1])]);
    $props = $this->get('/settings/services?kind=feature')->assertOk()->inertiaProps();
    expect($props['unavailable'])->toBeTrue()->and($props['services'])->toBe([]);
    Http::assertSentCount(1);
});
