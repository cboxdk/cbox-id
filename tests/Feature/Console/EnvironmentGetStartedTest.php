<?php

declare(strict_types=1);

use App\Platform\Actions\ActionTrail;
use App\Platform\Appearance\BrandImage;
use App\Platform\Appearance\BrandImages;
use App\Platform\Appearance\BrandImageUpload;
use App\Platform\Onboarding\EnvironmentChecklist;
use App\Platform\Onboarding\EnvironmentStep;
use App\Support\CliClient;
use Cbox\Id\Federation\Contracts\Connections;
use Cbox\Id\Federation\Enums\ConnectionType;
use Cbox\Id\Identity\Contracts\Subjects;
use Cbox\Id\Kernel\Audit\Models\AuditEntry;
use Cbox\Id\Kernel\Tenancy\Contracts\EnvironmentContext;
use Cbox\Id\Kernel\Tenancy\GenericEnvironment;
use Cbox\Id\OAuthServer\Contracts\ClientRegistry;
use Cbox\Id\OAuthServer\Enums\ClientType;
use Cbox\Id\OAuthServer\Models\AccessToken;
use Cbox\Id\OAuthServer\Models\Client;
use Cbox\Id\OAuthServer\ValueObjects\NewClient;
use Cbox\Id\Organization\Contracts\Organizations;
use Cbox\Id\Organization\Enums\MembershipRole;
use Cbox\Id\Organization\ValueObjects\NewOrganization;
use Cbox\Id\Platform\Contracts\EnvironmentApiKeys;
use Cbox\Id\Platform\PlatformRoot;
use Illuminate\Support\Str;

/*
|--------------------------------------------------------------------------
| The environment console's "Get started": a checklist that ticks itself, and a quickstart
| that ends with somebody signed in.
|--------------------------------------------------------------------------
*/

/** An environment administrator of a fresh tenant, and what was provisioned for them. */
function gettingStarted(): array
{
    multiTenantDeployment();
    $tenant = provisionAccount();

    serveOnTestHost($tenant['environment']);
    app(EnvironmentContext::class)->set(GenericEnvironment::of($tenant['environment']->id));
    actAsEnvironmentAdmin($tenant['subjectId'], $tenant['environment']->id);

    return $tenant;
}

/** @return array<string, bool> */
function checklistNow(array $tenant): array
{
    return app(EnvironmentChecklist::class)->progress($tenant['organization']->id);
}

function personToken(string $clientId): void
{
    $user = app(Subjects::class)->create(Str::random(8).'@signed-in.test', 'Signed In', 'supersecret123');

    AccessToken::query()->create([
        'jti' => (string) Str::ulid(),
        'client_id' => $clientId,
        'user_id' => $user->id,
        'scopes' => ['openid'],
        'expires_at' => now()->addHour(),
    ]);
}

it('ticks each step from what the environment holds, never from a checkbox', function (): void {
    $tenant = gettingStarted();

    // The CLI's own app is the environment's, not the developer's.
    app(ClientRegistry::class)->register(new NewClient(CliClient::NAME, ClientType::Public));
    expect(array_filter(checklistNow($tenant)))->toBe([]);

    $app = app(ClientRegistry::class)->register(new NewClient('Shop', redirectUris: ['http://localhost:3000/auth/callback']))->client;
    $done = checklistNow($tenant);

    expect($done[EnvironmentStep::CreateApp->value])->toBeTrue()
        ->and($done[EnvironmentStep::AddRedirect->value])->toBeTrue()
        // localhost is not live.
        ->and($done[EnvironmentStep::GoLive->value])->toBeFalse()
        ->and($done[EnvironmentStep::FirstSignIn->value])->toBeFalse();

    personToken($app->client_id);
    $customer = app(Organizations::class)->create(new NewOrganization('First Customer', 'first-customer'));
    app(Connections::class)->create($customer->id, ConnectionType::Oidc, 'Okta', ['issuer' => 'https://okta.example', 'client_id' => 'a', 'client_secret' => 'b']);
    $key = app(EnvironmentApiKeys::class)->issue($tenant['environment']->id, 'Claude Code', ['users:read'])->key;
    // An UPLOADED logo brands the sign-in; a remote URL left in settings does not.
    $tenant['environment']->forceFill(['settings' => [...($tenant['environment']->settings ?? []), 'brand_logo_url' => 'https://cdn.example/logo.svg']])->save();
    expect(checklistNow($tenant)[EnvironmentStep::BrandSignIn->value])->toBeFalse();
    app(BrandImages::class)->store(BrandImageUpload::fromDataUri(BrandImage::Logo, pngDataUri()), null);
    app(ClientRegistry::class)->register(new NewClient('Shop (live)', redirectUris: ['https://shop.example/auth/callback']));

    $done = checklistNow($tenant);

    expect($done[EnvironmentStep::FirstSignIn->value])->toBeTrue()
        ->and($done[EnvironmentStep::CreateOrganization->value])->toBeTrue()
        ->and($done[EnvironmentStep::ConnectSso->value])->toBeTrue()
        ->and($done[EnvironmentStep::BrandSignIn->value])->toBeTrue()
        ->and($done[EnvironmentStep::GoLive->value])->toBeTrue()
        // Minted is not connected: an agent counts once it has made a call.
        ->and($done[EnvironmentStep::ConnectAgent->value])->toBeFalse()
        ->and($done[EnvironmentStep::InviteTeammate->value])->toBeFalse();

    $key->forceFill(['last_used_at' => now()])->save();
    app(PlatformRoot::class)->run(fn () => memberWithRole($tenant['organization']->id, MembershipRole::Admin, 'teammate@acme.example'));

    $done = checklistNow($tenant);

    expect($done[EnvironmentStep::ConnectAgent->value])->toBeTrue()
        ->and($done[EnvironmentStep::InviteTeammate->value])->toBeTrue();
});

