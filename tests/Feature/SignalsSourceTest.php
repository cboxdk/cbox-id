<?php

declare(strict_types=1);

use App\Platform\Radar\RadarPseudonyms;
use App\SignalsData\IdentityCapture;
use App\SignalsData\SourceCursor;
use App\SignalsData\SourceLedger;
use Cbox\Id\Identity\Contracts\SessionManager;
use Cbox\Id\Identity\Contracts\Subjects;
use Cbox\Id\OAuthServer\Contracts\ClientRegistry;
use Cbox\Id\OAuthServer\Contracts\RefreshTokens;
use Cbox\Id\OAuthServer\Exceptions\InvalidGrant;
use Cbox\Id\OAuthServer\Models\RefreshToken;
use Cbox\Id\OAuthServer\ValueObjects\NewClient;
use Cbox\Id\Organization\Contracts\Memberships;
use Cbox\Id\Organization\Contracts\Organizations;
use Cbox\Id\Organization\Enums\MembershipRole;
use Cbox\Id\Organization\ValueObjects\NewOrganization;
use Cbox\Id\Platform\Contracts\EnvironmentApiKeys;
use Illuminate\Support\Facades\DB;
use Opis\JsonSchema\Errors\ErrorFormatter;
use Opis\JsonSchema\Validator;

beforeEach(function (): void {
    installedDeployment();
    config(['signals-data.environments' => ['env_test'], 'signals-auth.enabled' => false]);
});

function signalsSourceKey(string $environment = 'env_test', array $scopes = ['signals_source:read']): string
{
    return app(EnvironmentApiKeys::class)->issue($environment, 'Source qualification', $scopes)->plaintext;
}

function assertSourceEventContract(array $native): void
{
    unset($native['producer_environment'], $native['organization_id']);
    $native['scope'] = ['project_id' => 'qualification', 'environment_id' => 'sandbox', 'audience' => 'environment'];
    $result = (new Validator)->validate(json_decode(json_encode($native)), json_decode(file_get_contents(__DIR__.'/../Fixtures/Signals/event.schema.json')));
    expect($result->isValid())->toBeTrue($result->isValid() ? '' : json_encode((new ErrorFormatter)->format($result->error())));
}

it('exports actual session and token transitions without enabling risk enforcement', function (): void {
    $subject = app(Subjects::class)->create('source-person@example.test', 'Source person', 'source-password-123');
    $session = app(SessionManager::class)->start($subject->id, null, ['pwd'], '192.0.2.44', 'Firefox/140.0');
    $client = app(ClientRegistry::class)->register(new NewClient('Source client', scopes: ['openid', 'offline_access'], grantTypes: ['refresh_token']))->client;
    $tokens = app(RefreshTokens::class);
    $first = $tokens->issue($client, $subject->id, null, ['openid', 'offline_access']);
    $rotated = $tokens->rotate($client->client_id, $first);
    expect($tokens->rotate($client->client_id, $first)->refreshToken)->toBe($rotated->refreshToken);
    RefreshToken::query()->where('token_hash', hash('sha256', $first))->update(['consumed_at' => now()->subMinutes(2)]);
    expect(fn () => $tokens->rotate($client->client_id, $first))->toThrow(InvalidGrant::class);
    expect(RefreshToken::query()->whereNull('revoked_at')->count())->toBe(0);
    app(SessionManager::class)->revoke($session->id);

    $response = $this->withToken(signalsSourceKey())->getJson('/api/v1/signals/source')->assertOk()->assertHeader('Cache-Control', 'no-store, private');
    $events = collect($response->json('data'));
    $tokens = $events->where('name', 'identity.token');
    expect($tokens->where('data.action', 'issued'))->toHaveCount(2)
        ->and($tokens->where('data.action', 'rotated'))->toHaveCount(1)
        ->and($tokens->where('data.action', 'reuse_confirmed'))->toHaveCount(1)
        ->and($tokens->where('data.action', 'revoked'))->toHaveCount(2)
        ->and($events->where('name', 'identity.session')->where('data.action', 'revoked'))->toHaveCount(1)
        ->and($events->where('name', 'identity.account')->where('data.action', 'created'))->toHaveCount(1);
    foreach ($events as $event) {
        assertSourceEventContract($event);
    }
    foreach ([$first, $rotated->refreshToken, 'source-person@example.test', 'source-password-123', 'token_hash', 'successor_token'] as $secret) {
        expect($response->getContent())->not->toContain($secret);
    }
});

