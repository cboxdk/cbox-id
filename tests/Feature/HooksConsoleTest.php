<?php

declare(strict_types=1);

use App\Platform\CurrentUser;
use Cbox\Id\ExternalActions\Contracts\ExternalActions;
use Cbox\Id\ExternalActions\Enums\ActionEndpointStatus;
use Cbox\Id\ExternalActions\Enums\HookPoint;
use Cbox\Id\ExternalActions\Models\ExternalActionEndpoint;
use Cbox\Id\Identity\Contracts\SessionManager;
use Cbox\Id\Identity\Contracts\Subjects;
use Cbox\Id\Organization\Contracts\Memberships;
use Cbox\Id\Organization\Contracts\Organizations;
use Cbox\Id\Organization\Enums\MembershipRole;
use Cbox\Id\Organization\ValueObjects\NewOrganization;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Volt\Volt;

uses(RefreshDatabase::class);

/**
 * The inline-hook console, driven on the ORGANIZATION plane.
 *
 * One component serves both planes now, and the routable index/new/show shape won over
 * the organization plane's single page: an endpoint has a lifecycle worth linking to,
 * and the one-time signing secret needs somewhere to land. The parity tests in
 * ConsoleParityTest hold the other door open; this file holds this one.
 */
function hooksAdmin(MembershipRole $role = MembershipRole::Owner): string
{
    $subject = app(Subjects::class)->create('hooks@acme.test', 'Hooks Admin', 'supersecret123');
    // VERIFIED, because that is what an established admin of an established organization
    // IS — the same reasoning `actingAsRole()` states and applies by default. An
    // unverified fixture quietly exercises the unverified-account rules instead of the
    // page under test, and then the fixture gets blamed rather than the rule.
    app(Subjects::class)->markEmailVerified($subject->id, (string) $subject->email);
    $subject = app(Subjects::class)->find($subject->id) ?? $subject;

    $org = app(Organizations::class)->create(new NewOrganization('Acme', 'acme-hooks'));
    app(Memberships::class)->add($org->id, $subject->id, $role);
    $session = app(SessionManager::class)->start($subject->id, $org->id, ['pwd']);
    app(CurrentUser::class)->set($subject, $session, $org, $role);

    return $org->id;
}

it('registers a hook and reveals the signing secret once', function (): void {
    config(['cbox-id.external_actions.verify_url' => false]);
    $orgId = hooksAdmin();

    confirmConsoleStepUp();
    Volt::test('console.hooks.create')
        ->set('hook', 'token_minting')
        ->set('url', 'https://hooks.example.test/token')
        ->call('register')
        ->assertHasNoErrors();

    $endpoint = ExternalActionEndpoint::query()->where('organization_id', $orgId)->firstOrFail();

    // The plaintext exists only in the response that reveals it: handed to the detail
    // page as a one-time flash, held there in a PROTECTED prop (never dehydrated into
    // the wire snapshot) and surfaced only through the render. So assert the one-time
    // reveal on the rendered output rather than reaching into component state.
    $component = Volt::test('console.hooks.show', ['hook' => $endpoint->id]);
    $component->assertSee('Copy this signing secret now');
    expect($component->html())->toMatch('/[0-9a-f]{64}/');
});

it('dismisses the revealed secret', function (): void {
    config(['cbox-id.external_actions.verify_url' => false]);
    $orgId = hooksAdmin();

    confirmConsoleStepUp();
    Volt::test('console.hooks.create')
        ->set('url', 'https://hooks.example.test/token')
        ->call('register')
        ->assertHasNoErrors();

    $endpoint = ExternalActionEndpoint::query()->where('organization_id', $orgId)->firstOrFail();

    // Only the organization console offered this, and the banner shows a live credential
    // in plaintext — an administrator who has copied it needs it gone now, not at the
    // next navigation.
    Volt::test('console.hooks.show', ['hook' => $endpoint->id])
        ->assertSee('Copy this signing secret now')
        ->call('dismissSecret')
        ->assertDontSee('Copy this signing secret now');
});

it('pauses, activates then removes an endpoint', function (): void {
    config(['cbox-id.external_actions.verify_url' => false]);
    $orgId = hooksAdmin();

    confirmConsoleStepUp();
    Volt::test('console.hooks.create')
        ->set('url', 'https://hooks.example.test/token')
        ->call('register')
        ->assertHasNoErrors();

    $endpoint = ExternalActionEndpoint::query()->where('organization_id', $orgId)->firstOrFail();

    Volt::test('console.hooks.show', ['hook' => $endpoint->id])->call('pause')->assertHasNoErrors();
    expect($endpoint->fresh()?->status)->toBe(ActionEndpointStatus::Paused);

    Volt::test('console.hooks.show', ['hook' => $endpoint->id])->call('activate')->assertHasNoErrors();
    expect($endpoint->fresh()?->status)->toBe(ActionEndpointStatus::Active);

    Volt::test('console.hooks.show', ['hook' => $endpoint->id])->call('remove')->assertHasNoErrors();
    expect(ExternalActionEndpoint::query()->whereKey($endpoint->id)->exists())->toBeFalse();
});

/**
 * The detail page is NEW on this plane, and a detail page resolved by id is exactly
 * where a merge leaks: the environment plane never had to scope its lookup, because its
 * administrator sits above every organization in the environment.
 */
it('404s an endpoint belonging to another organization in the same environment', function (): void {
    config(['cbox-id.external_actions.verify_url' => false]);
    hooksAdmin();

    $other = app(Organizations::class)->create(new NewOrganization('Rival', 'rival-hooks'));
    $theirs = app(ExternalActions::class)
        ->register(HookPoint::TokenMinting, 'https://rival.example.test/token', $other->id)->endpoint;

    Volt::test('console.hooks.show', ['hook' => $theirs->id])->assertStatus(404);
})->group('security');

/**
 * An endpoint the ENVIRONMENT owns fires on this tenant's sign-ins, so it is shown — but
 * ExternalActions matches the acting organization exactly and would silently no-op every
 * button. A button that does nothing is worse than no button.
 */
it('shows an environment-owned endpoint to a tenant admin without offering to manage it', function (): void {
    config(['cbox-id.external_actions.verify_url' => false]);
    hooksAdmin();

    $environmentWide = app(ExternalActions::class)
        ->registerForEnvironment(HookPoint::TokenMinting, 'https://operator.example.test/token')->endpoint;

    Volt::test('console.hooks.show', ['hook' => $environmentWide->id])
        ->assertOk()
        ->assertSee('All organizations')
        ->assertDontSee('Pause');

    // And the button being absent is not the guard: the action is client-callable.
    Volt::test('console.hooks.show', ['hook' => $environmentWide->id])
        ->call('pause')
        ->assertForbidden();

    expect($environmentWide->fresh()?->status)->toBe(ActionEndpointStatus::Active);
})->group('security');

it('forbids a non-admin member', function (): void {
    hooksAdmin(MembershipRole::Member);

    Volt::test('console.hooks.index')->assertForbidden();
    confirmConsoleStepUp();
    Volt::test('console.hooks.create')->assertForbidden();
})->group('security');
