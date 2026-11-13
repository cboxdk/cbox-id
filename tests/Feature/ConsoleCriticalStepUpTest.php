<?php

declare(strict_types=1);

use App\Models\AdminPortalLink;
use App\Platform\Actions\Approvals\ActionApprovalRequest;
use App\Platform\Actions\Principal\ConsoleSessionPrincipal;
use App\Platform\CurrentUser;
use App\Platform\NativeProduct\NativeProductMembers;
use App\Platform\PlatformAuth;
use Cbox\Id\AccessControl\Contracts\Roles;
use Cbox\Id\Federation\Contracts\Connections;
use Cbox\Id\Federation\Enums\ConnectionType;
use Cbox\Id\Identity\Contracts\SessionManager;
use Cbox\Id\Identity\Contracts\Subjects;
use Cbox\Id\Identity\Models\User;
use Cbox\Id\OAuthServer\CibaActionApprovals;
use Cbox\Id\OAuthServer\Contracts\BackchannelAuthentication;
use Cbox\Id\OAuthServer\Contracts\ClientRegistry;
use Cbox\Id\OAuthServer\Enums\ClientType;
use Cbox\Id\OAuthServer\Enums\GrantPollStatus;
use Cbox\Id\OAuthServer\Models\BackchannelAuthRequest;
use Cbox\Id\OAuthServer\ValueObjects\NewClient;
use Cbox\Id\Organization\Contracts\Memberships;
use Cbox\Id\Organization\Contracts\Organizations;
use Cbox\Id\Organization\Enums\MembershipRole;
use Cbox\Id\Organization\ValueObjects\NewOrganization;
use Cbox\Id\Provisioning\Models\ProvisioningConnection;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;

uses(RefreshDatabase::class);

/*
|--------------------------------------------------------------------------
| EVERY CRITICAL CHANGE FROM A CONSOLE ASKS "CONFIRM IT'S YOU" FIRST.
|--------------------------------------------------------------------------
| The step-up used to be opt-in per route, and the routes that forgot were the ones a
| borrowed or hijacked console session wants: an address change before a password reset,
| a staff role or an organization's ownership, an Admin Portal link or a SCIM target, an SSO
| connection's activation. The runner asks now, for every Critical run
| ({@see ConsoleSessionPrincipal::confirmFresh()}), so a route cannot forget.
|
| Each test: no step-up → the plane's step-up screen and nothing changed; a confirmed
| window → the same request works, once, without asking again.
*/

function stepUpVictim(): string
{
    return app(Subjects::class)->create('victim@acme.example', 'Victim', 'an-original-passphrase-123')->id;
}

it('asks for a fresh credential before an administrator changes somebody\'s address (finding 13)', function (): void {
    crudSetup();
    $victim = stepUpVictim();

    test()->from(route('environment.users.show', $victim))
        ->patch(route('environment.users.update', $victim), ['name' => 'Victim', 'email' => 'attacker@evil.example'])
        ->assertRedirect(route('environment.sudo'));

    expect(User::query()->find($victim)?->email)->toBe('victim@acme.example');

    confirmEnvironmentStepUp();

    test()->from(route('environment.users.show', $victim))
        ->patch(route('environment.users.update', $victim), ['name' => 'Victim', 'email' => 'new@acme.example'])
        ->assertSessionHasNoErrors();

    expect(User::query()->find($victim)?->email)->toBe('new@acme.example');
})->group('security');

it('asks for a fresh credential before mailing a reset link to an address nobody verified (finding 13)', function (): void {
    crudSetup();
    Mail::fake();
    $victim = stepUpVictim();
    User::query()->whereKey($victim)->update(['email_verified_at' => null]);

    test()->from(route('environment.users.show', $victim))
        ->post(route('environment.users.password-reset', $victim))
        ->assertRedirect(route('environment.sudo'));

    Mail::assertNothingOutgoing();

    // A verified inbox is the one the account already trusts: no step-up for that.
    User::query()->whereKey($victim)->update(['email_verified_at' => now()]);

    test()->from(route('environment.users.show', $victim))
        ->post(route('environment.users.password-reset', $victim))
        ->assertSessionHasNoErrors()
        ->assertRedirect(route('environment.users.show', $victim));
})->group('security');

