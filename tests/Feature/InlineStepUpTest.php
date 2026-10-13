<?php

declare(strict_types=1);

use App\Http\Middleware\HandleInertiaRequests;
use App\Platform\EnvironmentSudo;
use App\Platform\PlatformAuth;
use App\Platform\StepUpPrompt;
use App\Platform\StepUpReason;
use App\Platform\Sudo;
use Cbox\Id\Identity\Contracts\SessionManager;
use Cbox\Id\Identity\Models\Session;
use Cbox\Id\Organization\Contracts\Organizations;
use Cbox\Id\Organization\ValueObjects\NewOrganization;
use Cbox\Id\TokenVault\Contracts\SecretVault;
use Cbox\Id\TokenVault\Models\VaultSecret;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Testing\TestResponse;
use Tests\Support\FormOrganization;

uses(RefreshDatabase::class);

/*
|--------------------------------------------------------------------------
| Step-up IN PLACE — "Confirm it's you" without leaving the page.
|--------------------------------------------------------------------------
|
| A gated click used to be answered with a redirect to the full-page `/sudo` screen, which
| came back to the PAGE and never to the ACTION: "Add passkey", password, and you were
| back on My account with no passkey. Every gated button behaved the same way.
|
| Now a request a script made — every Inertia visit, the passkey ceremony's JSON calls — is
| refused with a 403 the page recognises (`X-Cbox-Step-Up` and a `step_up` body), opens the
| dialog where it is, confirms as JSON, and replays the request. A document navigation
| still gets the full page. These hold that contract on both planes, and hold the planes
| apart.
*/

/** Signed in on the organization plane with a password ('supersecret123'), no step-up. */
function stepUpOrganizationSession(string $email = 'inline-step-up@acme.test'): string
{
    [$subject, $org] = accountWithOrg($email);

    test()->withSession([
        PlatformAuth::SESSION_KEY => app(SessionManager::class)->start($subject->id, $org->id, ['pwd'])->id,
    ]);

    return $subject->id;
}

/**
 * A request exactly as an Inertia click sends it — the version header included, without
 * which a GET is answered with Inertia's asset-mismatch reload before any middleware runs.
 *
 * @param  array<string, mixed>  $data
 */
function stepUpInertia(string $method, string $uri, array $data = [], ?string $from = null): TestResponse
{
    $headers = [
        'X-Inertia' => 'true',
        'X-Inertia-Version' => (string) app(HandleInertiaRequests::class)->version(request()),
        'X-Requested-With' => 'XMLHttpRequest',
    ];

    if ($from !== null) {
        $headers['Referer'] = $from;
    }

    return test()->call($method, $uri, $data, [], [], test()->transformHeadersToServerVars($headers));
}

/** The refusal the page turns into the dialog, asserted whole. */
function assertStepUpRefusal(TestResponse $response, string $plane, ?string $reason = null): void
{
    $confirm = $plane === 'environment' ? route('environment.sudo.confirm') : route('sudo.confirm');

    $response->assertForbidden()
        ->assertHeader(StepUpPrompt::HEADER, $confirm)
        ->assertJsonPath('step_up.plane', $plane)
        ->assertJsonPath('step_up.confirm', $confirm)
        // Kept for a client that predates the dialog: it can still go to the full page.
        ->assertJsonPath('sudo', $plane === 'environment' ? route('environment.sudo') : route('sudo'));

    expect($response->headers->get('Location'))->toBeNull('a step-up was answered with a redirect');

    if ($reason !== null) {
        $response->assertJsonPath('step_up.reason', $reason);
    }
}

/*
|--------------------------------------------------------------------------
| The organization plane — `sudo`
|--------------------------------------------------------------------------
*/

it('answers a gated Inertia write with the step-up refusal, not a redirect, and runs nothing', function (): void {
    $subjectId = stepUpOrganizationSession();
    $other = app(SessionManager::class)->start($subjectId, null, ['pwd']);

    $response = stepUpInertia('POST', route('account.sessions.revoke-others'), from: route('account'));

    assertStepUpRefusal($response, 'organization', StepUpReason::ROUTES['account.sessions.revoke-others']);

    expect(Session::query()->whereKey($other->id)->value('revoked_at'))
        ->toBeNull('a gated write ran with no step-up');
})->group('security');

