<?php

declare(strict_types=1);

use App\Platform\EnvironmentSudo;
use Cbox\Id\Kernel\Audit\Enums\ActorType;
use Cbox\Id\Kernel\Audit\Models\AuditEntry;
use Cbox\Id\Kernel\Tenancy\Contracts\EnvironmentContext;
use Cbox\Id\Kernel\Tenancy\GenericEnvironment;
use Cbox\Id\OAuthServer\Contracts\ClientRegistry;
use Cbox\Id\OAuthServer\ValueObjects\NewClient;
use Cbox\Id\Pipes\Contracts\Pipes;
use Cbox\Id\Pipes\Models\Pipe;
use Cbox\Id\Pipes\Models\PipeConnection;
use Cbox\Id\Pipes\Support\PipeSecrets;
use Cbox\Id\Pipes\Testing\InteractsWithPipes;
use Cbox\Id\Platform\Contracts\EnvironmentApiKeys;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;

uses(RefreshDatabase::class, InteractsWithPipes::class);

beforeEach(function (): void {
    config(['cbox-id.pipes.verify_url' => false]);
});

/**
 * @param  list<string>  $scopes
 * @return array{0: string, 1: string}
 */
function pipesKey(array $scopes = ['pipes:read', 'pipes:write']): array
{
    $issued = app(EnvironmentApiKeys::class)->issue('env_test', 'Pipes worker', $scopes);

    return [$issued->plaintext, (string) $issued->key->id];
}

function pipesApp(string $name = 'CRM sync'): string
{
    return app(ClientRegistry::class)->register(new NewClient($name))->client->client_id;
}

it('configures a pipe over REST, seals its secret, and never returns it', function (): void {
    [$key, $keyId] = pipesKey();

    $created = $this->withToken($key)->postJson('/api/v1/pipes', [
        'provider' => 'github',
        'client_id' => 'Iv1.abc',
        'client_secret' => 'THE-GITHUB-CLIENT-SECRET',
        'scopes' => ['read:user', 'repo'],
    ])->assertCreated()
        ->assertJsonPath('data.provider', 'github')
        ->assertJsonPath('data.name', 'GitHub')
        ->assertJsonPath('data.scopes', ['read:user', 'repo'])
        ->assertJsonPath('data.enabled', true)
        ->assertJsonPath('data.redirect_uri', route('account.pipes.callback', 'github'));

    $id = (string) $created->json('data.id');

    $this->withToken($key)->patchJson("/api/v1/pipes/{$id}", ['client_secret' => 'THE-NEXT-SECRET', 'enabled' => false])
        ->assertOk()->assertJsonPath('data.enabled', false);
    $read = $this->withToken($key)->getJson("/api/v1/pipes/{$id}")->assertOk();
    $list = $this->withToken($key)->getJson('/api/v1/pipes')->assertOk()->assertJsonPath('data.0.id', $id);

    expect($created->getContent().$read->getContent().$list->getContent().json_encode(DB::table('audit_logs')->get()))
        ->not->toContain('THE-GITHUB-CLIENT-SECRET')
        ->not->toContain('THE-NEXT-SECRET')
        ->and(app(PipeSecrets::class)->clientSecret(Pipe::query()->findOrFail($id)))->toBe('THE-NEXT-SECRET');

    // The actor is the key, through every door's audit decorator.
    foreach (['pipe.configured', 'pipe.updated'] as $action) {
        $entry = AuditEntry::query()->where('action', $action)->sole();

        expect($entry->actor_type)->toBe(ActorType::Service, $action)
            ->and($entry->actor_id)->toBe($keyId, $action);
    }
});

it('refuses a second pipe for the same provider, and a bad parameter, on the field', function (): void {
    [$key] = pipesKey();
    $this->withToken($key)->postJson('/api/v1/pipes', ['provider' => 'github', 'client_id' => 'a', 'client_secret' => 'b'])->assertCreated();

    $this->withToken($key)->postJson('/api/v1/pipes', ['provider' => 'github', 'client_id' => 'a', 'client_secret' => 'b'])
        ->assertUnprocessable()->assertJsonPath('error', 'invalid_pipe');
    $this->withToken($key)->postJson('/api/v1/pipes', ['provider' => 'salesforce', 'client_id' => 'a', 'client_secret' => 'b', 'parameters' => ['domain' => 'evil.example']])
        ->assertUnprocessable()->assertJsonPath('error', 'invalid_pipe');
    $this->withToken($key)->postJson('/api/v1/pipes', ['provider' => 'myspace', 'client_id' => 'a', 'client_secret' => 'b'])
        ->assertUnprocessable();
});

it('grants only an app of this environment, and revokes', function (): void {
    [$key] = pipesKey();
    $pipe = $this->configurePipe('github');
    $app = pipesApp();

    $this->withToken($key)->postJson("/api/v1/pipes/{$pipe->id}/grants", ['client_id' => 'cid_made_up'])
        ->assertUnprocessable()->assertJsonPath('error', 'unknown_app');

    $this->withToken($key)->postJson("/api/v1/pipes/{$pipe->id}/grants", ['client_id' => $app])
        ->assertOk()->assertJsonPath('data.grants', [$app]);

    $this->withToken($key)->deleteJson("/api/v1/pipes/{$pipe->id}/grants/{$app}")->assertNoContent();

    expect(app(Pipes::class)->grantedClients($pipe->id))->toBe([]);
});

