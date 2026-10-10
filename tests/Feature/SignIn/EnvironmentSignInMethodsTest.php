<?php

declare(strict_types=1);

use App\Platform\Turnstile;
use Cbox\Id\Federation\Contracts\Connections;
use Cbox\Id\Federation\Contracts\SignInProviders;
use Cbox\Id\Federation\Enums\ConnectionType;
use Cbox\Id\FrontendApi\Contracts\PublishableKeys;
use Cbox\Id\FrontendApi\Enums\KeyMode;
use Cbox\Id\Identity\Contracts\AuthPolicies;
use Cbox\Id\Identity\Contracts\MagicLink;
use Cbox\Id\Identity\Contracts\SessionManager;
use Cbox\Id\Identity\Contracts\Subjects;
use Cbox\Id\Identity\Models\Session;
use Cbox\Id\Identity\ValueObjects\AuthPolicy;
use Cbox\Id\Kernel\Tenancy\Contracts\EnvironmentContext;
use Cbox\Id\Kernel\Tenancy\GenericEnvironment;
use Cbox\Id\Organization\Contracts\Organizations;
use Cbox\Id\Organization\Enums\MembershipRole;
use Cbox\Id\Organization\Models\Organization;
use Cbox\Id\Organization\ValueObjects\NewOrganization;
use Cbox\Id\Platform\Contracts\EnvironmentApiKeys;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia;

/*
|--------------------------------------------------------------------------
| Passkeys, magic links, the bot challenge and session lengths, per environment.
|--------------------------------------------------------------------------
|
| They were the deployment's alone. They are on the environment's authentication policy
| now, under the deployment's ceiling — and switched off means switched off at every door:
| the button is not drawn, the endpoint refuses, a link already mailed stops working.
*/

beforeEach(function (): void {
    installedDeployment();
    Mail::fake();
});

function esmPolicy(AuthPolicy $policy): void
{
    app(AuthPolicies::class)->setForEnvironment($policy);
}

function esmOrg(string $name = 'Acme'): Organization
{
    return app(Organizations::class)->create(new NewOrganization($name, Str::slug($name).'-'.Str::lower(Str::random(4))));
}

function esmProvider(?string $organizationId, string $provider): string
{
    $connection = app(SignInProviders::class)->create($organizationId, $provider, ConnectionType::OAuth2, ucfirst($provider), [
        'provider' => $provider, 'client_id' => 'id', 'client_secret' => 'secret',
    ]);
    app(Connections::class)->activate($organizationId, $connection->id);

    return $connection->id;
}

// ── The hosted sign-in page ─────────────────────────────────────────────────

it('draws passkeys and magic links until the environment switches them off', function (): void {
    $this->get('/login')->assertOk()->assertInertia(fn (AssertableInertia $page) => $page
        ->where('methods.passkeys', true)
        ->where('methods.magicLink', true));

    esmPolicy(new AuthPolicy(passkeys: false, magicLink: false));

    $this->get('/login')->assertOk()->assertInertia(fn (AssertableInertia $page) => $page
        ->where('methods.passkeys', false)
        ->where('methods.magicLink', false));
});

it('shows the environment\'s social providers to everybody, and an organization\'s own in their place on its page', function (): void {
    $acme = esmOrg('Acme');
    $bank = esmOrg('Bank');
    $environmentGitHub = esmProvider(null, 'github');
    esmProvider(null, 'discord');
    $acmeGitHub = esmProvider($acme->id, 'github');
    app(SignInProviders::class)->stopInheriting($bank->id, 'discord');

    $urls = fn (string $path): array => collect($this->get($path)->assertOk()->viewData('page')['props']['providers'])
        ->mapWithKeys(fn (array $button): array => [$button['provider'] => $button['url']])->all();

    // The plain page, before anybody has said who they are: the environment's.
    expect($urls('/login'))->toBe([
        'discord' => url('/sso/oauth2/'.app(SignInProviders::class)->environmentProviders()[0]->id.'/redirect'),
        'github' => url('/sso/oauth2/'.$environmentGitHub.'/redirect'),
    ])
        // Acme's page: its own GitHub, the environment's Discord.
        ->and($urls('/o/'.$acme->slug.'/login')['github'])->toBe(url('/sso/oauth2/'.$acmeGitHub.'/redirect'))
        ->and(array_keys($urls('/o/'.$acme->slug.'/login')))->toBe(['discord', 'github'])
        // The bank turned Discord off for its page.
        ->and(array_keys($urls('/o/'.$bank->slug.'/login')))->toBe(['github']);
});

