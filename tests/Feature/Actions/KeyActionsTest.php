<?php

declare(strict_types=1);

use App\Platform\Actions\Idempotency\IdempotencyRecord;
use Carbon\CarbonImmutable;
use Cbox\Id\Platform\Contracts\EnvironmentApiKeys;
use Cbox\Id\Platform\Enums\EnvironmentApiScope;
use Cbox\Id\Platform\Models\EnvironmentApiKey;

/*
|--------------------------------------------------------------------------
| Keys that mint keys — never a wider one — and revoking a parent takes its children.
|--------------------------------------------------------------------------
*/

/** @param  list<string>  $scopes */
function keyWith(array $scopes, ?DateTimeInterface $expires = null): array
{
    $issued = app(EnvironmentApiKeys::class)->issue('env_test', 'Parent', $scopes, $expires);

    return [$issued->plaintext, $issued->key];
}

function minting(array $extra = []): array
{
    return [...['keys:read', 'keys:write', EnvironmentApiScope::AppsRead->value], ...$extra];
}

it('lets a key mint a narrower key, records its parent, and shows the value once', function (): void {
    [$parent, $parentKey] = keyWith(minting());

    $child = $this->withToken($parent)->postJson('/api/v1/keys', [
        'name' => 'Deploy bot',
        'scopes' => [EnvironmentApiScope::AppsRead->value],
        'description' => 'Reads apps for the deploy pipeline',
    ])->assertCreated()->json('data');

    expect($child['token'])->toStartWith('cbid_env_')
        ->and($child['parent_key_id'])->toBe($parentKey->id)
        ->and($child['created_by'])->toBe(['type' => 'environment_key', 'id' => $parentKey->id])
        ->and($child['scopes'])->toBe([EnvironmentApiScope::AppsRead->value])
        ->and($child['expires_at'])->not->toBeNull();

    $this->withToken($parent)->getJson('/api/v1/keys')->assertOk()->assertJsonMissingPath('data.0.token');
});

it('refuses a key-minted key wider than its parent', function (): void {
    [$parent] = keyWith(minting());

    $this->withToken($parent)->postJson('/api/v1/keys', [
        'name' => 'Escalation',
        'scopes' => [EnvironmentApiScope::UsersWrite->value],
    ])->assertUnprocessable()->assertJsonPath('error', 'scope_exceeds_parent');
})->group('security');

it('refuses a key-minted key that outlives its parent or the maximum', function (): void {
    [$parent] = keyWith(minting(), now()->addDays(10));

    $this->withToken($parent)->postJson('/api/v1/keys', [
        'name' => 'Too long',
        'scopes' => [EnvironmentApiScope::AppsRead->value],
        'expires_at' => now()->addDays(30)->toIso8601String(),
    ])->assertUnprocessable()->assertJsonPath('error', 'expiry_exceeds_parent');

    $ok = $this->withToken($parent)->postJson('/api/v1/keys', [
        'name' => 'Bounded',
        'scopes' => [EnvironmentApiScope::AppsRead->value],
    ])->assertCreated()->json('data.expires_at');

    expect(CarbonImmutable::parse($ok)->lessThanOrEqualTo(now()->addDays(10)->addSecond()))->toBeTrue();
})->group('security');

it('refuses a scope no key can carry', function (): void {
    [$parent] = keyWith(minting());

    $this->withToken($parent)->postJson('/api/v1/keys', ['name' => 'X', 'scopes' => ['nonsense:write']])
        ->assertUnprocessable();
});

it('needs keys:write to mint, and keys:read to list', function (): void {
    [$reader] = keyWith(['keys:read']);
    [$none] = keyWith([EnvironmentApiScope::AppsRead->value]);

    $this->withToken($reader)->postJson('/api/v1/keys', ['name' => 'X', 'scopes' => ['keys:read']])->assertForbidden();
    $this->withToken($none)->getJson('/api/v1/keys')->assertForbidden();
});

it('revokes a key and every key it minted, all the way down', function (): void {
    [$root, $rootKey] = keyWith(minting());

    $child = $this->withToken($root)->postJson('/api/v1/keys', ['name' => 'Child', 'scopes' => minting()])->json('data');
    $grandchild = $this->withToken($child['token'])->postJson('/api/v1/keys', ['name' => 'Grandchild', 'scopes' => ['keys:read']])->json('data');

    $this->withToken($root)->deleteJson("/api/v1/keys/{$rootKey->id}")->assertNoContent();

    $revoked = fn (string $id): bool => EnvironmentApiKey::query()->whereKey($id)->value('revoked_at') !== null;

    expect($revoked($rootKey->id))->toBeTrue()
        ->and($revoked($child['id']))->toBeTrue()
        ->and($revoked($grandchild['id']))->toBeTrue();

    $this->withToken($grandchild['token'])->getJson('/api/v1/keys')->assertUnauthorized();
})->group('security');

it('rotates a key: the successor carries the same scopes and the old one retires after the grace period', function (): void {
    [$admin] = keyWith(minting());
    $old = $this->withToken($admin)->postJson('/api/v1/keys', ['name' => 'CI', 'scopes' => ['keys:read']])->json('data');

    $new = $this->withToken($admin)->postJson("/api/v1/keys/{$old['id']}/rotate", ['grace_hours' => 2])->assertCreated()->json('data');

    expect($new['rotated_from_id'])->toBe($old['id'])
        ->and($new['scopes'])->toBe(['keys:read'])
        ->and($new['token'])->not->toBe($old['token']);

    $this->withToken($old['token'])->getJson('/api/v1/keys')->assertOk();
    $this->travel(3)->hours();
    $this->withToken($old['token'])->getJson('/api/v1/keys')->assertUnauthorized();
    $this->withToken($new['token'])->getJson('/api/v1/keys')->assertOk();
});

it('never keeps a minted key\'s value for an idempotent replay', function (): void {
    [$parent] = keyWith(minting());
    $body = ['name' => 'Retry', 'scopes' => ['keys:read']];

    $first = $this->withToken($parent)->withHeader('Idempotency-Key', 'mint-1')->postJson('/api/v1/keys', $body)->assertCreated();
    $again = $this->withToken($parent)->withHeader('Idempotency-Key', 'mint-1')->postJson('/api/v1/keys', $body)->assertCreated();

    expect($first->json('data.token'))->toStartWith('cbid_env_')
        ->and($again->json('data.id'))->toBe($first->json('data.id'))
        ->and($again->json('data.token'))->toBeNull()
        ->and(json_encode(IdempotencyRecord::query()->first()?->payload))->not->toContain((string) $first->json('data.token'));
})->group('security');

it('answers 404 for a key of another environment', function (): void {
    [$admin] = keyWith(minting());
    $other = app(EnvironmentApiKeys::class)->issue('env_other', 'Elsewhere', ['keys:read']);

    $this->withToken($admin)->deleteJson("/api/v1/keys/{$other->key->id}")->assertNotFound();
})->group('security');
