<?php

declare(strict_types=1);

use App\Models\InvitationRoleGrant;
use App\Platform\CurrentUser;
use App\Platform\PlatformAuth;
use Cbox\Id\AccessControl\Contracts\Roles;
use Cbox\Id\AccessControl\Models\Role;
use Cbox\Id\AccessControl\Models\RoleAssignment;
use Cbox\Id\Governance\Contracts\SegregationOfDuties;
use Cbox\Id\Governance\Models\SodPolicy;
use Cbox\Id\Identity\Contracts\SessionManager;
use Cbox\Id\Identity\Contracts\Subjects;
use Cbox\Id\Organization\Contracts\Invitations;
use Cbox\Id\Organization\Contracts\Memberships;
use Cbox\Id\Organization\Contracts\Organizations;
use Cbox\Id\Organization\Enums\MembershipRole;
use Cbox\Id\Organization\ValueObjects\NewOrganization;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Mail;
use Inertia\Testing\AssertableInertia;

uses(RefreshDatabase::class);

/**
 * @return array{0: string, 1: string} [organization id, member's user id]
 */
function sodEnforcementOrg(): array
{
    $admin = app(Subjects::class)->create('admin@acme.test', 'Admin', 'supersecret123');
    $member = app(Subjects::class)->create('member@acme.test', 'Member', 'supersecret123');
    $org = app(Organizations::class)->create(new NewOrganization('Acme', 'acme-sod-enforce'));
    app(Memberships::class)->add($org->id, $admin->id, MembershipRole::Owner);
    app(Memberships::class)->add($org->id, $member->id, MembershipRole::Member);
    $session = app(SessionManager::class)->start($admin->id, $org->id, ['pwd']);
    app(CurrentUser::class)->set($admin, $session, $org, MembershipRole::Owner);

    // AND THE SESSION KEY THE CONSOLE'S GUARD READS ON THE WAY IN — the conflict-rules
    // pages are requests now, and without this each one answers a redirect to /login.
    session([PlatformAuth::SESSION_KEY => $session->id]);

    return [$org->id, $member->id];
}

/**
 * @return array{0: Role, 1: Role}
 */
function sodEnforcementRoles(string $orgId): array
{
    return [
        app(Roles::class)->define($orgId, 'Create purchase order'),
        app(Roles::class)->define($orgId, 'Approve payment'),
    ];
}

/**
 * The console shipped the whole SoD surface — define a policy here, see violations
 * there — and never called the pre-grant gate the framework handed it. So an admin
 * could create on the Members page exactly the toxic combination the Governance page
 * reports, one screen contradicting the other.
 */
it('refuses a role grant that would complete a toxic combination, and names both roles', function (): void {
    [$orgId, $memberId] = sodEnforcementOrg();
    [$createPo, $approvePay] = sodEnforcementRoles($orgId);

    app(SegregationOfDuties::class)->definePolicy($orgId, 'PO vs payment', [$createPo->id, $approvePay->id]);
    app(Roles::class)->assign($orgId, $memberId, $createPo->id);

    // THE REFUSAL NAMES BOTH ROLES AND THE POLICY. "Blocked by a policy" tells the admin
    // nothing they can act on; this tells them which pair is forbidden and by what.
    setDirectoryAccessRole($memberId, $approvePay->id, true)
        ->assertSessionHasErrors(['role' => 'Blocked by "PO vs payment": "Approve payment" cannot be held together with "Create purchase order".']);

    expect(RoleAssignment::query()->where('user_id', $memberId)->where('role_id', $approvePay->id)->exists())->toBeFalse();
});

it('still allows a grant with no conflict', function (): void {
    [$orgId, $memberId] = sodEnforcementOrg();
    [$createPo, $approvePay] = sodEnforcementRoles($orgId);

    app(SegregationOfDuties::class)->definePolicy($orgId, 'PO vs payment', [$createPo->id, $approvePay->id]);

    setDirectoryAccessRole($memberId, $approvePay->id, true)->assertSessionHasNoErrors();

    expect(RoleAssignment::query()->where('user_id', $memberId)->where('role_id', $approvePay->id)->exists())->toBeTrue();
});

it('still allows REVOKING a role even where a policy applies', function (): void {
    [$orgId, $memberId] = sodEnforcementOrg();
    [$createPo, $approvePay] = sodEnforcementRoles($orgId);

    app(SegregationOfDuties::class)->definePolicy($orgId, 'PO vs payment', [$createPo->id, $approvePay->id]);
    app(Roles::class)->assign($orgId, $memberId, $createPo->id);

    // Taking a role AWAY can never complete a forbidden combination.
    setDirectoryAccessRole($memberId, $createPo->id, false)->assertSessionHasNoErrors();

    expect(RoleAssignment::query()->where('user_id', $memberId)->where('role_id', $createPo->id)->exists())->toBeFalse();
});

