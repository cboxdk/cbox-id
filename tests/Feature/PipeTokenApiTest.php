<?php

declare(strict_types=1);

use Cbox\Id\Kernel\Audit\Models\AuditEntry;
use Cbox\Id\Kernel\Tenancy\Contracts\EnvironmentContext;
use Cbox\Id\Kernel\Tenancy\GenericEnvironment;
use Cbox\Id\OAuthServer\Contracts\ClientRegistry;
use Cbox\Id\OAuthServer\Contracts\TokenIntrospector;
use Cbox\Id\OAuthServer\ValueObjects\Introspection;
use Cbox\Id\OAuthServer\ValueObjects\NewClient;
use Cbox\Id\Organization\Contracts\Memberships;
use Cbox\Id\Organization\Contracts\Organizations;
use Cbox\Id\Organization\Enums\MembershipRole;
use Cbox\Id\Organization\ValueObjects\NewOrganization;
use Cbox\Id\Pipes\Contracts\Pipes;
use Cbox\Id\Pipes\Enums\PipeConnectionStatus;
use Cbox\Id\Pipes\Testing\InteractsWithPipes;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;

uses(RefreshDatabase::class, InteractsWithPipes::class);

/**
 * `POST /api/v1/vault/pipes/{provider}/token` — an authorised app leases a fresh access
 * token for one person's connected account. The pipe's grant list is the gate; the token
 * names the app (and, for a person's token, the person).
 */
beforeEach(function (): void {
    $register = fn (string $name, ?string $organizationId = null): string => app(ClientRegistry::class)
        ->register(new NewClient($name, organizationId: $organizationId, redirectUris: ['https://app.example/callback']))->client->client_id;

    $this->acme = app(Organizations::class)->create(new NewOrganization('Acme', 'acme-pipes'));
    $this->granted = $register('CRM sync');
    $this->stranger = $register('Unrelated app');
    $this->acmeApp = $register('Acme backend', $this->acme->id);

    $pipe = $this->configurePipe('hubspot');
    $this->grantPipe($pipe, $this->granted);
    $this->grantPipe($pipe, $this->acmeApp);
    $this->connection = $this->connectPipeAccount('hubspot', 'user_ada', ['access_token' => 'CJ-ADA-ACCESS', 'refresh_token' => 'na1-ADA-REFRESH', 'expires_in' => 1800]);
    app(Memberships::class)->add($this->acme->id, 'user_ada', MembershipRole::Member);

    $tokens = [
        'machine' => Introspection::active($this->granted, $this->granted, ['vault.lease'], []),
        'ada' => Introspection::active('user_ada', $this->granted, ['vault.lease'], []),
        'stranger' => Introspection::active($this->stranger, $this->stranger, ['vault.lease'], []),
        'acme' => Introspection::active($this->acmeApp, $this->acmeApp, ['vault.lease'], ['org' => $this->acme->id]),
        'no-scope' => Introspection::active($this->granted, $this->granted, ['vault.manage'], []),
        'unknown-app' => Introspection::active('cid_nobody', 'cid_nobody', ['vault.lease'], []),
    ];

    $this->app->instance(TokenIntrospector::class, new readonly class($tokens) implements TokenIntrospector
    {
        /** @param  array<string, Introspection>  $tokens */
        public function __construct(private array $tokens) {}

        public function introspect(string $token): Introspection
        {
            return $this->tokens[$token] ?? Introspection::inactive();
        }

        public function revoke(string $jti): void {}
    });
});

function pipeLease(object $test, string $token, array $body = [], string $provider = 'hubspot')
{
    return $test->postJson("/api/v1/vault/pipes/{$provider}/token", ['purpose' => 'sync', ...$body], ['Authorization' => "Bearer {$token}"]);
}

it('leases a fresh token to a granted app for the person it names, and never lets it be cached', function (): void {
    $response = pipeLease($this, 'machine', ['user_id' => 'user_ada'])
        ->assertOk()
        ->assertHeader('Cache-Control', 'no-store, private')
        ->assertJsonPath('access_token', 'CJ-ADA-ACCESS')
        ->assertJsonPath('token_type', 'Bearer')
        ->assertJsonPath('provider', 'hubspot')
        ->assertJsonPath('user_id', 'user_ada')
        ->assertJsonPath('connection_id', $this->connection->id)
        ->assertJsonStructure(['expires_at', 'lease_expires_at', 'scopes', 'metadata']);

    expect($response->json())->not->toHaveKey('refresh_token');
    expect($response->getContent())->not->toContain('na1-ADA-REFRESH');

    $entry = AuditEntry::query()->where('action', 'pipe.token.leased')->sole();
    expect($entry->actor_id)->toBe($this->granted)
        ->and(json_encode(DB::table('audit_logs')->get()))->not->toContain('CJ-ADA-ACCESS')->not->toContain('na1-ADA-REFRESH');
});