it('asks for a fresh credential before transferring an organization (finding 15)', function (): void {
    crudSetup();
    $org = app(Organizations::class)->create(new NewOrganization('Globex', 'globex'));
    $owner = app(Subjects::class)->create('owner@globex.example', 'Owner')->id;
    $attacker = app(Subjects::class)->create('attacker@globex.example', 'Attacker')->id;
    app(Memberships::class)->add($org->id, $owner, MembershipRole::Owner);
    app(Memberships::class)->add($org->id, $attacker, MembershipRole::Member);

    test()->from(route('environment.organizations.show', $org->id))
        ->post(route('environment.organizations.members.transfer-ownership', [$org->id, $attacker]))
        ->assertRedirect(route('environment.sudo'));

    expect(app(Memberships::class)->owners($org->id))->toBe([$owner]);

    confirmEnvironmentStepUp();

    test()->from(route('environment.organizations.show', $org->id))
        ->post(route('environment.organizations.members.transfer-ownership', [$org->id, $attacker]))
        ->assertSessionHasNoErrors();

    expect(app(Memberships::class)->owners($org->id))->toBe([$attacker]);
})->group('security');

it('asks for a fresh credential before granting a staff role (finding 15)', function (): void {
    crudSetup();
    $support = app(Roles::class)->define(null, 'Support');
    $attacker = app(Subjects::class)->create('attacker@evil.test', 'Attacker')->id;

    test()->from(route('environment.staff'))
        ->post(route('environment.staff.store'), ['email' => 'attacker@evil.test', 'role' => $support->id])
        ->assertRedirect(route('environment.sudo'));

    expect(app(Roles::class)->everywhereFor($attacker))->toBe([]);

    confirmEnvironmentStepUp();

    test()->from(route('environment.staff'))
        ->post(route('environment.staff.store'), ['email' => 'attacker@evil.test', 'role' => $support->id])
        ->assertSessionHasNoErrors();

    expect(app(Roles::class)->everywhereFor($attacker))->toBe([$support->id]);
})->group('security');

it('asks for a fresh credential before minting an Admin Portal link (finding 16)', function (): void {
    crudSetup();
    $org = app(Organizations::class)->create(new NewOrganization('Globex', 'globex'));

    test()->from(route('environment.organizations.show', $org->id))
        ->post(route('environment.organizations.portal-links.store', $org->id), ['intents' => ['domain_verification']])
        ->assertRedirect(route('environment.sudo'));

    expect(AdminPortalLink::query()->count())->toBe(0);

    confirmEnvironmentStepUp();

    test()->from(route('environment.organizations.show', $org->id))
        ->post(route('environment.organizations.portal-links.store', $org->id), ['intents' => ['domain_verification']])
        ->assertSessionHasNoErrors();

    expect(AdminPortalLink::query()->count())->toBe(1);
})->group('security');

it('asks for a fresh credential before registering an environment-wide SCIM target (finding 16)', function (): void {
    crudSetup();
    config(['cbox-id.provisioning.verify_url' => false]);

    test()->from(route('environment.provisioning.create'))
        ->post(route('environment.provisioning.store'), [
            'name' => 'Downstream',
            'baseUrl' => 'https://scim.collector.example/v2',
            'scheme' => 'bearer',
            'secret' => 'tok_123',
            'environmentWide' => true,
        ])
        ->assertRedirect(route('environment.sudo'));

    expect(ProvisioningConnection::query()->count())->toBe(0);
})->group('security');

