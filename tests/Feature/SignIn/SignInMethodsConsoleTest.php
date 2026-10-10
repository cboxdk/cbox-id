<?php

declare(strict_types=1);

use App\Platform\InstallationOrganization;
use Cbox\Id\Federation\Contracts\Connections;
use Cbox\Id\Federation\Contracts\SignInProviders;
use Cbox\Id\Federation\Enums\ConnectionType;
use Cbox\Id\Identity\Contracts\AuthPolicies;
use Cbox\Id\Identity\Contracts\SmsFactorPolicies;
use Cbox\Id\Identity\ValueObjects\AuthPolicy;
use Cbox\Id\Organization\Contracts\Organizations;
use Cbox\Id\Organization\Enums\MembershipRole;
use Cbox\Id\Organization\ValueObjects\NewOrganization;
use Cbox\Id\Platform\PlatformRoot;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia;

/*
|--------------------------------------------------------------------------
| The console half: Social login for the environment, Sign-in methods on both consoles,
| and the methods panel on Authentication policy.
|--------------------------------------------------------------------------
*/

function smcProvider(?string $organizationId, string $provider): string
{
    $connection = app(SignInProviders::class)->create($organizationId, $provider, ConnectionType::OAuth2, ucfirst($provider), [
        'provider' => $provider, 'client_id' => 'id', 'client_secret' => 'secret',
    ]);
    app(Connections::class)->activate($organizationId, $connection->id);

    return $connection->id;
}

/** @return array<string, mixed> the page's props */
function smcProps(string $url): array
{
    $props = [];

    test()->get($url)->assertOk()->assertInertia(function (AssertableInertia $page) use (&$props): void {
        $props = $page->toArray()['props'];
    });

    return $props;
}

/** @return array<string, array<string, mixed>> rows by key */
function smcRows(array $props): array
{
    $rows = [];

    foreach ($props['sections'] as $section) {
        foreach ($section['rows'] as $row) {
            $rows[$row['key']] = $row;
        }
    }

    return $rows;
}

// ── Social login: turn Google on for the environment ────────────────────────

it('turns GitHub on for the whole environment from the setup form, under the redirect URI it showed', function (): void {
    actAsEnvironmentAdminOfATenant();

    $first = smcProps(route('environment.social-providers', ['provider' => 'github']));
    // The id behind the redirect URI is kept while the form is open — a reload while
    // somebody is in GitHub's console shows the same URI.
    $again = smcProps(route('environment.social-providers', ['provider' => 'github']));

    $reserved = $first['template']['reservedId'];

    expect($first['view'])->toBe('environment')
        ->and($first['organization']['allowsEnvironment'])->toBeTrue()
        ->and($first['organization']['selected'])->toBeNull()
        ->and($first['template']['redirectUri'])->toBe(url('/sso/oauth2/'.$reserved.'/callback'))
        ->and($again['template']['reservedId'])->toBe($reserved);

    $this->from(route('environment.social-providers', ['provider' => 'github']))
        ->post(route('environment.social-providers.store'), [
            'provider' => 'github',
            'organization' => '',
            'reservedId' => $reserved,
            'clientId' => 'gh-client',
            'clientSecret' => 'gh-secret',
            'scopes' => 'read:org, read:user',
            'parameters' => [],
        ])
        ->assertSessionHasNoErrors()
        ->assertRedirect(route('environment.social-providers'));

    $providers = app(SignInProviders::class)->environmentProviders();

    expect($providers)->toHaveCount(1)
        ->and($providers[0]->id)->toBe($reserved)
        ->and($providers[0]->organization_id)->toBeNull()
        ->and(app(Connections::class)->oauth2Config($providers[0])->scopes)->toBe(['read:org', 'read:user']);

    $after = smcProps(route('environment.social-providers'));

    expect(array_column($after['environmentProviders'], 'id'))->toBe([$reserved])
        ->and($after['environmentProviders'][0]['callbackUri'])->toBe(url('/sso/oauth2/'.$reserved.'/callback'))
        // GitHub is no longer offered as something to add for the environment.
        ->and(array_column($after['available'], 'key'))->not->toContain('github');

    // A fresh form for the same provider gets a fresh id: the old one is spent.
    expect(smcProps(route('environment.social-providers', ['provider' => 'github']))['template']['reservedId'])->not->toBe($reserved);
});

