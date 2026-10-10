<?php

declare(strict_types=1);

use Cbox\Id\Federation\Contracts\Connections;
use Cbox\Id\Federation\Contracts\SignInProviders;
use Cbox\Id\Federation\Enums\ConnectionType;
use Cbox\Id\Identity\Contracts\AuthPolicies;
use Cbox\Id\Identity\ValueObjects\AuthPolicy;
use Cbox\Id\Organization\Contracts\Organizations;
use Cbox\Id\Organization\Enums\MembershipRole;
use Cbox\Id\Organization\ValueObjects\NewOrganization;
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
    smcProvider(null, 'discord');
    smcProvider($org->id, 'github');

    $props = smcProps(route('sign-in-methods'));
    $rows = smcRows($props);

    expect($props['organizationName'])->toBe('Acme')
        ->and($props['organizationsHref'])->toBeNull()
        ->and($rows['password']['decidedBy'])->toBe('organization')
        ->and($rows['password']['href'])->toBe(route('auth-policy'))
        // Environment-wide here, and not this console's to change.
        ->and($rows['passkeys']['decidedBy'])->toBe('environment')
        ->and($rows['passkeys']['href'])->toBeNull()
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

it('keeps an organization\'s policy form working, without the environment\'s methods', function (): void {
    [, $org] = actingAsRole(MembershipRole::Owner);
    app(AuthPolicies::class)->setForEnvironment(new AuthPolicy(passkeys: false));

    expect(smcProps(route('auth-policy'))['signInMethods'])->toBeNull();

    $this->from(route('auth-policy'))->put(route('auth-policy.update'), [
        'minLength' => 16, 'requireBreachCheck' => true, 'maxAgeDays' => '', 'reuseHistory' => 0, 'mfa' => 'optional', 'sso' => 'off', 'lockoutThreshold' => '',
    ])->assertSessionHasNoErrors();

    expect(app(AuthPolicies::class)->resolve($org->id)->minLength)->toBe(16)
        ->and(app(AuthPolicies::class)->resolve($org->id)->passkeys)->toBeFalse();
});
