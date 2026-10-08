<?php

declare(strict_types=1);

/*
 * Seed a v1.x (1.1.x) Cbox ID database with the data a production deployment holds, so
 * an upgrade can be rehearsed against something that looks like the real thing.
 *
 * RUN WITH THE OLD RELEASE'S CODE. The working directory is the checkout of the previous
 * tag (its vendor/ installed), and every write below goes through that release's own
 * services: passwords are hashed by its hasher, client secrets and SSO configuration are
 * sealed by its SecretBox, the audit chain is written by its AuditLog. Rows inserted by
 * hand would prove only that the new code reads what the new code would have written.
 *
 *     php seed-v1.php <manifest.json>
 *
 * The manifest records what the verifier needs afterwards: ids, the plaintext of every
 * credential minted here (test data, nothing else), and what the old code itself said
 * about them (the audit chain verified, the secrets verified) before the upgrade.
 *
 * Expects `cbox-id:install --multi-tenant` to have run first: one platform root, one
 * workspace organization, one customer environment.
 */

use App\Models\InvitationRoleGrant;
use App\Models\OnboardingDismissal;
use App\Platform\AdminPortal;
use App\Platform\Enums\PortalScope;
use Cbox\Id\AccessControl\Contracts\Roles;
use Cbox\Id\Directory\Contracts\Directories;
use Cbox\Id\Federation\Contracts\Connections;
use Cbox\Id\Federation\Enums\ConnectionType;
use Cbox\Id\Identity\Contracts\Mfa;
use Cbox\Id\Identity\Contracts\SessionManager;
use Cbox\Id\Identity\Contracts\Subjects;
use Cbox\Id\Identity\Models\User;
use Cbox\Id\Identity\Models\WebAuthnCredential;
use Cbox\Id\Kernel\Audit\Contracts\AuditLog;
use Cbox\Id\Kernel\Audit\Enums\ActorType;
use Cbox\Id\Kernel\Audit\ValueObjects\AuditEvent;
use Cbox\Id\Kernel\Crypto\Contracts\KeyManager;
use Cbox\Id\Kernel\Crypto\TotpAuthenticator;
use Cbox\Id\Kernel\Tenancy\Contracts\EnvironmentContext;
use Cbox\Id\OAuthServer\Contracts\ClientRegistry;
use Cbox\Id\OAuthServer\Contracts\RefreshTokens;
use Cbox\Id\OAuthServer\Enums\ClientType;
use Cbox\Id\OAuthServer\ValueObjects\NewClient;
use Cbox\Id\Organization\Contracts\Invitations;
use Cbox\Id\Organization\Contracts\Memberships;
use Cbox\Id\Organization\Contracts\Organizations;
use Cbox\Id\Organization\Contracts\UserApiTokens;
use Cbox\Id\Organization\Enums\MembershipRole;
use Cbox\Id\Organization\Enums\OrganizationType;
use Cbox\Id\Organization\Enums\TokenScope;
use Cbox\Id\Organization\Models\Environment;
use Cbox\Id\Organization\Models\Organization;
use Cbox\Id\Organization\ValueObjects\NewOrganization;
use Cbox\Id\Platform\Contracts\EnvironmentApiKeys;
use Cbox\Id\Platform\Contracts\OperatorMfa;
use Cbox\Id\Platform\Contracts\OrganizationApiKeys;
use Cbox\Id\Platform\Models\PlatformOperator;
use Cbox\Id\TokenVault\Contracts\SecretVault;
use Cbox\Id\TokenVault\ValueObjects\VaultOwner;
use Cbox\Id\Webhooks\Contracts\WebhookRegistry;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

$manifestPath = $argv[1] ?? throw new InvalidArgumentException('usage: php seed-v1.php <manifest.json>');

require getcwd().'/vendor/autoload.php';
$app = require getcwd().'/bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();

/** One password for every seeded person; the verifier signs in with it. */
const SEED_PASSWORD = 'Upgrade-Rehearsal-2026!';

$m = ['password' => SEED_PASSWORD];
$failures = [];

/** Run one section; a failure is recorded and the rest still seeds. */
$section = static function (string $name, Closure $fn) use (&$failures): void {
    try {
        $fn();
        fwrite(STDOUT, "  seeded  {$name}\n");
    } catch (Throwable $e) {
        $failures[$name] = $e::class.': '.$e->getMessage();
        fwrite(STDERR, "  FAILED  {$name}: {$e->getMessage()}\n  at {$e->getFile()}:{$e->getLine()}\n");
    }
};

$root = Environment::query()->where('is_default', true)->firstOrFail();
$customer = Environment::query()->where('is_default', false)->orderBy('created_at')->firstOrFail();
$envs = app(EnvironmentContext::class);
// Organizations are environment-owned: outside a plane the global scope hides them all.
$workspace = $envs->runAs($root, static fn (): Organization => Organization::query()->orderBy('created_at')->firstOrFail());
$operator = PlatformOperator::query()->orderBy('created_at')->firstOrFail();

