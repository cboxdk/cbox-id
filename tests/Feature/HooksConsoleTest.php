<?php

declare(strict_types=1);

use App\Platform\CurrentUser;
use Cbox\Id\ExternalActions\Enums\ActionEndpointStatus;
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

function hooksAdmin(string $role = 'owner'): string
{
    $subject = app(Subjects::class)->create('hooks@acme.test', 'Hooks Admin', 'supersecret123');
    $org = app(Organizations::class)->create(new NewOrganization('Acme', 'acme-hooks'));
    app(Memberships::class)->add($org->id, $subject->id, $role);
    $session = app(SessionManager::class)->start($subject->id, $org->id, ['pwd']);
    app(CurrentUser::class)->set($subject, $session, $org, MembershipRole::from($role));

    return $org->id;
}

it('registers a hook and reveals the signing secret once', function (): void {
    config(['cbox-id.external_actions.verify_url' => false]);
    $orgId = hooksAdmin();

    $component = Volt::test('hooks')
        ->set('hook', 'token_minting')
        ->set('url', 'https://hooks.example.test/token')
        ->call('register')
        ->assertHasNoErrors();

    expect($component->get('newSecret'))->toMatch('/^[0-9a-f]{64}$/');

    expect(ExternalActionEndpoint::query()->where('organization_id', $orgId)->exists())->toBeTrue();
});

it('pauses then removes an endpoint', function (): void {
    config(['cbox-id.external_actions.verify_url' => false]);
    $orgId = hooksAdmin();

    Volt::test('hooks')
        ->set('url', 'https://hooks.example.test/token')
        ->call('register')
        ->assertHasNoErrors();

    $endpoint = ExternalActionEndpoint::query()->where('organization_id', $orgId)->firstOrFail();

    Volt::test('hooks')->call('pause', $endpoint->id)->assertHasNoErrors();
    expect($endpoint->fresh()->status)->toBe(ActionEndpointStatus::Paused);

    Volt::test('hooks')->call('remove', $endpoint->id)->assertHasNoErrors();
    expect(ExternalActionEndpoint::query()->whereKey($endpoint->id)->exists())->toBeFalse();
});

it('forbids a non-admin member', function (): void {
    hooksAdmin('member');

    Volt::test('hooks')->assertForbidden();
});
