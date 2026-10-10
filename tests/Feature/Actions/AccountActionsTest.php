<?php

declare(strict_types=1);

use App\Actions\Account\UpdateProfile;
use App\Platform\Actions\ActionPlane;
use App\Platform\Actions\ActionRegistry;
use App\Platform\Actions\ActionRunner;
use App\Platform\Actions\Principal\EnvironmentKeyPrincipal;
use Cbox\Id\Devices\Models\Device;
use Cbox\Id\Identity\Contracts\SessionManager;
use Cbox\Id\Identity\Contracts\Subjects;
use Cbox\Id\Identity\Models\Session;
use Cbox\Id\Identity\Models\WebAuthnCredential;
use Cbox\Id\Identity\ValueObjects\FederatedPrincipal;
use Cbox\Id\Kernel\Audit\Enums\ActorType;
use Cbox\Id\Kernel\Audit\Models\AuditEntry;
use Cbox\Id\Organization\Contracts\CustomerApiKeys;
use Cbox\Id\Organization\Enums\MembershipRole;
use Cbox\Id\Platform\Contracts\EnvironmentApiKeys;
use Cbox\Id\Platform\Contracts\OrganizationApiKeys;
use Cbox\Id\Platform\Models\EnvironmentApiKey;
use Cbox\Id\Platform\PlatformRoot;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\Support\FakeDelegatedTokens;

uses(RefreshDatabase::class);

/*
|--------------------------------------------------------------------------
| My account, as actions: the person themself, and nobody else.
|--------------------------------------------------------------------------
|
| The account pages run these as the signed-in person; `/api/v1/me` runs them for a token
| that person delegated — a seam here, {@see FakeDelegatedTokens} standing in for the
| resolver delegated management tokens will bind. No management key ever acts as a person,
| and no id in any path reaches anybody else's account.
*/

beforeEach(fn () => Http::fake(['api.pwnedpasswords.com/*' => Http::response('', 200)]));

/** @return list<array{0: string, 1: string}> A path for every account action, and its method. */
function accountRoutes(): array
{
    $routes = [];

    foreach (app(ActionRegistry::class)->forPlane(ActionPlane::Account) as $action) {
        $routes[] = [$action->method, '/api/v1'.preg_replace('/\{\w+\}/', 'x01JNOTANID', $action->documentedPath())];
    }

    return $routes;
}

/** A token $subjectId delegated, with every account scope unless told otherwise. */
function myToken(string $subjectId, ?array $scopes = null, ?string $sessionId = null): string
{
    FakeDelegatedTokens::install()->person('me-'.$subjectId.'-'.count($scopes ?? []), $subjectId, $scopes ?? ['account:profile:write', 'account:sessions:write', 'account:applications:write', 'account:api_keys:write', 'account:sign_in:write', 'account:devices:write'], $sessionId);

    return 'me-'.$subjectId.'-'.count($scopes ?? []);
}

it('refuses every management key, and every bearer it does not know', function (): void {
    $account = provisionAccount();
    $workspaceKey = app(OrganizationApiKeys::class)->issue($account['organization']->id, 'Owner key', MembershipRole::Owner)->plaintext;
    $environmentKey = app(EnvironmentApiKeys::class)->issue($account['environment']->id, 'Env key', ['users:write'])->plaintext;

    expect(accountRoutes())->toHaveCount(12);

    foreach (accountRoutes() as [$method, $path]) {
        $this->json($method, $path)->assertUnauthorized();

        foreach ([$workspaceKey, $environmentKey, 'not-a-token'] as $bearer) {
            $this->withToken($bearer)->json($method, $path)->assertUnauthorized()->assertJsonPath('error', 'unauthorized');
        }
    }
})->group('security');

it('never lets a management key run an account action, whatever door it finds', function (): void {
    $environment = provisionAccount()['environment'];
    $issued = app(EnvironmentApiKeys::class)->issue($environment->id, 'Env key', ['users:write']);
    $key = EnvironmentApiKey::query()->withoutGlobalScopes()->findOrFail($issued->key->id);

    expect(fn () => app(ActionRunner::class)->run(UpdateProfile::class, new EnvironmentKeyPrincipal($key), ['name' => 'Hijacked']))
        ->toThrow(AuthorizationException::class);
})->group('security');

