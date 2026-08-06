<?php

declare(strict_types=1);

use App\Platform\AccountAuth;
use Cbox\Id\Kernel\Tenancy\Contracts\EnvironmentContext;
use Cbox\Id\Organization\Models\Environment;
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

it('provisions an account and member on a Tier 2 signup, holding the environment back', function (): void {
    seedRootEnvironment();

    Volt::test('auth.signup')
        ->set('organization', 'Acme')
        ->set('name', 'Dana Reeves')
        ->set('email', 'dana@acme.example')
        ->set('password', 'a-strong-unbreached-passphrase')
        ->call('register')
        ->assertRedirect(route('projects'));

    // A global account + member exist (NOT a Subject in Cbox's environment)…
    $member = app(PlatformRoot::class)->run(fn () => app(Subjects::class)->findByEmail('dana@acme.example'));
    expect($member)->not->toBeNull();

    $account = Account::query()->whereKey($member->account_id)->first();
    expect($account)->not->toBeNull()
        ->and($account->name)->toBe('Acme');

    // …but NOT an environment: the IdP itself is deferred until the owner proves the
    // address (see SignupDeferredEnvironmentTest). A signup that never verifies
    // therefore costs a routable environment nothing.
    expect(environmentsOwnedBy($account->id)->get())->toHaveCount(0);

    // The member is signed into the workspace plane immediately — on the ONE session,
    // resolved back to the member the way every page does it.
    expect(app(AccountAuth::class)->current()?->id)->toBe($member->id);
});

it('refuses a second workspace for an email that already has one', function (): void {
    seedRootEnvironment();

    $register = fn () => Volt::test('auth.signup')
        ->set('organization', 'Acme')
        ->set('name', 'Dana')
        ->set('email', 'dana@acme.example')
        ->set('password', 'a-strong-unbreached-passphrase')
        ->call('register');

    $register()->assertRedirect(route('projects'));
    $register()->assertHasErrors('email');

    // Only one account member ever created for the email.
    expect(Account::query()->count())->toBe(1);
});
