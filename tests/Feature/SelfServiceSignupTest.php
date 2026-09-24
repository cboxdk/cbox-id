<?php

declare(strict_types=1);

use App\Platform\SelfServiceSignup;
use Cbox\Id\Identity\Contracts\BreachedPasswordCheck;
use Cbox\Id\Identity\Contracts\Subjects;
use Cbox\Id\Identity\NeverBreachedCheck;
use Cbox\Id\Kernel\Audit\Models\AuditEntry;
use Cbox\Id\Kernel\Tenancy\Contracts\EnvironmentContext;
use Cbox\Id\Kernel\Tenancy\Contracts\IssuerResolver;
use Cbox\Id\Kernel\Tenancy\GenericEnvironment;
use Cbox\Id\OAuthServer\Contracts\ClientRegistry;
use Cbox\Id\OAuthServer\Enums\ClientType;
use Cbox\Id\OAuthServer\Models\AuthorizationCode;
use Cbox\Id\OAuthServer\ValueObjects\NewClient;
use Cbox\Id\Organization\Contracts\Memberships;
use Cbox\Id\Organization\Contracts\Organizations;
use Cbox\Id\Organization\Enums\MembershipRole;
use Cbox\Id\Organization\Models\Environment;
use Cbox\Id\Organization\Models\Organization;
use Cbox\Id\Organization\ValueObjects\NewOrganization;
use Cbox\Id\Platform\PlatformRoot;
use Cbox\Id\Platform\TenantProvisioner;
use Cbox\Id\Platform\ValueObjects\TenantBlueprint;
use Illuminate\Support\Facades\Mail;
use Illuminate\Testing\TestResponse;
use Inertia\Testing\AssertableInertia;

/**
 * SELF-SERVICE SIGN-UP ON A TENANT ENVIRONMENT — "Anna signs up for cboxtax and creates her
 * team". On the SaaS shape `/signup` was served on the platform root only, so a vendor's end
 * users had no way to create an account at all; now the environment decides, with a switch
 * that is off until an administrator turns it on.
 */
beforeEach(fn () => app()->instance(BreachedPasswordCheck::class, new NeverBreachedCheck));

/**
 * The SaaS shape with one customer environment, served on this suite's own host, and the
 * request standing in it — nobody signed in.
 *
 * @return array{environment: Environment, ownerId: string}
 */
function selfServiceTenant(bool $enabled): array
{
    multiTenantDeployment();
    platformRootEnvironment();

    $tenant = app(TenantProvisioner::class)->provision(new TenantBlueprint(
        organizationName: 'Cboxtax Vendor',
        ownerEmail: 'vendor@cboxtax.example',
        ownerName: 'Vendor',
        ownerPassword: 'a-strong-unbreached-passphrase',
    ));

    $environment = serveOnTestHost($tenant->environment);
    app(EnvironmentContext::class)->set(GenericEnvironment::of($environment->id));

    if ($enabled) {
        app(SelfServiceSignup::class)->set($environment, true);
    }

    return ['environment' => $environment->refresh(), 'ownerId' => $tenant->owner->id];
}

/** A first-party app registered in the tenant environment. */
function selfServiceApp(): string
{
    return app(ClientRegistry::class)->register(new NewClient(
        'Cboxtax',
        ClientType::Public,
        redirectUris: ['https://cboxtax.test/cb'],
        grantTypes: ['authorization_code'],
        scopes: ['openid', 'profile'],
        firstParty: true,
    ))->client->client_id;
}

/** @return array<string, string> */
function selfServiceAuthorize(string $clientId, array $extra = []): array
{
    return [
        'client_id' => $clientId,
        'redirect_uri' => 'https://cboxtax.test/cb',
        'scope' => 'openid profile',
        'state' => 'st',
        ...$extra,
    ];
}

function selfServiceSignUp(array $fields = []): TestResponse
{
    return test()->from(route('signup'))->post(route('signup.register'), [
        'organization' => 'Anna\'s Bakery',
        'name' => 'Anna Berg',
        'email' => 'anna@bakery.example',
        'password' => 'a-strong-unbreached-passphrase',
        ...$fields,
    ]);
}

