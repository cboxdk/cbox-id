<?php

use App\Platform\PlatformAuth;
use Cbox\Id\Identity\Contracts\Subjects;
use Cbox\Id\Organization\Contracts\Memberships;
use Cbox\Id\Organization\Contracts\Organizations;
use Cbox\Id\Organization\ValueObjects\NewOrganization;
use Livewire\Volt\Volt;

it('renders the account chooser', function () {
    $s = app(Subjects::class)->create('chooser@test.dev', 'Chooser User', 'supersecret123');
    $o = app(Organizations::class)->create(new NewOrganization('Acme', 'acme-chz'));
    app(Memberships::class)->add($o->id, $s->id, 'owner');
    app(PlatformAuth::class)->establish(request(), $s->id, ['pwd']);

    Volt::test('auth.accounts')->assertOk()->assertSee('Choose an account')->assertSee('Add another account');
});
