<?php

declare(strict_types=1);

use App\Platform\CurrentUser;
use Cbox\Id\Identity\Contracts\SessionManager;
use Cbox\Id\Identity\Contracts\Subjects;
use Cbox\Id\Organization\Contracts\Memberships;
use Cbox\Id\Organization\Contracts\Organizations;
use Cbox\Id\Organization\ValueObjects\NewOrganization;
use Cbox\Id\TokenVault\Contracts\SecretVault;
use Cbox\Id\TokenVault\Models\VaultGrant;
use Cbox\Id\TokenVault\Models\VaultSecret;
use Cbox\Id\TokenVault\ValueObjects\VaultOwner;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Volt\Volt;

uses(RefreshDatabase::class);

function vaultAdmin(string $role = 'owner'): string
{
    $subject = app(Subjects::class)->create('vault@acme.test', 'Vault Admin', 'supersecret123');
    $org = app(Organizations::class)->create(new NewOrganization('Acme', 'acme-vault'));
    app(Memberships::class)->add($org->id, $subject->id, $role);
    $session = app(SessionManager::class)->start($subject->id, $org->id, ['pwd']);
    app(CurrentUser::class)->set($subject, $session, $org, $role);

    return $org->id;
}

it('stores a secret sealed at rest, scoped to the org', function (): void {
    $orgId = vaultAdmin();

    Volt::test('vault')
        ->set('name', 'openai')
        ->set('provider', 'openai')
        ->set('secret', 'sk-live-x')
        ->call('store')
        ->assertHasNoErrors();

    $secret = VaultSecret::query()
        ->where('owner_type', 'organization')
        ->where('owner_id', $orgId)
        ->firstOrFail();

    expect($secret->name)->toBe('openai')
        ->and($secret->provider)->toBe('openai')
        ->and($secret->owner_id)->toBe($orgId)
        // Sealed: the at-rest ciphertext is never the plaintext.
        ->and($secret->secret_encrypted)->not->toBe('sk-live-x');
});

it('grants then revokes a client', function (): void {
    $orgId = vaultAdmin();
    $secret = app(SecretVault::class)->store('openai', 'openai', 'sk-live-x', VaultOwner::organization($orgId));

    $component = Volt::test('vault');

    $component->set('grantClient', 'agent-1')->call('addGrant', $secret->id)->assertHasNoErrors();

    $grant = VaultGrant::query()->where('secret_id', $secret->id)->where('client_id', 'agent-1')->firstOrFail();
    expect($grant->isRevoked())->toBeFalse();

    $component->call('revokeGrant', $secret->id, 'agent-1')->assertHasNoErrors();

    expect($grant->fresh()->isRevoked())->toBeTrue();
});

it('revokes a secret', function (): void {
    $orgId = vaultAdmin();
    $secret = app(SecretVault::class)->store('openai', 'openai', 'sk-live-x', VaultOwner::organization($orgId));

    Volt::test('vault')->call('revoke', $secret->id)->assertHasNoErrors();

    expect($secret->fresh()->isRevoked())->toBeTrue();
});

it('forbids a non-admin member', function (): void {
    vaultAdmin('member');

    Volt::test('vault')->assertForbidden();
});