it('lists who connected, never a token, and disconnects one on their behalf', function (): void {
    [$key] = pipesKey();
    $pipe = $this->configurePipe('google');
    $connection = $this->connectPipeAccount('google', 'user_ada', ['access_token' => 'ya29.SECRET-ACCESS', 'refresh_token' => '1//SECRET-REFRESH', 'expires_in' => 3600]);

    $list = $this->withToken($key)->getJson("/api/v1/pipes/{$pipe->id}/connections")
        ->assertOk()
        ->assertJsonPath('data.0.user_id', 'user_ada')
        ->assertJsonPath('data.0.status', 'active');

    expect($list->getContent())->not->toContain('SECRET');

    Http::fake(['https://oauth2.googleapis.com/revoke' => Http::response([])]);

    $this->withToken($key)->deleteJson("/api/v1/pipes/{$pipe->id}/connections/{$connection->id}")->assertNoContent();

    Http::assertSent(fn (Request $request): bool => $request->url() === 'https://oauth2.googleapis.com/revoke' && $request['token'] === '1//SECRET-REFRESH');
    expect(PipeConnection::query()->count())->toBe(0);
});

it('removes a pipe with its connections', function (): void {
    [$key] = pipesKey();
    $pipe = $this->configurePipe('hubspot');
    $this->connectPipeAccount('hubspot', 'user_ada', ['access_token' => 'a', 'refresh_token' => 'r', 'expires_in' => 1800]);

    $this->withToken($key)->deleteJson("/api/v1/pipes/{$pipe->id}")->assertNoContent();

    expect(Pipe::query()->count())->toBe(0)->and(PipeConnection::query()->count())->toBe(0);
});

it('needs pipes:write to change anything', function (): void {
    [$reader] = pipesKey(['pipes:read']);
    $pipe = $this->configurePipe('github');

    $this->withToken($reader)->getJson("/api/v1/pipes/{$pipe->id}")->assertOk();
    $this->withToken($reader)->patchJson("/api/v1/pipes/{$pipe->id}", ['enabled' => false])->assertForbidden();
    $this->withToken($reader)->deleteJson("/api/v1/pipes/{$pipe->id}")->assertForbidden();
});

it('answers 404 to another environment\'s pipe and connection', function (): void {
    [$key] = pipesKey();
    [$theirPipe, $theirConnection] = app(EnvironmentContext::class)->runAs(GenericEnvironment::of('env_other'), function (): array {
        $pipe = $this->configurePipe('github');

        return [$pipe, $this->connectPipeAccount('github', 'user_ada', ['access_token' => 'gho_THEIRS'])];
    });

    $this->withToken($key)->getJson("/api/v1/pipes/{$theirPipe->id}")->assertNotFound();
    $this->withToken($key)->patchJson("/api/v1/pipes/{$theirPipe->id}", ['enabled' => false])->assertNotFound();
    $this->withToken($key)->postJson("/api/v1/pipes/{$theirPipe->id}/grants", ['client_id' => pipesApp()])->assertNotFound();
    $this->withToken($key)->getJson("/api/v1/pipes/{$theirPipe->id}/connections")->assertNotFound();
    $this->withToken($key)->deleteJson("/api/v1/pipes/{$theirPipe->id}/connections/{$theirConnection->id}")->assertNotFound();

    // And through this environment's own pipe, their connection id is still nobody's.
    $mine = $this->configurePipe('github');
    $this->withToken($key)->deleteJson("/api/v1/pipes/{$mine->id}/connections/{$theirConnection->id}")->assertNotFound();

    app(EnvironmentContext::class)->runAs(GenericEnvironment::of('env_other'), function () use ($theirPipe): void {
        expect(Pipe::query()->whereKey($theirPipe->id)->value('enabled'))->toBeTrue()
            ->and(PipeConnection::query()->count())->toBe(1);
    });
})->group('security');

it('runs the same actions from the environment console, as the person', function (): void {
    ['subjectId' => $subjectId] = crudSetup();
    app(EnvironmentSudo::class)->confirm();
    config(['cbox-id.pipes.verify_url' => false]);

    $this->get('/admin/pipes')->assertOk();
    $this->get('/admin/pipes/new?provider=slack')->assertOk();

    $this->post('/admin/pipes', [
        'provider' => 'slack',
        'client_id' => '123.456',
        'client_secret' => 'slack-secret',
        'scopes' => 'users:read channels:history',
    ])->assertRedirect();

    $pipe = Pipe::query()->sole();
    expect($pipe->scopes)->toBe(['users:read', 'channels:history']);

    $this->get("/admin/pipes/{$pipe->id}")->assertOk()->assertDontSee('slack-secret');
    $this->patch("/admin/pipes/{$pipe->id}", ['enabled' => '0', 'scopes' => 'users:read'])->assertRedirect();
    expect($pipe->fresh()?->enabled)->toBeFalse()->and($pipe->fresh()?->scopes)->toBe(['users:read']);

    $entry = AuditEntry::query()->where('action', 'pipe.configured')->sole();
    expect($entry->actor_id)->toBe($subjectId)
        ->and($entry->actor_type)->not->toBe(ActorType::Service);

    $this->delete("/admin/pipes/{$pipe->id}")->assertRedirect(route('environment.pipes'));
    expect(Pipe::query()->count())->toBe(0);
});

it('keeps the pipes pages behind the environment step-up', function (): void {
    crudSetup();

    $this->get('/admin/pipes')->assertRedirect(route('environment.sudo'));
    $this->post('/admin/pipes', ['provider' => 'github', 'client_id' => 'a', 'client_secret' => 'b'])->assertRedirect(route('environment.sudo'));

    expect(Pipe::query()->count())->toBe(0);
})->group('security');