it('shows one organization what its page offers, and lets it turn an inherited provider off', function (): void {
    actAsEnvironmentAdminOfATenant();
    $acme = app(Organizations::class)->create(new NewOrganization('Acme', 'acme-'.Str::lower(Str::random(4))));
    smcProvider(null, 'github');
    smcProvider(null, 'discord');
    $own = smcProvider($acme->id, 'github');

    $props = smcProps(route('environment.social-providers', ['organization' => $acme->id]));
    $rows = collect($props['page'])->keyBy(fn (array $row): string => $row['source'].':'.$row['provider']);

    expect($props['view'])->toBe('organization')
        ->and($rows['organization:github']['id'])->toBe($own)
        ->and($rows['organization:github']['state'])->toBe('offered')
        ->and($rows['environment:github']['state'])->toBe('replaced')
        ->and($rows['environment:github']['inheritHref'])->toBeNull()
        ->and($rows['environment:discord']['state'])->toBe('offered');

    $this->put($rows['environment:discord']['inheritHref'], ['organization' => $acme->id, 'offered' => false])
        ->assertSessionHasNoErrors();

    expect(app(SignInProviders::class)->notInheritedBy($acme->id))->toBe(['discord']);

    $environmentView = smcProps(route('environment.social-providers'));

    expect($environmentView['optOuts'])->toHaveCount(1)
        ->and($environmentView['optOuts'][0]['organization'])->toBe('Acme')
        ->and($environmentView['organizationProviders'][0]['replacesEnvironment'])->toBeTrue();
});

it('changes a provider\'s credentials from the console, keeping the secret it is not shown', function (): void {
    actAsEnvironmentAdminOfATenant();
    $id = smcProvider(null, 'github');

    $editing = smcProps(route('environment.social-providers', ['edit' => $id]))['editing'];

    expect($editing['clientId'])->toBe('id')
        ->and(json_encode($editing))->not->toContain('secret"');

    $this->patch($editing['updateHref'], ['clientId' => 'rotated', 'clientSecret' => '', 'scopes' => '', 'parameters' => []])
        ->assertSessionHasNoErrors();

    $config = app(Connections::class)->config(app(Connections::class)->byId($id));

    expect($config['client_id'])->toBe('rotated')
        ->and($config['client_secret'])->toBe('secret');
});

// ── Sign-in methods, on both consoles ──────────────────────────────────────

it('reports passkeys, magic link, sessions and the bot challenge as this environment\'s, under the deployment', function (): void {
    actAsEnvironmentAdminOfATenant();
    app(AuthPolicies::class)->setForEnvironment(new AuthPolicy(magicLink: false, sessionAbsoluteMinutes: 120));
    smcProvider(null, 'github');

    $rows = smcRows(smcProps(route('environment.sign-in-methods')));

    expect($rows['passkeys']['decidedBy'])->toBe('environment')
        ->and($rows['passkeys']['state'])->toBe('on')
        ->and($rows['passkeys']['href'])->toBe(route('environment.auth-policy').'#sign-in-methods')
        ->and($rows['magic-link']['state'])->toBe('off')
        ->and($rows['magic-link']['decidedBy'])->toBe('environment')
        ->and($rows['session']['decidedBy'])->toBe('environment')
        ->and($rows['session']['summary'])->toContain('2 hours at most')
        ->and($rows['session']['ceiling'])->toContain('CBOX_ID_SESSION_TTL_MINUTES')
        ->and($rows['social']['decidedBy'])->toBe('environment')
        ->and($rows['social']['summary'])->toContain('offered on every sign-in page');

    // The deployment switched passkeys off: it wins, and the row says so.
    config()->set('cbox-id.sign_in.passkeys', false);
    $rows = smcRows(smcProps(route('environment.sign-in-methods')));

    expect($rows['passkeys']['decidedBy'])->toBe('deployment')
        ->and($rows['passkeys']['state'])->toBe('off')
        ->and($rows['passkeys']['variable'])->toBe('CBOX_ID_PASSKEYS_ENABLED')
        ->and($rows['passkeys']['href'])->toBeNull();
});