// ── Switched off means refused ─────────────────────────────────────────────

it('refuses passkey sign-in where passkeys are off', function (): void {
    $this->postJson(route('passkeys.login.options'))->assertOk();

    esmPolicy(new AuthPolicy(passkeys: false));

    $this->postJson(route('passkeys.login.options'))->assertForbidden()
        ->assertJsonPath('error', __('auth.login.method_off.passkeys'));
    $this->postJson(route('passkeys.login'), ['id' => 'anything'])->assertForbidden();
})->group('security');

it('refuses to send or redeem a magic link where they are off — a link mailed before included', function (): void {
    app(Subjects::class)->create('dana@acme.test', 'Dana', 'supersecret123');
    $token = app(MagicLink::class)->request('dana@acme.test');

    esmPolicy(new AuthPolicy(magicLink: false));

    $this->from('/login')->post(route('login.magic-link'), ['email' => 'dana@acme.test'])
        ->assertRedirect(route('login'))
        ->assertSessionHasErrors(['email' => __('auth.login.method_off.magic_link')]);

    Mail::assertNothingSent();

    $this->get(route('magic.redeem', $token))->assertRedirect(route('login'));
    $this->post(route('magic.redeem.store', $token))->assertRedirect(route('login'));

    expect(Session::query()->count())->toBe(0);
})->group('security');

it('lets the deployment switch a method off whatever the environment says', function (): void {
    esmPolicy(new AuthPolicy(passkeys: true, magicLink: true));
    config()->set('cbox-id.sign_in.passkeys', false);

    $this->postJson(route('passkeys.login.options'))->assertForbidden();
    $this->get('/login')->assertInertia(fn (AssertableInertia $page) => $page
        ->where('methods.passkeys', false)
        ->where('methods.magicLink', true));
})->group('security');

it('refuses to mail a new user a sign-in link where magic links are off, before creating them', function (): void {
    multiTenantDeployment();
    $tenant = provisionAccount();
    serveOnTestHost($tenant['environment']);
    app(EnvironmentContext::class)->set(GenericEnvironment::of($tenant['environment']->id));
    $key = app(EnvironmentApiKeys::class)->issue($tenant['environment']->id, 'Users', ['users:write'])->plaintext;

    esmPolicy(new AuthPolicy(magicLink: false));

    $this->withToken($key)->postJson('/api/v1/users', ['email' => 'new@acme.test', 'send_sign_in_link' => true])
        ->assertUnprocessable()
        ->assertJsonPath('error', 'magic_link_disabled');

    expect(app(Subjects::class)->findByEmail('new@acme.test'))->toBeNull();
    Mail::assertNothingSent();
});

it('stops offering a passkey to add where passkeys are off, and keeps the ones people have', function (): void {
    actingAsRole(MembershipRole::Member);

    esmPolicy(new AuthPolicy(passkeys: false));

    $this->get(route('account'))->assertOk()->assertInertia(fn (AssertableInertia $page) => $page
        ->where('passkeysEnabled', false)
        ->has('passkeys'));
});

it('tells an embedded sign-in which methods to draw', function (): void {
    $key = app(PublishableKeys::class)->issue('Site', KeyMode::Test, ['https://acme.test']);

    esmPolicy(new AuthPolicy(magicLink: false));

    $this->withHeaders(['X-Cbox-Publishable-Key' => $key->key, 'Origin' => 'https://acme.test'])
        ->getJson('/frontend/v1/config')
        ->assertOk()
        ->assertJsonPath('methods.passkeys', true)
        ->assertJsonPath('methods.magic_link', false);
});

