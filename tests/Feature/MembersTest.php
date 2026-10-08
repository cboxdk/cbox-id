<?php

declare(strict_types=1);

use App\Platform\Actions\ActionDefinition;
use App\Platform\Actions\ActionPlane;
use App\Platform\Actions\ActionRegistry;
use App\Platform\Actions\ActionRunner;
use App\Platform\Actions\Principal\DelegatedTokenPrincipal;
use App\Platform\OAuth\ValueObjects\OrganizationChoice;
use Cbox\Id\AccessControl\Contracts\Roles;
use Cbox\Id\Identity\Contracts\Subjects;
use Cbox\Id\Organization\Contracts\Invitations;
use Cbox\Id\Organization\Contracts\Memberships;
use Cbox\Id\Organization\Enums\MembershipRole;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Collection;
use Inertia\Testing\AssertableInertia;

it('creates a pending invitation without granting membership', function () {
    [, $org] = actingAsRole(MembershipRole::Owner);

    inviteToDirectory(['email' => 'newbie@acme.test'])->assertSessionHasNoErrors();

    expect(app(Invitations::class)->pending($org->id)->pluck('email'))
        ->toContain('newbie@acme.test')
        // Only the owner is a member — the invitee has not accepted yet.
        ->and(app(Memberships::class)->forOrganization($org->id))->toHaveCount(1);
});

it('changes a member role and removes a member', function () {
    [, $org] = actingAsRole(MembershipRole::Owner);
    $target = app(Subjects::class)->create('target@acme.test', 'Target');
    app(Memberships::class)->add($org->id, $target->id, MembershipRole::Member);

    test()->from(route('directory.members'))
        ->patch(route('directory.members.role', $target->id), ['role' => 'admin'])
        ->assertSessionHasNoErrors();

    expect(app(Memberships::class)->of($org->id, $target->id)?->role?->value)->toBe('admin');

    test()->from(route('directory.members'))
        ->delete(route('directory.members.remove', $target->id))
        ->assertSessionHasNoErrors();

    expect(app(Memberships::class)->of($org->id, $target->id))->toBeNull();
});

it('will not let an admin remove themselves', function () {
    [$meId, $org] = actingAsRole(MembershipRole::Owner);

    // The refusal SAYS SO, rather than being a control that silently does nothing: the
    // error rides back to the page it was posted from and is announced there — and it
    // names the verb that does exist for this, rather than just "no".
    test()->from(route('directory.members'))
        ->delete(route('directory.members.remove', $meId))
        ->assertSessionHasErrors(['member' => 'To remove yourself, use "Leave organization".']);

    expect(app(Memberships::class)->of($org->id, $meId))->not->toBeNull();
});

it('forbids a plain member from inviting', function () {
    actingAsRole(MembershipRole::Member);

    inviteToDirectory(['email' => 'x@acme.test'])->assertForbidden();
});

it('forbids an admin from demoting or removing the org owner', function () {
    [, $org] = actingAsRole(MembershipRole::Admin);
    // Seed an existing owner in the same org.
    $owner = app(Subjects::class)->create('theowner@acme.test', 'Owner', 'supersecret123');
    app(Memberships::class)->add($org->id, $owner->id, MembershipRole::Owner);

    test()->from(route('directory.members'))
        ->patch(route('directory.members.role', $owner->id), ['role' => 'member'])
        ->assertStatus(403);

    test()->from(route('directory.members'))
        ->delete(route('directory.members.remove', $owner->id))
        ->assertStatus(403);

    // The owner is untouched.
    expect(app(Memberships::class)->of($org->id, $owner->id)?->role?->value)->toBe('owner');
});

it('paginates the member roster instead of hydrating it whole', function () {
    [, $org] = actingAsRole(MembershipRole::Owner);
    $memberships = app(Memberships::class);
    foreach (range(1, 30) as $i) {
        $memberships->add($org->id, "member_{$i}", MembershipRole::Member);
    }

    // 31 members (owner + 30) at 25/page: the first page carries 25 rows, not 31 — and the
    // paginator still knows there are 31, which is what makes the page a page.
    test()->get(route('directory.members'))
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->where('pagination.total', 31)
            ->where('members', fn (Collection $rows): bool => $rows->count() === 25));
});

/**
 * A ROSTER, NOT THE AUTHORIZATION MODEL.
 *
 * A plain member may see who else is here and at which tier. Which app roles each
 * colleague holds, and every role's permission catalogue, are an administrator's: the page
 * used to ship both to every member and rely on the client to hide them, which a browser's
 * dev tools undo in one click.
 */