it('changes the profile, as the console does, for the person behind the token only', function (): void {
    ['subjectId' => $me] = provisionAccount();

    $this->withToken(myToken($me))->patchJson('/api/v1/me/profile', ['name' => 'Ada L.'])
        ->assertOk()
        ->assertJsonPath('data.id', $me)
        ->assertJsonPath('data.name', 'Ada L.');

    expect(app(PlatformRoot::class)->run(fn () => app(Subjects::class)->find($me))?->name)->toBe('Ada L.');

    // A token without the scope gets nothing.
    $this->withToken(myToken($me, ['account:sessions:write']))->patchJson('/api/v1/me/profile', ['name' => 'Nope'])
        ->assertForbidden();

    // And the console runs the same action.
    signInAsMember($me);
    saveOwnProfile(['displayName' => 'Ada Lovelace'])->assertSessionHasNoErrors();

    expect(app(PlatformRoot::class)->run(fn () => app(Subjects::class)->find($me))?->name)->toBe('Ada Lovelace');
});

it('signs out one session, every other one, and never somebody else\'s', function (): void {
    ['subjectId' => $me] = provisionAccount();
    ['subjectId' => $them] = provisionAccount('them@other.example');

    // Workspace members are people of the platform root, which is what this host serves.
    [$current, $other, $third, $theirs] = app(PlatformRoot::class)->run(fn (): array => [
        app(SessionManager::class)->start($me, null, ['pwd']),
        app(SessionManager::class)->start($me, null, ['pwd']),
        app(SessionManager::class)->start($me, null, ['pwd']),
        app(SessionManager::class)->start($them, null, ['pwd']),
    ]);

    $token = myToken($me, sessionId: $current->id);

    $this->withToken($token)->deleteJson("/api/v1/me/sessions/{$other->id}")->assertNoContent();
    // Already signed out: not found, so asking twice is no second act.
    $this->withToken($token)->deleteJson("/api/v1/me/sessions/{$other->id}")->assertNotFound();
    // Somebody else's: not found, never refused.
    $this->withToken($token)->deleteJson("/api/v1/me/sessions/{$theirs->id}")->assertNotFound();

    $this->withToken($token)->postJson('/api/v1/me/sessions/revoke-others')
        ->assertOk()
        ->assertJsonPath('data.revoked', 1);

    $revokedAt = fn (Session $session): mixed => Session::query()->withoutGlobalScopes()->whereKey($session->id)->value('revoked_at');

    expect($revokedAt($third))->not->toBeNull()
        // The token's own session is spared, and nobody else's is touched.
        ->and($revokedAt($current))->toBeNull()
        ->and($revokedAt($theirs))->toBeNull();

    $this->withToken($token)->postJson('/api/v1/me/sessions/revoke-others')->assertOk()->assertJsonPath('data.revoked', 0);
});

it('removes a passkey and unlinks a social account, never the last way in', function (): void {
    ['subjectId' => $me] = provisionAccount();
    ['subjectId' => $them] = provisionAccount('them@other.example');

    [$mine, $theirs] = app(PlatformRoot::class)->run(fn (): array => [
        WebAuthnCredential::query()->create(['user_id' => $me, 'credential_id' => 'cred-mine', 'public_key' => 'pk', 'name' => 'Laptop', 'sign_count' => 0]),
        WebAuthnCredential::query()->create(['user_id' => $them, 'credential_id' => 'cred-theirs', 'public_key' => 'pk', 'name' => 'Theirs', 'sign_count' => 0]),
    ]);

    $token = myToken($me);

    $this->withToken($token)->deleteJson("/api/v1/me/passkeys/{$theirs->id}")->assertNotFound();
    $this->withToken($token)->deleteJson("/api/v1/me/passkeys/{$mine->id}")->assertNoContent();
    $this->withToken($token)->deleteJson("/api/v1/me/passkeys/{$mine->id}")->assertNotFound();

    expect(WebAuthnCredential::query()->withoutGlobalScopes()->whereKey($theirs->id)->exists())->toBeTrue();

    // A linked Google account beside a password: it may go.
    app(PlatformRoot::class)->run(fn () => app(Subjects::class)->link($me, new FederatedPrincipal('social:google', 'google|1')));
    $this->withToken($token)->deleteJson('/api/v1/me/social/github')->assertNotFound();
    $this->withToken($token)->deleteJson('/api/v1/me/social/google')->assertNoContent();

    // With no password and no passkey, the only linked account is the only way in — kept.
    $social = app(PlatformRoot::class)->run(function () {
        $social = app(Subjects::class)->create('social-only@acme.example', 'Social Only');
        app(Subjects::class)->link($social->id, new FederatedPrincipal('social:google', 'google|2'));

        return $social;
    });

    $this->withToken(myToken($social->id))->deleteJson('/api/v1/me/social/google')
        ->assertUnprocessable()
        ->assertJsonPath('error', 'last_sign_in_method');

    expect(app(PlatformRoot::class)->run(fn (): array => app(Subjects::class)->linkedIdentities($social->id)))->toHaveCount(1);
});