it('answers an Inertia visit to a gated page with the step-up refusal too', function (): void {
    stepUpOrganizationSession();

    assertStepUpRefusal(stepUpInertia('GET', route('vault'), from: route('account')), 'organization');
})->group('security');

it('still sends a document navigation to the full-page step-up and back to the page it asked for', function (): void {
    stepUpOrganizationSession();

    $this->get(route('vault'))->assertRedirect(route('sudo'));

    expect(session('sudo.intended'))->toBe(route('vault'));
})->group('security');

it('answers the passkey ceremony with the refusal and says why', function (): void {
    stepUpOrganizationSession();

    assertStepUpRefusal(
        $this->postJson(route('passkeys.register.options')),
        'organization',
        'Adding a passkey gives you a new way to sign in.',
    );
})->group('security');

it('confirms as JSON for the dialog, and then lets the very same request through', function (): void {
    $subjectId = stepUpOrganizationSession();
    $other = app(SessionManager::class)->start($subjectId, null, ['pwd']);

    $this->postJson(route('sudo.confirm'), ['password' => 'supersecret123'])
        ->assertOk()
        ->assertExactJson(['confirmed' => true, 'plane' => 'organization']);

    expect(app(Sudo::class)->confirmed())->toBeTrue()
        // The organization plane's confirmation never opens the environment plane's window.
        ->and(app(EnvironmentSudo::class)->confirmed())->toBeFalse();

    // The replay the dialog makes: the same visit, now through.
    stepUpInertia('POST', route('account.sessions.revoke-others'), from: route('account'))
        ->assertRedirect();

    expect(Session::query()->whereKey($other->id)->value('revoked_at'))->not->toBeNull();
})->group('security');

it('refuses a wrong password inline, as JSON, and confirms nothing', function (): void {
    stepUpOrganizationSession();

    $this->postJson(route('sudo.confirm'), ['password' => 'not-it'])
        ->assertStatus(422)
        ->assertJsonPath('errors.password.0', 'That password is incorrect.');

    $this->postJson(route('sudo.confirm'), [])
        ->assertStatus(422)
        ->assertJsonValidationErrors('password');

    expect(app(Sudo::class)->confirmed())->toBeFalse();
})->group('security');

it('throttles the dialog exactly like the full-page form — five tries, per person', function (): void {
    $subjectId = stepUpOrganizationSession();

    foreach (range(1, 5) as $_) {
        $this->postJson(route('sudo.confirm'), ['password' => 'not-it'])->assertStatus(422);
    }

    // Even the right password is refused now, and the dialog is told how long to wait.
    $this->postJson(route('sudo.confirm'), ['password' => 'supersecret123'])
        ->assertStatus(429)
        ->assertHeader('Retry-After')
        ->assertJsonPath('errors.password.0', fn (string $message): bool => str_starts_with($message, 'Too many attempts.'));

    expect(app(Sudo::class)->confirmed())->toBeFalse()
        ->and(RateLimiter::tooManyAttempts('sudo|'.$subjectId, 5))->toBeTrue();
})->group('security');

it('returns the full-page step-up to the page the person was on, never to an overview', function (): void {
    stepUpOrganizationSession();

    // Opened with nothing pending — a link to /sudo from the activity page.
    $this->withHeader('Referer', route('account.activity'))->get(route('sudo'))->assertOk();

    $this->from(route('sudo'))
        ->post(route('sudo.confirm'), ['password' => 'supersecret123'])
        ->assertRedirect('/account/activity');
});

it('never remembers an off-site page to return to', function (): void {
    stepUpOrganizationSession();

    $this->withHeader('Referer', 'https://evil.test/phish')->get(route('sudo'))->assertOk();

    expect(session()->has('sudo.intended'))->toBeFalse();
})->group('security');