it('serves Sign-in methods on a single-tenant install\'s organization console, about that organization', function (): void {
    [, $org] = actingAsRole(MembershipRole::Owner);
    app(InstallationOrganization::class)->set($org->id);
    smcProvider(null, 'discord');
    smcProvider($org->id, 'github');

    $props = smcProps(route('sign-in-methods'));
    $rows = smcRows($props);

    expect($props['organizationName'])->toBe('Acme')
        ->and($props['organizationsHref'])->toBeNull()
        ->and($rows['password']['decidedBy'])->toBe('organization')
        ->and($rows['password']['href'])->toBe(route('auth-policy'))
        // Environment-wide — and on a single-tenant install this console administers the
        // environment, so the row leads to the panel that changes it.
        ->and($rows['passkeys']['decidedBy'])->toBe('environment')
        ->and($rows['passkeys']['href'])->toBe(route('auth-policy').'#sign-in-methods')
        ->and($rows['sms']['href'])->toBe(route('auth-policy').'#sms')
        ->and($rows['social']['summary'])->toContain('Discord, Github')
        ->and($rows['social']['href'])->toBe(route('social-providers'));

    // On the rail, first in its area.
    $this->get(route('sign-in-methods'))->assertInertia(fn (AssertableInertia $page) => $page
        ->where('shell.areas', fn ($areas): bool => collect($areas)->contains(
            fn (array $area): bool => collect($area['pages'] ?? [])->pluck('href')->contains(route('sign-in-methods')),
        )));
});

it('refuses Sign-in methods to a plain member', function (): void {
    actingAsRole(MembershipRole::Member);

    $this->get(route('sign-in-methods'))->assertForbidden();
})->group('security');

// ── Authentication policy: the methods panel ──────────────────────────────

it('saves the environment\'s methods and session lengths from Authentication policy, and names the ceiling it refuses', function (): void {
    actAsEnvironmentAdminOfATenant();
    config()->set('cbox-id.sessions.ttl_minutes', 480);
    config()->set('cbox-id.sessions.idle_minutes', 30);

    $props = smcProps(route('environment.auth-policy'));

    expect($props['signInMethods']['passkeys'])->toBeTrue()
        ->and($props['signInMethods']['deployment']['sessionAbsoluteMinutes'])->toBe(480)
        ->and($props['signInMethods']['href'])->toBe(route('environment.auth-policy.methods'));

    $this->from(route('environment.auth-policy'))->put(route('environment.auth-policy.methods'), [
        'passkeys' => false, 'magicLink' => true, 'botChallenge' => true, 'sessionIdleMinutes' => '15', 'sessionAbsoluteMinutes' => '',
    ])->assertSessionHasNoErrors();

    $policy = app(AuthPolicies::class)->forEnvironment();

    expect($policy->passkeys)->toBeFalse()
        ->and($policy->sessionIdleMinutes)->toBe(15)
        ->and($policy->sessionAbsoluteMinutes)->toBeNull();

    $this->from(route('environment.auth-policy'))->put(route('environment.auth-policy.methods'), [
        'passkeys' => true, 'magicLink' => true, 'botChallenge' => true, 'sessionIdleMinutes' => '', 'sessionAbsoluteMinutes' => '900',
    ])->assertSessionHasErrors(['sessionAbsoluteMinutes']);

    // The password form does not touch them.
    $this->from(route('environment.auth-policy'))->put(route('environment.auth-policy.update'), [
        'minLength' => 14, 'requireBreachCheck' => true, 'maxAgeDays' => '', 'reuseHistory' => 0, 'mfa' => 'optional', 'sso' => 'off', 'lockoutThreshold' => '',
    ])->assertSessionHasNoErrors();

    expect(app(AuthPolicies::class)->forEnvironment()->passkeys)->toBeFalse()
        ->and(app(AuthPolicies::class)->forEnvironment()->minLength)->toBe(14);
});

