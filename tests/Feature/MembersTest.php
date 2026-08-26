<?php

declare(strict_types=1);

use Cbox\Id\Identity\Contracts\Subjects;
use Cbox\Id\Organization\Contracts\Invitations;
use Cbox\Id\Organization\Contracts\Memberships;
use Cbox\Id\Organization\Enums\MembershipRole;
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
    // error rides back to the page it was posted from and is announced there.
    test()->from(route('directory.members'))
        ->delete(route('directory.members.remove', $meId))
        ->assertSessionHasErrors(['member' => 'You cannot remove yourself.']);

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