it('enforces an ENVIRONMENT-WIDE policy on a grant too', function (): void {
    [$orgId, $memberId] = sodEnforcementOrg();
    [$createPo, $approvePay] = sodEnforcementRoles($orgId);

    app(SegregationOfDuties::class)->definePolicy(null, 'Env-wide PO vs payment', [$createPo->id, $approvePay->id]);
    app(Roles::class)->assign($orgId, $memberId, $createPo->id);

    setDirectoryAccessRole($memberId, $approvePay->id, true)->assertSessionHasErrors('role');

    expect(RoleAssignment::query()->where('user_id', $memberId)->where('role_id', $approvePay->id)->exists())->toBeFalse();
});

/**
 * A parked invite grant is a grant, just deferred. The invitee holds nothing yet, so
 * per-role evaluation sees no conflict — it is the SET chosen for them that violates,
 * and by acceptance time the only place left to refuse is a redirect-only controller
 * with nowhere to say why.
 */
it('refuses an invitation whose chosen access roles are themselves a toxic combination', function (): void {
    Mail::fake();
    [$orgId] = sodEnforcementOrg();
    [$createPo, $approvePay] = sodEnforcementRoles($orgId);

    app(SegregationOfDuties::class)->definePolicy($orgId, 'PO vs payment', [$createPo->id, $approvePay->id]);

    inviteToDirectory(['accessRoles' => [$createPo->id, $approvePay->id]])
        ->assertSessionHasErrors('accessRoles');

    expect(InvitationRoleGrant::query()->where('email', 'newbie@acme.test')->exists())->toBeFalse();
    Mail::assertNothingSent();
});

it('accepts an invitation whose chosen access roles do not conflict', function (): void {
    Mail::fake();
    [$orgId] = sodEnforcementOrg();
    [$createPo, $approvePay] = sodEnforcementRoles($orgId);

    app(SegregationOfDuties::class)->definePolicy($orgId, 'PO vs payment', [$createPo->id, $approvePay->id]);

    inviteToDirectory(['accessRoles' => [$createPo->id]])->assertSessionHasNoErrors();

    expect(InvitationRoleGrant::query()->where('email', 'newbie@acme.test')->count())->toBe(1);
});

/**
 * Defence in depth: a policy defined between the invite and its acceptance must still
 * be honoured. The person joins; only the conflicting grant is withheld.
 */
it('withholds a parked grant that has become conflicting by the time it is accepted', function (): void {
    Mail::fake();
    [$orgId] = sodEnforcementOrg();
    [$createPo, $approvePay] = sodEnforcementRoles($orgId);

    $invitee = app(Subjects::class)->create('later@acme.test', 'Later', 'supersecret123');
    $invitation = app(Invitations::class)->invite($orgId, 'later@acme.test', MembershipRole::Member);

    // Keyed to THIS invitation: parked by (org, email) alone, a grant outlived the invite
    // that chose it and the next invitation to that address collected it.
    foreach ([$createPo->id, $approvePay->id] as $roleId) {
        InvitationRoleGrant::query()->create([
            'invitation_id' => $invitation->invitation->id,
            'organization_id' => $orgId,
            'email' => 'later@acme.test',
            'role_id' => $roleId,
        ]);
    }

    // The rule appears AFTER the invite was sent.
    app(SegregationOfDuties::class)->definePolicy($orgId, 'PO vs payment', [$createPo->id, $approvePay->id]);

    $this->get(route('invitation.accept', $invitation->token))->assertRedirect(route('dashboard'));

    // They joined, and hold exactly one of the two — never the forbidden pair.
    expect(app(Memberships::class)->of($orgId, $invitee->id))->not->toBeNull()
        ->and(RoleAssignment::query()->where('user_id', $invitee->id)->count())->toBe(1);
});

/**
 * An environment-wide policy is the control plane's own rule and binds every tenant.
 * An org admin who could switch it off could then grant themselves the very pair it
 * forbids — a complete bypass of the control, from inside the org console.
 */
it('does not let an org admin deactivate an environment-wide policy', function (): void {
    [$orgId] = sodEnforcementOrg();
    [$createPo, $approvePay] = sodEnforcementRoles($orgId);

    $envWide = app(SegregationOfDuties::class)->definePolicy(null, 'Env-wide PO vs payment', [$createPo->id, $approvePay->id]);

    // A TYPED URL, not just a hidden button: the id resolves to nothing within this
    // organization's own write set, so it 404s (deny-by-default) instead of toggling.
    test()->post(route('sod-policies.toggle', $envWide->id))->assertNotFound();
    test()->delete(route('sod-policies.destroy', $envWide->id))->assertNotFound();

    expect(SodPolicy::query()->whereKey($envWide->id)->value('active'))->toBeTrue();
});

