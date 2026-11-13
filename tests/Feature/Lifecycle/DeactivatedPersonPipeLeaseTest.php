<?php

declare(strict_types=1);

use Cbox\Id\Identity\Contracts\Subjects;
use Cbox\Id\OAuthServer\Contracts\ClientRegistry;
use Cbox\Id\OAuthServer\Contracts\TokenIntrospector;
use Cbox\Id\OAuthServer\ValueObjects\Introspection;
use Cbox\Id\OAuthServer\ValueObjects\NewClient;
use Cbox\Id\Pipes\Contracts\PipeTokens;
use Cbox\Id\Pipes\Testing\InteractsWithPipes;
use Illuminate\Support\Facades\Http;
use Illuminate\Testing\TestResponse;

uses(InteractsWithPipes::class);

/**
 * A DEACTIVATED PERSON'S CONNECTED ACCOUNTS ARE NEITHER LEASED NOR RENEWED.
 *
 * Deactivation revoked the person's Cbox ID grants and left the GitHub, Google or HubSpot
 * token they connected through a pipe: an app granted the pipe went on leasing the leaver's
 * provider token, and the refresh sweep kept it fresh. Reactivated, the connection serves
 * again without anybody connecting it anew.
 */
beforeEach(function (): void {
    $this->person = app(Subjects::class)->create('leaver@acme.test', 'Lee', 'a-strong-unbreached-passphrase')->id;
    $this->granted = app(ClientRegistry::class)
        ->register(new NewClient('CRM sync', redirectUris: ['https://app.example/callback']))->client->client_id;

    $pipe = $this->configurePipe('hubspot');
    $this->grantPipe($pipe, $this->granted);
    $this->connection = $this->connectPipeAccount('hubspot', $this->person, ['access_token' => 'CJ-LEAVER-ACCESS', 'refresh_token' => 'na1-LEAVER-REFRESH', 'expires_in' => 1800]);

    $machine = Introspection::active($this->granted, $this->granted, ['vault.lease'], []);
    $this->app->instance(TokenIntrospector::class, new readonly class($machine) implements TokenIntrospector
    {
        public function __construct(private Introspection $machine) {}

        public function introspect(string $token): Introspection
        {
            return $token === 'machine' ? $this->machine : Introspection::inactive();
        }

        public function revoke(string $jti): void {}
    });
});

function dplLease(string $userId): TestResponse
{
    return test()->postJson('/api/v1/vault/pipes/hubspot/token', ['purpose' => 'sync', 'user_id' => $userId], ['Authorization' => 'Bearer machine']);
}

it('refuses to lease a deactivated person\'s provider token, and serves it again on reactivation (#42)', function (): void {
    dplLease($this->person)->assertOk()->assertJsonPath('access_token', 'CJ-LEAVER-ACCESS');

    app(Subjects::class)->deactivate($this->person);

    dplLease($this->person)->assertForbidden()->assertJsonPath('error', 'lease_denied');

    app(Subjects::class)->reactivate($this->person);

    dplLease($this->person)->assertOk()->assertJsonPath('access_token', 'CJ-LEAVER-ACCESS');
});

it('does not renew a deactivated person\'s connection in the refresh sweep (#42)', function (): void {
    $this->connection->forceFill(['access_expires_at' => now()->addSeconds(10)])->save();
    Http::fake(['https://api.hubapi.com/oauth/v1/token' => Http::response(['access_token' => 'CJ-FRESH', 'refresh_token' => 'na1-NEXT', 'expires_in' => 1800])]);

    app(Subjects::class)->deactivate($this->person);
    app(PipeTokens::class)->refreshIfExpiring($this->connection->id, 60);

    Http::assertNothingSent();
});
