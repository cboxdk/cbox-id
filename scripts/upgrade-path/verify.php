<?php

declare(strict_types=1);

/*
 * After the upgrade: read what seed-v1.php (or seed-v2.php) wrote under the OLD release
 * with the NEW release's code, and check that every credential still does what it did.
 *
 * RUN WITH THE NEW RELEASE'S CODE, from its root, after `php artisan migrate --force`:
 *
 *     php scripts/upgrade-path/verify.php <manifest.json> <smoke.json>
 *
 * Every check goes through the service a request would use (Subjects::verifyPassword,
 * ClientRegistry::verifySecret, the SecretBox that opens a webhook secret at delivery…)
 * rather than comparing columns, so a changed hash scheme, sealing context or lookup
 * that the migrations did not carry across fails here the way it would fail a user.
 *
 * It also mints the NEW release's keys (`cbid_env_`, `cbid_ws_`) and writes them, with the
 * old release's `csec_` machine secret, to <smoke.json> for the HTTP smoke test that follows. Exits 1 if any check fails.
 */

use App\Platform\Actions\Principal\EnvironmentKeyPrincipal;
use App\Platform\Scopes\ScopeRegistry;
use Cbox\Id\Directory\Contracts\Directories;
use Cbox\Id\Federation\Contracts\Connections;
use Cbox\Id\Federation\Models\Connection;
use Cbox\Id\Identity\Contracts\Mfa;
use Cbox\Id\Identity\Contracts\Passkeys;
use Cbox\Id\Identity\Contracts\SessionManager;
use Cbox\Id\Identity\Contracts\Subjects;
use Cbox\Id\Kernel\Audit\Contracts\AuditLog;
use Cbox\Id\Kernel\Crypto\Contracts\KeyManager;
use Cbox\Id\Kernel\Crypto\Contracts\SecretBox;
use Cbox\Id\Kernel\Crypto\SecretPrefix;
use Cbox\Id\Kernel\Crypto\TotpAuthenticator;
use Cbox\Id\Kernel\Tenancy\Contracts\EnvironmentContext;
use Cbox\Id\OAuthServer\Contracts\BackchannelAuthentication;
use Cbox\Id\OAuthServer\Contracts\ClientRegistry;
use Cbox\Id\OAuthServer\Contracts\RefreshTokens;
use Cbox\Id\OAuthServer\Enums\ClientType;
use Cbox\Id\OAuthServer\ValueObjects\NewClient;
use Cbox\Id\Organization\Contracts\UserApiTokens;
use Cbox\Id\Organization\Enums\MembershipRole;
use Cbox\Id\Organization\Models\Environment;
use Cbox\Id\Platform\Contracts\EnvironmentApiKeys;
use Cbox\Id\Platform\Contracts\OperatorMfa;
use Cbox\Id\Platform\Contracts\OrganizationApiKeys;
use Cbox\Id\Platform\Models\EnvironmentApiKey;
use Cbox\Id\TokenVault\Contracts\SecretVault;
use Cbox\Id\TokenVault\ValueObjects\VaultOwner;
use Cbox\Id\Webhooks\Models\WebhookEndpoint;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Contracts\Http\Kernel as HttpKernel;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

[$self, $manifestPath, $smokePath] = $argv + [null, null, null];
if ($manifestPath === null || $smokePath === null) {
    fwrite(STDERR, "usage: php verify.php <manifest.json> <smoke.json>\n");
    exit(2);
}

require getcwd().'/vendor/autoload.php';
$app = require getcwd().'/bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();

/** @var array<string, mixed> $m */
$m = json_decode((string) file_get_contents($manifestPath), true, flags: JSON_THROW_ON_ERROR);
$failed = 0;
/** The release the data was seeded with: 1 (seed-v1.php) or 2 (seed-v2.php). */
$major = (int) ($m['from_major'] ?? 1);

/** One named check: a closure that returns true, or a string saying what was wrong. */
$check = static function (string $name, Closure $fn) use (&$failed): void {
    try {
        $result = $fn();
    } catch (Throwable $e) {
        $result = $e::class.': '.$e->getMessage().' at '.basename($e->getFile()).':'.$e->getLine();
    }
    if ($result === true) {
        fwrite(STDOUT, "  ok    {$name}\n");

        return;
    }
    $failed++;
    fwrite(STDOUT, "  FAIL  {$name}: ".(is_string($result) ? $result : var_export($result, true))."\n");
};

