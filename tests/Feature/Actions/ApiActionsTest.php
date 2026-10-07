<?php

declare(strict_types=1);

use App\Platform\Actions\Idempotency\IdempotencyRecord;
use Cbox\Id\Kernel\Audit\Enums\ActorType;
use Cbox\Id\Kernel\Audit\Models\AuditEntry;
use Cbox\Id\Platform\Contracts\EnvironmentApiKeys;
use Cbox\Id\Platform\Enums\EnvironmentApiScope;
use Illuminate\Support\Str;

/*
|--------------------------------------------------------------------------
| APIs, as actions: the two new scope endpoints, idempotent retries, and one trail.
|--------------------------------------------------------------------------
*/

function apisKey(array $scopes = [EnvironmentApiScope::ApisRead, EnvironmentApiScope::ApisWrite]): string
{
    return app(EnvironmentApiKeys::class)->issue(
        'env_test',
        'APIs worker',
        array_map(fn (EnvironmentApiScope $scope): string => $scope->value, $scopes),
    )->plaintext;
}

function registerTaxApi(string $key): array
{
    return test()->withToken($key)->postJson('/api/v1/apis', [
        'identifier' => 'https://tax-'.Str::lower(Str::random(6)).'.example',
        'name' => 'Tax',
        'scopes' => [['key' => 'tax:quote-'.Str::lower(Str::random(4)), 'description' => 'Quote']],
    ])->assertCreated()->json('data');
}

it('adds, changes and removes one scope without resending the set', function (): void {
    $key = apisKey();
    $api = registerTaxApi($key);

    $scope = fn ($response): ?array => collect($response->json('data.scopes'))->firstWhere('key', 'tax:assess');

    $added = $this->withToken($key)->putJson("/api/v1/apis/{$api['id']}/scopes/tax:assess", ['description' => 'Assess'])->assertOk();

    expect($scope($added))->toMatchArray(['description' => 'Assess', 'tenant_requestable' => true]);

    $changed = $this->withToken($key)->putJson("/api/v1/apis/{$api['id']}/scopes/tax:assess", ['description' => 'Assess a return', 'tenant_requestable' => false])->assertOk();

    expect($scope($changed))->toMatchArray(['description' => 'Assess a return', 'tenant_requestable' => false]);

    $this->withToken($key)->deleteJson("/api/v1/apis/{$api['id']}/scopes/tax:assess")->assertNoContent();

    $this->withToken($key)->getJson("/api/v1/apis/{$api['id']}")
        ->assertOk()
        ->assertJsonCount(1, 'data.scopes');
});

it('refuses a scope key another API owns, and a sign-in scope', function (): void {
    $key = apisKey();
    $first = registerTaxApi($key);
    $second = registerTaxApi($key);
    $taken = $first['scopes'][0]['key'];

    $this->withToken($key)->putJson("/api/v1/apis/{$second['id']}/scopes/{$taken}", [])
        ->assertUnprocessable()->assertJsonPath('error', 'invalid_api');

    $this->withToken($key)->putJson("/api/v1/apis/{$second['id']}/scopes/openid", [])
        ->assertUnprocessable()->assertJsonPath('error', 'invalid_api');
});

it('answers 404 removing a scope this API does not own', function (): void {
    $key = apisKey();
    $first = registerTaxApi($key);
    $second = registerTaxApi($key);

    $this->withToken($key)->deleteJson("/api/v1/apis/{$second['id']}/scopes/{$first['scopes'][0]['key']}")
        ->assertNotFound();
});

it('needs apis:write for every write, whichever endpoint', function (): void {
    $writer = apisKey();
    $reader = apisKey([EnvironmentApiScope::ApisRead]);
    $api = registerTaxApi($writer);

    $this->withToken($reader)->putJson("/api/v1/apis/{$api['id']}/scopes/tax:x", [])->assertForbidden();
    $this->withToken($reader)->deleteJson("/api/v1/apis/{$api['id']}")->assertForbidden();
});

