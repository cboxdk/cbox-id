<?php

declare(strict_types=1);

use App\Platform\CurrentUser;
use App\Platform\PlatformAuth;
use Cbox\Id\Identity\Contracts\Subjects;
use Cbox\Id\Organization\Contracts\Invitations;
use Cbox\Id\Organization\Contracts\Memberships;
use Livewire\Volt\Volt;

it('grants membership only after the invitee accepts the emailed link', function () {
    [, $org] = actingAsRole('owner');
    $pending = app(Invitations::class)->invite($org->id, 'joiner@acme.test', 'member', invitedBy: app(CurrentUser::class)->id());

    // No membership yet — only a pending invitation.
    expect(app(Memberships::class)->forOrganization($org->id))->toHaveCount(1);

    $this->get('/invitations/'.$pending->token.'/accept')->assertRedirect(route('dashboard'));

    $subject = app(Subjects::class)->findByEmail('joiner@acme.test');
    expect($subject)->not->toBeNull()
        ->and(app(Memberships::class)->of($org->id, $subject->id)?->role?->value)->toBe('member')
        ->and(session()->has(PlatformAuth::SESSION_KEY))->toBeTrue();
});

it('rejects an unknown or reused invitation token', function () {
    [, $org] = actingAsRole('owner');
    $pending = app(Invitations::class)->invite($org->id, 'once@acme.test', 'member');

    $this->get('/invitations/'.$pending->token.'/accept')->assertRedirect(route('dashboard'));

    // Reusing the token fails.
    $this->get('/invitations/'.$pending->token.'/accept')->assertRedirect(route('login'));
    $this->get('/invitations/inv_does_not_exist/accept')->assertRedirect(route('login'));
});

it('lets an admin revoke a pending invitation', function () {
    [, $org] = actingAsRole('owner');
    $pending = app(Invitations::class)->invite($org->id, 'revoke@acme.test', 'member');

    Volt::test('members')->call('revokeInvitation', $pending->invitation->id);

    expect(app(Invitations::class)->pending($org->id))->toBeEmpty();
});
