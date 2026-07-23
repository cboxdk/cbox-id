<?php

declare(strict_types=1);

use App\Platform\CurrentUser;
use Cbox\Id\Identity\Contracts\SessionManager;
use Cbox\Id\Identity\Contracts\Subjects;
use Cbox\Id\Organization\Contracts\Memberships;
use Cbox\Id\Organization\Contracts\Organizations;
use Cbox\Id\Organization\Enums\MembershipRole;
use Cbox\Id\Organization\ValueObjects\NewOrganization;
use Cbox\Id\Provisioning\Enums\ConnectionStatus;
use Cbox\Id\Provisioning\Models\ProvisioningConnection;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Volt\Volt;

uses(RefreshDatabase::class);

function provAdmin(string $role = 'owner'): string
{
    $subject = app(Subjects::class)->create('prov@acme.test', 'Prov Admin', 'supersecret123');
    $org = app(Organizations::class)->create(new NewOrganization('Acme', 'acme-prov'));
    app(Memberships::class)->add($org->id, $subject->id, $role);
    $session = app(SessionManager::class)->start($subject->id, $org->id, ['pwd']);
    app(CurrentUser::class)->set($subject, $session, $org, MembershipRole::from($role));

    return $org->id;
}

it('registers a provisioning connection', function (): void {
    config(['cbox-id.provisioning.verify_url' => false]);
    $orgId = provAdmin();

    Volt::test('provisioning')
        ->set('name', 'Downstream')
        ->set('baseUrl', 'https://scim.example.test/v2')
        ->set('scheme', 'bearer')
        ->set('secret', 'tok_123')
        ->call('register')
        ->assertHasNoErrors();

    expect(ProvisioningConnection::query()->where('organization_id', $orgId)->exists())->toBeTrue();
});

it('pauses a connection', function (): void {
    config(['cbox-id.provisioning.verify_url' => false]);
    $orgId = provAdmin();

    Volt::test('provisioning')
        ->set('name', 'Downstream')
        ->set('baseUrl', 'https://scim.example.test/v2')
        ->set('scheme', 'bearer')
        ->set('secret', 'tok_123')
        ->call('register')
        ->assertHasNoErrors();

    $connection = ProvisioningConnection::query()->where('organization_id', $orgId)->firstOrFail();

    Volt::test('provisioning')->call('pause', $connection->id)->assertHasNoErrors();

    expect($connection->fresh()->status)->toBe(ConnectionStatus::Paused);
});

it('forbids a non-admin member', function (): void {
    provAdmin('member');

    Volt::test('provisioning')->assertForbidden();
});
