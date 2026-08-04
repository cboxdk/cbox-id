<?php

declare(strict_types=1);

use App\Platform\CurrentUser;
use Cbox\Id\Identity\Contracts\SessionManager;
use Cbox\Id\Identity\Contracts\Subjects;
use Cbox\Id\Organization\Contracts\Memberships;
use Cbox\Id\Organization\Contracts\Organizations;
use Cbox\Id\Organization\Enums\MembershipRole;
use Cbox\Id\Organization\ValueObjects\NewOrganization;
use Cbox\Id\TokenVault\Contracts\SecretVault;
use Cbox\Id\TokenVault\Models\VaultGrant;
use Cbox\Id\TokenVault\Models\VaultSecret;
use Cbox\Id\TokenVault\ValueObjects\VaultOwner;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Volt\Volt;

uses(RefreshDatabase::class);

function vaultAdmin(MembershipRole $role = MembershipRole::Owner): string
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

    Volt::test('console.vault.create')
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

    $component = Volt::test('console.vault.show', ['secret' => $secret->id]);

    $component->set('grantClient', 'agent-1')->call('addGrant')->assertHasNoErrors();

    $grant = VaultGrant::query()->where('secret_id', $secret->id)->where('client_id', 'agent-1')->firstOrFail();
    expect($grant->isRevoked())->toBeFalse();

    $component->call('revokeGrant', 'agent-1')->assertHasNoErrors();

    expect($grant->fresh()->isRevoked())->toBeTrue();
});

it('revokes a secret', function (): void {
    $orgId = vaultAdmin();
    $secret = app(SecretVault::class)->store('openai', 'openai', 'sk-live-x', VaultOwner::organization($orgId));

    Volt::test('console.vault.show', ['secret' => $secret->id])->call('revoke');

    expect($secret->fresh()->isRevoked())->toBeTrue();
});

it('forbids a non-admin member', function (): void {
    vaultAdmin(MembershipRole::Member);

    Volt::test('console.vault.index')->assertForbidden();
});

/**
 * The owner comes from the SCOPE, never from the row.
 *
 * The environment plane's version of this page passed
 * `VaultOwner::fromRow($secret->owner_type, $secret->owner_id)` into the framework's
 * deny-by-default owner check — reading the answer off the row being acted on and handing
 * it back as the question. Every row authorized against itself, so the control could not
 * fail and no test could tell whether it worked. Asked of the scope, a stranger's id is
 * simply not found here.
 */
it('never resolves a secret belonging to another organization', function (): void {
    vaultAdmin();

    $theirSubject = app(Subjects::class)->create('them@elsewhere.test', 'Them', 'supersecret123');
    $theirs = app(Organizations::class)->create(new NewOrganization('Elsewhere', 'elsewhere-vault'));
    app(Memberships::class)->add($theirs->id, $theirSubject->id, MembershipRole::Owner);

    $theirSecret = app(SecretVault::class)->store('their-openai', 'openai', 'sk-live-theirs', VaultOwner::organization($theirs->id));

    // Not listed…
    expect(Volt::test('console.vault.index')->html())->not->toContain('their-openai');

    // …and not addressable, even by naming the id outright. 404, not 403: a refusal that
    // distinguished "exists but not yours" from "no such thing" would answer a question
    // about another tenant's vault.
    Volt::test('console.vault.show', ['secret' => $theirSecret->id])->assertNotFound();

    expect($theirSecret->fresh()->isRevoked())->toBeFalse();
})->group('security');