$m['root_environment_id'] = $root->id;
$m['environment_id'] = $customer->id;
$m['environment_slug'] = $customer->slug;
$m['workspace_id'] = $workspace->id;
$m['operator'] = ['id' => $operator->id, 'email' => $operator->email];

$totp = app(TotpAuthenticator::class);

// --- The platform root: the operator's MFA, and the workspace's API keys --------------

$section('operator mfa', function () use (&$m, $operator, $totp): void {
    $mfa = app(OperatorMfa::class);
    $enrolment = $mfa->enrollTotp($operator->id, $operator->email);
    $mfa->confirmTotp($operator->id, $totp->codeAt($enrolment->secret, time())) || throw new RuntimeException('operator totp not confirmed');
    $codes = $mfa->generateRecoveryCodes($operator->id, 4);
    $m['operator']['totp_secret'] = $enrolment->secret;
    $m['operator']['recovery_code'] = $codes[0];
});

$section('workspace keys (cbid_org_)', function () use (&$m, $root, $envs, $workspace): void {
    $envs->runAs($root, function () use (&$m, $workspace): void {
        $keys = app(OrganizationApiKeys::class);
        $live = $keys->issue($workspace->id, 'CI deploy key', MembershipRole::Admin);
        $old = $keys->issue($workspace->id, 'Retired laptop key', MembershipRole::Developer);
        $keys->revoke($old->key->id);

        $m['workspace_keys'] = [
            'live' => ['id' => $live->key->id, 'plaintext' => $live->plaintext, 'prefix' => $live->key->prefix],
            'revoked' => ['id' => $old->key->id, 'revoked_at' => (string) $old->key->fresh()?->revoked_at],
        ];
    });
});

// --- The customer environment: everything an IdP in production holds -----------------

