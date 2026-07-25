<?php

declare(strict_types=1);

use App\Http\Middleware\AuthenticateAccountMember;
use App\Platform\AccountAuth;
use App\Platform\PlatformAuth;
use Cbox\Id\Identity\Contracts\AdminPasswords;
use Cbox\Id\Identity\Contracts\SessionManager;
use Cbox\Id\Identity\Contracts\Subjects;
use Cbox\Id\Identity\Enums\PasswordRevocationScope;
use Cbox\Id\Identity\ValueObjects\AdminPasswordAssignment;
use Cbox\Id\Organization\Contracts\Memberships;
use Cbox\Id\Organization\Contracts\Organizations;
use Cbox\Id\Organization\ValueObjects\NewOrganization;
use Cbox\Id\Platform\AccountProvisioner;
use Cbox\Id\Platform\PlatformRoot;
use Cbox\Id\Platform\ValueObjects\AccountBlueprint;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Volt\Volt;

uses(RefreshDatabase::class);

/**
 * `AdminPasswordAssignment` documents `temporary: true` as "the subject MUST choose a
 * new password at next sign-in". Until this, `requiresChange()` was read in exactly one
 * place — to render a line of prose on the admin's own console page — so a temporary
 * password with no expiry was simply a permanent one the administrator also knew.
 */
function subjectOwingAChange(bool $temporary = true): string
{
    $subject = app(Subjects::class)->create('dana@acme.test', 'Dana', 'the-handed-over-passphrase');
    $org = app(Organizations::class)->create(new NewOrganization('Acme', 'acme-forced'));
    app(Memberships::class)->add($org->id, $subject->id, 'member');

    app(AdminPasswords::class)->assign(new AdminPasswordAssignment(
        userId: $subject->id,
        password: 'the-handed-over-passphrase',
        temporary: $temporary,
        // No expiry: "until they change it". Precisely the combination that used to
        // produce a permanent administrator-known credential.
        expiresAt: null,
        revoke: PasswordRevocationScope::Nothing,
    ));

    $session = app(SessionManager::class)->start($subject->id, $org->id, ['pwd']);
    session([PlatformAuth::SESSION_KEY => $session->id]);

    return $subject->id;
}

it('holds every authenticated page until the temporary password is replaced', function (): void {
    subjectOwingAChange();

    // Not just the sign-in that used it — any authenticated request.
    $this->get('/dashboard')->assertRedirect(route('password.change'));
    $this->get('/account')->assertRedirect(route('password.change'));
    $this->get('/members')->assertRedirect(route('password.change'));

    // The change page itself must not redirect to itself.
    $this->get(route('password.change'))->assertOk();
});

it('lets a permanent administrative password through', function (): void {
    subjectOwingAChange(temporary: false);

    $this->get('/dashboard')->assertOk();
});

it('releases the hold once a new password is set, and only then', function (): void {
    $subjectId = subjectOwingAChange();

    // A refused password leaves the requirement standing — releasing the hold before
    // the write would let a policy rejection open the console anyway.
    Volt::test('auth.change-password')
        ->set('password', 'short')
        ->set('passwordConfirmation', 'short')
        ->call('save')
        ->assertHasErrors('password');

    expect(app(AdminPasswords::class)->requiresChange($subjectId))->toBeTrue();
    $this->get('/dashboard')->assertRedirect(route('password.change'));

    Volt::test('auth.change-password')
        ->set('password', 'a-passphrase-only-they-know')
        ->set('passwordConfirmation', 'a-passphrase-only-they-know')
        ->call('save')
        ->assertHasNoErrors();

    expect(app(AdminPasswords::class)->requiresChange($subjectId))->toBeFalse()
        ->and(app(Subjects::class)->verifyPassword($subjectId, 'a-passphrase-only-they-know'))->toBeTrue();

    $this->get('/dashboard')->assertOk();
});

it('refuses a mismatched confirmation without touching the credential', function (): void {
    $subjectId = subjectOwingAChange();

    Volt::test('auth.change-password')
        ->set('password', 'a-passphrase-only-they-know')
        ->set('passwordConfirmation', 'a-different-passphrase-entirely')
        ->call('save')
        ->assertHasErrors('passwordConfirmation');

    expect(app(Subjects::class)->verifyPassword($subjectId, 'the-handed-over-passphrase'))->toBeTrue()
        ->and(app(AdminPasswords::class)->requiresChange($subjectId))->toBeTrue();
});

/**
 * OIDC Core §3.1.2.6: prompt=none must answer the CLIENT with error=login_required, not
 * redirect a user agent that was explicitly told not to interact. The authorize endpoint
 * makes that call; the hold must not pre-empt it.
 */
it('does not redirect a prompt=none authorization request', function (): void {
    subjectOwingAChange();

    $response = $this->get('/oauth/authorize?prompt=none&client_id=nope&response_type=code&redirect_uri=https://app.test/cb');

    expect((string) $response->headers->get('Location'))->not->toContain('password/change');
});

/**
 * The account plane is a separate gate ({@see AuthenticateAccountMember}),
 * so it needs its own proof. The credential of record is the member's SUBJECT — see
 * docs/core-concepts/unified-account-identity.md — which is why the requirement is read
 * and cleared inside the platform root.
 */
it('holds the workspace console until an account member replaces a temporary password', function (): void {
    platformRootEnvironment();

    $result = app(AccountProvisioner::class)->provision(new AccountBlueprint(
        accountName: 'Acme',
        ownerEmail: 'owner@acme.example',
        ownerName: 'Owner',
        ownerPassword: 'a-strong-unbreached-passphrase',
    ));

    $subjectId = (string) $result->member->refresh()->subject_id;
    $root = app(PlatformRoot::class);

    $root->run(fn () => app(AdminPasswords::class)->assign(new AdminPasswordAssignment(
        userId: $subjectId,
        password: 'the-handed-over-passphrase',
        temporary: true,
        expiresAt: null,
        revoke: PasswordRevocationScope::Nothing,
    )));

    session()->put(AccountAuth::SESSION_KEY, $result->member->id);

    $this->get(route('workspace.home'))->assertRedirect(route('workspace.password.change'));
    $this->get(route('workspace.password.change'))->assertOk();

    Volt::test('workspace.change-password')
        ->set('password', 'a-passphrase-only-they-know')
        ->set('passwordConfirmation', 'a-passphrase-only-they-know')
        ->call('save')
        ->assertHasNoErrors();

    expect($root->run(fn () => app(AdminPasswords::class)->requiresChange($subjectId)))->toBeFalse();

    $this->get(route('workspace.home'))->assertOk();
});
