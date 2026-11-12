<?php

declare(strict_types=1);

use App\Http\Middleware\PointAtFirstRun;
use App\Platform\Organizations\PersonalOrganizations;
use App\Platform\PlatformAuth;
use Cbox\Id\Identity\Contracts\Subjects;
use Cbox\Id\Kernel\Tenancy\Contracts\EnvironmentContext;
use Cbox\Id\Organization\Contracts\Memberships;
use Cbox\Id\Organization\Contracts\Organizations;
use Cbox\Id\Organization\Enums\MembershipRole;
use Cbox\Id\Organization\Models\Environment;
use Cbox\Id\Organization\Models\Organization;
use Cbox\Id\Organization\ValueObjects\OrganizationChanges;
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

it('gives a root signup a personal organization when the switch is on', function (): void {
    seedRootEnvironment();
    config(['cbox-id.signup.personal_organization' => true]);

    $this->post(route('signup.register'), ['name' => 'Dana Reeves', 'email' => 'dana@acme.example', 'password' => 'a-strong-unbreached-passphrase'])
        ->assertSessionHasNoErrors()->assertRedirect(route('account'));

    $root = app(PlatformRoot::class);
    $person = $root->run(fn () => app(Subjects::class)->findByEmail('dana@acme.example'));
    $memberships = $root->run(fn () => app(Memberships::class)->forUser($person->id));
    $organization = $root->run(fn () => Organization::query()->sole());

    expect($memberships)->toHaveCount(1)
        ->and($memberships->first()->organization_id)->toBe($organization->id)
        ->and($memberships->first()->role)->toBe(MembershipRole::Owner)
        ->and($organization->name)->toBe('Dana Reeves')
        ->and($organization->slug)->toBe('dana-reeves')
        ->and($organization->settings[PersonalOrganizations::SETTING])->toBe($person->id)
        ->and(PersonalOrganizations::isPersonal($organization))->toBeTrue()
        ->and(session(PlatformAuth::ORG_KEY))->toBe($organization->id)
        ->and(Project::query()->count())->toBe(0);
});

it('gives two people with the same name their own personal organizations', function (): void {
    seedRootEnvironment();
    config(['cbox-id.signup.personal_organization' => true]);

    foreach (['dana@acme.example', 'dana@globex.example'] as $email) {
        $this->post(route('signup.register'), ['name' => 'Dana Reeves', 'email' => $email, 'password' => 'a-strong-unbreached-passphrase'])
            ->assertSessionHasNoErrors();
        $this->post(route('logout'));
    }

    expect(app(PlatformRoot::class)->run(fn () => Organization::query()->orderBy('slug')->pluck('slug')->all()))
        ->toBe(['dana-reeves', 'dana-reeves-2']);
});

it('founds a personal organization once, and keeps it personal through a rename', function (): void {
    seedRootEnvironment();
    config(['cbox-id.signup.personal_organization' => true]);

    $personal = app(PersonalOrganizations::class);
    $person = app(Subjects::class)->create('dana@acme.example', null, 'a-strong-unbreached-passphrase');

    $first = $personal->ensureFor($person);
    app(Organizations::class)->update($first->id, new OrganizationChanges(name: 'Reeves Consulting'));

    expect($first->name)->toBe('dana')
        ->and($personal->ensureFor($person)->id)->toBe($first->id)
        ->and(Organization::query()->count())->toBe(1)
        ->and(PersonalOrganizations::isPersonal(Organization::query()->sole()))->toBeTrue();
});

it('creates no personal organization in a customer environment', function (): void {
    seedRootEnvironment();
    config(['cbox-id.signup.personal_organization' => true]);

    $tenant = Environment::query()->create(['name' => 'Acme', 'slug' => 'acme', 'status' => 'active', 'is_default' => false]);
    app(EnvironmentContext::class)->set($tenant);

    expect(app(PersonalOrganizations::class)->enabled())->toBeFalse();
});
