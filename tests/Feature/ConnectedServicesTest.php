<?php

declare(strict_types=1);

use App\Platform\CurrentUser;
use App\Platform\PlatformAuth;
use Cbox\Id\Identity\Contracts\SessionManager;
use Cbox\Id\Identity\Contracts\Subjects;
use Cbox\Id\Kernel\Audit\Enums\ActorType;
use Cbox\Id\Kernel\Audit\Models\AuditEntry;
use Cbox\Id\OAuthServer\Contracts\ClientRegistry;
use Cbox\Id\OAuthServer\ValueObjects\NewClient;
use Cbox\Id\Organization\Contracts\Memberships;
use Cbox\Id\Organization\Contracts\Organizations;
use Cbox\Id\Organization\Enums\MembershipRole;
use Cbox\Id\Organization\ValueObjects\NewOrganization;
use Cbox\Id\Pipes\Models\PipeConnection;
use Cbox\Id\Pipes\PipeOAuthClient;
use Cbox\Id\Pipes\Support\PipeSecrets;
use Cbox\Id\Pipes\Testing\InteractsWithPipes;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Tests\Support\FakeDelegatedTokens;

uses(RefreshDatabase::class, InteractsWithPipes::class);

/**
 * Connected services, as the person sees them: the hosted connect page, the trip to the
 * provider and back, the list on My account, and disconnecting — translated, and only ever
 * the signed-in person's own.
 */
function pipePerson(string $email = 'ada@acme.test'): string
{
    $subject = app(Subjects::class)->create($email, 'Ada Lovelace', 'a-strong-unbreached-passphrase');
    app(Subjects::class)->markEmailVerified($subject->id, $email);

    $org = app(Organizations::class)->create(new NewOrganization('Acme', 'acme-pipes-'.bin2hex(random_bytes(3))));
    app(Memberships::class)->add($org->id, $subject->id, MembershipRole::Member);

    $session = app(SessionManager::class)->start($subject->id, $org->id, ['pwd']);
    session([PlatformAuth::SESSION_KEY => $session->id]);
    app(CurrentUser::class)->set($subject, $session, null, MembershipRole::Member);

    return $subject->id;
}

beforeEach(function (): void {
    $this->pipe = $this->configurePipe('github', ['read:user', 'repo'], 'gh-client', 'gh-secret');
    $this->dashboard = app(ClientRegistry::class)
        ->register(new NewClient('Repo dashboard', redirectUris: ['https://dash.example/oauth/callback']))->client->client_id;
    $this->grantPipe($this->pipe, $this->dashboard);
});

/**
 * Start the flow the way the browser does, and return the state the provider will echo.
 */
function startPipeConnect(object $test, array $body = []): string
{
    $response = $test->post(route('account.pipes.authorize', 'github'), $body)->assertRedirect();
    $location = (string) $response->headers->get('Location');

    expect($location)->toStartWith('https://github.com/login/oauth/authorize?');

    parse_str((string) parse_url($location, PHP_URL_QUERY), $query);

    expect($query['client_id'])->toBe('gh-client')
        ->and($query['scope'])->toBe('read:user repo')
        ->and($query['code_challenge_method'])->toBe('S256')
        ->and($query['redirect_uri'])->toBe(route('account.pipes.callback', 'github'));

    return (string) $query['state'];
}

function fakeGitHub(): void
{
    Http::fake([
        'https://github.com/login/oauth/access_token' => Http::response(['access_token' => 'gho_ADA-ACCESS', 'scope' => 'read:user,repo', 'token_type' => 'bearer']),
        'https://api.github.com/user' => Http::response(['login' => 'ada']),
    ]);
}

it('shows what connecting means, in the person\'s language, naming the app that asked', function (): void {
    pipePerson();

    $response = $this->get(route('account.pipes.connect', 'github').'?ui_locales=da&client_id='.$this->dashboard.'&return_to='.urlencode('https://dash.example/settings'))
        ->assertOk();

    $props = (array) $response->inertiaProps();

    expect($response->inertiaPage()['component'])->toBe('auth/connect-service')
        ->and($props['i18n']['locale'])->toBe('da')
        ->and($props['i18n']['messages']['auth.connect_service.heading'])->toBe(trans('auth.connect_service.heading', [], 'da'))
        ->and($props['providerName'])->toBe('GitHub')
        ->and($props['scopes'])->toBe(['read:user', 'repo'])
        ->and($props['appName'])->toBe('Repo dashboard')
        ->and($props['returnTo'])->toBe('https://dash.example/settings');
});

it('names no app, and keeps no way back, for an app that is not granted or an address it never registered', function (): void {
    pipePerson();
    $stranger = app(ClientRegistry::class)->register(new NewClient('Stranger', redirectUris: ['https://evil.example/cb']))->client->client_id;

    $props = (array) $this->get(route('account.pipes.connect', 'github').'?client_id='.$stranger.'&return_to='.urlencode('https://evil.example/cb'))->inertiaProps();
    expect($props['appName'])->toBeNull()->and($props['returnTo'])->toBeNull();

    $props = (array) $this->get(route('account.pipes.connect', 'github').'?client_id='.$this->dashboard.'&return_to='.urlencode('https://evil.example/phish'))->inertiaProps();
    expect($props['appName'])->toBe('Repo dashboard')->and($props['returnTo'])->toBeNull();
});