it('is off for an environment nobody has switched it on for', function (): void {
    ['environment' => $environment] = selfServiceTenant(enabled: false);

    expect(SelfServiceSignup::enabledFor($environment))->toBeFalse();

    // The page explains rather than 404s — the sign-in page it sits under is served here.
    test()->get(route('signup'))
        ->assertRedirect(route('login'))
        ->assertSessionHas('status', 'You need an invitation to join. Ask the person who runs your team for one.');

    // …and the sign-in page offers no way to create one.
    test()->get(route('login'))
        ->assertInertia(fn (AssertableInertia $page) => $page->where('signupOpen', false));
});

/**
 * A replayed or hand-built POST is refused on the server, not only hidden in the page —
 * and no account appears.
 */
it('refuses to create an account on a tenant environment with sign-up switched off', function (): void {
    selfServiceTenant(enabled: false);

    selfServiceSignUp()->assertForbidden();

    expect(app(Subjects::class)->findByEmail('anna@bakery.example'))->toBeNull();
})->group('security');

/**
 * The magic link provisions on first use, so under "open" it is a sign-up door too — and on
 * a tenant environment it used to follow the DEPLOYMENT's mode, which is open by default.
 * It follows the environment's switch now.
 */
it('mints no magic link for an unknown address when the environment has sign-up off', function (): void {
    Mail::fake();
    selfServiceTenant(enabled: false);

    test()->from(route('login'))->post(route('login.magic-link'), ['email' => 'stranger@example.com']);

    Mail::assertNothingSent();
})->group('security');

it('keeps the deployment\'s kill switch above the environment\'s switch', function (): void {
    selfServiceTenant(enabled: true);
    config(['cbox-id.signup.mode' => 'closed']);

    selfServiceSignUp()->assertForbidden();
})->group('security');

it('lets a stranger sign up and own their new organization when the environment allows it', function (): void {
    ['environment' => $environment] = selfServiceTenant(enabled: true);

    test()->get(route('signup'))->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page->where('createsIdp', false)->where('forApp', null));

    selfServiceSignUp()->assertRedirect(route('dashboard'));

    $anna = app(Subjects::class)->findByEmail('anna@bakery.example');
    $bakery = Organization::query()->where('name', 'Anna\'s Bakery')->sole();

    expect($anna)->not->toBeNull()
        // In the vendor's environment — not the platform root, where signups buy an IdP.
        ->and($bakery->environment_id)->toBe($environment->id)
        ->and(app(Memberships::class)->activeRole($bakery->id, (string) $anna?->id))->toBe(MembershipRole::Owner);

    // Nothing was provisioned in the root for her: she is the vendor's user, not a customer.
    expect(app(PlatformRoot::class)->run(fn () => app(Subjects::class)->findByEmail('anna@bakery.example')))->toBeNull();
});

/**
 * OIDC Prompt Create, end to end: the app sends `prompt=create`, the person signs up, and
 * the authorization finishes bound to the organization they just founded.
 */
it('sends prompt=create to sign-up and back into the authorization, bound to the new organization', function (): void {
    selfServiceTenant(enabled: true);
    $clientId = selfServiceApp();

    authorizeRequest(selfServiceAuthorize($clientId, ['prompt' => 'create']))
        ->assertRedirect(route('signup'));

    // The page says who it is signing up for.
    test()->get(route('signup'))
        ->assertInertia(fn (AssertableInertia $page) => $page->where('forApp', 'Cboxtax'));

    $resume = selfServiceSignUp()->assertRedirect()->headers->get('Location');

    expect(parse_url((string) $resume, PHP_URL_PATH))->toBe('/oauth/authorize')
        ->and($resume)->toContain('reauthed=1');

    // The resumed request does not send her to sign-up a second time; it issues.
    $callback = test()->get((string) $resume)->assertRedirect()->headers->get('Location');

    expect($callback)->toStartWith('https://cboxtax.test/cb?')
        ->and($callback)->toContain('code=');

    $bakery = Organization::query()->where('name', 'Anna\'s Bakery')->sole();

    expect(AuthorizationCode::query()->sole()->organization_id)->toBe($bakery->id);
});

it('refuses prompt=create and prompt=create_organization where sign-up is off', function (string $prompt, string $description): void {
    selfServiceTenant(enabled: false);
    $clientId = selfServiceApp();

    authorizeRequest(selfServiceAuthorize($clientId, ['prompt' => $prompt]))
        ->assertRedirect('https://cboxtax.test/cb?error=invalid_request'
            .'&error_description='.urlencode($description)
            .'&state=st&iss='.urlencode(app(IssuerResolver::class)->issuer()));
})->with([
    'create' => ['create', 'Self-service sign-up is not available here, so prompt=create cannot be honoured.'],
    'create_organization' => ['create_organization', 'Creating an organization is not available here, so prompt=create_organization cannot be honoured.'],
])->group('security');

