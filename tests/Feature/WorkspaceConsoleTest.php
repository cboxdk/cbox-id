<?php

declare(strict_types=1);

use App\Platform\AccountAuth;
use Cbox\Id\Organization\Models\Environment;
use Cbox\Id\Platform\AccountProvisioner;
use Cbox\Id\Platform\Contracts\AccountApiKeys;
use Cbox\Id\Platform\Contracts\AccountMembers;
use Cbox\Id\Platform\Contracts\Accounts;
use Cbox\Id\Platform\Enums\AccountRole;
use Cbox\Id\Platform\Models\Account;
use Cbox\Id\Platform\Models\AccountMember;
use Cbox\Id\Platform\ValueObjects\AccountBlueprint;
use Livewire\Volt\Volt;

/** Invite + activate a member with a role, returning them signed-in-ready. */
if (! function_exists('memberWithRole')) {
    function memberWithRole(string $accountId, AccountRole $role, string $email): AccountMember
    {
        $members = app(AccountMembers::class);
        $m = $members->invite($accountId, $email, $role);
        $members->activate($m->id, 'a-strong-unbreached-passphrase');

        return $members->find($m->id);
    }
}

if (! function_exists('provisionAccount')) {
    /**
     * Provision an account and return its member/account/environment, for signing in.
     *
     * @return array{member: AccountMember, account: Account, environment: Environment}
     */
    function provisionAccount(string $email = 'owner@acme.example'): array
    {
        $result = app(AccountProvisioner::class)->provision(new AccountBlueprint(
            accountName: 'Acme',
            ownerEmail: $email,
            ownerName: 'Owner',
            ownerPassword: 'a-strong-unbreached-passphrase',
        ));

        return ['member' => $result->member, 'account' => $result->account, 'environment' => $result->environment];
    }
}

it('renders the workspace sign-in for guests', function (): void {
    $this->get(route('workspace.login'))
        ->assertOk()
        ->assertSee('Sign in to your workspace');
});

it('redirects guests away from the workspace home', function (): void {
    $this->get(route('workspace.home'))->assertRedirect(route('workspace.login'));
});

it('renders the workspace home with the account\'s environments', function (): void {
    ['member' => $member, 'environment' => $environment] = provisionAccount();

    $this->withSession([AccountAuth::SESSION_KEY => $member->id])
        ->get(route('workspace.home'))
        ->assertOk()
        ->assertSee('Acme')
        ->assertSee($member->email)
        ->assertSee($environment->name)
        ->assertSee('1 of 2 used');
});

it('links each environment out to its own host-resolved URL', function (): void {
    ['member' => $member, 'account' => $account] = provisionAccount();
    config(['cbox-id.environments.base_domains' => ['cboxid.com']]);
    $staging = app(AccountProvisioner::class)->addEnvironment($account, 'Staging');

    // The launchpad is stateless: it lists each environment as a link to its own
    // {slug}.{base_domain} host — no session "current environment" is pinned.
    $this->withSession([AccountAuth::SESSION_KEY => $member->id])
        ->get(route('workspace.home'))
        ->assertOk()
        ->assertSee('https://acme.cboxid.com')
        ->assertSee('https://'.$staging->slug.'.cboxid.com');
});

it('renders the members roster with the signed-in member marked', function (): void {
    ['member' => $member] = provisionAccount('dana@acme.example');

    $this->withSession([AccountAuth::SESSION_KEY => $member->id])
        ->get(route('workspace.members'))
        ->assertOk()
        ->assertSee('Members')
        ->assertSee('dana@acme.example')
        ->assertSee('You');
});

it('renders billing with the real environment allowance', function (): void {
    ['member' => $member, 'account' => $account] = provisionAccount();
    app(AccountProvisioner::class)->addEnvironment($account, 'Staging');

    $this->withSession([AccountAuth::SESSION_KEY => $member->id])
        ->get(route('workspace.billing'))
        ->assertOk()
        ->assertSee('Billing')
        // 2 of 2 environments used — real figures, no fabricated usage.
        ->assertSee('2 of 2')
        ->assertSee('How pricing works');
});

it('guards the members and billing pages behind the account session', function (): void {
    $this->get(route('workspace.members'))->assertRedirect(route('workspace.login'));
    $this->get(route('workspace.billing'))->assertRedirect(route('workspace.login'));
});

it('logs out a member the moment their account is suspended', function (): void {
    ['account' => $account, 'member' => $owner] = provisionAccount();
    app(Accounts::class)->suspend($account->id);

    // A live session no longer resolves — every guarded page bounces to login.
    $this->withSession([AccountAuth::SESSION_KEY => $owner->id])
        ->get(route('workspace.home'))
        ->assertRedirect(route('workspace.login'));
});

it('redirects a member who cannot read billing away from it', function (): void {
    ['account' => $account] = provisionAccount();
    // A Developer is a technical role — no billing, no member roster.
    $dev = memberWithRole($account->id, AccountRole::Developer, 'dev-billing@acme.example');

    $this->withSession([AccountAuth::SESSION_KEY => $dev->id])
        ->get(route('workspace.billing'))->assertRedirect(route('workspace.home'));
    $this->withSession([AccountAuth::SESSION_KEY => $dev->id])
        ->get(route('workspace.members'))->assertRedirect(route('workspace.home'));

    // A read-only Viewer, by contrast, may read both.
    $viewer = memberWithRole($account->id, AccountRole::Viewer, 'viewer-billing@acme.example');
    $this->withSession([AccountAuth::SESSION_KEY => $viewer->id])->get(route('workspace.billing'))->assertOk();
    $this->withSession([AccountAuth::SESSION_KEY => $viewer->id])->get(route('workspace.members'))->assertOk();
});

