<?php

declare(strict_types=1);

use Cbox\Id\AccessControl\Contracts\AccessChecker;
use Cbox\Id\AccessControl\Contracts\Roles;
use Cbox\Id\Governance\Contracts\SegregationOfDuties;
use Cbox\Id\Identity\Contracts\Subjects;
use Cbox\Id\OAuthServer\Contracts\ClientRegistry;
use Cbox\Id\OAuthServer\ValueObjects\NewClient;
use Cbox\Id\Organization\Contracts\Memberships;
use Cbox\Id\Organization\Contracts\Organizations;
use Cbox\Id\Organization\Enums\MembershipRole;
use Cbox\Id\Organization\ValueObjects\NewOrganization;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;

uses(RefreshDatabase::class);

/*
|--------------------------------------------------------------------------
| Environment console › People › Staff
|--------------------------------------------------------------------------
| Roles held across the whole environment by its own people. The page lists them per app,
| grants and takes them back, and says clearly — naming the organization — when
| segregation of duties refuses one.
*/

/** An app of the environment's own, with a name the Staff page groups by. */
function staffApp(string $name): string
{
    return app(ClientRegistry::class)->register(new NewClient(
        name: $name,
        redirectUris: ['https://'.strtolower($name).'.test/callback'],
        grantTypes: ['authorization_code'],
        scopes: ['openid'],
        firstParty: true,
    ))->client->client_id;
}

function staffPerson(string $email = 'sam@vendor.test', string $name = 'Sam Support'): string
{
    return app(Subjects::class)->create($email, $name, 'a-strong-unbreached-passphrase')->id;
}

function grantStaffRole(string $email, string $roleId): TestResponse
{
    return test()->from(route('environment.staff'))->post(route('environment.staff.store'), [
        'email' => $email,
        'role' => $roleId,
    ]);
}

it('lists who holds a staff role, grouped by the app it reaches', function (): void {
    crudSetup();

    $parcels = staffApp('Parcels');
    $roles = app(Roles::class);
    $support = $roles->define(null, 'Support', clientId: $parcels, tenantAssignable: false);
    $auditor = $roles->define(null, 'Auditor');

    $sam = staffPerson();
    $roles->assignEverywhere($sam, $support->id);
    $roles->assignEverywhere($sam, $auditor->id);

    $props = $this->get(route('environment.staff'))->assertOk()->inertiaProps();

    expect($props['help']['topic'] ?? null)->toBe('staff')
        ->and(collect($props['grants'])->map(fn (array $g): string => $g['role']['name'].'@'.($g['role']['app'] ?? 'all'))->sort()->values()->all())
        ->toBe(['Auditor@all', 'Support@Parcels'])
        ->and(collect($props['grants'])->firstWhere('role.name', 'Support')['role']['staffOnly'])->toBeTrue()
        // An app's own role is grantable everywhere now — it reaches only that app.
        ->and(collect($props['roles'])->pluck('name')->all())->toContain('Support', 'Auditor');
});

it('grants an app\'s own role everywhere, reaching only that app\'s tokens', function (): void {
    ['member' => $member] = crudSetup();

    $parcels = staffApp('Parcels');
    $ledger = staffApp('Ledger');
    $support = app(Roles::class)->define(null, 'Support', clientId: $parcels, tenantAssignable: false);

    $sam = staffPerson();
    $org = app(Organizations::class)->create(new NewOrganization('Globex', 'globex-staff'));
    app(Memberships::class)->add($org->id, $sam, MembershipRole::Member);

    grantStaffRole('SAM@vendor.test', $support->id)->assertSessionHasNoErrors();

    expect(app(Roles::class)->everywhereFor($sam))->toBe([$support->id])
        ->and(app(AccessChecker::class)->forToken($sam, $org->id, $parcels)->roles)->toBe(['Support'])
        ->and(app(AccessChecker::class)->forToken($sam, $org->id, $ledger)->roles)->toBe([]);
});

it('takes a staff role back everywhere at once', function (): void {
    crudSetup();

    $support = app(Roles::class)->define(null, 'Support');
    $sam = staffPerson();
    app(Roles::class)->assignEverywhere($sam, $support->id);

    $this->from(route('environment.staff'))
        ->delete(route('environment.staff.destroy', ['user' => $sam, 'role' => $support->id]))
        ->assertSessionHasNoErrors();

    expect(app(Roles::class)->everywhereFor($sam))->toBe([]);
});