it('leases a person\'s own connection with their token, and refuses one that names someone else', function (): void {
    pipeLease($this, 'ada')->assertOk()->assertJsonPath('user_id', 'user_ada');
    pipeLease($this, 'ada', ['user_id' => 'user_ada'])->assertOk();

    pipeLease($this, 'ada', ['user_id' => 'user_bob'])
        ->assertForbidden()
        ->assertJsonPath('error', 'lease_denied')
        ->assertJsonPath('message', 'The lease was denied.');
});

it('asks a machine token which person it means', function (): void {
    pipeLease($this, 'machine')->assertUnprocessable()->assertJsonPath('error', 'invalid_request');
});

it('refuses every app that may not lease — the same answer whatever the reason', function (): void {
    // Every envelope carries its own `request_id`; everything else must be identical.
    $answer = fn ($response): string => (string) json_encode(collect($response->assertForbidden()->json())->except('request_id')->all());

    $answers = [
        $answer(pipeLease($this, 'stranger', ['user_id' => 'user_ada'])),
        $answer(pipeLease($this, 'unknown-app', ['user_id' => 'user_ada'])),
        $answer(pipeLease($this, 'machine', ['user_id' => 'user_ada'], 'github')),
        $answer(pipeLease($this, 'machine', ['user_id' => 'user_ada'], 'myspace')),
    ];

    expect(array_unique($answers))->toBe(['{"error":"lease_denied","message":"The lease was denied."}']);
});

it('needs the vault.lease scope', function (): void {
    pipeLease($this, 'no-scope', ['user_id' => 'user_ada'])->assertStatus(403)->assertHeader('WWW-Authenticate');
    $this->postJson('/api/v1/vault/pipes/hubspot/token', ['purpose' => 'x'])->assertUnauthorized();
});

it('confines an organization\'s app to its own members', function (): void {
    pipeLease($this, 'acme', ['user_id' => 'user_ada'])->assertOk();

    $this->connectPipeAccount('hubspot', 'user_outsider', ['access_token' => 'CJ-OUT', 'refresh_token' => 'r', 'expires_in' => 1800]);

    pipeLease($this, 'acme', ['user_id' => 'user_outsider'])->assertForbidden()->assertJsonPath('error', 'lease_denied');
    pipeLease($this, 'machine', ['user_id' => 'user_outsider'])->assertOk()->assertJsonPath('access_token', 'CJ-OUT');
});

it('tells a granted app where to send a person who has not connected, or must connect again', function (): void {
    pipeLease($this, 'machine', ['user_id' => 'user_nobody'])
        ->assertNotFound()
        ->assertJsonPath('error', 'not_connected')
        ->assertJsonPath('connect_url', route('account.pipes.connect', 'hubspot'));

    $this->connection->forceFill(['status' => PipeConnectionStatus::NeedsReauth])->save();

    pipeLease($this, 'machine', ['user_id' => 'user_ada'])
        ->assertStatus(409)
        ->assertJsonPath('error', 'reauthorization_required');
});

it('refreshes an expiring token on the way out', function (): void {
    $this->connection->forceFill(['access_expires_at' => now()->addSeconds(10)])->save();
    Http::fake(['https://api.hubapi.com/oauth/v1/token' => Http::response(['access_token' => 'CJ-FRESH', 'refresh_token' => 'na1-NEXT', 'expires_in' => 1800])]);

    pipeLease($this, 'machine', ['user_id' => 'user_ada'])->assertOk()->assertJsonPath('access_token', 'CJ-FRESH');
});

it('answers a provider outage as retry-later, not as a dead connection', function (): void {
    $this->connection->forceFill(['access_expires_at' => now()->subMinute()])->save();
    Http::fake(['https://api.hubapi.com/oauth/v1/token' => Http::response('', 503)]);

    pipeLease($this, 'machine', ['user_id' => 'user_ada'])
        ->assertStatus(503)
        ->assertHeader('Retry-After')
        ->assertJsonPath('error', 'temporarily_unavailable');

    expect($this->connection->fresh()?->status)->toBe(PipeConnectionStatus::Active);
});

it('reaches nothing in another environment', function (): void {
    // The same provider configured in another environment, the same person connected, the
    // same app id granted there — none of it answers here, and this environment's
    // connection answers nothing there.
    app(EnvironmentContext::class)->runAs(GenericEnvironment::of('env_other'), function (): void {
        $pipe = app(Pipes::class)->configure('salesforce', 'x', 'y');
        app(Pipes::class)->grant($pipe->id, $this->granted);
        $this->connectPipeAccount('salesforce', 'user_ada', ['access_token' => '00D!OTHER-ENV']);
    });

    pipeLease($this, 'machine', ['user_id' => 'user_ada'], 'salesforce')->assertForbidden();
});
