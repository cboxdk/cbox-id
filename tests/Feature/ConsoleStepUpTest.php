<?php

declare(strict_types=1);

use App\Platform\Console\ConsoleScope;
use App\Platform\EnvironmentSudo;
use App\Platform\StepUpReason;
use Cbox\Id\Directory\Contracts\Directories;
use Cbox\Id\Directory\Models\Directory;
use Cbox\Id\ExternalActions\Models\ExternalActionEndpoint;
use Cbox\Id\OAuthServer\Contracts\ClientRegistry;
use Cbox\Id\OAuthServer\Enums\ClientType;
use Cbox\Id\OAuthServer\Models\Client;
use Cbox\Id\OAuthServer\ValueObjects\NewClient;
use Cbox\Id\Organization\Contracts\Organizations;
use Cbox\Id\Organization\ValueObjects\NewOrganization;
use Cbox\Id\TokenVault\Contracts\SecretVault;
use Cbox\Id\TokenVault\Models\VaultSecret;
use Cbox\Id\Webhooks\Contracts\WebhookRegistry;
use Cbox\Id\Webhooks\Models\WebhookEndpoint;
use Cbox\LaravelSiem\Models\LogStream;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia;
use Livewire\Features\SupportTesting\PersistentMiddleware;

uses(RefreshDatabase::class);

/**
 * @group security
 *
 * The environment console's step-up, asserted where it actually runs.
 *
 * TWO THINGS WERE WRONG. `env.sudo` was applied to the token vault and to nothing else,
 * while three other environment-plane pages mint or reveal a live credential — an OAuth
 * app's secret, a directory's SCIM bearer token, a webhook's signing secret — on a plane
 * where `mayManage()` returns an unconditional true for every tenant in the environment.
 * And the vault's own gate had no test that could see it: every write test drove the
 * component through `Volt::test()`, which never touches the update endpoint, so
 * {@see PersistentMiddleware} short-circuits and NO route
 * middleware runs. Deleting `RequireEnvironmentSudo` from the persistent list turned
 * exactly one structural assertion red and left 52 write tests passing.
 *
 * AND THEN ONLY HALF OF EACH PAGE. The gate landed on ROTATION and not on CREATION, which
 * left it guarding the more expensive of two routes to the same credential: registering a
 * second app hands over a client secret exactly as re-keying the first does. The creation
 * cases below are the other half, including two resources — inline hooks and log streams —
 * that have no rotation at all, so creation was their whole surface.
 *
 * So every test below goes through the REAL endpoint ({@see livewireUpdate()}), and each
 * asserts both directions: the write happens with the window open, and does not without.
 * A gate is only proven by the refusal.
 */
beforeEach(function (): void {
    ['envId' => $this->envId] = crudSetup();
});

/**
 * The vault, which had the gate but no test that ran it.
 *
 * The snapshot is captured WITH the window open and replayed after it closes, which is
 * both the only way to reach the page (its route middleware gates the GET too) and the
 * precise threat the persistent-middleware list exists for: a retained snapshot must not
 * keep working once the confirmation lapses, or the step-up is a one-time formality for
 * the life of the session.
 */
it('refuses a vault write on a snapshot whose step-up window has closed', function (): void {
    $secret = app(SecretVault::class)->store('K', 'stripe', 'sk_x');
    $url = route('environment.vault.show', ['secret' => $secret->id]);

    app(EnvironmentSudo::class)->confirm();
    $page = $this->get($url)->assertSuccessful();

    // The window closes — the administrator walks away, the 15 minutes lapse — while the
    // page, and the snapshot embedded in it, are still sitting in the browser.
    app(EnvironmentSudo::class)->forget();

    $snapshot = snapshotFor((string) $page->getContent(), 'console.vault.show');

    replaySnapshot($url, $snapshot, 'revoke')
        ->assertForbidden()
        ->assertJsonPath('sudo', route('environment.sudo'));

    expect(VaultSecret::query()->whereKey($secret->id)->value('revoked_at'))
        ->toBeNull('a replayed snapshot revoked a secret with no live step-up');
})->group('security');

it('allows a vault write while the step-up window is open', function (): void {
    $secret = app(SecretVault::class)->store('K', 'stripe', 'sk_x');

    app(EnvironmentSudo::class)->confirm();

    livewireUpdate(
        route('environment.vault.show', ['secret' => $secret->id]),
        'console.vault.show',
        'revoke',
    )->assertSuccessful();

    expect(VaultSecret::query()->whereKey($secret->id)->value('revoked_at'))->not->toBeNull();
})->group('security');

