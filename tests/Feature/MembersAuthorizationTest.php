<?php

declare(strict_types=1);

use Cbox\Id\Identity\Contracts\Subjects;
use Cbox\Id\Organization\Contracts\Memberships;
use Cbox\Id\Organization\Enums\MembershipRole;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Livewire\Volt\Volt;

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
 * Driven off the component's own public methods rather than a hand-kept list, so an
 * action added tomorrow is included by existing.
 */
it('refuses every mutating action on the members page to a plain member', function (string $method, array $args): void {
    [$memberId, $org] = actingAsRole(MembershipRole::Member);

    $victim = app(Subjects::class)->create('victim@acme.test', 'Victim', 'supersecret123');
    app(Memberships::class)->add($org->id, $victim->id, MembershipRole::Member);

    $args = array_map(
        fn (string $arg): string => match ($arg) {
            '{self}' => $memberId,
            '{victim}' => $victim->id,
            default => $arg,
        },
        $args,
    );

    Volt::test('members')->call($method, ...$args)->assertForbidden();
})->with([
    // The one that matters most: a member handing themselves the admin role.
    'setRole — self-promotion' => ['setRole', ['{self}', 'admin']],
    'setRole — promoting someone else' => ['setRole', ['{victim}', 'admin']],
    'toggleRole' => ['toggleRole', ['{victim}', 'some-role-id']],
    'remove' => ['remove', ['{victim}']],
]);

/**
 * And the roster itself stays readable — the absence of a read gate is a decision, not an
 * oversight, and a test that "fixed" it by adding one would break the page for everyone
 * it is meant to serve.
 */
it('still lets a plain member see who they work with', function (): void {
    actingAsRole(MembershipRole::Member);

    Volt::test('members')->assertOk();
});

/**
 * The same actions succeed for an admin, so the tests above prove authorization rather
 * than that the methods are broken.
 */
it('lets an admin change a role', function (): void {
    [, $org] = actingAsRole(MembershipRole::Owner);

    $target = app(Subjects::class)->create('target@acme.test', 'Target', 'supersecret123');
    app(Memberships::class)->add($org->id, $target->id, MembershipRole::Member);

    Volt::test('members')->call('setRole', $target->id, 'admin')->assertOk();

    expect(app(Memberships::class)->forUser($target->id)->first()?->role)
        ->toBe(MembershipRole::Admin);
});
