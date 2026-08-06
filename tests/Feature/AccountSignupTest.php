<?php

declare(strict_types=1);

use App\Platform\PlatformAuth;
use Cbox\Id\Identity\Contracts\Subjects;
use Cbox\Id\Kernel\Tenancy\Contracts\EnvironmentContext;
use Cbox\Id\Organization\Contracts\Memberships;
use Cbox\Id\Organization\Contracts\Organizations;
use Cbox\Id\Organization\Models\Environment;
use Cbox\Id\Organization\Models\Organization;
use Cbox\Id\Platform\PlatformRoot;
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

    // The owner is a SUBJECT in the platform root, and their organization is reached
    // through the membership — there is no account row between the two any more.
    $member = app(PlatformRoot::class)->run(fn () => app(Subjects::class)->findByEmail('dana@acme.example'));
    expect($member)->not->toBeNull();

    $account = app(PlatformRoot::class)->run(function () use ($member) {
        $membership = app(Memberships::class)->forUser($member->id)->first();

        return $membership === null ? null : app(Organizations::class)->find($membership->organization_id);
    });

    expect($account)->not->toBeNull()
        ->and($account->name)->toBe('Acme');

    // …but NOT an environment: the IdP itself is deferred until the owner proves the
    // address (see SignupDeferredEnvironmentTest). A signup that never verifies
    // therefore costs a routable environment nothing.
    expect(environmentsOwnedBy($account->id)->get())->toHaveCount(0);

    // The owner is signed in immediately — on the ONE session, whose subject is theirs.
    // There is no second store to resolve them back out of, which is the whole of what
    // the fold bought here.
    expect(session(PlatformAuth::SESSION_KEY))->not->toBeNull()
        ->and(app(PlatformRoot::class)->run(
            fn () => app(Memberships::class)->forUser($member->id)->first()?->organization_id,
        ))->toBe($account->id);
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

    // Only one customer ever created for the email. Counted in the ROOT, where customers
    // live: the ambient scope would answer zero and the assertion would pass on nothing.
    expect(app(PlatformRoot::class)->run(fn (): int => Organization::query()->count()))->toBe(1);
});
