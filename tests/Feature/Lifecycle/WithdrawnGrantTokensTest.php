<?php

declare(strict_types=1);

use Cbox\Id\Identity\Contracts\Subjects;
use Cbox\Id\OAuthServer\Contracts\ClientRegistry;
use Cbox\Id\OAuthServer\Contracts\RefreshTokens;
use Cbox\Id\OAuthServer\Contracts\TokenIssuer;
use Cbox\Id\OAuthServer\Models\AccessToken;
use Cbox\Id\OAuthServer\Models\RefreshToken;
use Cbox\Id\OAuthServer\ValueObjects\NewClient;
use Cbox\Id\OAuthServer\ValueObjects\RegisteredClient;
use Illuminate\Support\Facades\DB;
use Illuminate\Testing\TestResponse;

/**
 * A WITHDRAWN GRANT TAKES ITS ACCESS TOKENS WITH IT, AND A DELETED APP ITS TOKENS.
 *
 * Withdrawing an app or a person's access revoked refresh tokens only. A client with the
 * token-exchange grant presented its still-valid access token as the subject token and got
 * a fresh one with a full lifetime, again and again: the withdrawal never landed. Deleting
 * an app left every token it held active until expiry.
 */
const WGT_TOKEN_EXCHANGE = 'urn:ietf:params:oauth:grant-type:token-exchange';
const WGT_ACCESS_TOKEN_TYPE = 'urn:ietf:params:oauth:token-type:access_token';

function wgtExchangingApp(): RegisteredClient
{
    return app(ClientRegistry::class)->register(new NewClient(
        name: 'Ledger backend',
        redirectUris: ['https://ledger.test/callback'],
        grantTypes: ['authorization_code', 'refresh_token', WGT_TOKEN_EXCHANGE],
        scopes: ['openid', 'profile', 'offline_access'],
        firstParty: true,
    ));
}

/** @return array<string, string> */
function wgtBasic(RegisteredClient $app): array
{
    return ['Authorization' => 'Basic '.base64_encode($app->client->client_id.':'.$app->secret)];
}

function wgtExchange(RegisteredClient $app, string $subjectToken): TestResponse
{
    return test()->postJson('/oauth/token', [
        'grant_type' => WGT_TOKEN_EXCHANGE,
        'subject_token' => $subjectToken,
        'subject_token_type' => WGT_ACCESS_TOKEN_TYPE,
    ], wgtBasic($app));
}

it('lets no app renew a person\'s access by token exchange after it was withdrawn (#31)', function (string $withdrawal): void {
    $person = app(Subjects::class)->create('ada@acme.test', 'Ada', 'a-strong-unbreached-passphrase');
    $app = wgtExchangingApp();
    $refresh = app(RefreshTokens::class)->issue($app->client, $person->id, null, ['openid', 'profile', 'offline_access']);
    $access = app(TokenIssuer::class)->issueForUser($app->client, $person->id, null, ['openid', 'profile'])->token;

    // Before: the exchange works, which is what made it a renewal.
    wgtExchange($app, $access)->assertOk();

    match ($withdrawal) {
        // DELETE /account/applications/{client} (RevokeApplication).
        'the person withdrew the app' => app(RefreshTokens::class)->revokeForUserAndClient($person->id, $app->client->client_id),
        // RevokeAllUserSessions, removal, an archived organization.
        'their access was withdrawn' => app(RefreshTokens::class)->withdrawAccess($person->id),
        // RFC 7009 at /oauth/revoke, by the app itself.
        'the app revoked its refresh token' => test()->post('/oauth/revoke', ['token' => $refresh, 'token_type_hint' => 'refresh_token'], wgtBasic($app))->assertOk(),
    };

    wgtExchange($app, $access)->assertStatus(400);
    $this->withToken($access)->getJson('/oauth/userinfo')->assertUnauthorized();
})->with([
    'the person withdrew the app',
    'their access was withdrawn',
    'the app revoked its refresh token',
]);

it('ends every token of a deleted app at once (#32)', function (): void {
    $person = app(Subjects::class)->create('ada@acme.test', 'Ada', 'a-strong-unbreached-passphrase');
    $app = wgtExchangingApp();
    $other = app(ClientRegistry::class)->register(new NewClient(name: 'Other app', redirectUris: ['https://other.test/cb'], grantTypes: ['authorization_code'], scopes: ['openid']));
    app(RefreshTokens::class)->issue($app->client, $person->id, null, ['openid', 'offline_access']);
    $access = app(TokenIssuer::class)->issueForUser($app->client, $person->id, null, ['openid'])->token;
    $kept = app(TokenIssuer::class)->issueForUser($other->client, $person->id, null, ['openid'])->token;

    $this->withToken($access)->getJson('/oauth/userinfo')->assertOk();
    $this->flushHeaders();

    app(ClientRegistry::class)->delete($app->client);

    $this->withToken($access)->getJson('/oauth/userinfo')->assertUnauthorized();
    $this->flushHeaders();

    expect(AccessToken::query()->where('client_id', $app->client->client_id)->whereNull('revoked_at')->count())->toBe(0)
        ->and(RefreshToken::query()->where('client_id', $app->client->client_id)->whereNull('revoked_at')->count())->toBe(0);

    // Another app's token is its own.
    $this->withToken($kept)->getJson('/oauth/userinfo')->assertOk();
});

it('refuses a deleted app\'s token at introspection even when its row was left behind (#32)', function (): void {
    $person = app(Subjects::class)->create('ada@acme.test', 'Ada', 'a-strong-unbreached-passphrase');
    $app = wgtExchangingApp();
    $access = app(TokenIssuer::class)->issueForUser($app->client, $person->id, null, ['openid'])->token;

    // A delete that bypassed the model (a raw query, a restore gone wrong): the row is
    // gone and its tokens were never touched.
    DB::table('oauth_clients')->where('id', $app->client->id)->delete();

    $this->withToken($access)->getJson('/oauth/userinfo')->assertUnauthorized();
});
