<?php

declare(strict_types=1);

use App\Platform\CurrentUser;
use Cbox\Id\Identity\Contracts\SessionManager;
use Cbox\Id\Identity\Contracts\Subjects;
use Cbox\Id\Organization\Contracts\Invitations;
use Cbox\Id\Organization\Contracts\Memberships;
use Cbox\Id\Organization\Contracts\Organizations;
use Cbox\Id\Organization\Models\Organization;
use Cbox\Id\Organization\ValueObjects\NewOrganization;
use Livewire\Volt\Volt;

/**
 * Populate CurrentUser as the Authenticate middleware would, then drive the
 * component directly.
 *
 * @return array{0: string, 1: Organization}
 */
function actingAsRole(string $role): array
{
    $subject = app(Subjects::class)->create($role.'@acme.test', ucfirst($role), 'supersecret123');
    $org = app(Organizations::class)->create(new NewOrganization('Acme', 'acme-'.$role));
    app(Memberships::class)->add($org->id, $subject->id, $role);
    $session = app(SessionManager::class)->start($subject->id, $org->id, ['pwd']);
    app(CurrentUser::class)->set($subject, $session, $org, $role);

    return [$subject->id, $org];
}

it('creates a pending invitation without granting membership', function () {
    [, $org] = actingAsRole('owner');

    Volt::test('members')
        ->set('inviteEmail', 'newbie@acme.test')
        ->set('inviteRole', 'member')
        ->call('invite')
        ->assertHasNoErrors();

    expect(app(Invitations::class)->pending($org->id)->pluck('email'))
        ->toContain('newbie@acme.test')
        // Only the owner is a member — the invitee has not accepted yet.
        ->and(app(Memberships::class)->forOrganization($org->id))->toHaveCount(1);
});

it('changes a member role and removes a member', function () {
    [, $org] = actingAsRole('owner');
    $target = app(Subjects::class)->create('target@acme.test', 'Target');
    app(Memberships::class)->add($org->id, $target->id, 'member');

    Volt::test('members')->call('setRole', $target->id, 'admin');
    expect(app(Memberships::class)->of($org->id, $target->id)?->role)->toBe('admin');

    Volt::test('members')->call('remove', $target->id);
    expect(app(Memberships::class)->of($org->id, $target->id))->toBeNull();
});

it('will not let an admin remove themselves', function () {
    [$meId, $org] = actingAsRole('owner');

    Volt::test('members')->call('remove', $meId)->assertHasErrors('inviteEmail');

    expect(app(Memberships::class)->of($org->id, $meId))->not->toBeNull();
});

it('forbids a plain member from inviting', function () {
    actingAsRole('member');

    Volt::test('members')
        ->set('inviteEmail', 'x@acme.test')
        ->set('inviteRole', 'member')
        ->call('invite')
        ->assertForbidden();
});

it('forbids an admin from demoting or removing the org owner', function () {
    [, $org] = actingAsRole('admin');
    // Seed an existing owner in the same org.
    $owner = app(Subjects::class)->create('theowner@acme.test', 'Owner', 'supersecret123');
    app(Memberships::class)->add($org->id, $owner->id, 'owner');

    Volt::test('members')->call('setRole', $owner->id, 'member')->assertStatus(403);
    Volt::test('members')->call('remove', $owner->id)->assertStatus(403);

    // The owner is untouched.
    expect(app(Memberships::class)->of($org->id, $owner->id)?->role)->toBe('owner');
});

it('paginates the member roster instead of hydrating it whole', function () {
    [, $org] = actingAsRole('owner');
    $memberships = app(Memberships::class);
    foreach (range(1, 30) as $i) {
        $memberships->add($org->id, "member_{$i}", 'member');
    }

    $component = Volt::test('members');

    // 31 members (owner + 30) at 25/page: the first page renders 25 rows, not 31.
    expect($component->viewData('members')->total())->toBe(31)
        ->and($component->viewData('rows'))->toHaveCount(25);
});
