<?php

declare(strict_types=1);

use Cbox\Id\Identity\Contracts\Subjects;
use Cbox\Id\Organization\Contracts\Memberships;
use Cbox\Id\Organization\Enums\MembershipRole;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Http;
use Inertia\Testing\AssertableInertia;

uses(RefreshDatabase::class);

beforeEach(fn () => Http::fake(['api.pwnedpasswords.com/*' => Http::response('', 200)]));

/**
 * Every mutating action on the members page, refused for a plain member.
 *
 * The guards are all present and all working. What was missing was any test of them: the
 * page has no `boot()` or `mount()` read gate — deliberately, since members may see who
 * they work with — so `authorizeAdmin()` at the head of each mutating method is the ONLY
 * defence, and deleting all six left 1008 tests green.
 *
 * That is not a live vulnerability; it is the shape that becomes one. The obvious tidy-up
 * on this file is to hoist six identical `authorizeAdmin()` calls into a `boot()`, and a
 * hoist that misses one, or a seventh action added later without it, would let a plain
 * member promote themselves to admin with nothing going red.
 *
 * EVERY WRITE THE PAGE OFFERS, one request each.
 *
 * The dataset is hand-kept, and that is a step down from what it replaces: a component's
 * public methods can be enumerated by reflection, and a controller's cannot be told apart
 * from its own helpers. What replaces the completeness is the shape of the thing — each
 * write is its own ROUTE now, so a new one is a line in `routes/web.php` beside these
 * rather than a method somewhere in a 600-line file, and the page's own `assertAdmin()`
 * is the first statement of every one of them.
 */
it('refuses every mutating action on the members page to a plain member', function (string $verb, string $name, string $target, array $payload): void {
    [$memberId, $org] = actingAsRole(MembershipRole::Member);

    $victim = app(Subjects::class)->create('victim@acme.test', 'Victim', 'supersecret123');
    app(Memberships::class)->add($org->id, $victim->id, MembershipRole::Member);

    $parameters = match ($target) {
        '{self}' => ['member' => $memberId],
        '{victim}' => ['member' => $victim->id],
        '{none}' => [],
        default => ['invitation' => $target],
    };

    test()->from(route('directory.members'))
        ->{$verb}(route($name, $parameters), $payload)
        ->assertForbidden();

    // AND NOTHING MOVED. A 403 from somewhere else in the stack would satisfy the status
    // assertion above; the roles staying put is the property.
    expect(app(Memberships::class)->of($org->id, $memberId)?->role)->toBe(MembershipRole::Member)
        ->and(app(Memberships::class)->of($org->id, $victim->id)?->role)->toBe(MembershipRole::Member);
})->with([
    // The one that matters most: a member handing themselves the admin role.
    'role — self-promotion' => ['patch', 'directory.members.role', '{self}', ['role' => 'admin']],
    'role — promoting someone else' => ['patch', 'directory.members.role', '{victim}', ['role' => 'admin']],
    'access' => ['post', 'directory.members.access', '{victim}', ['role' => 'some-role-id', 'granted' => true]],
    'remove' => ['delete', 'directory.members.remove', '{victim}', []],
    'invite' => ['post', 'directory.members.invite', '{none}', ['email' => 'x@acme.test', 'role' => 'member']],
    'revoke an invitation' => ['delete', 'directory.members.invitations.revoke', 'no-such-invitation', []],
])->group('security');

/**
 * And the roster itself stays readable — the absence of a read gate is a decision, not an
 * oversight, and a test that "fixed" it by adding one would break the page for everyone
 * it is meant to serve.
 */
it('still lets a plain member see who they work with', function (): void {
    actingAsRole(MembershipRole::Member);

    test()->get(route('directory.members'))
        ->assertOk()
        // …and they see the roster, not an empty page that happens to be a 200.
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->where('members', fn (Collection $rows): bool => $rows->isNotEmpty())
            // The controls are not offered, which is what makes the refusals above a
            // consistent product rather than a page full of buttons that 403.
            ->where('isAdmin', false)
            ->where('invitations', []));
});

/**
 * The same actions succeed for an admin, so the tests above prove authorization rather
 * than that the methods are broken.
 */
it('lets an admin change a role', function (): void {
    [, $org] = actingAsRole(MembershipRole::Owner);

    $target = app(Subjects::class)->create('target@acme.test', 'Target', 'supersecret123');
    app(Memberships::class)->add($org->id, $target->id, MembershipRole::Member);

    test()->from(route('directory.members'))
        ->patch(route('directory.members.role', $target->id), ['role' => 'admin'])
        ->assertSessionHasNoErrors();

    expect(app(Memberships::class)->forUser($target->id)->first()?->role)
        ->toBe(MembershipRole::Admin);
});