it('connects the signed-in person\'s account end to end', function (): void {
    $me = pipePerson();
    fakeGitHub();

    $state = startPipeConnect($this);

    $this->get(route('account.pipes.callback', 'github').'?code=the-code&state='.$state)
        ->assertRedirect(route('account.pipes'))
        ->assertSessionHas('status', 'GitHub connected.');

    $connection = PipeConnection::query()->sole();
    expect($connection->user_id)->toBe($me)
        ->and($connection->account_label)->toBe('ada')
        ->and(app(PipeSecrets::class)->open($connection->access_secret_id, $me, 'test'))->toBe('gho_ADA-ACCESS');

    // The verifier that went to GitHub hashes to the challenge sent at the start.
    Http::assertSent(fn (Request $request): bool => $request->url() === 'https://github.com/login/oauth/access_token'
        && is_string($request['code_verifier']) && strlen($request['code_verifier']) >= 43);

    $audit = AuditEntry::query()->where('action', 'pipe.connection.connected')->sole();
    expect($audit->actor_type)->toBe(ActorType::User)->and($audit->actor_id)->toBe($me);

    // A replayed callback finds no flow to finish.
    $this->get(route('account.pipes.callback', 'github').'?code=the-code&state='.$state)
        ->assertSessionHas('error');
    expect(PipeConnection::query()->count())->toBe(1);
});

it('sends the person back to the app that asked, with the outcome', function (): void {
    pipePerson();
    fakeGitHub();

    $state = startPipeConnect($this, ['client_id' => $this->dashboard, 'return_to' => 'https://dash.example/settings?tab=integrations']);

    $this->get(route('account.pipes.callback', 'github').'?code=the-code&state='.$state)
        ->assertRedirect('https://dash.example/settings?tab=integrations&provider=github&status=connected');
});

it('refuses a callback whose state is not this browser\'s', function (): void {
    pipePerson();
    fakeGitHub();
    startPipeConnect($this);

    $this->get(route('account.pipes.callback', 'github').'?code=the-code&state=forged')
        ->assertRedirect(route('account.pipes'))
        ->assertSessionHas('error', 'GitHub could not be connected. Please try again.');

    Http::assertNotSent(fn (Request $request): bool => str_contains($request->url(), 'access_token'));
    expect(PipeConnection::query()->count())->toBe(0);
});

it('takes a "no" at the provider as a cancel, not a failure', function (): void {
    pipePerson();
    $state = startPipeConnect($this);

    $this->get(route('account.pipes.callback', 'github').'?error=access_denied&state='.$state)
        ->assertSessionHas('error', 'You did not connect GitHub.');
});

it('lists the person\'s services and disconnects one, revoking it at the provider', function (): void {
    $me = pipePerson();
    $this->connectPipeAccount('github', $me, ['access_token' => 'gho_ADA']);
    $this->connectPipeAccount('github', 'someone_else', ['access_token' => 'gho_BOB']);

    $props = (array) $this->get(route('account.pipes'))->assertOk()->inertiaProps();
    expect($props['services'])->toHaveCount(1)
        ->and($props['services'][0]['provider'])->toBe('github')
        ->and($props['services'][0]['connected'])->toBeTrue();

    Http::fake(['https://api.github.com/applications/gh-client/grant' => Http::response(null, 204)]);

    $this->from(route('account.pipes'))->delete(route('account.pipes.destroy', 'github'))
        ->assertRedirect(route('account.pipes'))
        ->assertSessionHas('status', 'GitHub disconnected.');

    Http::assertSent(fn (Request $request): bool => $request->method() === 'DELETE' && $request['access_token'] === 'gho_ADA');

    // Theirs is untouched.
    expect(PipeConnection::query()->pluck('user_id')->all())->toBe(['someone_else']);
});

it('disconnects over the account API, for the token\'s person only', function (): void {
    $me = pipePerson();
    $this->connectPipeAccount('github', $me, ['access_token' => 'gho_ADA']);
    $this->connectPipeAccount('github', 'someone_else', ['access_token' => 'gho_BOB']);
    Http::fake();

    FakeDelegatedTokens::install()->person('me-pipes', $me, ['account:pipes:write']);

    $this->withToken('me-pipes')->deleteJson('/api/v1/me/pipes/slack')->assertNotFound();
    $this->withToken('me-pipes')->deleteJson('/api/v1/me/pipes/github')->assertNoContent();
    $this->withToken('me-pipes')->deleteJson('/api/v1/me/pipes/github')->assertNotFound();

    expect(PipeConnection::query()->pluck('user_id')->all())->toBe(['someone_else']);
});

it('verifies a PKCE pair the way the provider will', function (): void {
    $verifier = PipeOAuthClient::codeVerifier();

    expect(PipeOAuthClient::codeChallenge($verifier))
        ->toBe(rtrim(strtr(base64_encode(hash('sha256', $verifier, true)), '+/', '-_'), '='));
});