it('shows a scoped member only the environments they are granted', function (): void {
    ['account' => $account] = provisionAccount();
    config(['cbox-id.environments.base_domains' => ['cboxid.com']]);
    $staging = app(AccountProvisioner::class)->addEnvironment($account, 'Staging');

    $dev = memberWithRole($account->id, AccountRole::Developer, 'dev@acme.example');
    app(AccountMembers::class)->setEnvironmentAccess($dev->id, all: false, environmentIds: [$staging->id]);

    $this->withSession([AccountAuth::SESSION_KEY => $dev->id])
        ->get(route('workspace.home'))
        ->assertOk()
        ->assertSee('acme-staging.cboxid.com')
        // Production (acme.cboxid.com) is outside their grant — not shown.
        ->assertDontSee('https://acme.cboxid.com');
});

it('lets a manager mint an API key and shows the plaintext once', function (): void {
    ['account' => $account, 'member' => $owner] = provisionAccount();
    session()->put(AccountAuth::SESSION_KEY, $owner->id);

    $component = Volt::test('workspace.api-keys')
        ->set('newKeyName', 'CI deploy')
        ->set('newKeyRole', 'developer')
        ->call('createKey')
        ->assertHasNoErrors();

    expect($component->get('freshKey'))->toStartWith('cbid_acc_')
        ->and(app(AccountApiKeys::class)->forAccount($account->id))->toHaveCount(1);
});

it('redirects a non-manager away from API keys', function (): void {
    ['account' => $account] = provisionAccount();
    $dev = memberWithRole($account->id, AccountRole::Developer, 'dev@acme.example');

    $this->withSession([AccountAuth::SESSION_KEY => $dev->id])
        ->get(route('workspace.api-keys'))
        ->assertRedirect(route('workspace.home'));
});

it('lets an owner remove a member and transfer ownership', function (): void {
    ['account' => $account, 'member' => $owner] = provisionAccount();
    $dev = memberWithRole($account->id, AccountRole::Developer, 'dev@acme.example');
    $admin = memberWithRole($account->id, AccountRole::Admin, 'admin@acme.example');
    session()->put(AccountAuth::SESSION_KEY, $owner->id);
    $members = app(AccountMembers::class);

    Volt::test('workspace.members')->call('removeMember', $dev->id);
    expect($members->find($dev->id))->toBeNull();

    Volt::test('workspace.members')->call('makeOwner', $admin->id);
    expect($members->find($admin->id)->role)->toBe(AccountRole::Owner)
        ->and($members->find($owner->id)->role)->toBe(AccountRole::Admin);
});

it('scopes a member to specific environments via the access editor', function (): void {
    ['account' => $account, 'member' => $owner] = provisionAccount();
    $staging = app(AccountProvisioner::class)->addEnvironment($account, 'Staging');
    $dev = memberWithRole($account->id, AccountRole::Developer, 'dev@acme.example');
    session()->put(AccountAuth::SESSION_KEY, $owner->id);

    Volt::test('workspace.members')
        ->call('manageAccess', $dev->id)
        ->assertSet('accessAll', true)
        ->set('accessAll', false)
        ->set('accessEnvIds', [$staging->id])
        ->call('saveAccess')
        ->assertSet('editingAccessFor', null);

    $members = app(AccountMembers::class);
    expect($members->accessibleEnvironmentIds($members->find($dev->id)))->toBe([$staging->id]);
});

it('renames the account from settings and redirects non-managers', function (): void {
    ['account' => $account, 'member' => $owner] = provisionAccount();
    session()->put(AccountAuth::SESSION_KEY, $owner->id);

    Volt::test('workspace.settings')->set('name', 'Renamed Co')->call('save')->assertHasNoErrors();
    expect(app(Accounts::class)->find($account->id)->name)->toBe('Renamed Co');

    // A developer can't reach settings.
    $dev = memberWithRole($account->id, AccountRole::Developer, 'dev@acme.example');
    $this->withSession([AccountAuth::SESSION_KEY => $dev->id])
        ->get(route('workspace.settings'))->assertRedirect(route('workspace.home'));
});

it('shows a read-only viewer the roster but not the invite form', function (): void {
    ['account' => $account] = provisionAccount();
    $viewer = memberWithRole($account->id, AccountRole::Viewer, 'viewer@acme.example');

    $this->withSession([AccountAuth::SESSION_KEY => $viewer->id])
        ->get(route('workspace.members'))
        ->assertOk()
        ->assertSee('Members')
        ->assertDontSee('Invite a teammate');
});

it('adds an environment from the home screen up to the plan limit', function (): void {
    ['member' => $member, 'account' => $account] = provisionAccount();

    // The home component resolves the signed-in member from the session.
    session()->put(AccountAuth::SESSION_KEY, $member->id);

    // Limit 2, one used → adding one succeeds and it becomes visible.
    Volt::test('workspace.home')
        ->set('newEnvironment', 'Staging')
        ->call('addEnvironment')
        ->assertHasNoErrors();

    expect(Environment::query()->where('account_id', $account->id)->count())->toBe(2);

    // The third is refused by the plan, with a friendly error rather than a throw.
    Volt::test('workspace.home')
        ->set('newEnvironment', 'Dev')
        ->call('addEnvironment')
        ->assertHasErrors('newEnvironment');

    expect(Environment::query()->where('account_id', $account->id)->count())->toBe(2);
});