/**
 * And the three pages that had no gate at all.
 *
 * These are gated on the ACTION rather than the route, because each page is also where
 * ordinary, harmless detail is read — an app's redirect URIs, a directory's group
 * mappings, a webhook's deliveries — and a password prompt to read those is a gate people
 * learn to click through. So the page still serves; the button is what stops.
 */
it('refuses to rotate an app secret with no step-up, and rotates with one', function (): void {
    $client = app(ClientRegistry::class)->register(new NewClient(
        name: 'Tenant App',
        type: ClientType::Confidential,
        redirectUris: ['https://tenant.example/cb'],
        grantTypes: ['authorization_code'],
        scopes: ['openid'],
    ))->client;

    $url = route('environment.clients.show', ['client' => $client->id]);
    $before = Client::query()->whereKey($client->id)->value('secret_hash');

    // Refused: sent to the step-up rather than answered with a credential.
    livewireUpdate($url, 'console.clients.show', 'rotateSecret')
        ->assertSuccessful()
        ->assertJsonPath('components.0.effects.redirect', route('environment.sudo'));

    expect(Client::query()->whereKey($client->id)->value('secret_hash'))
        ->toBe($before, 'an app secret was rotated with no step-up');

    app(EnvironmentSudo::class)->confirm();

    livewireUpdate($url, 'console.clients.show', 'rotateSecret')->assertSuccessful();

    expect(Client::query()->whereKey($client->id)->value('secret_hash'))->not->toBe($before);
})->group('security');

it('refuses to rotate a SCIM bearer token with no step-up, and rotates with one', function (): void {
    $org = app(Organizations::class)->create(new NewOrganization('Tenant', 'tenant-scim'));
    grantFeature($org->id, 'scim');
    $directory = app(Directories::class)->register($org->id, 'Okta')->directory;

    $url = route('environment.directories.show', ['directory' => $directory->id]);
    $before = $directory->bearer_token_hash;

    livewireUpdate($url, 'console.directories.show', 'regenerateToken')
        ->assertSuccessful()
        ->assertJsonPath('components.0.effects.redirect', route('environment.sudo'));

    expect($directory->fresh()?->bearer_token_hash)
        ->toBe($before, 'a live SCIM bearer token was minted with no step-up');

    app(EnvironmentSudo::class)->confirm();

    livewireUpdate($url, 'console.directories.show', 'regenerateToken')->assertSuccessful();

    expect($directory->fresh()?->bearer_token_hash)->not->toBe($before);
})->group('security');

it('refuses to rotate a webhook signing secret with no step-up, and rotates with one', function (): void {
    $org = app(Organizations::class)->create(new NewOrganization('Tenant', 'tenant-hook'));
    $endpoint = app(WebhookRegistry::class)->register(
        $org->id,
        'https://hooks.tenant.example/events',
        ['user.created'],
    )->endpoint;

    // A real POST to the real route, so the route's middleware stack runs. The Livewire
    // helper this replaces invoked the component directly and never routed, which is why
    // it could not have caught a gate that had moved to the route.
    $rotate = route('environment.webhooks.rotate', ['webhook' => $endpoint->id]);
    $before = WebhookEndpoint::query()->whereKey($endpoint->id)->value('secret_encrypted');

    $this->post($rotate)->assertRedirect(route('environment.sudo'));

    expect(WebhookEndpoint::query()->whereKey($endpoint->id)->value('secret_encrypted'))
        ->toBe($before, 'a webhook signing secret was rotated with no step-up');

    app(EnvironmentSudo::class)->confirm();

    $this->post($rotate)->assertRedirect();

    expect(WebhookEndpoint::query()->whereKey($endpoint->id)->value('secret_encrypted'))
        ->not->toBe($before);
})->group('security');

/**
 * AND THE PAGES THAT MINT THE SAME CREDENTIALS FOR THE FIRST TIME.
 *
 * Rotation was gated and creation was not, which left the gate guarding the more
 * expensive of two ways to the same secret: rather than rotate a tenant's app secret, an
 * unattended env-admin session registers a second app and reads its secret off the
 * confirmation screen. Every credential below is minted here and revealed exactly once —
 * a client secret, a SCIM bearer token, a webhook signing secret, an inline-hook signing
 * secret, a log-stream key — so every one of them now asks.
 *
 * THE SNAPSHOT IS CAPTURED WITH THE WINDOW OPEN and replayed after it closes. That is not
 * a convenience: these pages ask for the step-up in `mount()` too, so a browser can only
 * hold a create form at all if a window was open when it loaded. Replaying it is exactly
 * the threat — the form is real, the checksum is real, and the only thing that has
 * changed is that the administrator can no longer be shown to be there. `mount()` is not
 * what refuses it; the check in front of the write is, which is why deleting that check
 * alone turns each of these red.
 */
