<?php

use App\Platform\PlatformAuth;
use Cbox\Id\Identity\Contracts\Subjects;
use Cbox\Id\Organization\Contracts\Memberships;
use Cbox\Id\Organization\Contracts\Organizations;
use Cbox\Id\Organization\Enums\MembershipRole;
use Cbox\Id\Organization\ValueObjects\NewOrganization;
use Illuminate\Support\Collection;
use Inertia\Testing\AssertableInertia;

it('renders the account chooser', function () {
    $s = app(Subjects::class)->create('chooser@test.dev', 'Chooser User', 'supersecret123');
    $o = app(Organizations::class)->create(new NewOrganization('Acme', 'acme-chz'));
    app(Memberships::class)->add($o->id, $s->id, MembershipRole::Owner);
    app(PlatformAuth::class)->establish(request(), $s->id, ['pwd']);

    // The LIST, which is what the chooser is: the words around it are the page's and
    // changing them is not a regression. What must hold is that the browser's held
    // identities reach the page — a chooser that renders an empty list is a dead end
    // somebody signed in to reach.
    test()->get(route('accounts'))
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->component('auth/accounts')
            ->where('accounts', fn (Collection $accounts): bool => $accounts
                ->pluck('email')
                ->contains('chooser@test.dev')));
});
