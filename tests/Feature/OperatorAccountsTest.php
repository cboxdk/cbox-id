<?php

declare(strict_types=1);

use App\Platform\OperatorAuth;
use Cbox\Id\Kernel\Audit\Enums\ActorType;
use Cbox\Id\Kernel\Audit\Models\AuditEntry;
use Cbox\Id\Platform\AccountProvisioner;
use Cbox\Id\Platform\Contracts\PlatformOperators;
use Cbox\Id\Platform\Enums\AccountStatus;
use Cbox\Id\Platform\Models\Account;
use Cbox\Id\Platform\ValueObjects\AccountBlueprint;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Volt\Volt;

uses(RefreshDatabase::class);

/**
 * `Accounts::suspend()` was fully implemented and had zero callers: there was no
 * operator screen for accounts at all, so a junk or abusive signup could not be turned
 * off from anywhere. This screen is that caller.
 */
beforeEach(function (): void {
    $operator = app(PlatformOperators::class)->create('accounts-op@platform.test', 'a-strong-operator-pass', 'Op');
    session([OperatorAuth::SESSION_KEY => $operator->id]);
    $this->operatorId = $operator->id;

    platformRootEnvironment();
});

function suspendableAccount(string $email = 'junk@signup.example'): Account
{
    return app(AccountProvisioner::class)->provision(new AccountBlueprint(
        accountName: 'Junk Signup',
        ownerEmail: $email,
        ownerName: 'Junk',
        ownerPassword: 'a-strong-unbreached-passphrase',
    ))->account;
}

it('lists every account with its members, projects and environments', function (): void {
    $account = suspendableAccount();

    $rows = collect(Volt::test('operator.accounts')->viewData('rows'))->keyBy('id');

    expect($rows)->toHaveKey($account->id)
        ->and($rows[$account->id]['name'])->toBe('Junk Signup')
        ->and($rows[$account->id]['active'])->toBeTrue()
        ->and($rows[$account->id]['members'])->toBe(1)
        ->and($rows[$account->id]['projects'])->toBe(1)
        ->and($rows[$account->id]['environments'])->toBe(1);
});

it('suspends and reactivates an account, and the toggle is reversible', function (): void {
    $account = suspendableAccount();

    Volt::test('operator.accounts')->call('toggleStatus', $account->id)->assertRenderedNotRedirected();
    expect(Account::query()->whereKey($account->id)->value('status'))->toBe(AccountStatus::Suspended);

    Volt::test('operator.accounts')->call('toggleStatus', $account->id)->assertRenderedNotRedirected();
    expect(Account::query()->whereKey($account->id)->value('status'))->toBe(AccountStatus::Active);
});

it('records both directions on the account’s own audit chain, as the operator', function (): void {
    $account = suspendableAccount();

    Volt::test('operator.accounts')->call('toggleStatus', $account->id);
    Volt::test('operator.accounts')->call('toggleStatus', $account->id);

    $entries = AuditEntry::query()
        ->where('scope', $account->id)
        ->whereIn('action', ['account.suspended', 'account.reactivated'])
        ->orderBy('sequence')
        ->get();

    expect($entries)->toHaveCount(2)
        ->and($entries[0]->action)->toBe('account.suspended')
        ->and($entries[0]->actor_type)->toBe(ActorType::Operator)
        ->and($entries[0]->actor_id)->toBe($this->operatorId)
        ->and($entries[0]->target_id)->toBe($account->id)
        ->and($entries[0]->context['to'] ?? null)->toBe('suspended')
        ->and($entries[1]->action)->toBe('account.reactivated')
        ->and($entries[1]->context['to'] ?? null)->toBe('active');
});

it('refuses the screen, and the toggle, without an operator session', function (): void {
    $account = suspendableAccount();
    session()->forget(OperatorAuth::SESSION_KEY);

    $this->get(route('operator.accounts'))->assertRedirect(route('operator.login'));

    // boot() — not mount() — carries the check, so it re-runs on every Livewire action
    // too and a crafted wire request cannot reach the toggle.
    Volt::test('operator.accounts')->assertForbidden();

    expect(Account::query()->whereKey($account->id)->value('status'))->toBe(AccountStatus::Active);
});