$envs = app(EnvironmentContext::class);
$totp = app(TotpAuthenticator::class);
$root = Environment::query()->findOrFail($m['root_environment_id']);
$customer = Environment::query()->findOrFail($m['environment_id']);

// The next 30-second TOTP step: the old release just consumed the current one at enrolment,
// and replay protection rightly refuses a step twice.
$nextStep = time() + 30;

// --- Platform root -------------------------------------------------------------------

$check('operator TOTP secret still opens (next step verifies)', function () use ($m, $totp, $nextStep): bool|string {
    if (! isset($m['operator']['totp_secret'])) {
        return 'not seeded';
    }

    return app(OperatorMfa::class)->verifyTotp($m['operator']['id'], $totp->codeAt($m['operator']['totp_secret'], $nextStep)) ?: 'code refused';
});

$check('operator recovery code still verifies', fn (): bool|string => app(OperatorMfa::class)->verifyRecoveryCode($m['operator']['id'], $m['operator']['recovery_code']) ?: 'refused');

// 2.0 revoked every 1.x `cbid_org_` key; a 2.x `cbid_ws_` key keeps working until v3.1.
$major === 1 && $check('live cbid_org_ workspace key is marked revoked, and no longer resolves', function () use ($m, $envs, $root): bool|string {
    $row = DB::table('organization_api_keys')->where('id', $m['workspace_keys']['live']['id'])->first();
    if ($row === null) {
        return 'row gone';
    }
    if ($row->revoked_at === null) {
        return 'revoked_at still null';
    }
    $resolved = $envs->runAs($root, fn () => app(OrganizationApiKeys::class)->resolve($m['workspace_keys']['live']['plaintext']));

    return $resolved === null ?: 'still resolves';
});

$major === 2 && $check('the 2.x cbid_ws_ workspace key still resolves', function () use ($m, $envs, $root): bool|string {
    $resolved = $envs->runAs($root, fn () => app(OrganizationApiKeys::class)->resolve($m['workspace_keys']['live']['plaintext']));

    return $resolved?->id === $m['workspace_keys']['live']['id'] ?: 'refused';
});

$check('an already-revoked workspace key keeps its original revoked_at', function () use ($m): bool|string {
    $at = (string) DB::table('organization_api_keys')->where('id', $m['workspace_keys']['revoked']['id'])->value('revoked_at');

    return substr($at, 0, 19) === substr($m['workspace_keys']['revoked']['revoked_at'], 0, 19) ?: "revoked_at moved to {$at}";
});

// --- Customer environment ------------------------------------------------------------

