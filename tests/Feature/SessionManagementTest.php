<?php

declare(strict_types=1);

use App\Platform\CurrentUser;
use App\Platform\Sudo;
use Cbox\Id\Identity\Contracts\SessionManager;
use Cbox\Id\Identity\Contracts\Subjects;
use Cbox\Id\Identity\Models\Session;
use Cbox\Id\Organization\Contracts\Memberships;
use Cbox\Id\Organization\Contracts\Organizations;
use Cbox\Id\Organization\ValueObjects\NewOrganization;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Volt\Volt;

uses(RefreshDatabase::class);

it('signs out every other session but keeps the current one', function (): void {
    $subject = app(Subjects::class)->create('multi@acme.test', 'Multi Device', 'a-strong-unbreached-passphrase');
    $org = app(Organizations::class)->create(new NewOrganization('Acme', 'acme-multi'));
    app(Memberships::class)->add($org->id, $subject->id, 'owner');

    $sessions = app(SessionManager::class);
    $current = $sessions->start($subject->id, $org->id, ['pwd']);
    $other1 = $sessions->start($subject->id, $org->id, ['pwd']);
    $other2 = $sessions->start($subject->id, $org->id, ['pwd']);

    app(CurrentUser::class)->set($subject, $current, $org, 'owner');
    app(Sudo::class)->confirm(); // sensitive action

    Volt::test('settings')->call('signOutOtherSessions')->assertHasNoErrors();

    // Current stays active; the two others are revoked.
    expect(Session::query()->whereKey($current->id)->value('revoked_at'))->toBeNull()
        ->and(Session::query()->whereKey($other1->id)->value('revoked_at'))->not->toBeNull()
        ->and(Session::query()->whereKey($other2->id)->value('revoked_at'))->not->toBeNull();
});

it('requires step-up before signing out other sessions', function (): void {
    $subject = app(Subjects::class)->create('multi2@acme.test', 'Multi Device', 'a-strong-unbreached-passphrase');
    $org = app(Organizations::class)->create(new NewOrganization('Acme', 'acme-multi2'));
    app(Memberships::class)->add($org->id, $subject->id, 'owner');
    $current = app(SessionManager::class)->start($subject->id, $org->id, ['pwd']);
    $other = app(SessionManager::class)->start($subject->id, $org->id, ['pwd']);
    app(CurrentUser::class)->set($subject, $current, $org, 'owner');

    // No sudo confirmation -> redirected, nothing revoked.
    Volt::test('settings')->call('signOutOtherSessions')->assertRedirect(route('sudo'));

    expect(Session::query()->whereKey($other->id)->value('revoked_at'))->toBeNull();
});