it('lets Connect leave for the provider for real once the step-up is fresh', function (): void {
    stepUpOrganizationSession();

    // Without the step-up: the refusal the dialog opens on.
    assertStepUpRefusal(
        stepUpInertia('GET', route('social.connect', ['provider' => 'google']), from: route('account')),
        'organization',
        'Connecting an account gives you a new way to sign in.',
    );

    app(Sudo::class)->confirm();

    // With it: Inertia is told to navigate for real, and nothing has been started yet.
    stepUpInertia('GET', route('social.connect', ['provider' => 'google']), from: route('account'))
        ->assertStatus(409)
        ->assertHeader('X-Inertia-Location', route('social.connect', ['provider' => 'google']));
});

/*
|--------------------------------------------------------------------------
| The environment plane — `env.sudo`, and the action-level gate
|--------------------------------------------------------------------------
*/

it('answers an environment-plane Inertia write with the ENVIRONMENT refusal', function (): void {
    crudSetup();
    $secret = app(SecretVault::class)->store('K', 'stripe', 'sk_x');

    assertStepUpRefusal(
        stepUpInertia('POST', route('environment.vault.revoke', $secret->id), from: route('environment.vault.show', $secret->id)),
        'environment',
    );

    expect(VaultSecret::query()->whereKey($secret->id)->value('revoked_at'))->toBeNull();
})->group('security');

it('confirms the environment plane against the platform-root subject, as JSON', function (): void {
    crudSetup();
    $secret = app(SecretVault::class)->store('K', 'stripe', 'sk_x');

    $this->postJson(route('environment.sudo.confirm'), ['password' => 'wrong'])->assertStatus(422);

    $this->postJson(route('environment.sudo.confirm'), ['password' => 'a-strong-unbreached-passphrase'])
        ->assertOk()
        ->assertJsonPath('plane', 'environment');

    expect(app(EnvironmentSudo::class)->confirmed())->toBeTrue()
        ->and(app(Sudo::class)->confirmed())->toBeFalse();

    stepUpInertia('POST', route('environment.vault.revoke', $secret->id), from: route('environment.vault.show', $secret->id))
        ->assertRedirect();

    expect(VaultSecret::query()->whereKey($secret->id)->value('revoked_at'))->not->toBeNull();
})->group('security');

it('never lets the organization plane open the environment plane', function (): void {
    crudSetup();
    $secret = app(SecretVault::class)->store('K', 'stripe', 'sk_x');

    app(Sudo::class)->confirm();

    assertStepUpRefusal(stepUpInertia('GET', route('environment.vault')), 'environment');

    expect(VaultSecret::query()->whereKey($secret->id)->value('revoked_at'))->toBeNull();
})->group('security');

it('asks for the action-level step-up in place, with the action\'s own sentence', function (): void {
    crudSetup();
    config(['cbox-id.external_actions.verify_url' => false]);
    $org = app(Organizations::class)->create(new NewOrganization('Tenant', 'tenant-inline-step-up'));
    FormOrganization::$id = $org->id;

    $response = stepUpInertia('POST', route('environment.hooks.store'), FormOrganization::apply([
        'point' => 'token_minting',
        'url' => 'https://hooks.example.test/token',
        'environmentWide' => false,
    ], 'environment.hooks'), route('environment.hooks.create'));

    assertStepUpRefusal(
        $response,
        'environment',
        'Registering a hook issues a signing secret and puts your endpoint inside the sign-in path.',
    );

    // …and the full page still has the sentence and the page to come back to, for a
    // browser that ends up there instead.
    expect(StepUpReason::pending('environment.sudo'))->toStartWith('Registering a hook');
})->group('security');

it('names a reason only for routes that exist and are behind a step-up', function (): void {
    $routes = app('router')->getRoutes();

    foreach (array_keys(StepUpReason::ROUTES) as $name) {
        $route = $routes->getByName($name);

        expect($route)->not->toBeNull("{$name} is not a route")
            ->and(collect($route?->gatherMiddleware() ?? [])->contains(
                fn (mixed $middleware): bool => in_array($middleware, ['sudo', 'env.sudo'], true),
            ))->toBeTrue("{$name} is not behind a step-up, so its sentence would never be shown");
    }
});