/**
 * The create step's POST asks again: a page opened while sign-up was on must not found an
 * organization after an administrator switched it off.
 */
it('refuses the create step once the environment has switched sign-up off', function (): void {
    ['environment' => $environment, 'ownerId' => $ownerId] = selfServiceTenant(enabled: true);
    $clientId = selfServiceApp();

    // Somebody signed in to this environment.
    selfServiceSignUp(['email' => 'first@bakery.example', 'organization' => 'First'])->assertRedirect();

    $location = (string) authorizeRequest(selfServiceAuthorize($clientId, ['prompt' => 'create_organization']))
        ->assertRedirect()->headers->get('Location');
    $props = (array) test()->get($location)->assertOk()->inertiaProps();

    app(SelfServiceSignup::class)->set($environment->refresh(), false);

    test()->from($location)->post($props['storeHref'], ['name' => 'Second'])
        ->assertSessionHasErrors(['name' => 'Creating an organization is not available here. Ask an administrator to invite you to one.']);

    expect(Organization::query()->where('name', 'Second')->exists())->toBeFalse()
        ->and($ownerId)->not->toBe('');
})->group('security');

it('advertises the prompt values the environment actually honours', function (bool $enabled, array $expected): void {
    selfServiceTenant(enabled: $enabled);

    foreach (['/.well-known/openid-configuration', '/.well-known/oauth-authorization-server'] as $document) {
        expect(test()->getJson($document)->assertOk()->json('prompt_values_supported'))->toBe($expected);
    }
})->with([
    'sign-up off' => [false, ['none', 'login', 'consent', 'select_account', 'select_organization']],
    'sign-up on' => [true, ['none', 'login', 'consent', 'select_account', 'select_organization', 'create_organization', 'create']],
]);

// ── The switch itself ────────────────────────────────────────────────────────────────

it('lets the environment administrator switch sign-up on and off, and records who did', function (): void {
    ['environment' => $environment, 'ownerId' => $ownerId] = selfServiceTenant(enabled: false);
    actAsEnvironmentAdmin($ownerId, $environment->id);

    test()->get(route('environment.auth-policy'))->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->where('selfServiceSignup.decidedHere', true)
            ->where('selfServiceSignup.enabled', false));

    test()->from(route('environment.auth-policy'))
        ->put(route('environment.auth-policy.self-service-signup'), ['enabled' => true])
        ->assertRedirect(route('environment.auth-policy'));

    expect(SelfServiceSignup::enabledFor($environment->refresh()))->toBeTrue();

    test()->from(route('environment.auth-policy'))
        ->put(route('environment.auth-policy.self-service-signup'), ['enabled' => false]);

    expect(SelfServiceSignup::enabledFor($environment->refresh()))->toBeFalse();

    $actions = app(PlatformRoot::class)->run(fn () => AuditEntry::query()
        ->whereIn('action', ['environment.self_service_signup_enabled', 'environment.self_service_signup_disabled'])
        ->orderBy('id')
        ->get(['action', 'actor_id'])
        ->toArray());

    expect(array_column($actions, 'action'))->toBe(['environment.self_service_signup_enabled', 'environment.self_service_signup_disabled'])
        ->and(array_unique(array_column($actions, 'actor_id')))->toBe([$ownerId]);
});

/**
 * An organization's own administrator decides nothing about who may join the environment:
 * there is no such route on their plane, and the environment plane's refuses anybody who is
 * not administering the environment.
 */
it('gives an organization owner no way to open the environment\'s sign-up', function (): void {
    ['environment' => $environment] = selfServiceTenant(enabled: false);

    $owner = app(Subjects::class)->create('owner@bakery.example', 'Owner', 'a-strong-unbreached-passphrase');
    $organization = app(Organizations::class)->create(new NewOrganization('Bakery', 'bakery-owner'));
    app(Memberships::class)->add($organization->id, $owner->id, MembershipRole::Owner);
    signInAsSubject($owner->id);

    expect(test()->put('/sign-in-rules/self-service-signup', ['enabled' => true])->status())->toBeIn([404, 405]);

    $refused = test()->put(route('environment.auth-policy.self-service-signup'), ['enabled' => true]);

    expect($refused->status())->not->toBe(200)
        ->and(SelfServiceSignup::enabledFor($environment->refresh()))->toBeFalse();
})->group('security');
