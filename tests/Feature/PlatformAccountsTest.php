<?php

declare(strict_types=1);

use App\Platform\OrganizationActivity;
use Cbox\Id\Kernel\Audit\Enums\ActorType;
use Cbox\Id\Kernel\Audit\Models\AuditEntry;
use Cbox\Id\Platform\TenantProvisioner;
use Cbox\Id\Platform\Contracts\PlatformOperators;
use Cbox\Id\Organization\Enums\OrganizationStatus;
use Cbox\Id\Organization\Models\Organization;
use Cbox\Id\Platform\ValueObjects\TenantBlueprint;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Volt\Volt;

uses(RefreshDatabase::class);

/**
 * `Accounts::suspend()` was fully implemented and had zero callers: there was no
 * operator screen for accounts at all, so a junk or abusive signup could not be turned
 * off from anywhere. This screen is that caller.
 */
beforeEach(function (): void {
    $operator = actAsOperator('accounts-op@platform.test');
    $this->operatorId = $operator->id;

    platformRootEnvironment();
});

function suspendableAccount(string $email = 'junk@signup.example'): Account
{
    return app(TenantProvisioner::class)->provision(new TenantBlueprint(
        accountName: 'Junk Signup',
        ownerEmail: $email,
        ownerName: 'Junk',
        ownerPassword: 'a-strong-unbreached-passphrase',
    ))->account;
}

it('lists every account with its members, projects and environments', function (): void {
    $account = suspendableAccount();

    $rows = collect(Volt::test('platform.accounts')->viewData('rows'))->keyBy('id');

    expect($rows)->toHaveKey($account->id)
        ->and($rows[$account->id]['name'])->toBe('Junk Signup')
        ->and($rows[$account->id]['active'])->toBeTrue()
        ->and($rows[$account->id]['members'])->toBe(1)
        ->and($rows[$account->id]['projects'])->toBe(1)
        ->and($rows[$account->id]['environments'])->toBe(1);
});

it('suspends and reactivates an account, and the toggle is reversible', function (): void {
    $account = suspendableAccount();

    Volt::test('platform.accounts')->call('toggleStatus', $account->id)->assertRenderedNotRedirected();
    expect(Account::query()->whereKey($account->id)->value('status'))->toBe(OrganizationStatus::Suspended);

    Volt::test('platform.accounts')->call('toggleStatus', $account->id)->assertRenderedNotRedirected();
    expect(Account::query()->whereKey($account->id)->value('status'))->toBe(OrganizationStatus::Active);
});

/**
 * The entries land on the SYSTEM chain, not the account's own.
 *
 * This screen used to write the audit itself, scoped to the account id — matching
 * {@see OrganizationActivity}, which reads `where('scope', $accountId)` so an
 * account's trail explains why it went dark. laravel-id v0.64.0 moved the audit inside
 * `Accounts::suspend()`, which scopes it to the system chain because an account sits
 * ABOVE the tenancy boundary — consistent with `PlatformOperators`, which does the same.
 *
 * The package's scoping is the right one and this test follows it rather than forcing
 * the package to match the app. The cost is real and is tracked separately: the account
 * activity view no longer surfaces its own suspension, so it needs to read system-chain
 * entries that target the account. Asserting the old scope here would have hidden that.
 */
it('records both directions on the system chain, as the operator', function (): void {
    $account = suspendableAccount();

    Volt::test('platform.accounts')->call('toggleStatus', $account->id);
    Volt::test('platform.accounts')->call('toggleStatus', $account->id);

    $entries = AuditEntry::query()
        ->whereIn('action', ['account.suspended', 'account.reactivated'])
        ->orderBy('sequence')
        ->get();

    expect($entries)->toHaveCount(2)
        ->and($entries[0]->action)->toBe('account.suspended')
        ->and($entries[0]->actor_type)->toBe(ActorType::Operator)
        ->and($entries[0]->actor_id)->toBe($this->operatorId)
        ->and($entries[0]->target_id)->toBe($account->id)
        ->and($entries[0]->context['status'] ?? null)->toBe('suspended')
        ->and($entries[1]->action)->toBe('account.reactivated')
        ->and($entries[1]->context['status'] ?? null)->toBe('active');

    // The account's own chain is now empty for these actions — the gap this documents.
    expect(AuditEntry::query()->where('scope', $account->id)
        ->whereIn('action', ['account.suspended', 'account.reactivated'])->count())->toBe(0);
});

it('refuses the screen, and the toggle, without operator authority', function (): void {
    $account = suspendableAccount();
    forgetSubjectSession();

    // `login`, not `workspace.login`. The suite's baseline is a single-host install, and
    // `workspace.login` carries `plane:account` — false when there is no host split, by
    // design — so pointing a self-hosted operator there points them at a 404. The gate
    // asks the deployment shape; see AuthenticateOperator::signInRoute().
    $this->get(route('platform.accounts'))->assertRedirect(route('login'));

    // boot() — not mount() — carries the check, so it re-runs on every Livewire action
    // too and a crafted wire request cannot reach the toggle.
    Volt::test('platform.accounts')->assertStatus(404);

    expect(Account::query()->whereKey($account->id)->value('status'))->toBe(OrganizationStatus::Active);
});
