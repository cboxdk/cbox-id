<?php

declare(strict_types=1);

use App\Platform\CurrentUser;
use Cbox\Id\Identity\Contracts\SessionManager;
use Cbox\Id\Identity\Contracts\Subjects;
use Cbox\Id\Kernel\Usage\Contracts\UsageMeter;
use Cbox\Id\Organization\Contracts\Memberships;
use Cbox\Id\Organization\Contracts\Organizations;
use Cbox\Id\Organization\ValueObjects\NewOrganization;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Volt\Volt;

uses(RefreshDatabase::class);

function usageOwner(): string
{
    $owner = app(Subjects::class)->create('usage@acme.test', 'Uma Owner', 'supersecret123');
    $org = app(Organizations::class)->create(new NewOrganization('Acme', 'acme-usage'));
    app(Memberships::class)->add($org->id, $owner->id, 'owner');
    $session = app(SessionManager::class)->start($owner->id, $org->id, ['pwd']);
    app(CurrentUser::class)->set($owner, $session, $org, 'owner');

    return $org->id;
}

it('renders the usage dashboard with recorded metrics under human labels', function (): void {
    $orgId = usageOwner();
    app(UsageMeter::class)->record('auth.login', 5, $orgId);
    app(UsageMeter::class)->record('auth.id_token', 12, $orgId);

    Volt::test('usage')
        ->assertSee('Sign-ins')       // human label for auth.login
        ->assertSee('Tokens issued')  // human label for auth.id_token
        ->assertSee('auth.login')     // the raw shared metric key
        ->assertSee('12');
});

it('shows an empty state when there is no usage yet', function (): void {
    usageOwner();

    Volt::test('usage')->assertSee('No activity recorded yet');
});

it('scopes usage to the current organization', function (): void {
    $orgId = usageOwner();
    app(UsageMeter::class)->record('auth.login', 3, $orgId);
    app(UsageMeter::class)->record('auth.login', 99, 'some-other-org');

    // The dashboard reads only this org's counters (3), never the other org's 99.
    // Assert the DATA, not the rendered HTML: a bare "99" also matches Livewire's
    // random wire:key/wire:id attributes (CI hit `wire:key="lw-2049943276-0"`), which
    // made assertDontSee('99') flaky. The snapshot is the thing being scoped.
    Volt::test('usage')
        ->assertSee('Sign-ins')
        ->assertViewHas('snapshot', fn (array $snapshot): bool => (int) ($snapshot['auth.login'] ?? 0) === 3);
});