$envs->runAs($customer, function () use (&$m, $section, $customer, $totp): void {
    $subjects = app(Subjects::class);
    $orgs = app(Organizations::class);
    $members = app(Memberships::class);

    $section('signing key', function () use (&$m): void {
        $m['signing_kid'] = app(KeyManager::class)->activeSigningKey()->kid;
    });

    $section('environment key (cbid_env_)', function () use (&$m, $customer): void {
        $issued = app(EnvironmentApiKeys::class)->issue($customer->id, 'Provisioning', ['users:read', 'users:write', 'organizations:read']);
        $m['environment_key'] = ['id' => $issued->key->id, 'plaintext' => $issued->plaintext];
    });

    $section('people', function () use (&$m, $subjects): void {
        $m['users'] = [];
        foreach (range(1, 24) as $i) {
            $email = sprintf('person%02d@acme.test', $i);
            $subject = $subjects->create($email, 'Person '.$i, SEED_PASSWORD);
            User::query()->whereKey($subject->id)->update(['email_verified_at' => now()]);
            $m['users'][$email] = $subject->id;
        }
        // One without a password (invited, never set one) and one deactivated.
        $m['users']['passwordless@acme.test'] = $subjects->create('passwordless@acme.test', 'No Password')->id;
        $subjects->deactivate($m['users']['person24@acme.test']);
        $m['deactivated'] = 'person24@acme.test';
    });

    $section('organizations and memberships', function () use (&$m, $orgs, $members): void {
        $m['organizations'] = [];
        foreach (['acme' => 'Acme Inc', 'globex' => 'Globex', 'initech' => 'Initech'] as $slug => $name) {
            $m['organizations'][$slug] = $orgs->create(new NewOrganization(name: $name, slug: $slug, type: OrganizationType::Customer))->id;
        }
        $roles = [MembershipRole::Owner, MembershipRole::Admin, MembershipRole::Developer, MembershipRole::Member, MembershipRole::Viewer];
        $i = 0;
        foreach ($m['users'] as $email => $id) {
            if ($email === 'passwordless@acme.test') {
                continue;
            }
            $slug = array_keys($m['organizations'])[$i % 3];
            $members->add($m['organizations'][$slug], $id, $roles[intdiv($i, 3) % count($roles)]);
            $i++;
        }
        // Somebody in two organizations.
        $members->add($m['organizations']['globex'], $m['users']['person01@acme.test'], MembershipRole::Admin);
    });

    $section('mfa (totp + recovery codes)', function () use (&$m, $totp): void {
        $mfa = app(Mfa::class);
        $m['mfa'] = [];
        foreach (['person01@acme.test', 'person02@acme.test'] as $email) {
            $userId = $m['users'][$email];
            $enrolment = $mfa->enrollTotp($userId, $email);
            $mfa->confirmTotp($userId, $totp->codeAt($enrolment->secret, time())) || throw new RuntimeException('totp not confirmed');
            $codes = $mfa->generateRecoveryCodes($userId, 4);
            $m['mfa'][$email] = ['totp_secret' => $enrolment->secret, 'recovery_code' => $codes[0]];
        }
    });

    $section('passkey', function () use (&$m, $customer): void {
        // A registered credential as the WebAuthn ceremony leaves it. The ceremony itself
        // needs an authenticator; what an upgrade must keep is the row it produced.
        $key = openssl_pkey_new(['curve_name' => 'prime256v1', 'private_key_type' => OPENSSL_KEYTYPE_EC]);
        $pem = openssl_pkey_get_details($key)['key'];
        $credentialId = rtrim(strtr(base64_encode(random_bytes(32)), '+/', '-_'), '=');
        WebAuthnCredential::query()->create([
            'id' => strtolower((string) Str::ulid()),
            'environment_id' => $customer->id,
            'user_id' => $m['users']['person03@acme.test'],
            'credential_id' => $credentialId,
            'public_key' => $pem,
            'sign_count' => 7,
            'transports' => ['internal', 'hybrid'],
            'name' => 'MacBook Touch ID',
        ]);
        $m['passkey'] = ['credential_id' => $credentialId, 'user' => 'person03@acme.test', 'public_key' => $pem];
    });

    $section('apps (oauth clients)', function () use (&$m): void {
        $registry = app(ClientRegistry::class);
        $web = $registry->register(new NewClient(
            name: 'Acme Web',
            type: ClientType::Confidential,
            redirectUris: ['https://app.acme.test/callback'],
            grantTypes: ['authorization_code', 'refresh_token'],
            scopes: ['openid', 'profile', 'email', 'offline_access'],
            organizationId: $m['organizations']['acme'],
            postLogoutRedirectUris: ['https://app.acme.test/'],
        ));
        $machine = $registry->register(new NewClient(
            name: 'Billing sync',
            type: ClientType::Confidential,
            grantTypes: ['client_credentials'],
            scopes: ['users:read'],
        ));
        $spa = $registry->register(new NewClient(
            name: 'Acme SPA',
            type: ClientType::Public,
            redirectUris: ['https://spa.acme.test/callback'],
            grantTypes: ['authorization_code', 'refresh_token'],
            scopes: ['openid', 'profile'],
            organizationId: $m['organizations']['acme'],
        ));
        $m['apps'] = [
            'web' => ['client_id' => $web->client->client_id, 'secret' => $web->secret],
            'machine' => ['client_id' => $machine->client->client_id, 'secret' => $machine->secret],
            'spa' => ['client_id' => $spa->client->client_id],
        ];
        $m['apps']['web']['secret_verified_before'] = $registry->verifySecret($web->client, (string) $web->secret);
    });

    $section('sessions and refresh tokens', function () use (&$m): void {
        $sessions = app(SessionManager::class);
        $m['sessions'] = [];
        foreach (['person01@acme.test', 'person04@acme.test', 'person05@acme.test'] as $email) {
            $m['sessions'][$email] = $sessions->start($m['users'][$email], $m['organizations']['acme'], ['pwd'], '203.0.113.7', 'Rehearsal/1.0')->id;
        }
        $client = app(ClientRegistry::class)->byClientId($m['apps']['web']['client_id']) ?? throw new RuntimeException('web client gone');
        $m['refresh_token'] = app(RefreshTokens::class)->issue($client, $m['users']['person01@acme.test'], $m['organizations']['acme'], ['openid', 'offline_access'], null, null, time(), ['pwd']);
    });

    $section('invitations and their role grants', function () use (&$m): void {
        $roles = app(Roles::class);
        $invitations = app(Invitations::class);
        $orgId = $m['organizations']['acme'];
        $role = $roles->define($orgId, 'Billing manager', 'Sees invoices');
        $roles->assign($orgId, $m['users']['person04@acme.test'], $role->id);

        $pending = $invitations->invite($orgId, 'newhire@acme.test', MembershipRole::Member, $m['users']['person01@acme.test']);
        $revoked = $invitations->invite($orgId, 'changed-mind@acme.test', MembershipRole::Member, $m['users']['person01@acme.test']);
        $invitations->revoke($orgId, $revoked->invitation->id);

        foreach (['newhire@acme.test', 'changed-mind@acme.test'] as $email) {
            InvitationRoleGrant::query()->create([
                'id' => strtolower((string) Str::ulid()),
                'environment_id' => $m['environment_id'],
                'organization_id' => $orgId,
                'email' => $email,
                'role_id' => $role->id,
            ]);
        }
        $m['role_id'] = $role->id;
        $m['invitations'] = ['pending' => $pending->invitation->id, 'revoked' => $revoked->invitation->id];
    });

    $section('webhooks', function () use (&$m): void {
        $hooks = app(WebhookRegistry::class);
        $env = $hooks->register(null, 'https://hooks.acme.test/cbox', ['user.created', 'user.updated']);
        $org = $hooks->register($m['organizations']['acme'], 'https://hooks.acme.test/org', ['membership.created']);
        $m['webhooks'] = [
            'environment' => ['id' => $env->endpoint->id, 'secret' => $env->secret],
            'organization' => ['id' => $org->endpoint->id, 'secret' => $org->secret],
        ];
    });

    $section('sso connections', function () use (&$m): void {
        $connections = app(Connections::class);
        $key = openssl_pkey_new(['private_key_bits' => 2048]);
        $csr = openssl_csr_new(['commonName' => 'idp.acme.test'], $key);
        openssl_x509_export(openssl_csr_sign($csr, null, $key, 365), $cert);

        $saml = $connections->create($m['organizations']['acme'], ConnectionType::Saml, 'Acme Okta', [
            'idp_entity_id' => 'http://www.okta.com/exk-acme',
            'idp_sso_url' => 'https://acme.okta.test/app/sso/saml',
            'idp_x509cert' => $cert,
            'sp_entity_id' => 'http://acme-workspace.id.localhost:8111/sso/saml/acme',
            'sp_acs_url' => 'http://acme-workspace.id.localhost:8111/sso/saml/acme/acs',
        ]);
        $oidc = $connections->create($m['organizations']['globex'], ConnectionType::Oidc, 'Globex Entra', [
            'issuer' => 'https://login.microsoftonline.test/globex/v2.0',
            'client_id' => 'globex-client',
            'client_secret' => 'globex-oidc-client-secret-value',
        ]);
        $m['sso'] = [
            'saml' => ['id' => $saml->id, 'certificate' => $cert],
            'oidc' => ['id' => $oidc->id, 'client_secret' => 'globex-oidc-client-secret-value'],
        ];
    });

    $section('directory (scim)', function () use (&$m): void {
        $registered = app(Directories::class)->register($m['organizations']['acme'], 'Acme Okta SCIM');
        $m['directory'] = ['id' => $registered->directory->id, 'token' => $registered->token];
    });

    $section('vault secret', function () use (&$m): void {
        $secret = app(SecretVault::class)->store('Slack bot token', 'slack', 'xoxb-rehearsal-secret', VaultOwner::organization($m['organizations']['acme']));
        $m['vault'] = ['id' => $secret->id, 'plaintext' => 'xoxb-rehearsal-secret'];
    });

    $section('user api token', function () use (&$m): void {
        $issued = app(UserApiTokens::class)->issue($m['organizations']['acme'], $m['users']['person01@acme.test'], 'CLI', TokenScope::Read);
        $m['user_api_token'] = ['id' => $issued->token->id, 'plaintext' => $issued->plaintext];
    });

    $section('admin portal links', function () use (&$m): void {
        $portal = app(AdminPortal::class);
        $m['portal_links'] = [];
        foreach ([PortalScope::Sso, PortalScope::Scim, PortalScope::Both] as $scope) {
            $portal->generate($m['organizations']['initech'], $scope, $m['users']['person03@acme.test']);
        }
        $m['portal_links'] = DB::table('admin_portal_links')->orderBy('created_at')->pluck('scope', 'id')->all();
    });

    $section('onboarding dismissals', function () use (&$m): void {
        OnboardingDismissal::query()->create([
            'id' => strtolower((string) Str::ulid()),
            'environment_id' => $m['environment_id'],
            'organization_id' => $m['organizations']['acme'],
            'subject_id' => $m['users']['person01@acme.test'],
        ]);
    });

    $section('audit entries', function () use (&$m): void {
        $audit = app(AuditLog::class);
        foreach (range(1, 5) as $i) {
            $audit->record(new AuditEvent('rehearsal.marker', ActorType::System, null, $m['organizations']['acme'], 'marker', (string) $i, ['n' => $i]));
        }
    });
});

// --- What the OLD code says about it, before the upgrade -----------------------------

$section('audit chain before', function () use (&$m, $customer, $envs): void {
    $m['audit_chain_before'] = $envs->runAs($customer, function () use ($m): array {
        $audit = app(AuditLog::class);
        $out = [];
        foreach (array_merge([null], array_values($m['organizations'] ?? [])) as $org) {
            $v = $audit->verifyChain($org);
            $out[$org ?? 'environment'] = ['valid' => $v->valid, 'verified' => $v->verifiedCount, 'reason' => $v->reason];
        }

        return $out;
    });
});

$m['failures'] = $failures;
file_put_contents($manifestPath, json_encode($m, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
fwrite(STDOUT, $failures === [] ? "seed complete\n" : 'seed finished with '.count($failures)." failed section(s)\n");
exit($failures === [] ? 0 : 1);