it('shows a plain member the roster without access roles or permissions', function () {
    [$memberId, $org] = actingAsRole(MembershipRole::Member);

    $role = app(Roles::class)->define($org->id, 'Billing approver');
    app(Roles::class)->grantPermission($org->id, $role->id, 'invoices.approve');

    $colleague = app(Subjects::class)->create('colleague@acme.test', 'Colleague');
    app(Memberships::class)->add($org->id, $colleague->id, MembershipRole::Admin);
    app(Roles::class)->assign($org->id, $colleague->id, $role->id);

    $props = test()->get(route('directory.members'))->assertOk()->inertiaProps();

    // Names, addresses and tiers, exactly as before.
    $rows = collect($props['members'])->keyBy('id');
    expect($props['isAdmin'])->toBeFalse()
        ->and($rows[$colleague->id]['email'])->toBe('colleague@acme.test')
        ->and($rows[$colleague->id]['name'])->toBe('Colleague')
        ->and($rows[$colleague->id]['role'])->toBe('admin')
        ->and($rows[$memberId]['role'])->toBe('member');

    // …and nothing of the authorization model: no role catalogue, no permissions, and no
    // colleague's assignments.
    expect($props['accessRoles'])->toBe([])
        ->and($rows->pluck('accessRoleIds')->flatten()->all())->toBe([])
        ->and(json_encode($props))->not->toContain('invoices.approve')
        ->and(json_encode($props))->not->toContain('Billing approver');
});

it('still shows an admin every access role, its permissions and who holds it', function () {
    [, $org] = actingAsRole(MembershipRole::Admin);

    $role = app(Roles::class)->define($org->id, 'Billing approver');
    app(Roles::class)->grantPermission($org->id, $role->id, 'invoices.approve');

    $colleague = app(Subjects::class)->create('colleague@acme.test', 'Colleague');
    app(Memberships::class)->add($org->id, $colleague->id, MembershipRole::Member);
    app(Roles::class)->assign($org->id, $colleague->id, $role->id);

    $props = test()->get(route('directory.members'))->assertOk()->inertiaProps();

    $offered = collect($props['accessRoles'])->keyBy('id');
    expect($offered->has($role->id))->toBeTrue()
        ->and($offered[$role->id]['permissions'])->toContain('invoices.approve')
        ->and(collect($props['members'])->keyBy('id')[$colleague->id]['accessRoleIds'])->toBe([$role->id]);
});

/**
 * AND NOT THROUGH ANY OTHER DOOR. The People page is one way to read a colleague's access
 * roles; the management API and MCP are others, and a plain member can sign an agent in.
 * Every read that names members, their roles or the role catalogue is the environment's or
 * an administrator's: a plain member's signed-in token — every scope granted — reaches none
 * of them, over the runner every door shares, the REST API and MCP alike.
 */
it('keeps every member, role and permission read away from a plain member\'s signed-in token', function () {
    [$memberId, $org] = actingAsRole(MembershipRole::Member);

    $role = app(Roles::class)->define($org->id, 'Billing approver');
    $colleague = app(Subjects::class)->create('colleague@acme.test', 'Colleague');
    app(Memberships::class)->add($org->id, $colleague->id, MembershipRole::Admin);
    app(Roles::class)->assign($org->id, $colleague->id, $role->id);

    $everyScope = array_values(array_unique(array_map(
        static fn (ActionDefinition $action): string => $action->scope,
        app(ActionRegistry::class)->forPlane(ActionPlane::Environment),
    )));

    $token = new DelegatedTokenPrincipal(
        subjectId: $memberId,
        personName: 'Member',
        clientId: 'member-cli',
        clientName: 'Member CLI',
        environmentId: 'env_test',
        scopes: $everyScope,
        organization: new OrganizationChoice($org->id, $org->name, MembershipRole::Member),
        customerConsole: false,
    );

    $reachable = [];

    foreach (app(ActionRegistry::class)->forPlane(ActionPlane::Environment) as $action) {
        try {
            $token->authorize($action);
            $reachable[] = $action->name;
        } catch (AuthorizationException) {
        }
    }

    // Not one environment action — so no member list, no member's roles, no role or
    // permission catalogue, whichever door the token is presented at.
    expect($reachable)->toBe([]);

    // Over the runner itself, the lock every door shares: the roster and a colleague's roles.
    $runner = app(ActionRunner::class);

    expect(fn () => $runner->run(app(ActionRegistry::class)->named('members.list'), $token, ['organization_id' => $org->id]))
        ->toThrow(AuthorizationException::class)
        ->and(fn () => $runner->run(app(ActionRegistry::class)->named('members.roles.list'), $token, ['organization_id' => $org->id, 'user_id' => $colleague->id]))
        ->toThrow(AuthorizationException::class);
})->group('security');
