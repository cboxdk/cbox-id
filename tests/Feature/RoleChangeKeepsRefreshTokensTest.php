<?php

declare(strict_types=1);

use Cbox\Id\AccessControl\Contracts\Roles;
use Cbox\Id\Identity\Contracts\Subjects;
use Cbox\Id\Kernel\Crypto\Contracts\TokenSigner;
use Cbox\Id\Kernel\Crypto\Enums\SigningAlg;
use Cbox\Id\Kernel\Events\Contracts\EventBus;
use Cbox\Id\OAuthServer\Contracts\ClientRegistry;
use Cbox\Id\OAuthServer\Contracts\RefreshTokens;
use Cbox\Id\OAuthServer\Enums\ClientType;
use Cbox\Id\OAuthServer\Jobs\DeliverBackchannelLogout;
use Cbox\Id\OAuthServer\Models\Client;
use Cbox\Id\OAuthServer\ValueObjects\NewClient;
use Cbox\Id\Organization\Contracts\Memberships;
use Cbox\Id\Organization\Contracts\Organizations;
use Cbox\Id\Organization\Enums\MembershipRole;
use Cbox\Id\Organization\ValueObjects\NewOrganization;
use Illuminate\Support\Facades\Queue;

/**
 * A ROLE CHANGE IS NOT A SIGN-OUT.
 *
 * Every role change used to revoke the person's refresh tokens. For a web app that is a
 * silent re-SSO; for a native app (the Cbox ID Authenticator, which holds a DPoP-bound
 * refresh token and cannot sign in again on its own) it IS a sign-out — production lost
 * the owner's Authenticator, and with it CIBA approvals and push step-up, the moment a
 * staff role was granted or taken back.
 *
 * The revocation bought nothing: a refresh token carries no roles. Every refresh mints its
 * access token (and ID Token) from the roles in the database at that moment, so a change —
 * a downgrade included — reaches the app on its next refresh, i.e. within one access-token
 * lifetime, exactly as fast as before. These tests hold both halves: every grant still
 * refreshes, and the refreshed token for a role-bearing app carries the NEW roles.
 */
function roleChangeClient(string $name, array $scopes): Client
{
    return app(ClientRegistry::class)->register(new NewClient(
        name: $name,
        type: ClientType::Public,
        redirectUris: ['https://'.strtolower($name).'.test/callback'],
        grantTypes: ['authorization_code', 'refresh_token'],
        scopes: $scopes,
        firstParty: true,
        backchannelLogoutUri: 'https://'.strtolower($name).'.test/backchannel-logout',
    ))->client;
}

/** @return array{status: int, roles: list<string>, refresh: string|null} */
function roleChangeRefresh(Client $client, string $refreshToken): array
{
    $response = test()->postJson('/oauth/token', [
        'grant_type' => 'refresh_token',
        'client_id' => $client->client_id,
        'refresh_token' => $refreshToken,
    ]);

    if ($response->status() !== 200) {
        return ['status' => $response->status(), 'roles' => [], 'refresh' => null];
    }

    $claims = app(TokenSigner::class)->verify((string) $response->json('access_token'), [SigningAlg::RS256])->all();
    $roles = $claims['roles'] ?? [];

    return [
        'status' => 200,
        'roles' => is_array($roles) ? array_values($roles) : [],
        'refresh' => is_string($refresh = $response->json('refresh_token')) ? $refresh : null,
    ];
}

it('keeps the Authenticator signed in and re-mints the app\'s roles when a role changes', function (string $change): void {
    $subject = app(Subjects::class)->create('owner@acme.test', 'Olive', 'a-strong-unbreached-passphrase');
    $acme = app(Organizations::class)->create(new NewOrganization('Acme', 'acme-'.uniqid()));
    app(Memberships::class)->add($acme->id, $subject->id, MembershipRole::Member);

    // The Authenticator: no role-bearing scope, and nothing it does depends on a role.
    $authenticator = roleChangeClient('Authenticator', ['openid', 'offline_access']);
    // An app that enforces roles from its tokens.
    $ledger = roleChangeClient('Ledger', ['openid', 'offline_access']);

    $roles = app(Roles::class);
    $editor = $roles->define($acme->id, 'Editor', clientId: $ledger->client_id);
    $support = $roles->define(null, 'Support', clientId: $ledger->client_id, tenantAssignable: false);

    // What the person holds before the change.
    match ($change) {
        'org role taken back', 'org role deleted' => $roles->assign($acme->id, $subject->id, $editor->id),
        'staff role taken back' => $roles->assignEverywhere($subject->id, $support->id),
        default => null,
    };
    app(EventBus::class)->flushPending(1000);

    $refreshTokens = app(RefreshTokens::class);
    $authenticatorToken = $refreshTokens->issue($authenticator, $subject->id, $acme->id, ['openid', 'offline_access']);
    $ledgerToken = $refreshTokens->issue($ledger, $subject->id, $acme->id, ['openid', 'offline_access']);

    // Before the change, the app's token says what the person holds now.
    $before = roleChangeRefresh($ledger, $ledgerToken);
    expect($before['status'])->toBe(200);
    match ($change) {
        'org role taken back', 'org role deleted' => expect($before['roles'])->toContain('Editor'),
        'staff role taken back' => expect($before['roles'])->toContain('Support'),
        default => expect($before['roles'])->not->toContain('Editor')->not->toContain('Support'),
    };
    $ledgerToken = (string) $before['refresh'];

    Queue::fake();

    match ($change) {
        'org role granted' => $roles->assign($acme->id, $subject->id, $editor->id),
        'org role taken back' => $roles->unassign($acme->id, $subject->id, $editor->id),
        'org role deleted' => $roles->deleteRole($editor->id, $acme->id),
        'staff role granted' => $roles->assignEverywhere($subject->id, $support->id),
        'staff role taken back' => $roles->unassignEverywhere($subject->id, $support->id),
    };
    app(EventBus::class)->flushPending(1000);

    // The Authenticator still refreshes — and keeps refreshing.
    $first = roleChangeRefresh($authenticator, $authenticatorToken);
    expect($first['status'])->toBe(200)
        ->and(roleChangeRefresh($authenticator, (string) $first['refresh'])['status'])->toBe(200);

    // The role-bearing app refreshes too, and its new token carries the roles as they are NOW.
    $ledgerRefreshed = roleChangeRefresh($ledger, $ledgerToken);
    expect($ledgerRefreshed['status'])->toBe(200);

    match ($change) {
        'org role granted' => expect($ledgerRefreshed['roles'])->toContain('Editor'),
        'staff role granted' => expect($ledgerRefreshed['roles'])->toContain('Support'),
        // The downgrade lands on the very next refresh.
        default => expect($ledgerRefreshed['roles'])->not->toContain('Editor')->not->toContain('Support'),
    };

    // And no application was told the person left.
    Queue::assertNotPushed(DeliverBackchannelLogout::class);
})->with([
    'org role granted',
    'org role taken back',
    'org role deleted',
    'staff role granted',
    'staff role taken back',
]);
