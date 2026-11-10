<?php

declare(strict_types=1);

use App\Http\Middleware\PointAtFirstRun;
use App\Platform\PlatformAuth;
use Cbox\Id\Identity\Contracts\Subjects;
use Cbox\Id\Kernel\Tenancy\Contracts\EnvironmentContext;
use Cbox\Id\Organization\Contracts\Memberships;
use Cbox\Id\Organization\Models\Environment;
use Cbox\Id\Organization\Models\Organization;
use Cbox\Id\Platform\Models\Project;
use Cbox\Id\Platform\PlatformRoot;
use Illuminate\Support\Facades\Http;

// Signup screens the password against HaveIBeenPwned — keep it offline.
beforeEach(fn () => Http::fake(['api.pwnedpasswords.com/*' => Http::response('', 200)]));

/**
 * Stand up the platform-root environment and point the request at it, the way
 * cboxid.com resolves. `base_domains` set = SaaS multi-tenant, so a standalone
 * signup here provisions a whole new account (Tier 2).
 */
function seedRootEnvironment(): Environment
{
    config(['cbox-id.environments.base_domains' => ['cboxid.com'], 'cbox-id.tenancy.multi_tenant' => true]);

    $root = Environment::query()->create([
        'name' => 'Production',
        'slug' => 'production',
        'status' => 'active',
        'is_default' => true,
    ]);

    app(EnvironmentContext::class)->set($root);

    return $root;
}

it('creates only a person on root signup, with no organization or product resources', function (): void {
    seedRootEnvironment();
    $this->post(route('signup.register'), ['name' => 'Dana Reeves', 'email' => 'dana@acme.example', 'password' => 'a-strong-unbreached-passphrase'])
        ->assertSessionHasNoErrors()->assertRedirect(route('account'));

    $person = app(PlatformRoot::class)->run(fn () => app(Subjects::class)->findByEmail('dana@acme.example'));
    expect($person)->not->toBeNull()
        ->and(session(PlatformAuth::SESSION_KEY))->not->toBeNull()
        ->and(app(PlatformRoot::class)->run(fn () => app(Memberships::class)->forUser($person->id)->count()))->toBe(0)
        ->and(app(PlatformRoot::class)->run(fn () => Organization::query()->count()))->toBe(0)
        ->and(Project::query()->count())->toBe(0)
        ->and(Environment::query()->where('is_default', false)->count())->toBe(0);
});

it('refuses duplicate identity registration without creating an organization', function (): void {
    seedRootEnvironment();
    $register = fn () => $this->from(route('signup'))->post(route('signup.register'), ['name' => 'Dana', 'email' => 'dana@acme.example', 'password' => 'a-strong-unbreached-passphrase']);
    $register()->assertRedirect(route('account'));
    $this->post(route('logout'));
    $register()->assertSessionHasErrors('email');
    expect(app(PlatformRoot::class)->run(fn () => Organization::query()->count()))->toBe(0);
});

it('allows identity signup when product signup is invite only', function (): void {
    seedRootEnvironment();
    config(['cbox-id.signup.mode' => 'invite_only']);
    $this->withoutMiddleware(PointAtFirstRun::class)->get(route('signup'))->assertOk();
    $this->post(route('signup.register'), ['name' => 'Dana', 'email' => 'dana@acme.example', 'password' => 'a-strong-unbreached-passphrase'])
        ->assertSessionHasNoErrors()->assertRedirect(route('account'));
});
