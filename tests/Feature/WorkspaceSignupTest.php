<?php

declare(strict_types=1);

use App\Platform\AccountAuth;
use Cbox\Id\Kernel\Tenancy\Contracts\EnvironmentContext;
use Cbox\Id\Organization\Models\Environment;
use Cbox\Id\Platform\Contracts\AccountMembers;
use Cbox\Id\Platform\Models\Account;
use Illuminate\Support\Facades\Http;
use Livewire\Volt\Volt;

// Signup screens the password against HaveIBeenPwned — keep it offline.
beforeEach(fn () => Http::fake(['api.pwnedpasswords.com/*' => Http::response('', 200)]));

/**
 * Stand up the platform-root environment and point the request at it, the way
 * cboxid.com resolves. `base_domains` set = SaaS multi-tenant, so a standalone
 * signup here provisions a whole new account (Tier 2).
 */
function seedRootEnvironment(): Environment
{
    config(['cbox-id.environments.base_domains' => ['cboxid.com']]);

    $root = Environment::query()->create([
        'name' => 'Production',
        'slug' => 'production',
        'status' => 'active',
        'is_default' => true,
    ]);

    app(EnvironmentContext::class)->set($root);

    return $root;
}

it('provisions an account, member, and environment on a Tier 2 signup', function (): void {
    seedRootEnvironment();

    Volt::test('auth.signup')
        ->set('organization', 'Acme')
        ->set('name', 'Dana Reeves')
        ->set('email', 'dana@acme.example')
        ->set('password', 'a-strong-unbreached-passphrase')
        ->call('register')
        ->assertRedirect(route('workspace.home'));

    // A global account + member exist (NOT a Subject in Cbox's environment)…
    $member = app(AccountMembers::class)->findByEmail('dana@acme.example');
    expect($member)->not->toBeNull();

    $account = Account::query()->whereKey($member->account_id)->first();
    expect($account)->not->toBeNull()
        ->and($account->name)->toBe('Acme');

    // …and the account owns a fresh, isolated environment (its own IdP), distinct
    // from the Cbox root the signup ran on.
    $owned = Environment::query()->where('account_id', $account->id)->get();
    expect($owned)->toHaveCount(1)
        ->and($owned->first()->is_default)->toBeFalse();

    // The member is signed into the workspace plane immediately.
    expect(session()->get(AccountAuth::SESSION_KEY))->toBe($member->id);
});

it('refuses a second workspace for an email that already has one', function (): void {
    seedRootEnvironment();

    $register = fn () => Volt::test('auth.signup')
        ->set('organization', 'Acme')
        ->set('name', 'Dana')
        ->set('email', 'dana@acme.example')
        ->set('password', 'a-strong-unbreached-passphrase')
        ->call('register');

    $register()->assertRedirect(route('workspace.home'));
    $register()->assertHasErrors('email');

    // Only one account member ever created for the email.
    expect(Account::query()->count())->toBe(1);
});