it('keeps an organization\'s policy form working beside the environment\'s methods', function (): void {
    [, $org] = actingAsRole(MembershipRole::Owner);
    app(AuthPolicies::class)->setForEnvironment(new AuthPolicy(passkeys: false));

    $this->from(route('auth-policy'))->put(route('auth-policy.update'), [
        'minLength' => 16, 'requireBreachCheck' => true, 'maxAgeDays' => '', 'reuseHistory' => 0, 'mfa' => 'optional', 'sso' => 'off', 'lockoutThreshold' => '',
    ])->assertSessionHasNoErrors();

    expect(app(AuthPolicies::class)->resolve($org->id)->minLength)->toBe(16)
        ->and(app(AuthPolicies::class)->resolve($org->id)->passkeys)->toBeFalse();
});

// ── A single-tenant install: the organization console administers the environment ──

it('lets the owner of a single-tenant install\'s own organization change the environment\'s methods, sessions and SMS', function (): void {
    [, $org] = actingAsRole(MembershipRole::Owner);
    app(InstallationOrganization::class)->set($org->id);
    config()->set('cbox-id.sessions.ttl_minutes', 480);
    config()->set('cbox-id.sessions.idle_minutes', 30);

    $props = smcProps(route('auth-policy'));

    expect($props['onEnvironmentPlane'])->toBeFalse()
        ->and($props['signInMethods']['href'])->toBe(route('auth-policy.methods'))
        ->and($props['smsFactor']['href'])->toBe(route('auth-policy.sms'))
        // Sign-up here is the deployment's (CBOX_ID_SIGNUP_MODE): drawn, with nothing to post.
        ->and($props['selfServiceSignup']['decidedHere'])->toBeFalse();

    $this->from(route('auth-policy'))->put(route('auth-policy.methods'), [
        'passkeys' => false, 'magicLink' => false, 'botChallenge' => true, 'sessionIdleMinutes' => '10', 'sessionAbsoluteMinutes' => '120',
    ])->assertSessionHasNoErrors();

    $policy = app(AuthPolicies::class)->forEnvironment();

    expect($policy->passkeys)->toBeFalse()
        ->and($policy->magicLink)->toBeFalse()
        ->and($policy->sessionAbsoluteMinutes)->toBe(120);

    // The same action, so the same ceiling.
    $this->from(route('auth-policy'))->put(route('auth-policy.methods'), [
        'passkeys' => true, 'magicLink' => true, 'botChallenge' => true, 'sessionIdleMinutes' => '', 'sessionAbsoluteMinutes' => '900',
    ])->assertSessionHasErrors(['sessionAbsoluteMinutes']);

    $this->from(route('auth-policy'))->put(route('auth-policy.sms'), [
        'enabled' => true, 'allowedCountries' => ['DK'], 'privilegedNeedStrongerFactor' => true,
    ])->assertSessionHasNoErrors();

    expect(app(SmsFactorPolicies::class)->forEnvironment()->enabled)->toBeTrue();
});

