<?php

declare(strict_types=1);

use App\Platform\OrganizationActivity;
use Cbox\Id\Kernel\Audit\Models\AuditEntry;
use Cbox\Id\Organization\Enums\MembershipRole;
use Cbox\Id\Organization\Models\Membership;
use Cbox\Id\Organization\Models\Organization;
use Cbox\Id\Platform\PlatformRoot;
use Livewire\Volt\Volt;

// Guarded so they coexist with the same helpers in WorkspaceConsoleTest (Pest
// requires each test file independently — the first definition wins).
it('records an account-scoped, hash-chained entry when a member is invited', function (): void {
    ['member' => $owner, 'subjectId' => $ownerSubjectId, 'organization' => $account] = provisionAccount();

    // The page action funnels through OrganizationActivity; drive it through the real
    // Livewire component (deps are auto-injected) with the owner as the actor.
    signInAsMember($ownerSubjectId);

    Volt::test('console.members')
        ->set('inviteEmail', 'newbie@acme.example')
        ->set('inviteName', 'New Bie')
        ->set('inviteRole', MembershipRole::Admin->value)
        ->call('invite');

    // Chained under the ORGANIZATION id as scope, isolated to this customer. Read in the
    // platform root, where the chain lives — an audit entry is environment-owned, and the
    // ambient scope answers with an empty set that reads exactly like "nothing recorded".
    $entry = app(PlatformRoot::class)->run(fn () => AuditEntry::query()->where('scope', $account->id)
        ->where('action', 'organization.member_invited')->firstOrFail());

    expect($entry->actor_id)->toBe($ownerSubjectId)
        ->and($entry->target_type)->toBe('invitation')
        ->and($entry->context['email'])->toBe('newbie@acme.example')
        ->and($entry->sequence)->toBeGreaterThanOrEqual(1);
});

it('records environment key mint + revoke on the account chain', function (): void {
    ['member' => $owner, 'subjectId' => $ownerSubjectId, 'organization' => $account, 'environment' => $env] = provisionAccount();
    $activity = app(OrganizationActivity::class);

    // Record directly via the service (the page action funnels through it).
    $activity->record($account->id, 'organization.environment_key_created', $ownerSubjectId,
        targetType: 'environment', targetId: $env->id, context: ['name' => 'CI']);
    $activity->record($account->id, 'organization.environment_key_revoked', $ownerSubjectId,
        targetType: 'environment', targetId: $env->id, context: ['key_id' => 'k_1']);

    $recent = $activity->recent($account->id);

    // Newest first, and gap-free monotonic sequence within the account chain.
    expect($recent->first()->action)->toBe('organization.environment_key_revoked')
        ->and($recent->pluck('action'))->toContain('organization.environment_key_created')
        ->and($recent->pluck('sequence')->sort()->values()->all())->toBe(range(1, $recent->count()));
});

it('keeps one account activity chain from leaking into another', function (): void {
    ['organization' => $a, 'member' => $ownerA, 'subjectId' => $ownerASubjectId] = provisionAccount('a@acme.example');
    ['organization' => $b] = provisionAccount('b@beta.example');
    $activity = app(OrganizationActivity::class);

    $activity->record($a->id, 'organization.environment_created', $ownerASubjectId, targetType: 'environment', targetId: 'e1');

    // The entry landed on A's chain and NOT on B's, asked of the one action this test
    // wrote. Provisioning also writes to a customer's chain — the organization is created
    // through the contract, which audits — so counting everything would make this a "did
    // anything land" test rather than an isolation one.
    expect($activity->recent($a->id)->where('action', 'organization.environment_created'))->toHaveCount(1)
        ->and($activity->recent($b->id)->where('action', 'organization.environment_created'))->toHaveCount(0);
});

it('renders the activity page for an admin and lists recorded actions', function (): void {
    ['member' => $owner, 'subjectId' => $ownerSubjectId, 'organization' => $account, 'environment' => $env] = provisionAccount();
    app(OrganizationActivity::class)->record($account->id, 'organization.environment_created', $ownerSubjectId,
        targetType: 'environment', targetId: $env->id, context: ['name' => 'Staging']);

    signInAsMember($ownerSubjectId);
    $this->get(route('activity'))
        ->assertOk()
        ->assertSee('Activity')
        ->assertSee('environment created')
        ->assertSee('Staging');
});

it('refuses the activity page to a member who cannot read members (403)', function (): void {
    ['organization' => $account] = provisionAccount();
    // A DEVELOPER, not a Billing member. Billing was the role this pinned, and it is no
    // longer assignable: an account is an organization now, the capability comes from the
    // membership, and Billing maps to Viewer — who may read the roster. Developer is the
    // reachable role that still refuses it, and refuses it for the reason that matters: a
    // technical credential must not enumerate the team.
    [$viewer, $viewerSubjectId] = memberWithRole($account->id, MembershipRole::Developer, 'dev@acme.example');

    signInAsMember($viewerSubjectId);
    $this->get(route('activity'))
        ->assertForbidden();
});