$envs->runAs($customer, function () use ($m, $check, $totp, $nextStep): void {
    $subjects = app(Subjects::class);

    $check('every seeded person signs in with their 1.x password', function () use ($m, $subjects): bool|string {
        $bad = [];
        foreach ($m['users'] as $email => $id) {
            // The deactivated person's password is refused because they are deactivated,
            // which is checked on its own below.
            if ($email === 'passwordless@acme.test' || $email === $m['deactivated']) {
                continue;
            }
            if (! $subjects->verifyPassword($id, $m['password'])) {
                $bad[] = $email;
            }
        }

        return $bad === [] ?: 'refused: '.implode(', ', $bad);
    });

    $check('a wrong password is still refused', fn (): bool|string => ! $subjects->verifyPassword($m['users']['person01@acme.test'], 'wrong-password') ?: 'accepted');
    $check('the person with no password still has none', fn (): bool|string => ! $subjects->verifyPassword($m['users']['passwordless@acme.test'], $m['password']) ?: 'accepted');
    $check('the deactivated person is still inactive', fn (): bool|string => ! $subjects->isActive($m['users'][$m['deactivated']]) ?: 'active again');

    $check('TOTP secrets still open (next step verifies)', function () use ($m, $totp, $nextStep): bool|string {
        foreach ($m['mfa'] as $email => $f) {
            if (! app(Mfa::class)->verifyTotp($m['users'][$email], $totp->codeAt($f['totp_secret'], $nextStep))) {
                return "{$email} refused";
            }
        }

        return true;
    });
    $check('recovery codes still verify', function () use ($m): bool|string {
        foreach ($m['mfa'] as $email => $f) {
            if (! app(Mfa::class)->verifyRecoveryCode($m['users'][$email], $f['recovery_code'])) {
                return "{$email} refused";
            }
        }

        return true;
    });

    $check('the passkey is still registered to its owner', function () use ($m): bool|string {
        $credential = app(Passkeys::class)->credentialById($m['passkey']['credential_id']);
        if ($credential === null) {
            return 'not found';
        }

        return ($credential->user_id === $m['users'][$m['passkey']['user']] && $credential->public_key === $m['passkey']['public_key'] && (int) $credential->sign_count === 7) ?: 'row differs';
    });

    $check('app client secrets still verify (and a wrong one does not)', function () use ($m): bool|string {
        $registry = app(ClientRegistry::class);
        foreach (['web', 'machine'] as $app) {
            $client = $registry->byClientId($m['apps'][$app]['client_id']);
            if ($client === null) {
                return "{$app}: client gone";
            }
            if (! $registry->verifySecret($client, $m['apps'][$app]['secret'])) {
                return "{$app}: secret refused";
            }
            if ($registry->verifySecret($client, $m['apps'][$app]['secret'].'x')) {
                return "{$app}: wrong secret accepted";
            }
        }

        return $registry->byClientId($m['apps']['spa']['client_id']) !== null ?: 'spa gone';
    });

    $check('a 1.x refresh token still rotates, and its successor is a cbid_rt_ token', function () use ($m): bool|string {
        $grant = app(RefreshTokens::class)->rotate($m['apps']['web']['client_id'], $m['refresh_token']);
        if ($grant->userId !== $m['users']['person01@acme.test']) {
            return 'rotated to someone else';
        }

        return str_starts_with($grant->refreshToken, SecretPrefix::RefreshToken->value) ?: 'successor minted as '.substr($grant->refreshToken, 0, 8);
    });

    // laravel-id 2.5 mints every secret as `cbid_<kind>_`. The old forms are verified by the
    // hash of the whole plaintext, so they keep working untouched; nothing rewrites them.
    $check('the old release minted the legacy forms (csec_, scim_), so the checks above prove the grace', function () use ($m): bool|string {
        foreach (['web', 'machine'] as $app) {
            if (! str_starts_with($m['apps'][$app]['secret'], SecretPrefix::ClientSecret->legacy())) {
                return "{$app} secret was minted as ".substr($m['apps'][$app]['secret'], 0, 8);
            }
        }

        return str_starts_with($m['directory']['token'], SecretPrefix::ScimToken->legacy()) ?: 'SCIM token was minted as '.substr($m['directory']['token'], 0, 8);
    });

    $check('a client secret and a SCIM token minted by the new code are cbid_cs_ and cbid_scim_', function () use ($m): bool|string {
        $registered = app(ClientRegistry::class)->register(new NewClient(
            name: 'Upgrade smoke',
            type: ClientType::Confidential,
            grantTypes: ['client_credentials'],
            scopes: ['id:users:read'],
        ));
        if (! str_starts_with((string) $registered->secret, SecretPrefix::ClientSecret->value)) {
            return 'client secret minted as '.substr((string) $registered->secret, 0, 8);
        }
        if (! app(ClientRegistry::class)->verifySecret($registered->client, (string) $registered->secret)) {
            return 'the new client secret does not verify';
        }
        $directory = app(Directories::class)->register($m['organizations']['acme'], 'Upgrade smoke SCIM');
        if (! str_starts_with($directory->token, SecretPrefix::ScimToken->value)) {
            return 'SCIM token minted as '.substr($directory->token, 0, 8);
        }

        return app(Directories::class)->authenticate($directory->token)?->id === $directory->directory->id ?: 'the new SCIM token does not authenticate';
    });

    $check('1.x sessions are still active', function () use ($m): bool|string {
        foreach ($m['sessions'] as $email => $id) {
            if (app(SessionManager::class)->active($id) === null) {
                return "{$email}'s session is gone";
            }
        }

        return true;
    });

    $check('webhook signing secrets still open to the same value', function () use ($m): bool|string {
        $box = app(SecretBox::class);
        foreach ($m['webhooks'] as $which => $hook) {
            $endpoint = WebhookEndpoint::query()->find($hook['id']);
            if ($endpoint === null) {
                return "{$which}: endpoint gone";
            }
            if ($box->open($endpoint->secret_encrypted, $endpoint->secretContext()) !== $hook['secret']) {
                return "{$which}: secret differs";
            }
        }

        return true;
    });

    $check('SSO connection configuration still unseals (SAML certificate, OIDC client secret)', function () use ($m): bool|string {
        $connections = app(Connections::class);
        $saml = Connection::query()->find($m['sso']['saml']['id']);
        $oidc = Connection::query()->find($m['sso']['oidc']['id']);
        if ($saml === null || $oidc === null) {
            return 'connection gone';
        }
        if (trim($connections->samlConfig($saml)->idpCertificate) !== trim($m['sso']['saml']['certificate'])) {
            return 'SAML certificate differs';
        }

        return $connections->oidcConfig($oidc)->clientSecret === $m['sso']['oidc']['client_secret'] ?: 'OIDC client secret differs';
    });

    $check('the SCIM directory token still authenticates', fn (): bool|string => app(Directories::class)->authenticate($m['directory']['token'])?->id === $m['directory']['id'] ?: 'refused');

    $check('a vault secret still leases its 1.x value', function () use ($m): bool|string {
        $vault = app(SecretVault::class);
        $owner = VaultOwner::organization($m['organizations']['acme']);
        $vault->grant($m['vault']['id'], $m['apps']['web']['client_id'], $owner);

        return $vault->lease($m['vault']['id'], $m['apps']['web']['client_id'], 'upgrade rehearsal', $owner)->secret === $m['vault']['plaintext'] ?: 'value differs';
    });

    $check('a personal API token still resolves', fn (): bool|string => app(UserApiTokens::class)->resolve($m['user_api_token']['plaintext'])?->id === $m['user_api_token']['id'] ?: 'refused');
    $check('a 1.x cbid_env_ key still resolves', fn (): bool|string => app(EnvironmentApiKeys::class)->resolve($m['environment_key']['plaintext'])?->id === $m['environment_key']['id'] ?: 'refused');

    $check('the 1.x signing key is still published and still the active one', function () use ($m): bool|string {
        $kids = array_column(app(KeyManager::class)->jwks()['keys'] ?? [], 'kid');
        if (! in_array($m['signing_kid'], $kids, true)) {
            return 'kid missing from JWKS';
        }

        return app(KeyManager::class)->activeSigningKey()->kid === $m['signing_kid'] ?: 'a different key is active';
    });

    $check('audit chains still verify', function () use ($m): bool|string {
        $audit = app(AuditLog::class);
        foreach ($m['audit_chain_before'] as $scope => $before) {
            $v = $audit->verifyChain($scope === 'environment' ? null : $scope);
            if (! $v->valid) {
                return "{$scope}: broken at {$v->brokenAtSequence} ({$v->reason})";
            }
            if ($v->verifiedCount < $before['verified']) {
                return "{$scope}: {$v->verifiedCount} entries verified, {$before['verified']} before";
            }
        }

        return true;
    });
});