it('shows an environment-wide policy as read-only rather than hiding it', function (): void {
    [$orgId] = sodEnforcementOrg();
    [$createPo, $approvePay] = sodEnforcementRoles($orgId);

    app(SegregationOfDuties::class)->definePolicy(null, 'Env-wide PO vs payment', [$createPo->id, $approvePay->id]);

    // The org must SEE what constrains it; it just cannot change it. Both halves are one
    // prop — a row drawn with `mayChange` false is a row with no switch on it.
    test()->get(route('sod-policies'))
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page->where(
            'rules',
            fn (Collection $rules): bool => $rules
                ->firstWhere('name', 'Env-wide PO vs payment') !== null
                && $rules->firstWhere('name', 'Env-wide PO vs payment')['mayChange'] === false
                && $rules->firstWhere('name', 'Env-wide PO vs payment')['owner'] === null,
        ));
});

it('still lets an org admin toggle its own policy', function (): void {
    [$orgId] = sodEnforcementOrg();
    [$createPo, $approvePay] = sodEnforcementRoles($orgId);

    $own = app(SegregationOfDuties::class)->definePolicy($orgId, 'Our PO vs payment', [$createPo->id, $approvePay->id]);

    test()->from(route('sod-policies'))->post(route('sod-policies.toggle', $own->id))
        ->assertSessionHasNoErrors();

    expect(SodPolicy::query()->whereKey($own->id)->value('active'))->toBeFalse();
});

/**
 * The structural half, and the one that would have caught the real gap.
 *
 * Segregation of duties is a PRE-GRANT gate the host must call. The console called it on
 * the Members page and on invitation acceptance — and skipped it on four other grant
 * paths, all of them on the environment-admin plane, where the most privileged
 * administrators work. An env admin could therefore create exactly the toxic combination
 * the Governance screen then reports: the control was advisory precisely where it
 * mattered most, and the tests above all drove the two paths that were already gated.
 *
 * Every console grant now goes through App\Platform\GrantAccessRole. This asserts that
 * no view reaches past it to the raw assign(), so a grant surface added next month is
 * covered by construction rather than by someone remembering.
 */
it('routes every console role grant through the segregation-of-duties gate', function (): void {
    $offenders = [];

    /*
     * THE GRANT SURFACES ARE CONTROLLERS. This swept `views/livewire/**` — where the
     * console's logic lived — and that directory is gone, so it was reporting a clean
     * sweep over nothing. Same rule, same question, asked of where the code went; the
     * module controllers are included because a module ships its own grant surface and
     * nobody enforcing this rule goes looking in one.
     */
    $sources = array_merge(
        (array) glob(app_path('Http/Controllers/*.php')),
        (array) glob(app_path('Http/Controllers/*/*.php')),
        (array) glob(base_path('modules/*/src/Http/Controllers/*.php')),
        (array) glob(base_path('modules/*/src/Http/Controllers/*/*.php')),
    );

    // Guard the guard: an empty sweep is a passing sweep, and the directory has moved once
    // already.
    expect(count($sources))->toBeGreaterThan(40, 'the grant sweep found almost no controllers; did they move?');

    foreach ($sources as $file) {
        if (! is_string($file)) {
            continue;
        }

        $source = (string) file_get_contents($file);

        // The membership verb is a different thing — belonging, not an RBAC grant — and
        // has its own rules. This is about `Roles::assign()`.
        // Call-level, not file-level: a file that imports the service and still has one
        // raw call left is exactly the half-migration this is meant to catch. Matched on
        // the CALL rather than on a variable name, because a controller injects the
        // service by type and may call it anything.
        /*
         * `Roles::assign()` BY ITS SIGNATURE, not by the variable it is called on. A
         * controller injects the service by type and may name it anything, and the file
         * next door calls `$passwords->assign(new AdminPasswordAssignment(…))` — a
         * different verb entirely, which a name-shaped match reported as a role grant.
         * The `GrantSource` argument is what makes this call the one under the rule.
         */
        if (preg_match('/->assign\([^;]*GrantSource::/s', $source) === 1) {
            $offenders[] = str_replace(base_path().'/', '', $file);
        }
    }

    expect($offenders)->toBe([], 'grants a role without the SoD gate: '.implode(', ', $offenders));
});
