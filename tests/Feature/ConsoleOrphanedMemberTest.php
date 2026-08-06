<?php

declare(strict_types=1);

use App\Platform\CurrentUser;
use Cbox\Id\Governance\Contracts\AccessReviews;
use Cbox\Id\Identity\Contracts\SessionManager;
use Cbox\Id\Identity\Contracts\Subjects;
use Cbox\Id\Organization\Contracts\Organizations;
use Cbox\Id\Organization\Enums\MembershipRole;
use Cbox\Id\Organization\ValueObjects\NewOrganization;
use Livewire\Volt\Volt;

/**
 * An organization-plane session with no organization.
 *
 * The console reads are all written as `when($id !== null, fn ($q) => $q->where(...))`,
 * so what null MEANS is load-bearing. It used to mean two things — "an environment
 * administrator has not chosen yet" and "this member has no organization" — and the
 * second one handed the unfiltered query to somebody whose membership had gone.
 */
it('does not show an orphaned organization admin another organization data', function (): void {
    // Somebody else's campaign.
    $theirs = app(Organizations::class)->create(new NewOrganization('Theirs', 'theirs-org'));
    app(AccessReviews::class)->open($theirs->id, 'Their private review', now()->addWeek(), createdBy: 'x');

    // An admin whose membership has gone — the org was deleted, or they were removed.
    // CurrentUser::organizationId() is null for them, exactly as it is for an environment
    // admin who has not picked. The console must NOT read those two as the same thing.
    $orphan = app(Subjects::class)->create('orphan@acme.test', 'Orphan', 'a-strong-unbreached-passphrase');
    $session = app(SessionManager::class)->start($orphan->id, null, ['pwd']);
    app(CurrentUser::class)->set($orphan, $session, null, MembershipRole::Owner);

    Volt::test('console.governance.index')->assertDontSee('Their private review');
});