// --- What the migrations reshaped ----------------------------------------------------

$major === 1 && $check('admin portal links carry their scope across as intents', function () use ($m): bool|string {
    if (Schema::hasColumn('admin_portal_links', 'scope')) {
        return 'scope column still there';
    }
    $expected = ['sso' => ['sso'], 'scim' => ['dsync'], 'both' => ['sso', 'dsync'], 'audit_logs' => ['audit_logs']];
    foreach ($m['portal_links'] as $id => $scope) {
        $intents = json_decode((string) DB::table('admin_portal_links')->where('id', $id)->value('intents'), true);
        if ($intents !== $expected[$scope]) {
            return "{$id} ({$scope}) became ".json_encode($intents);
        }
    }

    return true;
});

$major === 1 && $check('invitation role grants are keyed to the pending invitation; the revoked one\'s is gone', function () use ($m): bool|string {
    $grants = DB::table('invitation_role_grants')->get();
    if ($grants->count() !== 1) {
        return $grants->count().' grants left';
    }

    return $grants->first()->invitation_id === $m['invitations']['pending'] ?: 'keyed to '.$grants->first()->invitation_id;
});

$major === 1 && $check('client secrets were backfilled into oauth_client_secrets', fn (): bool|string => DB::table('oauth_client_secrets')->count() === 2 ?: DB::table('oauth_client_secrets')->count().' rows');