it('denies missing foreign wrong-scope and revoked credentials at the source endpoint', function (): void {
    $this->getJson('/api/v1/signals/source')->assertUnauthorized();
    $this->withToken(signalsSourceKey('foreign'))->getJson('/api/v1/signals/source')->assertUnauthorized();
    $this->withToken(signalsSourceKey(scopes: ['events:read']))->getJson('/api/v1/signals/source')->assertForbidden();
    $issued = app(EnvironmentApiKeys::class)->issue('env_test', 'Revoke test', ['signals_source:read']);
    $this->withToken($issued->plaintext)->getJson('/api/v1/signals/source')->assertOk();
    app(EnvironmentApiKeys::class)->revoke('env_test', $issued->key->id);
    $this->withToken($issued->plaintext)->getJson('/api/v1/signals/source')->assertUnauthorized();
});

it('keeps equal-time pages stable and rejects forged and stale-generation cursors', function (): void {
    $this->freezeTime();
    for ($i = 0; $i < 3; $i++) {
        app(Subjects::class)->create("source-{$i}@example.test", 'Source');
    }
    $key = signalsSourceKey();
    $one = $this->withToken($key)->getJson('/api/v1/signals/source?limit=2')->assertOk()->assertJsonPath('meta.has_more', true);
    $cursor = $one->json('meta.next_cursor');
    $two = $this->withToken($key)->getJson('/api/v1/signals/source?limit=2&after='.urlencode($cursor))->assertOk()->assertJsonPath('meta.has_more', false);
    expect(array_merge(array_column($one->json('data'), 'id'), array_column($two->json('data'), 'id')))->toHaveCount(3);
    $this->withToken($key)->getJson('/api/v1/signals/source?after='.urlencode($cursor.'x'))->assertUnprocessable();
    DB::table('signals_source_heads')->where('environment_id', 'env_test')->update(['generation' => 'reset']);
    $this->withToken($key)->getJson('/api/v1/signals/source?after='.urlencode($cursor))->assertUnprocessable();
});

it('rolls source facts back with the owning mutation', function (): void {
    try {
        DB::transaction(function (): void {
            app(Subjects::class)->create('rollback@example.test', 'Rollback');
            throw new RuntimeException('qualification rollback');
        });
    } catch (RuntimeException) {
    }
    expect(app(Subjects::class)->findByEmail('rollback@example.test'))->toBeNull();
    $this->withToken(signalsSourceKey())->getJson('/api/v1/signals/source')->assertOk()->assertJsonCount(0, 'data');
});

it('reports disabled capture as unavailable rather than healthy zero', function (): void {
    config(['signals-data.environments' => []]);
    app(Subjects::class)->create('uncaptured@example.test', 'Uncaptured');
    expect(DB::table('signals_source_records')->count())->toBe(0);
    $this->withToken(signalsSourceKey())->getJson('/api/v1/signals/source')->assertStatus(503);
});

it('exports public password failures success and logout with one attempt lineage', function (): void {
    app(Subjects::class)->create('login-source@example.test', 'Source', 'source-password-123');
    $this->withServerVariables(['REMOTE_ADDR' => '192.0.2.44', 'HTTP_USER_AGENT' => 'Mozilla/5.0 Firefox/140.0']);
    $this->from('/login')->post(route('login.attempt'), ['email' => 'login-source@example.test', 'password' => 'incorrect-password'])->assertRedirect();
    $this->from('/login')->post(route('login.attempt'), ['email' => 'login-source@example.test', 'password' => 'source-password-123'])->assertRedirect();
    $this->post(route('logout'))->assertRedirect();
    $response = $this->withToken(signalsSourceKey())->getJson('/api/v1/signals/source')->assertOk();
    $events = collect($response->json('data'));
    $auth = $events->where('name', 'identity.authentication');
    expect($auth->where('data.stage', 'attempted'))->toHaveCount(2)
        ->and($auth->where('data.outcome', 'failed'))->toHaveCount(1)
        ->and($auth->where('data.outcome', 'succeeded'))->toHaveCount(1)
        ->and($auth->pluck('data.attempt_id')->unique())->toHaveCount(2)
        ->and($events->where('name', 'identity.session')->where('data.action', 'logout'))->toHaveCount(1);
    $success = $auth->where('data.outcome', 'succeeded')->first();
    expect($success['data']['context']['ip'])->toBe('192.0.2.44')
        ->and($success['data']['context']['browser_family'])->toBe('Firefox')
        ->and($success['data']['context']['device_evidence'])->toBe('first_party_cookie');
});

