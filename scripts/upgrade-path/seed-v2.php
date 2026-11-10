<?php

declare(strict_types=1);

/*
 * Seed a v2.x (2.2.x) Cbox ID database: everything seed-v1.php seeds that 2.x still has,
 * then what only 2.x writes and v3 renames — a `cbid_ws_` workspace key holding the v2
 * scope names, the keys it minted (their provenance says `workspace_key`), an idempotent
 * answer and a pending approval filed under the v2 action name, and a refresh token
 * granted the v2 `account:*` names.
 *
 * RUN WITH THE OLD RELEASE'S CODE, exactly like seed-v1.php (whose header explains why):
 *
 *     php seed-v2.php <manifest.json>
 *
 * The workspace API rows are written by sending the requests a v2 agent sent through the
 * old release's own HTTP kernel, so the action name, the owner kind and the approval digest
 * are what 2.x really stored. Expects `cbox-id:install --multi-tenant` to have run first.
 */

use Cbox\Id\Identity\Contracts\Subjects;
use Cbox\Id\Identity\Models\User;
use Cbox\Id\OAuthServer\Contracts\ClientRegistry;
use Cbox\Id\OAuthServer\Contracts\RefreshTokens;
use Cbox\Id\OAuthServer\Enums\ClientType;
use Cbox\Id\OAuthServer\ValueObjects\NewClient;
use Cbox\Id\Organization\Contracts\Memberships;
use Cbox\Id\Organization\Enums\MembershipRole;
use Cbox\Id\Platform\Contracts\OrganizationApiKeys;
use Cbox\Id\Platform\ValueObjects\KeyProvenance;
use Illuminate\Contracts\Http\Kernel as HttpKernel;
use Illuminate\Http\Request;

// seed-v1.php seeds the shared data and hands back here instead of writing the manifest.
const UPGRADE_SEED_MAJOR = 2;
require __DIR__.'/seed-v1.php';

/** @var array<string, mixed> $m */
/** @var Closure(string, Closure): void $section */
$m['from_major'] = 2;

/** Send one request through the old release's HTTP kernel, the way a v2 agent sent it. */
$call = static function (string $method, string $path, string $token, array $body = [], array $headers = []): array {
    $url = rtrim((string) config('app.url'), '/').$path;
    $server = ['HTTP_AUTHORIZATION' => 'Bearer '.$token, 'HTTP_ACCEPT' => 'application/json', 'CONTENT_TYPE' => 'application/json'];
    foreach ($headers as $name => $value) {
        $server['HTTP_'.strtoupper(str_replace('-', '_', $name))] = $value;
    }
    $kernel = app(HttpKernel::class);
    $request = Request::create($url, $method, [], [], [], $server, $body === [] ? null : json_encode($body, JSON_THROW_ON_ERROR));
    $response = $kernel->handle($request);
    $kernel->terminate($request, $response);

    return [$response->getStatusCode(), json_decode((string) $response->getContent(), true)];
};

$section('v2 workspace API: a cbid_ws_ key with v2 scopes, and what it minted', function () use (&$m, $root, $envs, $workspace, $customer, $call): void {
    $owner = $envs->runAs($root, static fn (): ?string => app(Memberships::class)->owners($workspace->id)[0] ?? null);
    $owner ??= throw new RuntimeException('the workspace has no owner');
    $m['workspace_owner_id'] = $owner;

    $agent = $envs->runAs($root, static fn () => app(OrganizationApiKeys::class)->issue(
        $workspace->id, 'v2 agent', MembershipRole::Admin, null,
        ['workspace:read', 'projects:write', 'environments:write', 'team:read', 'keys:write'],
        new KeyProvenance(createdByType: 'organization_member', createdById: $owner),
    ));
    str_starts_with($agent->plaintext, 'cbid_ws_') || throw new RuntimeException('2.x minted '.substr($agent->plaintext, 0, 9));

    // An organization key and an environment key minted BY the workspace key: both rows say
    // `workspace_key`; the first answer is stored for its Idempotency-Key.
    [$status, $child] = $call('POST', '/api/v1/workspace/keys', $agent->plaintext, ['name' => 'Minted by the agent', 'role' => 'developer'], ['Idempotency-Key' => 'upgrade-v2-once']);
    $status === 201 || throw new RuntimeException("workspace key mint: {$status} ".json_encode($child));
    [$status, $envKey] = $call('POST', "/api/v1/workspace/environments/{$customer->id}/keys", $agent->plaintext, ['name' => 'CI (minted by the agent)', 'scopes' => ['users:read']]);
    $status === 201 || throw new RuntimeException("environment key mint: {$status} ".json_encode($envKey));

    $m['v2'] = [
        'agent' => ['id' => $agent->key->id, 'plaintext' => $agent->plaintext],
        'child_key_id' => $child['data']['id'],
        'environment_key' => ['id' => $envKey['data']['id'], 'plaintext' => $envKey['data']['token'] ?? null],
        'idempotency_key' => 'upgrade-v2-once',
        'idempotent_body' => ['name' => 'Minted by the agent', 'role' => 'developer'],
    ];
});

$section('v2 pending approval under keys.workspace.create', function () use (&$m, $root, $envs, $workspace, $call): void {
    $supervised = $envs->runAs($root, static fn () => app(OrganizationApiKeys::class)->issue(
        $workspace->id, 'v2 supervised agent', MembershipRole::Admin, null, null,
        new KeyProvenance(createdByType: 'organization_member', createdById: $m['workspace_owner_id'], stepUpPolicy: ['min_danger' => 'critical', 'actions' => []]),
    ));
    $body = ['name' => 'Held until approved', 'role' => 'developer'];
    [$status, $held] = $call('POST', '/api/v1/workspace/keys', $supervised->plaintext, $body);
    $status === 202 || throw new RuntimeException("expected the mint to be held: {$status} ".json_encode($held));

    $m['v2']['approval'] = ['id' => $held['approval']['id'], 'key' => $supervised->plaintext, 'body' => $body];
});

$section('v2 refresh token granted v2 account:* scopes', function () use (&$m, $root, $envs): void {
    $envs->runAs($root, function () use (&$m): void {
        $person = app(Subjects::class)->create('agent-owner@upgrade.test', 'Agent Owner', 'Upgrade-Rehearsal-2026!');
        User::query()->whereKey($person->id)->update(['email_verified_at' => now()]);
        $registered = app(ClientRegistry::class)->register(new NewClient(
            name: 'v2 CLI',
            type: ClientType::Public,
            redirectUris: ['http://127.0.0.1:53682/callback'],
            grantTypes: ['authorization_code', 'refresh_token'],
            scopes: ['openid', 'offline_access', 'account:profile:write', 'account:sessions:write'],
        ));
        $scopes = ['openid', 'offline_access', 'account:profile:write', 'account:sessions:write'];
        $m['v2']['refresh_token'] = [
            'client_id' => $registered->client->client_id,
            'user_id' => $person->id,
            'scopes' => $scopes,
            'token' => app(RefreshTokens::class)->issue($registered->client, $person->id, null, $scopes, null, null, time(), ['pwd']),
        ];
    });
});

upgrade_seed_finish($m, $failures, $manifestPath);