// --- What v3 renamed: the workspace is the organization (2.x data only) ---------------

/** Send one request through the NEW release's HTTP kernel, as an agent would. */
$call = static function (string $method, string $path, string $token, array $body = [], array $headers = []): array {
    $server = ['HTTP_AUTHORIZATION' => 'Bearer '.$token, 'HTTP_ACCEPT' => 'application/json', 'CONTENT_TYPE' => 'application/json'];
    foreach ($headers as $name => $value) {
        $server['HTTP_'.strtoupper(str_replace('-', '_', $name))] = $value;
    }
    $kernel = app(HttpKernel::class);
    // A path is sent to the application's own URL; a full URL (the API host) as it is.
    $url = str_starts_with($path, 'http') ? $path : rtrim((string) config('app.url'), '/').$path;
    $request = Request::create($url, $method, [], [], [], $server, $body === [] ? null : json_encode($body, JSON_THROW_ON_ERROR));
    $response = $kernel->handle($request);
    $kernel->terminate($request, $response);

    return [$response->getStatusCode(), json_decode((string) $response->getContent(), true), $response->headers->get('Deprecation')];
};

if ($major === 2) {
    $v2 = $m['v2'];

    // v3.0 is EXPAND-ONLY: 2.x keeps serving this database while v3 rolls out, so nothing
    // 2.x stored is rewritten — every row below is read by v3 as 2.x wrote it.
    $check('workspace_key provenance is left as 2.x wrote it, in both key tables', function () use ($v2): bool|string {
        foreach (['organization_api_keys' => $v2['child_key_id'], 'environment_api_keys' => $v2['environment_key']['id']] as $table => $id) {
            $type = DB::table($table)->where('id', $id)->value('created_by_type');
            if ($type !== 'workspace_key') {
                return "{$table} {$id}: {$type}";
            }
        }

        return true;
    });

    $check('the environment key the 2.x agent minted still finds its approver', function () use ($m, $v2, $envs, $customer): bool|string {
        $key = $envs->runAs($customer, fn () => EnvironmentApiKey::query()->withoutGlobalScopes()->find($v2['environment_key']['id']));

        return $key !== null && (new EnvironmentKeyPrincipal($key))->approverSubjectId() === $m['workspace_owner_id'] ?: 'approver lost';
    });

    $check('the 2.x agent\'s scopes are left in the v2 names', function () use ($v2): bool|string {
        $scopes = json_decode((string) DB::table('organization_api_keys')->where('id', $v2['agent']['id'])->value('scopes'), true);
        $want = ['workspace:read', 'projects:write', 'environments:write', 'team:read', 'keys:write'];

        return $scopes === $want ?: 'stored '.json_encode($scopes);
    });

    // The PATH is deprecated from v3.0 (its successor is the API host's /v1), never the key.
    $check('the 2.x agent still reads the organization on /api/v1/workspace, marked deprecated', function () use ($v2, $call): bool|string {
        [$status, , $deprecation] = $call('GET', '/api/v1/workspace', $v2['agent']['plaintext']);

        return ($status === 200 && $deprecation !== null) ?: "status {$status}, Deprecation ".var_export($deprecation, true);
    });

    $check('the 2.x agent reads the same organization on the API host\'s /v1/organization, with no Deprecation', function () use ($v2, $call, $m): bool|string {
        $host = config('cbox-id.hosts.api');

        if (! is_string($host) || $host === '') {
            return 'no API host configured (CBOX_ID_API_HOST)';
        }

        [$status, $body, $deprecation] = $call('GET', 'http://'.$host.'/v1/organization', $v2['agent']['plaintext']);

        return ($status === 200 && $deprecation === null && ($body['id'] ?? null) === $m['workspace_id'])
            ?: "status {$status}, id ".var_export($body['id'] ?? null, true).', Deprecation '.var_export($deprecation, true);
    });

    $check('the idempotent answer 2.x stored under keys.workspace.create replays', function () use ($v2, $call): bool|string {
        $row = DB::table('action_idempotency_records')->where('idempotency_key', $v2['idempotency_key'])->first();
        if ($row === null || $row->action !== 'keys.workspace.create' || ! str_starts_with((string) $row->principal, 'workspace_key:')) {
            return 'stored as '.json_encode($row);
        }
        [$status, $body] = $call('POST', '/api/v1/workspace/keys', $v2['agent']['plaintext'], $v2['idempotent_body'], ['Idempotency-Key' => $v2['idempotency_key']]);
        if ($status !== 201 || ($body['data']['id'] ?? null) !== $v2['child_key_id']) {
            return "replay answered {$status} ".json_encode($body['data']['id'] ?? $body);
        }

        return DB::table('organization_api_keys')->where('name', $v2['idempotent_body']['name'])->count() === 1 ?: 'minted twice';
    });

    $check('the approval 2.x filed under keys.workspace.create is spent by the v3 action', function () use ($m, $v2, $envs, $root, $call): bool|string {
        $approval = $v2['approval'];
        $action = DB::table('action_approval_requests')->where('id', $approval['id'])->value('action');
        if ($action !== 'keys.workspace.create') {
            return "stored action {$action}";
        }
        $envs->runAs($root, fn () => app(BackchannelAuthentication::class)->approve($approval['id'], $m['workspace_owner_id']));
        [$status, $body] = $call('POST', '/api/v1/workspace/keys', $approval['key'], $approval['body'], ['Cbox-Approval' => $approval['id']]);

        return ($status === 201 && ($body['data']['name'] ?? null) === $approval['body']['name']) ?: "answered {$status} ".json_encode($body);
    });

    $check('the billing usage outbox keeps the workspace_id column 2.x writes', fn (): bool|string => Schema::hasColumn('product_billing_usage_outbox', 'workspace_id') ?: 'workspace_id is gone');

    $check('a 2.x refresh token granted account:* names rotates, and its names pass the v3 checks', function () use ($v2, $envs, $root): bool|string {
        $token = $v2['refresh_token'];
        $grant = $envs->runAs($root, fn () => app(RefreshTokens::class)->rotate($token['client_id'], $token['token']));
        if ($grant->userId !== $token['user_id']) {
            return 'rotated to someone else';
        }
        foreach (['id:my-profile:write', 'id:my-sessions:write'] as $required) {
            if (! ScopeRegistry::satisfies($grant->scopes, $required)) {
                return "{$required} not satisfied by ".implode(' ', $grant->scopes);
            }
        }

        return true;
    });
}

