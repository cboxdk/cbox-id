<?php

declare(strict_types=1);

use App\Platform\CurrentUser;
use App\Platform\PlatformAuth;
use Cbox\Id\Identity\Contracts\SessionManager;
use Cbox\Id\Identity\Contracts\Subjects;
use Cbox\Id\Kernel\Usage\Contracts\UsageMeter;
use Cbox\Id\Organization\Contracts\Memberships;
use Cbox\Id\Organization\Contracts\Organizations;
use Cbox\Id\Organization\Enums\MembershipRole;
use Cbox\Id\Organization\ValueObjects\NewOrganization;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Collection;
use Inertia\Testing\AssertableInertia;

uses(RefreshDatabase::class);

function usageOwner(): string
{
    $owner = app(Subjects::class)->create('usage@acme.test', 'Uma Owner', 'supersecret123');
    $org = app(Organizations::class)->create(new NewOrganization('Acme', 'acme-usage'));
    app(Memberships::class)->add($org->id, $owner->id, MembershipRole::Owner);
    $session = app(SessionManager::class)->start($owner->id, $org->id, ['pwd']);
    app(CurrentUser::class)->set($owner, $session, $org, MembershipRole::Owner);

    // AND THE SESSION KEY THE CONSOLE'S GUARD READS. `CurrentUser` is resolved state for
    // code already inside the process, which is all a Volt component ever needed; the page
    // is reached by a REQUEST now, and without this it answers a redirect to /login — which
    // an assertion about what the page shows would pass against happily.
    session([PlatformAuth::SESSION_KEY => $session->id]);

    return $org->id;
}

it('renders the usage dashboard with recorded metrics under human labels', function (): void {
    $orgId = usageOwner();
    app(UsageMeter::class)->record('auth.login', 5, $orgId);
    app(UsageMeter::class)->record('auth.id_token', 12, $orgId);

    test()->get(route('usage'))
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page
            // The HUMAN LABEL and the RAW KEY, on the same row. The key is not decoration:
            // whoever reads this page may be the one who has to go and find the counter.
            ->where('metrics', fn (Collection $rows): bool => $rows
                ->firstWhere('key', 'auth.login') === ['key' => 'auth.login', 'label' => 'Sign-ins', 'total' => 5])
            ->where('metrics', fn (Collection $rows): bool => $rows
                ->firstWhere('key', 'auth.id_token') === ['key' => 'auth.id_token', 'label' => 'Tokens issued', 'total' => 12]));
});

it('shows an empty state when there is no usage yet', function (): void {
    usageOwner();

    // The empty STATE is the empty metric list — the sentence that renders from it is in
    // the component, and the browser suite is what can see whether it is drawn.
    test()->get(route('usage'))
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page->where('metrics', []));
});

it('scopes usage to the current organization', function (): void {
    $orgId = usageOwner();
    app(UsageMeter::class)->record('auth.login', 3, $orgId);
    app(UsageMeter::class)->record('auth.login', 99, 'some-other-org');

    // The dashboard reads only this org's counters (3), never the other org's 99 — and
    // never their sum. Asserted on the NUMBER rather than on the rendered page: a bare "99"
    // matches all sorts of incidental markup, which is how the earlier spelling of this
    // test came to be flaky.
    test()->get(route('usage'))
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->where('metrics', fn (Collection $rows): bool => $rows->firstWhere('key', 'auth.login')['total'] === 3));
});