/**
 * SEGREGATION OF DUTIES, NAMING THE ORGANIZATION. A staff role lands in every
 * organization the person belongs to at once, so "blocked by a policy" is only actionable
 * when it says in WHICH one they already hold the other half.
 */
it('refuses a staff role that completes a conflict in one of the person\'s organizations, and says where', function (): void {
    crudSetup();

    $roles = app(Roles::class);
    $approver = $roles->define(null, 'Approver');
    $support = $roles->define(null, 'Support');
    app(SegregationOfDuties::class)->definePolicy(null, 'Approve or support', [$approver->id, $support->id]);

    $sam = staffPerson();
    $globex = app(Organizations::class)->create(new NewOrganization('Globex', 'globex-sod'));
    app(Memberships::class)->add($globex->id, $sam, MembershipRole::Member);
    $roles->assign($globex->id, $sam, $approver->id);

    grantStaffRole('sam@vendor.test', $support->id)
        ->assertSessionHasErrors(['role' => 'In Globex: Blocked by "Approve or support": "Support" cannot be held together with "Approver".']);

    expect($roles->everywhereFor($sam))->toBe([]);
})->group('security');

/**
 * The pair no organization can see: two conflicting staff roles held by somebody who
 * belongs to no organization. The framework asks only inside organizations, so without
 * the console's own check both would be granted, and the pair would land in every
 * organization the person later joined — where joining asks nothing.
 */
it('refuses a second staff role that conflicts with one the person already holds, with no organization involved', function (): void {
    crudSetup();

    $roles = app(Roles::class);
    $approver = $roles->define(null, 'Approver');
    $support = $roles->define(null, 'Support');
    app(SegregationOfDuties::class)->definePolicy(null, 'Approve or support', [$approver->id, $support->id]);

    $sam = staffPerson();
    $roles->assignEverywhere($sam, $approver->id);

    grantStaffRole('sam@vendor.test', $support->id)
        ->assertSessionHasErrors(['role' => 'Blocked by "Approve or support": "Support" cannot be held together with "Approver". Both would be staff roles.']);

    expect($roles->everywhereFor($sam))->toBe([$approver->id]);
})->group('security');

it('refuses one organization\'s own role, and a role that does not exist', function (): void {
    ['member' => $member] = crudSetup();

    $theirs = app(Roles::class)->define($member->organization_id, 'Billing admin');
    $sam = staffPerson();

    grantStaffRole('sam@vendor.test', $theirs->id)
        ->assertSessionHasErrors(['role' => 'That role cannot be granted across the environment.']);
    grantStaffRole('sam@vendor.test', '01NOSUCHROLE0000000000000')
        ->assertSessionHasErrors(['role' => 'That role cannot be granted across the environment.']);

    expect(app(Roles::class)->everywhereFor($sam))->toBe([]);
})->group('security');

it('names nobody it cannot find', function (): void {
    crudSetup();

    $support = app(Roles::class)->define(null, 'Support');

    grantStaffRole('nobody@vendor.test', $support->id)
        ->assertSessionHasErrors(['email' => 'Nobody in this environment uses that address.']);
});

it('shows the same refusal on the user page, under the staff roles', function (): void {
    crudSetup();

    $roles = app(Roles::class);
    $approver = $roles->define(null, 'Approver');
    $support = $roles->define(null, 'Support');
    app(SegregationOfDuties::class)->definePolicy(null, 'Approve or support', [$approver->id, $support->id]);

    $sam = staffPerson();
    $roles->assignEverywhere($sam, $approver->id);

    setEnvironmentRole($sam, $support->id, true)
        ->assertSessionHasErrors(['staffRole' => 'Blocked by "Approve or support": "Support" cannot be held together with "Approver". Both would be staff roles.']);

    $props = $this->get(route('environment.users.show', $sam))->assertOk()->inertiaProps();

    expect($props['heldStaffRoles'])->toBe([$approver->id])
        ->and(collect($props['staffRoles'])->pluck('name')->all())->toContain('Approver', 'Support');
})->group('security');

it('is not a page of the organization console', function (): void {
    expect(Route::has('staff'))->toBeFalse()
        ->and(route('environment.staff', [], false))->toBe('/admin/staff');
});