// --- Mint the new release's keys for the HTTP smoke test ------------------------------

$smoke = ['environment_slug' => $m['environment_slug']];
$check('mint a cbid_env_ and a cbid_ws_ organization key with the new code', function () use (&$smoke, $m, $major, $envs, $root, $customer): bool|string {
    $smoke['environment_key'] = $envs->runAs($customer, fn () => app(EnvironmentApiKeys::class)->issue($customer->id, 'Upgrade smoke', ['users:read', 'organizations:read'])->plaintext);
    $smoke['workspace_key'] = $envs->runAs($root, fn () => app(OrganizationApiKeys::class)->issue($m['workspace_id'], 'Upgrade smoke', MembershipRole::Admin)->plaintext);
    $smoke['old_environment_key'] = $m['environment_key']['plaintext'];
    $smoke['old_workspace_key'] = $m['workspace_keys']['live']['plaintext'];
    $smoke['from_major'] = $major;
    $smoke['login_email'] = 'person05@acme.test';
    $smoke['signing_kid'] = $m['signing_kid'];
    $smoke['password'] = $m['password'];
    $smoke['organization_id'] = $m['organizations']['acme'];
    // The old release's csec_ secret, for the smoke test's client_credentials exchange.
    $smoke['machine_client_id'] = $m['apps']['machine']['client_id'];
    $smoke['machine_client_secret'] = $m['apps']['machine']['secret'];

    return str_starts_with($smoke['workspace_key'], 'cbid_ws_') ?: 'organization key minted as '.substr($smoke['workspace_key'], 0, 9);
});
file_put_contents($smokePath, json_encode($smoke, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

fwrite(STDOUT, $failed === 0 ? "verify: OK\n" : "verify: {$failed} check(s) failed\n");
exit($failed === 0 ? 0 : 1);
