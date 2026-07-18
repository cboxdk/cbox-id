<?php

declare(strict_types=1);

use App\Mail\PasswordResetMail;
use App\Platform\EnvironmentAdminAuth;
use Cbox\Id\Identity\Contracts\Subjects;
use Cbox\Id\Identity\Enums\UserStatus;
use Cbox\Id\Identity\Models\User;
use Cbox\Id\Kernel\Tenancy\Contracts\EnvironmentContext;
use Cbox\Id\Kernel\Tenancy\GenericEnvironment;
use Cbox\Id\Organization\Contracts\Memberships;
use Cbox\Id\Organization\Contracts\Organizations;
use Cbox\Id\Organization\Enums\OrganizationStatus;
use Cbox\Id\Organization\Models\Organization;
use Cbox\Id\Organization\ValueObjects\NewOrganization;
use Cbox\Id\Platform\AccountProvisioner;
use Cbox\Id\Platform\ValueObjects\AccountBlueprint;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Livewire\Volt\Volt;

uses(RefreshDatabase::class);

beforeEach(fn () => Http::fake(['api.pwnedpasswords.com/*' => Http::response('', 200)]));

/** Provision an account + env, pin the env context + an env-admin session. */
function crudSetup(): array
{
    $r = app(AccountProvisioner::class)->provision(new AccountBlueprint(
        accountName: 'Acme',
        ownerEmail: 'owner@acme.example',
        ownerName: 'Owner',
        ownerPassword: 'a-strong-unbreached-passphrase',
    ));

    config(['cbox-id.environments.default' => $r->environment->id]);
    app(EnvironmentContext::class)->set(GenericEnvironment::of($r->environment->id));
    session()->put(EnvironmentAdminAuth::SESSION_KEY, $r->member->id);
    session()->put(EnvironmentAdminAuth::ENV_KEY, $r->environment->id);

    return ['member' => $r->member, 'envId' => $r->environment->id];
}

it('renders the user + org detail pages and edits a user profile', function (): void {
    crudSetup();
    $user = app(Subjects::class)->create('jane@acme.example', 'Jane');
    $org = app(Organizations::class)->create(new NewOrganization(name: 'Tenant A', slug: 'tenant-a'));

    $this->get("/admin/users/{$user->id}")->assertOk()->assertSee('jane@acme.example');
    $this->get("/admin/organizations/{$org->id}")->assertOk()->assertSee('Tenant A');

    Volt::test('environment.users.show', ['user' => $user->id])
        ->set('editName', 'Jane Doe')
        ->call('saveProfile')
        ->assertHasNoErrors();

    expect(User::query()->whereKey($user->id)->value('name'))->toBe('Jane Doe');
});

it('deactivates and reactivates a user', function (): void {
    crudSetup();
    $user = app(Subjects::class)->create('bob@acme.example', 'Bob');

    Volt::test('environment.users.show', ['user' => $user->id])->call('suspend');
    expect(User::query()->whereKey($user->id)->value('status'))->toBe(UserStatus::Disabled);

    Volt::test('environment.users.show', ['user' => $user->id])->call('reactivate');
    expect(User::query()->whereKey($user->id)->value('status'))->toBe(UserStatus::Active);
});

it('sends a password reset email from the user detail page', function (): void {
    crudSetup();
    Mail::fake();
    $user = app(Subjects::class)->create('reset@acme.example', 'Reset Me');

    Volt::test('environment.users.show', ['user' => $user->id])->call('sendPasswordReset');

    Mail::assertSent(PasswordResetMail::class);
});

it('assigns a user to an organization', function (): void {
    crudSetup();
    $user = app(Subjects::class)->create('carol@acme.example', 'Carol');
    $org = app(Organizations::class)->create(new NewOrganization(name: 'Tenant B', slug: 'tenant-b'));

    Volt::test('environment.users.show', ['user' => $user->id])
        ->set('assignOrgId', $org->id)
        ->set('assignRole', 'member')
        ->call('assignOrg')
        ->assertHasNoErrors();

    expect(app(Memberships::class)->of($org->id, $user->id))->not->toBeNull();
});

it('renames and soft-deletes an organization', function (): void {
    crudSetup();
    $org = app(Organizations::class)->create(new NewOrganization(name: 'Old Name', slug: 'old-name'));

    Volt::test('environment.organizations.show', ['organization' => $org->id])
        ->set('editName', 'New Name')
        ->set('editSlug', 'new-name')
        ->call('saveDetails')
        ->assertHasNoErrors();
    expect(Organization::query()->whereKey($org->id)->value('name'))->toBe('New Name');

    Volt::test('environment.organizations.show', ['organization' => $org->id])->call('deleteOrg');
    expect(Organization::query()->whereKey($org->id)->value('status'))->toBe(OrganizationStatus::Deleted);
});

it('adds a member to an organization by email', function (): void {
    crudSetup();
    $org = app(Organizations::class)->create(new NewOrganization(name: 'Tenant C', slug: 'tenant-c'));
    $user = app(Subjects::class)->create('dave@acme.example', 'Dave');

    Volt::test('environment.organizations.show', ['organization' => $org->id])
        ->set('memberEmail', 'dave@acme.example')
        ->set('memberRole', 'admin')
        ->call('addMember')
        ->assertHasNoErrors();

    expect(app(Memberships::class)->of($org->id, $user->id)?->role)->toBe('admin');
});

it('starts an env-admin impersonation of a member (redirect + marker)', function (): void {
    ['member' => $member] = crudSetup();
    $org = app(Organizations::class)->create(new NewOrganization(name: 'Tenant D', slug: 'tenant-d'));
    $user = app(Subjects::class)->create('erin@acme.example', 'Erin');
    app(Memberships::class)->add($org->id, $user->id, 'member');

    $this->post("/admin/impersonate/{$user->id}", ['organization' => $org->id, 'reason' => 'support ticket 42'])
        ->assertRedirect(route('dashboard'));

    $marker = session('cbox.impersonation');
    expect($marker['subject'])->toBe($user->id)
        ->and($marker['operator'])->toBe($member->id)
        ->and($marker['actor_type'])->toBe('account_member');
});

it('refuses to impersonate an owner/admin', function (): void {
    crudSetup();
    $org = app(Organizations::class)->create(new NewOrganization(name: 'Tenant E', slug: 'tenant-e'));
    $user = app(Subjects::class)->create('frank@acme.example', 'Frank');
    app(Memberships::class)->add($org->id, $user->id, 'admin');

    $this->post("/admin/impersonate/{$user->id}", ['organization' => $org->id, 'reason' => 'nope'])
        ->assertForbidden();
});
