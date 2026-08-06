<?php

declare(strict_types=1);

use App\Platform\OrganizationActivity;
use Cbox\Id\Kernel\Audit\Models\AuditEntry;
use Cbox\Id\Organization\Contracts\Memberships;
use Cbox\Id\Organization\Enums\MembershipRole;
use Cbox\Id\Organization\Models\Environment;
use Cbox\Id\Organization\Models\Membership;
use Cbox\Id\Organization\Models\Organization;
use Cbox\Id\Platform\Models\Project;
use Cbox\Id\Platform\TenantProvisioner;
use Cbox\Id\Platform\ValueObjects\TenantBlueprint;
use Livewire\Volt\Volt;

// Guarded so they coexist with the same helpers in WorkspaceConsoleTest (Pest
// requires each test file independently — the first definition wins).
if (! function_exists('provisionAccount')) {
    /**
     * @return array{member: Membership, account: Account, project: Project, environment: Environment}
     */
    function provisionAccount(string $email = 'owner@acme.example'): array
    {
        // The platform root FIRST, and STOOD IN. An account provisioned without one is
        // in the first-install bootstrap window, where its members have no subject and a
        // member with no subject has nothing to sign in — and the account chain itself is
        // environment-owned, so a test that records entries from outside the root files
        // them where the account host will not read them.
        platformRootDeployment();

        $result = app(TenantProvisioner::class)->provision(new TenantBlueprint(
            organizationName: 'Acme',
            ownerEmail: $email,
            ownerName: 'Owner',
            ownerPassword: 'a-strong-unbreached-passphrase',
        ));

        return ['member' => $result->membership, 'subjectId' => $result->owner->id, 'organization' => $result->organization, 'project' => $result->project, 'environment' => $result->environment];
    }
}

if (! function_exists('memberWithRole')) {
    function memberWithRole(string $accountId, MembershipRole $role, string $email): Membership
    {
        $members = app(Memberships::class);
        [$m, $mSubjectId] = addMember($accountId, $role, $email);

        return freshMembership($m);
    }
}

it('records an account-scoped, hash-chained entry when a member is invited', function (): void {
    ['member' => $owner, 'organization' => $account] = provisionAccount();

    // The page action funnels through OrganizationActivity; drive it through the real
    // Livewire component (deps are auto-injected) with the owner as the actor.
    signInAsMember($owner->user_id);

    Volt::test('console.members')
        ->set('inviteEmail', 'newbie@acme.example')
        ->set('inviteName', 'New Bie')
        ->set('inviteRole', MembershipRole::Admin->value)
        ->call('invite');

    // The event chained under the ACCOUNT id as scope, isolated to this account.
    $entry = AuditEntry::query()->where('scope', $account->id)
        ->where('action', 'organization.member_invited')->firstOrFail();

    expect($entry->actor_id)->toBe($owner->id)
        ->and($entry->target_type)->toBe('account_member')
        ->and($entry->context['email'])->toBe('newbie@acme.example')
        ->and($entry->sequence)->toBeGreaterThanOrEqual(1);
});

it('records environment key mint + revoke on the account chain', function (): void {
    ['member' => $owner, 'organization' => $account, 'environment' => $env] = provisionAccount();
    $activity = app(OrganizationActivity::class);

    // Record directly via the service (the page action funnels through it).
    $activity->record($account->id, 'organization.environment_key_created', $owner->id,
        targetType: 'environment', targetId: $env->id, context: ['name' => 'CI']);
    $activity->record($account->id, 'organization.environment_key_revoked', $owner->id,
        targetType: 'environment', targetId: $env->id, context: ['key_id' => 'k_1']);

    $recent = $activity->recent($account->id);

    // Newest first, and gap-free monotonic sequence within the account chain.
    expect($recent->first()->action)->toBe('organization.environment_key_revoked')
        ->and($recent->pluck('action'))->toContain('organization.environment_key_created')
        ->and($recent->pluck('sequence')->sort()->values()->all())->toBe(range(1, $recent->count()));
});

it('keeps one account activity chain from leaking into another', function (): void {
    ['organization' => $a, 'member' => $ownerA] = provisionAccount('a@acme.example');
    ['organization' => $b] = provisionAccount('b@beta.example');
    $activity = app(OrganizationActivity::class);

    $activity->record($a->id, 'organization.environment_created', $ownerA->id, targetType: 'environment', targetId: 'e1');

    expect($activity->recent($a->id))->toHaveCount(1)
        ->and($activity->recent($b->id))->toHaveCount(0);
});

it('renders the activity page for an admin and lists recorded actions', function (): void {
    ['member' => $owner, 'organization' => $account, 'environment' => $env] = provisionAccount();
    app(OrganizationActivity::class)->record($account->id, 'organization.environment_created', $owner->id,
        targetType: 'environment', targetId: $env->id, context: ['name' => 'Staging']);

    signInAsMember($owner->user_id);
    $this->get(route('activity'))
        ->assertOk()
        ->assertSee('Account activity')
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
    $viewer = memberWithRole($account->id, MembershipRole::Developer, 'dev@acme.example');

    signInAsMember($viewer->user_id);
    $this->get(route('activity'))
        ->assertForbidden();
});
