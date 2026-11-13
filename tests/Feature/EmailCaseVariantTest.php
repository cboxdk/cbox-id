<?php

declare(strict_types=1);

use Cbox\Id\Identity\Contracts\Subjects;
use Cbox\Id\Identity\Models\User;
use Cbox\Id\Organization\Contracts\Memberships;
use Cbox\Id\Organization\Contracts\Organizations;
use Cbox\Id\Organization\Enums\MembershipRole;
use Cbox\Id\Organization\ValueObjects\NewOrganization;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;

/*
|--------------------------------------------------------------------------
| An address is one address, whatever its case
|--------------------------------------------------------------------------
|
| The account store matched addresses byte for byte, so `DAVE@acme.example` and
| `dave@acme.example` could be two accounts, and a lookup by address returned whichever
| row the database produced first. An outsider who signed up a case variant ahead of the
| real person then received the membership an administrator gave that person by address.
*/

beforeEach(fn () => Http::fake(['api.pwnedpasswords.com/*' => Http::response('', 200)]));

it('finds the account whatever case the address is typed in', function (): void {
    $subject = app(Subjects::class)->create('dave@acme.example', 'Dave');

    expect(app(Subjects::class)->findByEmail('DAVE@Acme.Example')?->id)->toBe($subject->id)
        ->and(app(Subjects::class)->findByEmail('nobody@acme.example'))->toBeNull();
});

it('refuses a signup for a case variant of an address that already has an account', function (): void {
    app(Subjects::class)->create('dave@acme.example', 'Dave', 'a-strong-unbreached-passphrase');

    $this->from(route('signup'))->post(route('signup.register'), [
        'organization' => 'Not Dave Inc.', 'name' => 'Not Dave', 'email' => 'DAVE@acme.example', 'password' => 'a-strong-unbreached-passphrase',
    ])->assertSessionHasErrors('email');

    expect(User::query()->whereRaw('lower(email) = ?', ['dave@acme.example'])->count())->toBe(1);
});

it('adds the confirmed account, not a case variant registered ahead of it', function (): void {
    Mail::fake();
    crudSetup();
    $org = app(Organizations::class)->create(new NewOrganization(name: 'Tenant W', slug: 'tenant-w'));

    // Variants that predate canonical lookups: the squatter's first, then the real person's.
    $squatter = app(Subjects::class)->create('DAVE@acme.example', 'Squatter', 'squatter-password-123');
    $dave = User::query()->create(['email' => 'dave@acme.example', 'name' => 'Dave']);
    app(Subjects::class)->markEmailVerified($dave->id, 'dave@acme.example');

    addOrganizationMember($org->id, ['role' => 'admin'])->assertSessionHasNoErrors();

    expect(app(Memberships::class)->of($org->id, $dave->id)?->role)->toBe(MembershipRole::Admin)
        ->and(app(Memberships::class)->of($org->id, $squatter->id))->toBeNull();
});

it('asks for the user id when several confirmed accounts share the address and none is spelled as typed', function (): void {
    Mail::fake();
    crudSetup();
    $org = app(Organizations::class)->create(new NewOrganization(name: 'Tenant X', slug: 'tenant-x'));

    foreach (['DAVE@acme.example', 'Dave@acme.example'] as $address) {
        $user = User::query()->create(['email' => $address, 'name' => 'Dave']);
        app(Subjects::class)->markEmailVerified($user->id, $address);
    }

    addOrganizationMember($org->id, ['role' => 'admin'])->assertSessionHasErrors('email');

    expect(app(Memberships::class)->forOrganization($org->id)->pluck('user_id')->all())
        ->not->toContain(...User::query()->pluck('id')->all());
});