it('turns a provider on for every sign-in page from a single-tenant install\'s own organization, and manages it there', function (): void {
    [, $org] = actingAsRole(MembershipRole::Owner);
    app(InstallationOrganization::class)->set($org->id);

    $props = smcProps(route('social-providers', ['provider' => 'github']));

    expect($props['ownerChoice'])->toBeTrue()
        ->and($props['view'])->toBe('organization');

    $this->from(route('social-providers', ['provider' => 'github']))->post(route('social-providers.store'), [
        'provider' => 'github', 'forEnvironment' => true, 'reservedId' => $props['template']['reservedId'],
        'clientId' => 'gh', 'clientSecret' => 'gh', 'scopes' => '', 'parameters' => [],
    ])->assertSessionHasNoErrors();

    $environment = app(SignInProviders::class)->environmentProviders();

    expect($environment)->toHaveCount(1)
        ->and($environment[0]->organization_id)->toBeNull()
        ->and($environment[0]->id)->toBe($props['template']['reservedId']);

    $id = $environment[0]->id;
    $listed = smcProps(route('social-providers'));

    expect(array_column($listed['environmentProviders'], 'id'))->toBe([$id])
        ->and(smcProps(route('social-providers', ['edit' => $id]))['editing']['id'])->toBe($id);

    $this->patch(route('social-providers.update', $id), ['clientId' => 'rotated', 'clientSecret' => '', 'scopes' => '', 'parameters' => []])->assertSessionHasNoErrors();
    $this->post(route('social-providers.disable', $id))->assertSessionHasNoErrors();

    expect(app(Connections::class)->config(app(Connections::class)->byId($id))['client_id'])->toBe('rotated')
        ->and(app(SignInProviders::class)->offeredTo($org->id))->toBe([]);

    $this->post(route('social-providers.enable', $id))->assertSessionHasNoErrors();
    $this->delete(route('social-providers.destroy', $id))->assertSessionHasNoErrors();

    expect(app(SignInProviders::class)->environmentProviders())->toBe([]);

    // Without the choice, the organization's own — as before.
    $this->from(route('social-providers', ['provider' => 'discord']))->post(route('social-providers.store'), [
        'provider' => 'discord', 'forEnvironment' => false, 'clientId' => 'd', 'clientSecret' => 'd', 'scopes' => '', 'parameters' => [],
    ])->assertSessionHasNoErrors();

    expect(app(SignInProviders::class)->offeredTo($org->id)[0]->organization_id)->toBe($org->id);
});

/**
 * @group security
 *
 * On a multi-tenant deployment an organization console belongs to one customer, and the
 * environment stays the vendor's: none of it is drawn, and the writes are refused.
 */
it('keeps the environment\'s settings off a multi-tenant organization console', function (): void {
    multiTenantDeployment();
    [, $org] = actingAsRole(MembershipRole::Owner);
    $other = app(Organizations::class)->create(new NewOrganization('Elsewhere', 'elsewhere-'.Str::lower(Str::random(4))));
    $environmentProvider = smcProvider(null, 'github');

    $this->put(route('auth-policy.methods'), [
        'passkeys' => false, 'magicLink' => false, 'botChallenge' => true, 'sessionIdleMinutes' => '', 'sessionAbsoluteMinutes' => '',
    ]);
    $this->put(route('auth-policy.sms'), ['enabled' => true, 'allowedCountries' => ['DK'], 'privilegedNeedStrongerFactor' => true]);
    $this->post(route('social-providers.store'), ['provider' => 'discord', 'forEnvironment' => true, 'clientId' => 'd', 'clientSecret' => 'd', 'parameters' => []]);
    $this->post(route('social-providers.disable', $environmentProvider));

    expect(app(AuthPolicies::class)->forEnvironment()->passkeys)->toBeTrue()
        ->and(app(SmsFactorPolicies::class)->forEnvironment()->enabled)->toBeFalse()
        ->and(app(SignInProviders::class)->environmentProviders())->toHaveCount(1)
        ->and(app(SignInProviders::class)->offeredTo(null)[0]->id)->toBe($environmentProvider)
        // Whatever the form said, an organization console here can only add the organization's own.
        ->and(collect(app(SignInProviders::class)->offeredTo($other->id))->pluck('provider')->all())->toBe(['github']);
})->group('security');

/**
 * @group security
 *
 * A single-tenant install can host CUSTOMER organizations. Their owners administer their own
 * organization and nothing of the environment's: every environment-level write is refused,
 * and the environment's rows are read-only on their pages. So is the install's own
 * organization's admin who is not its owner.
 */