it('mints and revokes its own API key, shown once, recorded as the person', function (): void {
    $fixture = appKeyFixture();
    $token = myToken($fixture['ada']);
    $org = $fixture['org']->id;

    $created = $this->withToken($token)->withHeader('Idempotency-Key', 'key-1')->postJson("/api/v1/me/organizations/{$org}/api-keys", [
        'client_id' => $fixture['clientId'],
        'name' => 'Accounting sync',
        'permissions' => ['returns:read'],
    ])->assertCreated()
        ->assertJsonPath('data.organization_id', $org)
        ->assertJsonPath('data.permissions', ['returns:read']);

    expect($created->json('data.token'))->toStartWith('ctax_live');

    // The retry gets the first answer back — without the value.
    $replay = $this->withToken($token)->withHeader('Idempotency-Key', 'key-1')->postJson("/api/v1/me/organizations/{$org}/api-keys", [
        'client_id' => $fixture['clientId'],
        'name' => 'Accounting sync',
        'permissions' => ['returns:read'],
    ])->assertCreated();
    $this->flushHeaders();

    expect($replay->json('data.id'))->toBe($created->json('data.id'))
        ->and($replay->json('data.token'))->toBeNull();

    // A permission Ada does not hold is refused with the console's own sentence.
    $this->withToken($token)->postJson("/api/v1/me/organizations/{$org}/api-keys", [
        'client_id' => $fixture['clientId'], 'name' => 'Too wide', 'permissions' => ['settings:manage'],
    ])->assertUnprocessable()->assertJsonPath('error', 'permission_not_held');

    $keyId = $created->json('data.id');

    // Bob is a member of the same organization: Ada's key is still not his.
    $this->withToken(myToken($fixture['bob']))->deleteJson("/api/v1/me/organizations/{$org}/api-keys/{$keyId}")->assertNotFound();

    $this->withToken($token)->deleteJson("/api/v1/me/organizations/{$org}/api-keys/{$keyId}")->assertNoContent();
    $this->withToken($token)->deleteJson("/api/v1/me/organizations/{$org}/api-keys/{$keyId}")->assertNotFound();

    expect(app(CustomerApiKeys::class)->find($keyId)?->revoked_at)->not->toBeNull()
        ->and(AuditEntry::query()->where('action', 'api_key.revoked')->sole()->actor_id)->toBe($fixture['ada']);
});

it('withdraws an application, and removes a device the way My devices does', function (): void {
    [$me] = cibaSubjectWithDevice();
    $device = Device::query()->where('subject_id', $me)->sole();
    $token = myToken($me);

    $this->withToken($token)->deleteJson('/api/v1/me/applications/some-client')->assertNoContent();

    $this->withToken(myToken('somebody-else'))->deleteJson("/api/v1/me/devices/{$device->id}")->assertNotFound();

    $token = myToken($me);
    $this->withToken($token)->deleteJson("/api/v1/me/devices/{$device->id}")->assertNoContent();
    $this->withToken($token)->deleteJson("/api/v1/me/devices/{$device->id}")->assertNotFound();

    $entry = AuditEntry::query()->where('action', 'device.removed')->sole();

    expect(Device::query()->whereKey($device->id)->exists())->toBeFalse()
        ->and($entry->actor_type)->toBe(ActorType::User)
        ->and($entry->actor_id)->toBe($me)
        ->and($entry->target_id)->toBe($device->id);
});

it('serves the account API\'s own contract, publicly', function (): void {
    $this->get('/api/v1/me/openapi.yaml')->assertOk()->assertSee('My Account API');
});
