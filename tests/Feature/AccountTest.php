<?php

declare(strict_types=1);

use App\Platform\CurrentUser;
use Cbox\Id\Identity\Contracts\SessionManager;
use Cbox\Id\Identity\Contracts\Subjects;
use Cbox\Id\Organization\Contracts\Memberships;
use Cbox\Id\Organization\Contracts\Organizations;
use Cbox\Id\Organization\Enums\MembershipRole;
use Cbox\Id\Organization\ValueObjects\NewOrganization;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Volt\Volt;

uses(RefreshDatabase::class);

/** Sign a subject in on the org plane and return their id. */
function signInToAccount(): string
{
    $subject = app(Subjects::class)->create('me@acme.test', 'Original Name', 'a-strong-unbreached-passphrase');
    $org = app(Organizations::class)->create(new NewOrganization('Acme', 'acme-account'));
    app(Memberships::class)->add($org->id, $subject->id, MembershipRole::Owner);
    $session = app(SessionManager::class)->start($subject->id, $org->id, ['pwd']);
    app(CurrentUser::class)->set($subject, $session, $org, MembershipRole::Owner);

    return $subject->id;
}

/**
 * A person can change their own name.
 *
 * The Profile panel was a definition list — so the name someone is addressed by on every
 * screen, and the label stamped on their passkeys, could only be changed by an
 * administrator, or not at all if they had none. That is the most ordinary self-service
 * edit there is, and it was simply missing.
 */
it('lets a signed-in person rename themselves', function (): void {
    $id = signInToAccount();

    Volt::test('account')
        ->set('displayName', 'Sylvester Damgaard')
        ->call('saveProfile')
        ->assertHasNoErrors();

    expect(app(Subjects::class)->find($id)?->name)->toBe('Sylvester Damgaard');
});

it('refuses to blank out a name', function (): void {
    $id = signInToAccount();

    Volt::test('account')
        ->set('displayName', '   ')
        ->call('saveProfile')
        ->assertHasErrors('displayName');

    expect(app(Subjects::class)->find($id)?->name)->toBe('Original Name');
});

/**
 * The email is deliberately NOT editable here. It is the sign-in identifier, and letting
 * it change without re-proving control of the new address is how an account is taken
 * over — so it belongs in a verification flow, not a text field on this page. Asserted
 * so that adding one later is a decision someone makes on purpose.
 */
it('keeps the email read-only on the account page', function (): void {
    $markup = (string) file_get_contents(
        __DIR__.'/../../resources/views/livewire/account.blade.php'
    );

    expect($markup)->toContain('<dt>Email</dt>');
    expect(preg_match('/wire:model="[^"]*[Ee]mail"/', $markup))
        ->toBe(0, 'the sign-in identifier became editable without a verification step');
});
