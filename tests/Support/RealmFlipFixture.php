<?php

declare(strict_types=1);

namespace Tests\Support;

use App\Platform\NativeProduct\NativeProductClient;
use App\Platform\NativeProduct\NativeProductManifest;
use App\Platform\Organizations\OrganizationLinks;
use App\Platform\SignInThrough;
use Cbox\Id\AccessControl\Contracts\AppManifests;
use Cbox\Id\Devices\Support\AuthenticatorClient;
use Cbox\Id\Identity\Contracts\SessionManager;
use Cbox\Id\Identity\Contracts\Subjects;
use Cbox\Id\Kernel\Audit\Contracts\AuditLog;
use Cbox\Id\Kernel\Audit\Enums\ActorType;
use Cbox\Id\Kernel\Audit\ValueObjects\AuditEvent;
use Cbox\Id\Kernel\Tenancy\Contracts\EnvironmentContext;
use Cbox\Id\OAuthServer\Contracts\ClientRegistry;
use Cbox\Id\OAuthServer\Enums\ClientType;
use Cbox\Id\OAuthServer\ValueObjects\NewClient;
use Cbox\Id\Organization\Contracts\Invitations;
use Cbox\Id\Organization\Contracts\Memberships;
use Cbox\Id\Organization\Contracts\Organizations;
use Cbox\Id\Organization\Enums\EnvironmentStatus;
use Cbox\Id\Organization\Enums\EnvironmentType;
use Cbox\Id\Organization\Enums\MembershipRole;
use Cbox\Id\Organization\Models\Environment;
use Cbox\Id\Organization\ValueObjects\NewOrganization;
use Cbox\Id\Platform\Contracts\PlatformOperators;
use Cbox\Id\Platform\Contracts\Projects;
use Cbox\Id\Platform\TenantProvisioner;
use Cbox\Id\Platform\ValueObjects\TenantBlueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * A deployment shaped like the hosted service the day before the realm flip, written
 * through the product's own services wherever one exists.
 *
 * The shape, from the production inventory of 2026-11-11:
 *
 * - The platform root, slug `platform`, on the console host. One organization, "Cbox",
 *   with one owner who is also the one platform operator. It owns two projects: `Cbox`
 *   (holding the `cbox` environment and a sandbox, `cbox-signals-p1`) and a suspended one
 *   with no environments. The root signs in THROUGH the `cbox` environment, so the owner
 *   has an OIDC identity whose subject is their `cbox` user id. Three first-party clients
 *   live here, one of them the Authenticator, which has a twin in `cbox`.
 * - The `cbox` environment: no domain, a project. The linked "Cbox" organization with two
 *   members (the owner again, as a separate user row with passkeys of their own, and a
 *   colleague the root has never seen), an organization that owns nothing, a deleted one,
 *   social providers, app clients, live sessions and refresh tokens.
 * - The sandbox, a customer-shaped tenant environment with people of its own.
 *
 * `seedScript()` in scripts/realm-flip/seed-fixture.php builds the same thing in a real
 * database for the rehearsal.
 *
 * @phpstan-type Built array{root: string, realm: string, sandbox: string, rootOrganization: string, realmOrganization: string, emptyOrganization: string, rootOwner: string, realmOwner: string, colleague: string, project: string, idleProject: string, operator: string, rootMembership: string, realmMembership: string, movedClient: string, twinClient: string, realmTwinClient: string, nativeClient: string|null}
 */
final class RealmFlipFixture
{
    public const OWNER = 'sylvester@cbox.test';

    public const CONSOLE_HOST = 'app.cboxid.test';