it('erases source rows including unknown-target attempts and keeps unrelated people', function (): void {
    $one = app(Subjects::class)->create('erase-source@example.test', 'Erase source');
    $other = app(Subjects::class)->create('keep-source@example.test', 'Keep source');
    $this->from('/login')->post(route('login.attempt'), ['email' => 'erase-source@example.test', 'password' => 'wrong-password'])->assertRedirect();
    $snapshot = DB::table('signals_source_records')->get()->map(fn ($r) => (array) $r)->all();
    $target = 'target:'.app(RadarPseudonyms::class)->subject('erase-source@example.test');
    app(SourceLedger::class)->erase([$one->id, $target]);
    $key = signalsSourceKey();
    $response = $this->withToken($key)->getJson('/api/v1/signals/source')->assertOk();
    expect($response->getContent())->not->toContain($one->id)->toContain($other->id);
    // A restored raw snapshot must not expose the erased subject while the
    // independent deletion ledger remains in place.
    foreach ($snapshot as $row) {
        DB::table('signals_source_records')->insertOrIgnore($row);
    }
    $this->withToken($key)->getJson('/api/v1/signals/source?limit=1')->assertOk()->assertJsonPath('meta.has_more', false)->assertJsonCount(1, 'data');
});

it('bounds immutable replay by raw-IP retention and publishes independent heartbeats', function (): void {
    $this->freezeTime();
    app(Subjects::class)->create('retention-source@example.test', 'Retention');
    $key = signalsSourceKey();
    $before = $this->withToken($key)->getJson('/api/v1/signals/source')->assertOk()->assertJsonPath('meta.heartbeat_at', null);
    $this->artisan('signals:source-maintain')->assertSuccessful();
    $alive = $this->withToken($key)->getJson('/api/v1/signals/source')->assertOk();
    expect($alive->json('meta.heartbeat_at'))->not->toBeNull();
    $this->travel(8)->days();
    $this->withToken($key)->getJson('/api/v1/signals/source')->assertOk()->assertJsonCount(0, 'data');
    $this->artisan('signals:source-maintain')->assertSuccessful();
    expect(DB::table('signals_source_records')->count())->toBe(0);
    // Rewinding into purged history cannot be mistaken for a complete empty page.
    $cursor = app(SourceCursor::class)->encode('env_test', $before->json('meta.generation'), 0);
    $this->withToken($key)->getJson('/api/v1/signals/source?after='.urlencode($cursor))->assertUnprocessable();
});

it('exports membership intervals without granting tenant authority to pre-membership events', function (): void {
    $person = app(Subjects::class)->create('relations-source@example.test', 'Relations');
    $organization = app(Organizations::class)->create(new NewOrganization('Source team', 'source-team'));
    app(Memberships::class)->add($organization->id, $person->id, MembershipRole::Member);
    $this->travel(5)->minutes();
    app(Memberships::class)->remove($organization->id, $person->id);
    $events = collect($this->withToken(signalsSourceKey())->getJson('/api/v1/signals/source')->assertOk()->json('data'));
    expect($events->where('name', 'identity.account')->first()['organization_id'])->toBeNull();
    $relations = $events->where('kind', 'relation');
    expect($relations)->toHaveCount(2);
    $added = $relations->where('operation', 'assert')->first();
    $removed = $relations->where('operation', 'retract')->first();
    expect($removed['supersedes_id'])->toBe($added['id'])
        ->and($removed['valid_from'])->toBe($added['valid_from'])
        ->and($removed['valid_until'])->not->toBeNull();
    foreach ($relations as $record) {
        unset($record['producer_environment'], $record['organization_id']);
        $record['scope'] = ['project_id' => 'qualification', 'environment_id' => 'sandbox', 'audience' => 'environment'];
        $valid = (new Validator)->validate(json_decode(json_encode($record)), json_decode(file_get_contents(__DIR__.'/../Fixtures/Signals/relation.schema.json')));
        expect($valid->isValid())->toBeTrue();
    }
});