it('refuses to register an app with no step-up, and registers with one', function (): void {
    $org = app(Organizations::class)->create(new NewOrganization('Tenant', 'tenant-newapp'));
    session()->put(ConsoleScope::SELECTION_KEY, $org->id);

    $url = route('environment.clients.create');
    $form = [
        'name' => 'Support Portal',
        'redirectUris' => 'https://portal.tenant.example/callback',
    ];

    app(EnvironmentSudo::class)->confirm();
    $page = $this->get($url)->assertSuccessful();
    $snapshot = snapshotFor((string) $page->getContent(), 'console.clients.create');
    app(EnvironmentSudo::class)->forget();

    replaySnapshot($url, $snapshot, 'create', [], $form)
        ->assertSuccessful()
        ->assertJsonPath('components.0.effects.redirect', route('environment.sudo'));

    expect(Client::query()->where('name', 'Support Portal')->exists())
        ->toBeFalse('an app secret was minted with no step-up, by registering the app instead of rotating it');

    app(EnvironmentSudo::class)->confirm();

    replaySnapshot($url, $snapshot, 'create', [], $form)->assertSuccessful();

    expect(Client::query()->where('name', 'Support Portal')->exists())->toBeTrue();
})->group('security');

it('refuses to register a SCIM directory with no step-up, and registers with one', function (): void {
    $org = app(Organizations::class)->create(new NewOrganization('Tenant', 'tenant-newdir'));
    grantFeature($org->id, 'scim');
    session()->put(ConsoleScope::SELECTION_KEY, $org->id);

    $url = route('environment.directories.create');
    $form = ['provider' => 'scim', 'name' => 'Okta'];

    app(EnvironmentSudo::class)->confirm();
    $page = $this->get($url)->assertSuccessful();
    $snapshot = snapshotFor((string) $page->getContent(), 'console.directories.create');
    app(EnvironmentSudo::class)->forget();

    replaySnapshot($url, $snapshot, 'register', [], $form)
        ->assertSuccessful()
        ->assertJsonPath('components.0.effects.redirect', route('environment.sudo'));

    expect(Directory::query()->where('name', 'Okta')->exists())
        ->toBeFalse('a SCIM bearer token was minted with no step-up');

    app(EnvironmentSudo::class)->confirm();

    replaySnapshot($url, $snapshot, 'register', [], $form)->assertSuccessful();

    expect(Directory::query()->where('name', 'Okta')->exists())->toBeTrue();
})->group('security');

it('refuses to register a webhook endpoint with no step-up, and registers with one', function (): void {
    $org = app(Organizations::class)->create(new NewOrganization('Tenant', 'tenant-newhook'));
    session()->put(ConsoleScope::SELECTION_KEY, $org->id);

    $store = route('environment.webhooks.store');
    $form = [
        'url' => 'https://collector.attacker.example/events',
        'eventTypes' => ['user.created'],
    ];

    // POSTED DIRECTLY, without visiting the form first. That is the attack this gate
    // exists to refuse: the create PAGE asks for the step-up at the door, and a gate that
    // only lived there would be satisfied by never opening the door.
    $this->post($store, $form)->assertRedirect(route('environment.sudo'));

    expect(WebhookEndpoint::query()->where('url', $form['url'])->exists())
        ->toBeFalse('a signing secret was minted with no step-up, and a live event feed pointed somewhere new');

    app(EnvironmentSudo::class)->confirm();

    $this->post($store, $form)->assertRedirect();

    expect(WebhookEndpoint::query()->where('url', $form['url'])->exists())->toBeTrue();
})->group('security');

