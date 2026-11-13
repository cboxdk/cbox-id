<?php

declare(strict_types=1);

use Cbox\Id\Identity\Contracts\SessionManager;
use Cbox\Id\Identity\Contracts\Subjects;
use Cbox\Id\Identity\Models\MfaFactor;
use Cbox\Id\Organization\Contracts\Invitations;
use Cbox\Id\Organization\Contracts\Memberships;
use Cbox\Id\Organization\Contracts\Organizations;
use Cbox\Id\Organization\Enums\MembershipRole;
use Cbox\Id\Organization\ValueObjects\NewOrganization;
use Cbox\Id\Platform\PlatformRoot;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\URL;

/*
|--------------------------------------------------------------------------
| An account nobody has confirmed never receives a membership
|--------------------------------------------------------------------------
|
| Signup makes a usable account for any typed address; the address stays unconfirmed
| until the link in the inbox is opened. An invitation is redeemed by a link only the
| inbox receives, so the person redeeming it owns the address — and an unconfirmed
| account found under that address is handed to them, with everything set on it before
| that proof withdrawn. A door that proves nothing (an administrator typing an address)
| refuses an unconfirmed account instead.
*/

beforeEach(fn () => Http::fake(['api.pwnedpasswords.com/*' => Http::response('', 200)]));

it('hands an unconfirmed account to the person who accepted the organization invitation', function (): void {
    [, $org] = actingAsRole(MembershipRole::Owner);

    $earlier = app(Subjects::class)->create('invitee@acme.test', 'Earlier', 'earlier-password-123');
    $earlierSession = app(SessionManager::class)->start($earlier->id, null, ['pwd']);
    MfaFactor::query()->create([
        'user_id' => $earlier->id,
        'type' => 'totp',
        'secret_encrypted' => 'sealed',
        'confirmed_at' => now(),
    ]);
    expect($earlier->emailVerified)->toBeFalse();

    $pending = app(Invitations::class)->invite($org->id, 'invitee@acme.test', MembershipRole::Admin);

    $this->post('/invitations/'.$pending->token.'/accept')->assertRedirect(route('dashboard'));

    $subject = app(Subjects::class)->find($earlier->id);

    expect(app(Memberships::class)->of($org->id, $earlier->id)?->role)->toBe(MembershipRole::Admin)
        ->and($subject?->emailVerified)->toBeTrue()
        ->and(app(Subjects::class)->verifyPassword($earlier->id, 'earlier-password-123'))->toBeFalse()
        ->and(app(SessionManager::class)->active($earlierSession->id))->toBeNull()
        ->and(MfaFactor::query()->where('user_id', $earlier->id)->exists())->toBeFalse();
});

it('leaves a confirmed account as it is when its owner accepts an invitation', function (): void {
    [, $org] = actingAsRole(MembershipRole::Owner);

    $owner = app(Subjects::class)->create('confirmed@acme.test', 'Confirmed', 'confirmed-password-123');
    app(Subjects::class)->markEmailVerified($owner->id, 'confirmed@acme.test');

    $pending = app(Invitations::class)->invite($org->id, 'confirmed@acme.test', MembershipRole::Member);

    $this->post('/invitations/'.$pending->token.'/accept')->assertRedirect(route('dashboard'));

    expect(app(Memberships::class)->of($org->id, $owner->id)?->role)->toBe(MembershipRole::Member)
        ->and(app(Subjects::class)->verifyPassword($owner->id, 'confirmed-password-123'))->toBeTrue();
});

it('hands an unconfirmed account to the person who accepted a team invitation, with the password they chose', function (): void {
    $target = provisionAccount('target-owner@invite.example');

    $earlier = app(PlatformRoot::class)->run(
        fn () => app(Subjects::class)->create('newcomer@invite.example', 'Earlier', 'earlier-password-123'),
    );
    $earlierSession = app(PlatformRoot::class)->run(
        fn () => app(SessionManager::class)->start($earlier->id, null, ['pwd']),
    );

    $pending = app(PlatformRoot::class)->run(fn () => app(Invitations::class)->invite(
        $target['organization']->id, 'newcomer@invite.example', MembershipRole::Developer,
    ));
    $accept = URL::temporarySignedRoute('organization.invite.accept.store', now()->addDay(), ['token' => $pending->token]);

    $this->post($accept, ['password' => 'a-strong-unbreached-passphrase'])->assertRedirect(route('dashboard'));

    app(PlatformRoot::class)->run(function () use ($earlier, $earlierSession, $target): void {
        expect(app(Memberships::class)->of($target['organization']->id, $earlier->id)?->role)->toBe(MembershipRole::Developer)
            ->and(app(Subjects::class)->find($earlier->id)?->emailVerified)->toBeTrue()
            ->and(app(Subjects::class)->verifyPassword($earlier->id, 'earlier-password-123'))->toBeFalse()
            ->and(app(Subjects::class)->verifyPassword($earlier->id, 'a-strong-unbreached-passphrase'))->toBeTrue()
            ->and(app(SessionManager::class)->active($earlierSession->id))->toBeNull();
    });
});

it('refuses to add an unconfirmed account by address from the environment console', function (): void {
    Mail::fake();
    crudSetup();
    $org = app(Organizations::class)->create(new NewOrganization(name: 'Tenant U', slug: 'tenant-u'));
    $user = app(Subjects::class)->create('dave@acme.example', 'Dave', 'earlier-password-123');

    addOrganizationMember($org->id, ['role' => 'admin'])->assertSessionHasErrors('email');

    expect(app(Memberships::class)->of($org->id, $user->id))->toBeNull();
});

it('still adds an account the administrator picked by id', function (): void {
    Mail::fake();
    crudSetup();
    $org = app(Organizations::class)->create(new NewOrganization(name: 'Tenant V', slug: 'tenant-v'));
    $user = app(Subjects::class)->create('erin@acme.example', 'Erin');

    assignUserToOrganization($user->id, $org->id)->assertSessionHasNoErrors();

    expect(app(Memberships::class)->of($org->id, $user->id)?->role)->toBe(MembershipRole::Member);
});
