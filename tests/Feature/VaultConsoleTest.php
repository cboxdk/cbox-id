<?php

declare(strict_types=1);

use App\Platform\CurrentUser;
use App\Platform\PlatformAuth;
use App\Platform\Sudo;
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
use Illuminate\Support\Collection;
use Inertia\Testing\AssertableInertia;

uses(RefreshDatabase::class);

function vaultAdmin(MembershipRole $role = MembershipRole::Owner): string
{
    $subject = app(Subjects::class)->create('vault@acme.test', 'Vault Admin', 'supersecret123');

    // VERIFIED, because that is what an established admin of an established organization
    // IS — the same reasoning `actingAsRole()` states and applies by default. Sealing a
    // downstream credential is held behind the verified-address gate, so an unverified
    // fixture would quietly exercise that rule instead of the page under test, and the
    // fixture rather than the rule would get the blame.
    app(Subjects::class)->markEmailVerified($subject->id, (string) $subject->email);
    $subject = app(Subjects::class)->find($subject->id) ?? $subject;

    $org = app(Organizations::class)->create(new NewOrganization('Acme', 'acme-vault'));
    app(Memberships::class)->add($org->id, $subject->id, $role);
    $session = app(SessionManager::class)->start($subject->id, $org->id, ['pwd']);
    app(CurrentUser::class)->set($subject, $session, $org, $role);
    session([PlatformAuth::SESSION_KEY => $session->id]);

    /*
     * AND THE STEP-UP WINDOW. Every vault route is behind `sudo`, reads included — the
     * list names the providers this organization integrates with, which is worth a fresh
     * password on its own. Opened here so the tests below are about the vault rather than
     * about the gate, which has its own coverage in ConsoleStepUpTest.
     */
    app(Sudo::class)->confirm();

    return $org->id;
}

it('stores a secret sealed at rest, scoped to the org', function (): void {
    $orgId = vaultAdmin();

    storeVaultSecret()->assertSessionHasNoErrors();

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

    $from = route('vault.show', $secret->id);

    test()->from($from)->post(route('vault.grants.store', $secret->id), ['client' => 'agent-1'])
        ->assertSessionHasNoErrors();

    $grant = VaultGrant::query()->where('secret_id', $secret->id)->where('client_id', 'agent-1')->firstOrFail();
    expect($grant->isRevoked())->toBeFalse();

    test()->from($from)
        ->delete(route('vault.grants.destroy', ['secret' => $secret->id, 'client' => 'agent-1']))
        ->assertSessionHasNoErrors();

    expect($grant->fresh()->isRevoked())->toBeTrue();
});

it('revokes a secret', function (): void {
    $orgId = vaultAdmin();
    $secret = app(SecretVault::class)->store('openai', 'openai', 'sk-live-x', VaultOwner::organization($orgId));

    test()->post(route('vault.revoke', $secret->id))->assertRedirect(route('vault'));

    expect($secret->fresh()->isRevoked())->toBeTrue();
});

it('forbids a non-admin member', function (): void {
    vaultAdmin(MembershipRole::Member);

    test()->get(route('vault'))->assertForbidden();
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
    test()->get(route('vault'))
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page->where(
            'secrets',
            fn (Collection $secrets): bool => $secrets->pluck('name')->doesntContain('their-openai'),
        ));

    /*
     * …and not addressable, even by naming the id outright. 404, not 403: a refusal that
     * distinguished "exists but not yours" from "no such thing" would answer a question
     * about another tenant's vault.
     *
     * EVERY ROUTE, not just the read. The owner used to be derived from the ROW being
     * acted on and handed to the framework's ownership check — a tautology that authorized
     * every row against itself — so the writes are where that bug lived.
     */
    test()->get(route('vault.show', $theirSecret->id))->assertNotFound();
    test()->post(route('vault.rotate', $theirSecret->id), ['secret' => 'sk-live-mine'])->assertNotFound();
    test()->post(route('vault.grants.store', $theirSecret->id), ['client' => 'my-agent'])->assertNotFound();
    test()->post(route('vault.revoke', $theirSecret->id))->assertNotFound();

    expect($theirSecret->fresh()->isRevoked())->toBeFalse()
        ->and(VaultGrant::query()->where('secret_id', $theirSecret->id)->exists())->toBeFalse();
})->group('security');
