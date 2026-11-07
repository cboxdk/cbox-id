<?php

declare(strict_types=1);

use Cbox\Id\AccessControl\Models\Role;
use Cbox\Id\Identity\Contracts\Subjects;
use Cbox\Id\OAuthServer\Contracts\ClientRegistry;
use Cbox\Id\OAuthServer\Contracts\TokenIntrospector;
use Cbox\Id\OAuthServer\Contracts\TokenIssuer;
use Cbox\Id\OAuthServer\ValueObjects\ActingParty;
use Cbox\Id\OAuthServer\ValueObjects\NewClient;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    $this->publisher = app(ClientRegistry::class)->register(new NewClient(
        name: 'Mixed login and manifest publisher',
        grantTypes: ['authorization_code', 'client_credentials'],
        scopes: ['openid', 'apps.manifest'],
    ))->client;
    $this->manifest = ['version' => 'authority-proof', 'permissions' => [['key' => 'records:read']], 'roles' => [['key' => 'reader', 'name' => 'Reader', 'permissions' => ['records:read']]]];
});

it('accepts a genuine own-client machine token and never trusts a requested foreign target', function (): void {
    $other = app(ClientRegistry::class)->register(new NewClient(name: 'Other application', scopes: ['apps.manifest']))->client;
    $token = app(TokenIssuer::class)->issueClientCredentials($this->publisher, ['apps.manifest']);
    expect(app(TokenIntrospector::class)->introspect($token->token)->active)->toBeTrue();
    $this->withToken($token->token)->postJson('/api/v1/apps/manifest', $this->manifest + ['client_id' => $other->client_id])
        ->assertOk()->assertJsonPath('client_id', $this->publisher->client_id);
    expect(Role::query()->where('client_id', $this->publisher->client_id)->where('key', 'reader')->exists())->toBeTrue()
        ->and(Role::query()->where('client_id', $other->client_id)->exists())->toBeFalse();
});

it('refuses a genuine human token even when the login client grants the manifest scope', function (): void {
    $user = app(Subjects::class)->create('manifest-human@example.test', 'Human');
    $token = app(TokenIssuer::class)->issueForUser($this->publisher, $user->id, null, ['apps.manifest']);
    $verified = app(TokenIntrospector::class)->introspect($token->token);
    expect($verified->active)->toBeTrue()->and($verified->hasScope('apps.manifest'))->toBeTrue();
    $this->withToken($token->token)->postJson('/api/v1/apps/manifest', $this->manifest)
        ->assertForbidden()->assertJsonPath('error', 'machine_token_required');
    expect(Role::query()->where('client_id', $this->publisher->client_id)->exists())->toBeFalse();
});

it('refuses genuine acted tokens including a subject that resembles the machine identity', function (bool $machineSubject): void {
    $user = app(Subjects::class)->create('manifest-supported@example.test', 'Supported person');
    if ($machineSubject) {
        $this->publisher->update(['client_id' => $user->id]);
    }
    $token = app(TokenIssuer::class)->issueActing($this->publisher, $user->id, null,
        ['apps.manifest'], new ActingParty('support-actor', 'support-session'), now()->addMinutes(5));
    $verified = app(TokenIntrospector::class)->introspect($token->token);
    expect($verified->active)->toBeTrue()->and(((array) $verified->claims['act'])['sub'])->toBe('support-actor');
    $this->withToken($token->token)->postJson('/api/v1/apps/manifest', $this->manifest)
        ->assertForbidden()->assertJsonPath('error', 'machine_token_required');
    expect(Role::query()->where('client_id', $this->publisher->client_id)->exists())->toBeFalse();
})->with([false, true]);

it('does not mistake a human subject matching an opaque client id for machine issuance', function (): void {
    $user = app(Subjects::class)->create('manifest-collision@example.test', 'Human');
    $this->publisher->update(['client_id' => $user->id]);
    $token = app(TokenIssuer::class)->issueForUser($this->publisher, $user->id, null, ['apps.manifest']);
    $verified = app(TokenIntrospector::class)->introspect($token->token);
    expect($verified->active)->toBeTrue()->and($verified->subject)->toBe($verified->clientId);
    $this->withToken($token->token)->postJson('/api/v1/apps/manifest', $this->manifest)
        ->assertForbidden()->assertJsonPath('error', 'machine_token_required');
    expect(Role::query()->where('client_id', $this->publisher->client_id)->exists())->toBeFalse();
});