it('refuses a customer organization\'s owner on a single-tenant install every environment-level write', function (MembershipRole $role, bool $home): void {
    [, $org] = actingAsRole($role);
    $installation = $home ? $org : app(Organizations::class)->create(new NewOrganization('The install', 'install-'.Str::lower(Str::random(4))));
    app(InstallationOrganization::class)->set($installation->id);
    $environmentProvider = smcProvider(null, 'github');

    // Read-only: nothing to change the environment with is drawn.
    $rows = smcRows(smcProps(route('sign-in-methods')));
    $policy = smcProps(route('auth-policy'));
    $social = smcProps(route('social-providers', ['provider' => 'discord']));

    expect($rows['passkeys']['decidedBy'])->toBe('environment')
        ->and($rows['passkeys']['href'])->toBeNull()
        ->and($rows['session']['href'])->toBeNull()
        ->and($rows['sms']['href'])->toBeNull()
        ->and($policy['signInMethods'])->toBeNull()
        ->and($policy['smsFactor'])->toBeNull()
        ->and($social['ownerChoice'])->toBeFalse()
        ->and($social['environmentProviders'])->toBe([]);

    // And every write refused.
    $this->put(route('auth-policy.methods'), ['passkeys' => false, 'magicLink' => false, 'botChallenge' => false, 'sessionIdleMinutes' => '5', 'sessionAbsoluteMinutes' => '10'])->assertForbidden();
    $this->put(route('auth-policy.sms'), ['enabled' => true, 'allowedCountries' => ['DK'], 'privilegedNeedStrongerFactor' => true])->assertForbidden();
    $this->patch(route('social-providers.update', $environmentProvider), ['clientId' => 'stolen', 'clientSecret' => '', 'scopes' => '', 'parameters' => []])->assertNotFound();
    $this->post(route('social-providers.disable', $environmentProvider))->assertNotFound();
    $this->delete(route('social-providers.destroy', $environmentProvider))->assertNotFound();
    // "For every page" from here is the organization's own, whatever the form says.
    $this->post(route('social-providers.store'), ['provider' => 'discord', 'forEnvironment' => true, 'clientId' => 'd', 'clientSecret' => 'd', 'scopes' => '', 'parameters' => []]);

    $environment = app(SignInProviders::class)->environmentProviders();

    expect(app(AuthPolicies::class)->forEnvironment()->passkeys)->toBeTrue()
        ->and(app(AuthPolicies::class)->forEnvironment()->sessionAbsoluteMinutes)->toBeNull()
        ->and(app(SmsFactorPolicies::class)->forEnvironment()->enabled)->toBeFalse()
        ->and(array_map(fn ($c) => $c->provider, $environment))->toBe(['github'])
        ->and($environment[0]->isActive())->toBeTrue()
        ->and(app(Connections::class)->config($environment[0])['client_id'])->toBe('id');
})->with([
    'a customer organization\'s owner' => [MembershipRole::Owner, false],
    'a customer organization\'s admin' => [MembershipRole::Admin, false],
    'the install\'s own organization\'s admin, not its owner' => [MembershipRole::Admin, true],
])->group('security');

it('lets nobody on an organization console administer the environment until the install names its own organization', function (): void {
    actingAsRole(MembershipRole::Owner);

    expect(smcProps(route('auth-policy'))['signInMethods'])->toBeNull();
    $this->put(route('auth-policy.methods'), ['passkeys' => false, 'magicLink' => true, 'botChallenge' => true, 'sessionIdleMinutes' => '', 'sessionAbsoluteMinutes' => ''])->assertForbidden();
});

it('names the install\'s own organization from the command line, and only on a single-tenant install', function (): void {
    platformRootEnvironment();

    $org = app(PlatformRoot::class)->run(fn () => app(Organizations::class)->create(new NewOrganization('The install', 'the-install')));

    $this->artisan('cbox-id:installation-organization', ['organization' => 'the-install'])->assertSuccessful();
    expect(app(PlatformRoot::class)->run(fn (): ?string => app(InstallationOrganization::class)->id()))->toBe($org->id);

    $this->artisan('cbox-id:installation-organization', ['organization' => 'nobody'])->assertFailed();
    $this->artisan('cbox-id:installation-organization', ['--clear' => true])->assertSuccessful();
    expect(app(PlatformRoot::class)->run(fn (): ?string => app(InstallationOrganization::class)->id()))->toBeNull();

    multiTenantDeployment();
    $this->artisan('cbox-id:installation-organization', ['organization' => 'the-install'])->assertFailed();
});