it('retains only a deletion receipt for consumer restore after raw history expires', function (): void {
    $person = app(Subjects::class)->create('delete-public-source@example.test', 'Delete public source');
    $key = signalsSourceKey(scopes: ['signals_source:read', 'users:erase']);
    $this->withToken($key)->postJson('/api/v1/users/'.$person->id.'/erase')->assertOk()->assertJsonPath('data.subject_pseudonymised', true);
    $first = $this->withToken($key)->getJson('/api/v1/signals/source/erasures')->assertOk()->assertJsonCount(1, 'data');
    assertSourceEventContract($first->json('data.0'));
    expect($first->json('data.0.data.action'))->toBe('erased')
        ->and($first->json('data.0.data.subject.id'))->toBe($person->id)
        ->and($first->getContent())->not->toContain('delete-public-source@example.test');
    $this->travel(8)->days();
    $this->artisan('signals:source-maintain')->assertSuccessful();
    $restored = $this->withToken($key)->getJson('/api/v1/signals/source/erasures')->assertOk();
    expect($restored->json('data'))->toBe($first->json('data'));
    $this->withToken(signalsSourceKey(scopes: ['events:read']))->getJson('/api/v1/signals/source/erasures')->assertForbidden();
});

it('publishes deletion receipts while ordinary publication is backpressured', function (): void {
    $person = app(Subjects::class)->create('delete-full@example.test', 'Delete full');
    app(Subjects::class)->create('keep-full@example.test', 'Keep full');
    $key = signalsSourceKey(scopes: ['signals_source:read', 'users:erase']);
    config(['signals-data.max_records' => 0]);
    $this->withToken($key)->getJson('/api/v1/signals/source')->assertStatus(503);
    $this->withToken($key)->postJson('/api/v1/users/'.$person->id.'/erase')->assertOk();
    $this->withToken($key)->getJson('/api/v1/signals/source/erasures')->assertOk()
        ->assertJsonCount(1, 'data')->assertJsonPath('data.0.data.subject.id', $person->id)
        ->assertJsonPath('meta.has_more', false);
    expect(DB::table('signals_source_pending')->where('name', 'identity.account')->count())->toBe(1);
});

it('never attaches another person or an expired attempt to a successful session', function (): void {
    $one = app(Subjects::class)->create('pending-one@example.test', 'One');
    $two = app(Subjects::class)->create('pending-two@example.test', 'Two');
    $request = app('request');
    app(IdentityCapture::class)->begin($request, email: 'pending-one@example.test');
    app(SessionManager::class)->start($two->id, null, ['pwd'], '192.0.2.44', 'Firefox/140.0');
    app(IdentityCapture::class)->begin($request, $one->id, 'pending-one@example.test');
    $this->travel(16)->minutes();
    app(SessionManager::class)->start($one->id, null, ['pwd'], '192.0.2.44', 'Firefox/140.0');
    $events = collect($this->withToken(signalsSourceKey())->getJson('/api/v1/signals/source')->assertOk()->json('data'))->where('name', 'identity.authentication');
    expect($events->where('data.stage', 'attempted'))->toHaveCount(4)
        ->and($events->where('data.outcome', 'succeeded'))->toHaveCount(2);
    foreach ($events->where('data.outcome', 'succeeded') as $success) {
        $start = $events->where('data.attempt_id', $success['data']['attempt_id'])->where('data.stage', 'attempted')->first();
        expect($start['data']['subject'])->toBe($success['data']['subject']);
    }
});