it('refuses to register an inline hook with no step-up, and registers with one', function (): void {
    // The registry resolves the endpoint's host before it will store it; the step-up is
    // what is under test here, not the SSRF guard, which has its own coverage.
    config(['cbox-id.external_actions.verify_url' => false]);

    $org = app(Organizations::class)->create(new NewOrganization('Tenant', 'tenant-inline'));
    session()->put(ConsoleScope::SELECTION_KEY, $org->id);

    $url = route('environment.hooks.create');
    $form = [
        'hook' => 'token_minting',
        'url' => 'https://inline.example.test/hook',
    ];

    app(EnvironmentSudo::class)->confirm();
    $page = $this->get($url)->assertSuccessful();
    $snapshot = snapshotFor((string) $page->getContent(), 'console.hooks.create');
    app(EnvironmentSudo::class)->forget();

    replaySnapshot($url, $snapshot, 'register', [], $form)
        ->assertSuccessful()
        ->assertJsonPath('components.0.effects.redirect', route('environment.sudo'));

    expect(ExternalActionEndpoint::query()->where('url', $form['url'])->exists())
        ->toBeFalse('an endpoint was planted inside the sign-in path with no step-up');

    app(EnvironmentSudo::class)->confirm();

    replaySnapshot($url, $snapshot, 'register', [], $form)->assertSuccessful();

    expect(ExternalActionEndpoint::query()->where('url', $form['url'])->exists())->toBeTrue();
})->group('security');

/**
 * The log-stream create page, which is gated on the ROUTE rather than in the component.
 *
 * It is the environment plane's own page rather than one of the merged pair-per-plane
 * components, so `env.sudo` names the plane outright instead of asking ConsoleStepUp to
 * infer it — and the refusal therefore looks like the vault's: a 403 carrying where to
 * step up, not a redirect effect. Same property, different messenger.
 */
it('refuses to register a log stream with no step-up, and registers with one', function (): void {
    config(['siem.http.verify_url' => false]);

    $url = route('environment.audit-streams.create');
    $form = [
        'name' => 'SIEM',
        'destination' => 'generic_json',
        'endpointUrl' => 'https://siem.example.test/ingest',
        'auth' => 'hmac',
    ];

    app(EnvironmentSudo::class)->confirm();
    $page = $this->get($url)->assertSuccessful();
    $snapshot = snapshotFor((string) $page->getContent(), 'console.audit-streams.create');
    app(EnvironmentSudo::class)->forget();

    replaySnapshot($url, $snapshot, 'create', [], $form)
        ->assertForbidden()
        ->assertJsonPath('sudo', route('environment.sudo'));

    expect(LogStream::query()->where('name', 'SIEM')->exists())
        ->toBeFalse('a log-stream signing key was minted with no step-up');

    app(EnvironmentSudo::class)->confirm();

    replaySnapshot($url, $snapshot, 'create', [], $form)->assertSuccessful();

    expect(LogStream::query()->where('name', 'SIEM')->exists())->toBeTrue();
})->group('security');

/**
 * And the copy on the screen the refusal sends them to.
 *
 * A step-up that says "this is a protected action" is one people learn to type a password
 * past; the sentence is what makes it a decision. It is bound to the intent it was
 * recorded for ({@see StepUpReason}), so it cannot be left behind to
 * caption an unrelated prompt raised later by one of the route middlewares.
 */
it('tells the administrator why the step-up appeared', function (): void {
    $org = app(Organizations::class)->create(new NewOrganization('Tenant', 'tenant-why'));
    session()->put(ConsoleScope::SELECTION_KEY, $org->id);

    $url = route('environment.clients.create');

    app(EnvironmentSudo::class)->confirm();
    $page = $this->get($url)->assertSuccessful();
    $snapshot = snapshotFor((string) $page->getContent(), 'console.clients.create');
    app(EnvironmentSudo::class)->forget();

    // A form that would otherwise have succeeded: the step-up is asked LAST, after every
    // refusal, so a half-filled one is answered with validation errors and never reaches it.
    replaySnapshot($url, $snapshot, 'create', [], [
        'name' => 'Support Portal',
        'redirectUris' => 'https://portal.tenant.example/callback',
    ])->assertSuccessful();

    // The REASON prop, not the rendered sentence. The step-up page draws it in the
    // browser, and the fallback wording ("This is a protected action.") belongs to the
    // page rather than to the thing that raised the prompt — so the server's answer for
    // "no reason was given" is null, and that is what is asserted.
    $this->get(route('environment.sudo'))
        ->assertSuccessful()
        ->assertInertia(
            fn (AssertableInertia $page) => $page
                ->component('auth/sudo')
                ->where('plane', 'environment')
                ->where('reason', fn (?string $reason): bool => is_string($reason)
                    && str_contains($reason, 'Registering an app issues a client secret')),
        );

    // Spent with the intent it belongs to: once the step-up is through, an unrelated
    // prompt raised later must not still be captioned with it.
    session()->forget('environment.sudo.intended');

    $this->get(route('environment.sudo'))
        ->assertSuccessful()
        ->assertInertia(
            fn (AssertableInertia $page) => $page
                ->component('auth/sudo')
                ->where('reason', null),
        );
})->group('security');
