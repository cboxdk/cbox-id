<?php

declare(strict_types=1);

use App\Platform\AccountActivity;
use App\Platform\AccountAuth;
use Cbox\Id\Kernel\Audit\Models\AuditEntry;
use Cbox\Id\Organization\Models\Environment;
use Cbox\Id\Platform\AccountProvisioner;
use Cbox\Id\Platform\Contracts\AccountMembers;
use Cbox\Id\Platform\Enums\AccountRole;
use Cbox\Id\Platform\Models\Account;
use Cbox\Id\Platform\Models\AccountMember;
use Cbox\Id\Platform\Models\Project;
use Cbox\Id\Platform\ValueObjects\AccountBlueprint;
use Livewire\Volt\Volt;

// Guarded so they coexist with the same helpers in WorkspaceConsoleTest (Pest
// requires each test file independently — the first definition wins).
if (! function_exists('provisionAccount')) {
    /**
     * @return array{member: AccountMember, account: Account, project: Project, environment: Environment}
     */
    function provisionAccount(string $email = 'owner@acme.example'): array
    {
        $result = app(AccountProvisioner::class)->provision(new AccountBlueprint(
            accountName: 'Acme',
            ownerEmail: $email,
            ownerName: 'Owner',
            ownerPassword: 'a-strong-unbreached-passphrase',
        ));

        return ['member' => $result->member, 'account' => $result->account, 'project' => $result->project, 'environment' => $result->environment];
    }
}

if (! function_exists('memberWithRole')) {
    function memberWithRole(string $accountId, AccountRole $role, string $email): AccountMember
    {
        $members = app(AccountMembers::class);
        $m = $members->invite($accountId, $email, $role);
        $members->activate($m->id, 'a-strong-unbreached-passphrase');

        return $members->find($m->id);
    }
}

it('records an account-scoped, hash-chained entry when a member is invited', function (): void {
    ['member' => $owner, 'account' => $account] = provisionAccount();

    // The page action funnels through AccountActivity; drive it through the real
    // Livewire component (deps are auto-injected) with the owner as the actor.
    $this->withSession([AccountAuth::SESSION_KEY => $owner->id]);

    Volt::test('workspace.members')
        ->set('inviteEmail', 'newbie@acme.example')
        ->set('inviteName', 'New Bie')
        ->set('inviteRole', AccountRole::Admin->value)
        ->call('invite');

    // The event chained under the ACCOUNT id as scope, isolated to this account.
    $entry = AuditEntry::query()->where('scope', $account->id)
        ->where('action', 'account.member_invited')->firstOrFail();

    expect($entry->actor_id)->toBe($owner->id)
        ->and($entry->target_type)->toBe('account_member')
        ->and($entry->context['email'])->toBe('newbie@acme.example')
        ->and($entry->sequence)->toBeGreaterThanOrEqual(1);
});

it('records environment key mint + revoke on the account chain', function (): void {
    ['member' => $owner, 'account' => $account, 'environment' => $env] = provisionAccount();
    $activity = app(AccountActivity::class);

    // Record directly via the service (the page action funnels through it).
    $activity->record($account->id, 'account.environment_key_created', $owner->id,
        targetType: 'environment', targetId: $env->id, context: ['name' => 'CI']);
    $activity->record($account->id, 'account.environment_key_revoked', $owner->id,
        targetType: 'environment', targetId: $env->id, context: ['key_id' => 'k_1']);

    $recent = $activity->recent($account->id);

    // Newest first, and gap-free monotonic sequence within the account chain.
    expect($recent->first()->action)->toBe('account.environment_key_revoked')
        ->and($recent->pluck('action'))->toContain('account.environment_key_created')
        ->and($recent->pluck('sequence')->sort()->values()->all())->toBe(range(1, $recent->count()));
});

it('keeps one account activity chain from leaking into another', function (): void {
    ['account' => $a, 'member' => $ownerA] = provisionAccount('a@acme.example');
    ['account' => $b] = provisionAccount('b@beta.example');
    $activity = app(AccountActivity::class);

    $activity->record($a->id, 'account.environment_created', $ownerA->id, targetType: 'environment', targetId: 'e1');

    expect($activity->recent($a->id))->toHaveCount(1)
        ->and($activity->recent($b->id))->toHaveCount(0);
});

it('renders the activity page for an admin and lists recorded actions', function (): void {
    ['member' => $owner, 'account' => $account, 'environment' => $env] = provisionAccount();
    app(AccountActivity::class)->record($account->id, 'account.environment_created', $owner->id,
        targetType: 'environment', targetId: $env->id, context: ['name' => 'Staging']);

    $this->withSession([AccountAuth::SESSION_KEY => $owner->id])
        ->get(route('workspace.activity'))
        ->assertOk()
        ->assertSee('Activity')
        ->assertSee('environment created')
        ->assertSee('Staging');
});

it('refuses the activity page to a member who cannot read members (403)', function (): void {
    ['account' => $account] = provisionAccount();
    $viewer = memberWithRole($account->id, AccountRole::Billing, 'billing@acme.example');

    $this->withSession([AccountAuth::SESSION_KEY => $viewer->id])
        ->get(route('workspace.activity'))
        ->assertForbidden();
});