/*
 * A retried write must not make a second API. The same key and request replay the first
 * answer; the same key on a different request is refused rather than replayed.
 */
it('replays the first answer to a retried create with the same Idempotency-Key', function (): void {
    $key = apisKey();
    $body = ['identifier' => 'https://retry.example', 'name' => 'Retry'];

    $first = $this->withToken($key)->withHeader('Idempotency-Key', 'k-1')->postJson('/api/v1/apis', $body)->assertCreated();
    $again = $this->withToken($key)->withHeader('Idempotency-Key', 'k-1')->postJson('/api/v1/apis', $body)->assertCreated();

    expect($again->json('data.id'))->toBe($first->json('data.id'))
        ->and($again->headers->get('Idempotent-Replayed'))->toBe('true')
        ->and($first->headers->get('Idempotent-Replayed'))->toBeNull();

    $this->withToken($key)->getJson('/api/v1/apis')->assertJsonCount(1, 'data');
});

it('refuses an Idempotency-Key reused for a different request', function (): void {
    $key = apisKey();

    $this->withToken($key)->withHeader('Idempotency-Key', 'k-2')->postJson('/api/v1/apis', ['identifier' => 'https://a.example', 'name' => 'A'])->assertCreated();

    $this->withToken($key)->withHeader('Idempotency-Key', 'k-2')->postJson('/api/v1/apis', ['identifier' => 'https://b.example', 'name' => 'B'])
        ->assertUnprocessable()
        ->assertJsonPath('error', 'idempotency_key_reused');
});

it('keeps idempotency keys per key: another key may use the same string', function (): void {
    $this->withToken(apisKey())->withHeader('Idempotency-Key', 'shared')->postJson('/api/v1/apis', ['identifier' => 'https://one.example', 'name' => 'One'])->assertCreated();
    $this->withToken(apisKey())->withHeader('Idempotency-Key', 'shared')->postJson('/api/v1/apis', ['identifier' => 'https://two.example', 'name' => 'Two'])->assertCreated();

    expect(IdempotencyRecord::query()->where('idempotency_key', 'shared')->count())->toBe(2);
});

it('does not keep a refusal, so a corrected retry runs', function (): void {
    $key = apisKey();

    $this->withToken($key)->withHeader('Idempotency-Key', 'k-3')->postJson('/api/v1/apis', ['identifier' => 'https://c.example', 'name' => 'C', 'organization_id' => 'org_missing'])
        ->assertUnprocessable()->assertJsonPath('error', 'organization_not_found');

    $this->withToken($key)->withHeader('Idempotency-Key', 'k-3')->postJson('/api/v1/apis', ['identifier' => 'https://c.example', 'name' => 'C', 'organization_id' => 'org_missing'])
        ->assertUnprocessable();

    expect(IdempotencyRecord::query()->count())->toBe(0);
});

it('lets old idempotency records be pruned', function (): void {
    $key = apisKey();
    $this->withToken($key)->withHeader('Idempotency-Key', 'k-4')->postJson('/api/v1/apis', ['identifier' => 'https://d.example', 'name' => 'D'])->assertCreated();

    $this->travel(25)->hours();

    expect((new IdempotencyRecord)->prunable()->count())->toBe(1);
});

it('records a scope change from the API as the key, in the same shape as the console', function (): void {
    $key = apisKey();
    $api = registerTaxApi($key);

    $this->withToken($key)->putJson("/api/v1/apis/{$api['id']}/scopes/tax:file", ['description' => 'File'])->assertOk();

    $entry = AuditEntry::query()->where('action', 'api.scope_defined')->orderByDesc('id')->first();

    expect($entry)->not->toBeNull()
        ->and($entry->actor_type)->toBe(ActorType::Service)
        ->and($entry->context['scope'] ?? null)->toBe('tax:file');
});