it('asks for a fresh credential before activating an SSO connection (finding 21)', function (): void {
    crudSetup();
    $connection = app(Connections::class)->create(null, ConnectionType::Saml, 'Corporate SAML', [
        'idp_entity_id' => 'https://idp.corp.example/metadata',
        'idp_sso_url' => 'https://idp.corp.example/sso',
        'sp_entity_id' => 'https://acme.test/sp',
        'sp_acs_url' => 'https://acme.test/acs',
    ]);

    test()->from(route('environment.connections.show', $connection->id))
        ->post(route('environment.connections.activate', $connection->id))
        ->assertRedirect(route('environment.sudo'));

    expect(app(Connections::class)->byId($connection->id)?->isActive())->toBeFalse();
})->group('security');

it('answers an Inertia write with the in-place step-up, so the page replays it (finding 21)', function (): void {
    crudSetup();
    $victim = stepUpVictim();

    test()->from(route('environment.users.show', $victim))
        ->withHeader('X-Inertia', 'true')
        ->patch(route('environment.users.update', $victim), ['name' => 'Victim', 'email' => 'attacker@evil.example'])
        ->assertForbidden()
        ->assertHeader('X-Cbox-Step-Up')
        ->assertJsonPath('step_up.plane', 'environment');

    expect(User::query()->find($victim)?->email)->toBe('victim@acme.example');
})->group('security');

it('asks for a fresh credential before a team grant that hands out more than reading (finding 14)', function (): void {
    $account = provisionAccount();
    [$member] = addMember($account['organization']->id, MembershipRole::Member, 'grant@example.test');
    grantNativeProductRoles($member, ['viewer']);
    signInAsMember($account['subjectId']);
    $roles = app(NativeProductMembers::class);
    $route = route('orgs.settings.members.role', ['member' => $member->id, 'organization' => $account['organization']->id]);

    test()->from(route('orgs.settings.members'))
        ->patch($route, ['roles' => ['admin'], 'roles_version' => $roles->access($member)['roles_version']])
        ->assertRedirect(route('sudo'));

    expect($roles->access($member)['roles'])->toBe(['viewer']);

    confirmStepUp();

    test()->from(route('orgs.settings.members'))
        ->patch($route, ['roles' => ['admin'], 'roles_version' => $roles->access($member)['roles_version']])
        ->assertSessionHasNoErrors();

    expect($roles->access($member)['roles'])->toBe(['admin']);
})->group('security');

it('asks for a fresh credential before approving a held Critical action at /approvals (finding 7)', function (): void {
    $subject = app(Subjects::class)->create('member@acme.test', 'Member User', 'supersecret123');
    $org = app(Organizations::class)->create(new NewOrganization('Acme', 'acme-held'));
    app(Memberships::class)->add($org->id, $subject->id, MembershipRole::Member);
    $session = app(SessionManager::class)->start($subject->id, $org->id, ['pwd']);
    app(CurrentUser::class)->set($subject, $session, $org, MembershipRole::Member);
    session([PlatformAuth::SESSION_KEY => $session->id]);

    $client = app(ClientRegistry::class)->register(new NewClient('Agent', ClientType::Confidential, scopes: ['openid']))->client;
    app(BackchannelAuthentication::class)->request($client, ['openid'], $subject->id);
    $row = BackchannelAuthRequest::query()->where('user_id', $subject->id)->firstOrFail();
    $row->forceFill(['purpose' => CibaActionApprovals::PURPOSE])->save();
    ActionApprovalRequest::query()->create([
        'id' => $row->id,
        'principal' => 'delegated:token',
        'action' => 'account.passkeys.remove',
        'binding_code' => 'AB12',
        'input' => [],
    ]);

    test()->from(route('approvals'))->post(route('approvals.approve', $row->id))->assertRedirect(route('sudo'));

    expect(BackchannelAuthRequest::query()->whereKey($row->id)->value('status'))->toBe(GrantPollStatus::Pending);

    confirmStepUp();

    test()->from(route('approvals'))->post(route('approvals.approve', $row->id))->assertSessionHasNoErrors();

    expect(BackchannelAuthRequest::query()->whereKey($row->id)->value('status'))->toBe(GrantPollStatus::Approved);
})->group('security');