    /** @return Built */
    public static function build(): array
    {
        $context = app(EnvironmentContext::class);

        $root = Environment::query()->where('is_default', true)->first() ?? Environment::query()->create([
            'name' => 'Platform',
            'slug' => 'platform',
            'type' => EnvironmentType::Production,
            'status' => EnvironmentStatus::Active,
            'is_default' => true,
            'settings' => [],
        ]);
        $root->forceFill(['slug' => 'platform', 'domain' => self::CONSOLE_HOST, 'domain_verified_at' => now()])->save();

        // The root organization, its owner, the `Cbox` project and its first environment.
        $tenant = app(TenantProvisioner::class)->provision(new TenantBlueprint(
            organizationName: 'Cbox',
            ownerEmail: self::OWNER,
            ownerName: 'Sylvester',
            ownerPassword: 'a-strong-unbreached-passphrase',
        ));

        $realm = $tenant->environment;
        $realm->forceFill(['name' => 'Cbox', 'slug' => 'cbox', 'domain' => null, 'settings' => ['default_locale' => 'da']])->save();

        $sandbox = Environment::query()->create([
            'project_id' => $tenant->project->id,
            'name' => 'Signals P1',
            'slug' => 'cbox-signals-p1',
            'type' => EnvironmentType::Sandbox,
            'status' => EnvironmentStatus::Active,
            'is_default' => false,
            'settings' => [],
        ]);

        $idle = app(Projects::class)->createForOrganization($tenant->organization->id, 'Cbox ISMS');
        app(Projects::class)->suspend($idle->id);

        // The root's own state: an operator, an invitation, a grant, keys, clients, audit.
        [$operator, $movedClient, $twinClient] = $context->runAs($root, function () use ($tenant, $sandbox): array {
            $operator = app(PlatformOperators::class)->create(self::OWNER, Str::password(40), 'Sylvester');

            app(Invitations::class)->invite($tenant->organization->id, 'newcomer@cbox.test', MembershipRole::Developer, $tenant->owner->id);

            // A colleague of the root with no account in the realm yet would block the flip;
            // the base fixture has none. A viewer with a per-environment grant is added by
            // the tests that need one.
            app(Memberships::class)->setEnvironmentAccess($tenant->organization->id, $tenant->owner->id, true);

            $clients = app(ClientRegistry::class);
            $moved = $clients->register(new NewClient(name: 'Cbox ID', type: ClientType::Confidential, grantTypes: ['client_credentials'], firstParty: true))->client;
            $twin = $clients->register(new NewClient(
                name: AuthenticatorClient::NAME,
                type: ClientType::Public,
                redirectUris: ['https://'.self::CONSOLE_HOST.'/app/oauth/callback'],
                grantTypes: ['authorization_code', 'refresh_token'],
                firstParty: true,
            ))->client;

            app(SessionManager::class)->start($tenant->owner->id, null, ['pwd'], '203.0.113.9', 'fixture');

            app(AuditLog::class)->record(new AuditEvent(
                action: 'project.created',
                actorType: ActorType::OrganizationMember,
                actorId: $tenant->owner->id,
                targetType: 'project',
                targetId: $tenant->project->id,
                organizationId: $tenant->organization->id,
                context: ['environment' => $sandbox->id],
            ));
            app(AuditLog::class)->record(new AuditEvent(action: 'operator.granted', actorType: ActorType::System, actorId: 'fixture', targetType: 'operator', targetId: (string) $operator->id));

            return [$operator, $moved, $twin];
        });

        DB::table('organization_api_keys')->insert([
            'id' => Str::lower((string) Str::ulid()),
            'organization_id' => $tenant->organization->id,
            'name' => 'CI',
            'prefix' => 'cbid_ws_fixture',
            'token_hash' => hash('sha256', 'fixture-org-key'),
            'role' => 'admin',
            'created_by_type' => 'user',
            'created_by_id' => $tenant->owner->id,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        // The native product registration: the console's own roles and permissions.
        $context->runAs($root, fn () => app(NativeProductClient::class)->synchronize(app(AppManifests::class), app(NativeProductManifest::class)));
        $native = DB::table('native_product_clients')->value('client_id');

        // The realm: the linked organization, the same person again, a colleague, bystanders.
        [$realmOrganization, $emptyOrganization, $realmOwner, $colleague, $realmMembership, $realmTwin, $connection] = $context->runAs($realm, function (): array {
            $organizations = app(Organizations::class);
            $subjects = app(Subjects::class);

            $organization = $organizations->create(new NewOrganization(name: 'Cbox', slug: 'cbox'));
            $empty = $organizations->create(new NewOrganization(name: 'Test', slug: 'test'));
            $deleted = $organizations->create(new NewOrganization(name: 'Test1', slug: 'test1'));
            DB::table('organizations')->where('id', $deleted->id)->update(['status' => 'deleted']);

            $owner = $subjects->create(self::OWNER, 'Sylvester', 'another-strong-unbreached-passphrase');
            $colleague = $subjects->create('colleague@cbox.test', 'Colleague', 'a-third-strong-unbreached-passphrase');
            $bystander = $subjects->create('bystander@example.test', 'Bystander', 'a-fourth-strong-unbreached-passphrase');

            // Deliberately only a member here: the root says owner, and the flip has to decide.
            $membership = app(Memberships::class)->add($organization->id, $owner->id, MembershipRole::Member);
            app(Memberships::class)->add($organization->id, $colleague->id, MembershipRole::Admin);
            app(Memberships::class)->add($empty->id, $bystander->id, MembershipRole::Owner);

            $clients = app(ClientRegistry::class);
            $twin = $clients->register(new NewClient(
                name: AuthenticatorClient::NAME,
                type: ClientType::Public,
                redirectUris: ['https://cbox.cboxid.test/app/oauth/callback'],
                grantTypes: ['authorization_code', 'refresh_token'],
                firstParty: true,
            ))->client;
            $console = $clients->register(new NewClient(name: 'Cbox ID console', type: ClientType::Confidential, redirectUris: ['https://'.self::CONSOLE_HOST.'/login/sso/callback'], grantTypes: ['authorization_code'], firstParty: true))->client;
            $clients->register(new NewClient(name: 'Cbox Work', type: ClientType::Confidential, redirectUris: ['https://work.cbox.test/callback'], grantTypes: ['authorization_code', 'refresh_token'], firstParty: true));

            $session = app(SessionManager::class)->start($owner->id, $organization->id, ['pwd'], '203.0.113.9', 'fixture');

            DB::table('oauth_refresh_tokens')->insert([
                'id' => Str::lower((string) Str::ulid()),
                'environment_id' => app(EnvironmentContext::class)->current()?->environmentKey(),
                'token_hash' => hash('sha256', 'fixture-refresh'),
                'family_id' => Str::lower((string) Str::ulid()),
                'client_id' => $console->client_id,
                'user_id' => $owner->id,
                'organization_id' => $organization->id,
                'scopes' => json_encode(['openid']),
                'session_id' => $session->id,
                'expires_at' => now()->addDays(30),
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            app(AuditLog::class)->record(new AuditEvent(action: 'member.added', actorType: ActorType::System, actorId: 'fixture', targetType: 'user', targetId: $colleague->id, organizationId: $organization->id));

            return [$organization->id, $empty->id, $owner->id, $colleague->id, $membership->id, $twin->client_id, $console->client_id];
        });

        // Passkeys: the same person, two user rows, separate credentials on each.
        foreach ([[$root->id, $tenant->owner->id, 'root'], [$realm->id, $realmOwner, 'realm']] as [$environmentId, $userId, $label]) {
            foreach ([1, 2] as $n) {
                self::passkey($environmentId, $userId, "{$label}-{$n}");
            }
        }

        // The root signs in through the realm: the owner's root user carries an OIDC
        // identity whose subject is their realm user id.
        $connectionId = Str::lower((string) Str::ulid());
        self::insert('connections', [
            'id' => $connectionId,
            'environment_id' => $root->id,
            'organization_id' => null,
            'name' => 'Cbox',
            'type' => 'oidc',
            'status' => 'active',
            'config' => json_encode(['issuer' => 'https://cbox.cboxid.test', 'client_id' => $connection]),
        ]);
        self::insert('identities', [
            'id' => Str::lower((string) Str::ulid()),
            'environment_id' => $root->id,
            'user_id' => $tenant->owner->id,
            'provider' => 'oidc',
            'subject' => $realmOwner,
            'connection_id' => $connectionId,
        ]);
        $root->forceFill(['settings' => [SignInThrough::SETTING => $connectionId, 'appearance' => ['preset' => 'fixture'], 'default_locale' => 'en']])->save();

        app(OrganizationLinks::class)->flush();
        app(OrganizationLinks::class)->link($tenant->organization->id, $realmOrganization, 'slug');

        // The sandbox has people of its own, who are nobody's business but the tenant's.
        $context->runAs($sandbox, function (): void {
            $subject = app(Subjects::class)->create('tester@customer.test', 'Tester', 'a-fifth-strong-unbreached-passphrase');
            app(SessionManager::class)->start($subject->id, null, ['pwd'], '198.51.100.4', 'fixture');
        });

        return [
            'root' => $root->id,
            'realm' => $realm->id,
            'sandbox' => $sandbox->id,
            'rootOrganization' => $tenant->organization->id,
            'realmOrganization' => $realmOrganization,
            'emptyOrganization' => $emptyOrganization,
            'rootOwner' => $tenant->owner->id,
            'realmOwner' => $realmOwner,
            'colleague' => $colleague,
            'project' => $tenant->project->id,
            'idleProject' => $idle->id,
            'operator' => (string) $operator->id,
            'rootMembership' => $tenant->membership->id,
            'realmMembership' => $realmMembership,
            'movedClient' => $movedClient->client_id,
            'twinClient' => $twinClient->client_id,
            'realmTwinClient' => $realmTwin,
            'nativeClient' => is_string($native) ? $native : null,
        ];
    }

    private static function passkey(string $environmentId, string $userId, string $label): void
    {
        self::insert('webauthn_credentials', [
            'id' => Str::lower((string) Str::ulid()),
            'environment_id' => $environmentId,
            'user_id' => $userId,
            'credential_id' => 'cred-'.$label,
            'public_key' => 'pk-'.$label,
            'name' => $label,
        ]);
    }

    /**
     * Insert a row giving only the columns that matter, and a harmless value for every
     * other column the table requires: the fixture stays true as columns are added.
     *
     * @param  array<string, mixed>  $row
     */
    public static function insert(string $table, array $row): void
    {
        foreach (DB::getSchemaBuilder()->getColumns($table) as $column) {
            $name = $column['name'];

            if (array_key_exists($name, $row) || $column['nullable'] || $column['default'] !== null || $column['auto_increment']) {
                continue;
            }

            $type = strtolower($column['type_name']);
            $row[$name] = match (true) {
                in_array($name, ['created_at', 'updated_at'], true), str_contains($type, 'time'), str_contains($type, 'date') => now(),
                str_contains($type, 'int'), str_contains($type, 'bool'), str_contains($type, 'numeric') => 0,
                str_contains($type, 'json') => '[]',
                default => '',
            };
        }

        $row['created_at'] ??= now();
        $row['updated_at'] ??= now();

        $columns = array_column(DB::getSchemaBuilder()->getColumns($table), 'name');

        DB::table($table)->insert(array_intersect_key($row, array_flip($columns)));
    }
}