it('stops challenging flagged sign-ups where the environment switched the bot challenge off', function (): void {
    config()->set('services.turnstile.site_key', 'site-key');
    config()->set('services.turnstile.secret_key', 'secret-key');

    expect(app(Turnstile::class)->enabled())->toBeTrue()
        ->and(app(Turnstile::class)->siteKey())->toBe('site-key');

    esmPolicy(new AuthPolicy(botChallenge: false));

    expect(app(Turnstile::class)->configured())->toBeTrue()
        ->and(app(Turnstile::class)->enabled())->toBeFalse()
        ->and(app(Turnstile::class)->siteKey())->toBe('')
        // Not configured at the deployment, the environment's switch turns nothing on.
        ->and((function (): bool {
            config()->set('services.turnstile.site_key', '');
            esmPolicy(new AuthPolicy(botChallenge: true));

            return app(Turnstile::class)->enabled();
        })())->toBeFalse();
});

// ── Session lengths, applied at sign-in ─────────────────────────────────────

it('starts a password sign-in\'s session with the environment\'s lifetime, and ends it when idle', function (): void {
    config()->set('cbox-id.sessions.ttl_minutes', 480);
    config()->set('cbox-id.sessions.idle_minutes', 30);
    esmPolicy(new AuthPolicy(sessionIdleMinutes: 10, sessionAbsoluteMinutes: 60));
    app(Subjects::class)->create('dana@acme.test', 'Dana', 'supersecret123');

    $this->freezeSecond();
    $this->post(route('login.attempt'), ['email' => 'dana@acme.test', 'password' => 'supersecret123'])->assertRedirect();

    $session = Session::query()->latest('created_at')->firstOrFail();

    expect($session->expires_at->equalTo(now()->addMinutes(60)))->toBeTrue();

    $this->travel(11)->minutes();

    expect(app(SessionManager::class)->active($session->id))->toBeNull();
});

// ── The policy, through the management API ─────────────────────────────────

it('changes the environment\'s methods and session lengths, and refuses them on an organization or past the deployment', function (): void {
    multiTenantDeployment();
    $tenant = provisionAccount();
    serveOnTestHost($tenant['environment']);
    app(EnvironmentContext::class)->set(GenericEnvironment::of($tenant['environment']->id));
    $key = app(EnvironmentApiKeys::class)->issue($tenant['environment']->id, 'Policy', ['signin:read', 'signin:write'])->plaintext;
    config()->set('cbox-id.sessions.ttl_minutes', 480);
    config()->set('cbox-id.sessions.idle_minutes', 30);
    $acme = esmOrg();

    $this->withToken($key)->patchJson('/api/v1/sign-in/policy', [
        'passkeys' => false, 'magic_link' => false, 'session_idle_minutes' => 15, 'session_absolute_minutes' => 120, 'bot_challenge' => false,
    ])->assertOk()
        ->assertJsonPath('data.policy.passkeys', false)
        ->assertJsonPath('data.policy.session_absolute_minutes', 120)
        ->assertJsonPath('data.in_force.magic_link', false)
        ->assertJsonPath('data.in_force.session_idle_minutes', 15)
        ->assertJsonPath('data.deployment.session_absolute_minutes', 480)
        ->assertJsonPath('data.deployment.passkeys', true);

    // Password rules sent alone leave the methods as they are.
    $this->withToken($key)->patchJson('/api/v1/sign-in/policy', ['min_length' => 14])->assertOk()
        ->assertJsonPath('data.policy.passkeys', false);

    $this->withToken($key)->patchJson('/api/v1/sign-in/policy', ['organization_id' => $acme->id, 'passkeys' => true])
        ->assertUnprocessable()->assertJsonPath('error', 'environment_only');

    $refused = $this->withToken($key)->patchJson('/api/v1/sign-in/policy', ['session_absolute_minutes' => 600, 'session_idle_minutes' => 45])
        ->assertUnprocessable()->assertJsonPath('error', 'past_deployment_limit');

    expect($refused->json('message'))->toContain('480 minutes')->toContain('30 minutes')
        ->and(app(AuthPolicies::class)->forEnvironment()->sessionAbsoluteMinutes)->toBe(120)
        ->and(app(AuthPolicies::class)->resolve($acme->id)->passkeys)->toBeFalse();
});
