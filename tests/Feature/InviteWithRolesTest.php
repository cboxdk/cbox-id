<?php

declare(strict_types=1);

use App\Models\InvitationRoleGrant;
use Cbox\Id\AccessControl\Contracts\Roles;
use Cbox\Id\AccessControl\Models\RoleAssignment;
use Cbox\Id\Identity\Contracts\Subjects;
use Cbox\Id\Organization\Contracts\Invitations;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Livewire\Volt\Volt;

uses(RefreshDatabase::class);

it('parks chosen access roles at invite time', function (): void {
    Mail::fake();
    [, $org] = actingAsRole('owner');
    $role = app(Roles::class)->define($org->id, 'Editor');

    Volt::test('members')
        ->set('inviteEmail', 'newbie@acme.test')
        ->set('inviteRole', 'member')
        ->set('inviteAccessRoles', [$role->id])
        ->call('invite')
        ->assertHasNoErrors();

    expect(InvitationRoleGrant::query()
        ->where('organization_id', $org->id)
        ->where('email', 'newbie@acme.test')
        ->where('role_id', $role->id)
        ->exists())->toBeTrue();
});

it('applies parked access roles when the invitation is accepted', function (): void {
    Mail::fake();
    [, $org] = actingAsRole('owner');
    $role = app(Roles::class)->define($org->id, 'Editor');

    $pending = app(Invitations::class)->invite($org->id, 'newbie@acme.test', 'member');
    InvitationRoleGrant::query()->create([
        'organization_id' => $org->id,
        'email' => 'newbie@acme.test',
        'role_id' => $role->id,
    ]);

    $this->get('/invitations/'.$pending->token.'/accept')->assertRedirect();

    $subject = app(Subjects::class)->findByEmail('newbie@acme.test');
    expect($subject)->not->toBeNull()
        ->and(RoleAssignment::query()
            ->where('organization_id', $org->id)
            ->where('user_id', $subject->id)
            ->where('role_id', $role->id)
            ->exists())->toBeTrue()
        // The parked grants are cleared after applying.
        ->and(InvitationRoleGrant::query()->where('email', 'newbie@acme.test')->exists())->toBeFalse();
});