it('shows the checklist on top of Overview until it is put away, per person', function (): void {
    gettingStarted();

    $checklist = $this->get(route('environment.home'))->assertOk()->inertiaProps('checklist');

    expect($checklist)->not->toBeNull()
        ->and($checklist['next'])->toBe(EnvironmentStep::CreateApp->title())
        ->and($checklist['href'])->toBe(route('environment.get-started'));

    $this->post(route('environment.get-started.dismiss'))->assertRedirect(route('environment.home'));

    expect($this->get(route('environment.home'))->assertOk()->inertiaProps('checklist'))->toBeNull()
        ->and($this->get(route('environment.get-started'))->assertOk()->inertiaProps('dismissed'))->toBeTrue();

    $this->delete(route('environment.get-started.restore'))->assertRedirect(route('environment.get-started'));

    expect($this->get(route('environment.home'))->assertOk()->inertiaProps('checklist'))->not->toBeNull();
});

it('puts Get started on the Home area of the rail', function (): void {
    gettingStarted();

    $areas = (array) $this->get(route('environment.home'))->assertOk()->inertiaProps('shell.areas');
    $home = collect($areas)->firstWhere('label', 'Home');

    expect(collect($home['pages'] ?? [])->pluck('route')->all())->toContain('environment.get-started');
});

it('creates the app for the framework picked, through apps.create, and waits for the first sign-in', function (): void {
    gettingStarted();
    confirmEnvironmentStepUp();

    $response = $this->post(route('environment.get-started.app'), ['framework' => 'nextjs', 'name' => 'Storefront']);

    $app = Client::query()->where('name', 'Storefront')->sole();

    $response->assertRedirect(route('environment.get-started', ['framework' => 'nextjs', 'app' => $app->id]));

    // The secret is on the flash channel, once — never in the page's props.
    expect(flashed('revealedSecret'))->toStartWith('csec_')
        ->and($app->redirect_uris)->toBe(['http://localhost:3000/auth/callback'])
        ->and($app->type)->toBe(ClientType::Confidential);

    $created = AuditEntry::query()->where('action', 'app.created')->orderByDesc('sequence')->first();
    expect($created?->context[ActionTrail::VIA] ?? null)->toBe('console');

    $page = $this->get(route('environment.get-started', ['framework' => 'nextjs', 'app' => $app->id]))->assertOk();

    expect($page->inertiaProps('quickstart.install.0.code'))->toBe('npm install @cboxdk/id-js jose')
        ->and($page->inertiaProps('quickstartGuide'))->toEndWith('/quickstarts/nextjs')
        ->and($page->inertiaProps('quickstart.env'))->toContain('CBOX_ID_CLIENT_ID='.$app->client_id)
        ->and($page->inertiaProps('quickstart.env'))->toContain('CBOX_ID_CLIENT_SECRET=<your client secret>')
        ->and($page->inertiaProps('quickstart.env'))->not->toContain('csec_')
        ->and($page->inertiaProps('signedIn'))->toBeFalse();

    personToken($app->client_id);

    expect($this->get(route('environment.get-started', ['framework' => 'nextjs', 'app' => $app->id]))->inertiaProps('signedIn'))->toBeTrue();
});

it('makes a browser app public, with no secret anywhere in its snippet', function (): void {
    gettingStarted();
    confirmEnvironmentStepUp();

    $this->post(route('environment.get-started.app'), ['framework' => 'react'])->assertRedirect();

    $app = Client::query()->where('name', 'My React app')->sole();

    expect($app->type)->toBe(ClientType::Public)
        ->and($app->redirect_uris)->toBe(['http://localhost:5173/callback'])
        ->and(flashed('revealedSecret'))->toBeNull();

    $env = (string) $this->get(route('environment.get-started', ['framework' => 'react', 'app' => $app->id]))->inertiaProps('quickstart.env');

    // No secret to fill in: the page's optional line for a Web app stays commented out.
    expect($env)->toContain('CBOX_ID_CLIENT_ID='.$app->client_id)
        ->not->toContain('<your client secret>')
        ->not->toMatch('/^CBOX_ID_CLIENT_SECRET=/m');
});

it('asks for the step-up before minting a secret, and refuses an unknown framework', function (): void {
    gettingStarted();

    $this->post(route('environment.get-started.app'), ['framework' => 'nextjs'])->assertRedirect(route('environment.sudo'));
    expect(Client::query()->count())->toBe(0);

    confirmEnvironmentStepUp();

    $this->from(route('environment.get-started'))->post(route('environment.get-started.app'), ['framework' => 'cobol'])
        ->assertSessionHasErrors('framework');
});
