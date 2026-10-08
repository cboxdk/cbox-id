<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Actions\Members\RemoveMember;
use App\Actions\Sso\SsoFields;
use App\Actions\Users\CreateUser;
use App\Actions\Users\RevokeAllUserSessions;
use App\Platform\Actions\Action;
use App\Platform\Actions\ActionRunner;
use App\Platform\Actions\Approvals\ApprovalRequired;
use App\Platform\Actions\Principal\EnvironmentKeyPrincipal;
use App\Platform\Agents\ActionApprovalInbox;
use App\Platform\AppKind;
use App\Platform\AuditLogs\AuditLogIngest;
use App\Platform\Enterprise\EnterpriseAudit;
use App\Platform\Integrations\IntegrationAudit;
use Carbon\CarbonImmutable;
use Cbox\Id\AccessControl\Contracts\AppManifests;
use Cbox\Id\AccessControl\Contracts\Roles;
use Cbox\Id\AccessControl\Manifest\DeclaredPermission;
use Cbox\Id\AccessControl\Manifest\DeclaredRole;
use Cbox\Id\AccessControl\Manifest\Manifest;
use Cbox\Id\Directory\Contracts\Directories;
use Cbox\Id\Directory\Contracts\DirectoryGroups;
use Cbox\Id\Directory\Contracts\DirectorySync;
use Cbox\Id\Directory\ValueObjects\ScimUser;
use Cbox\Id\Federation\Contracts\Connections;
use Cbox\Id\Federation\Contracts\DnsResolver;
use Cbox\Id\Federation\Contracts\DomainVerification;
use Cbox\Id\Federation\Enums\ConnectionType;
use Cbox\Id\Federation\Testing\FakeDnsResolver;
use Cbox\Id\FrontendApi\Contracts\PublishableKeys;
use Cbox\Id\FrontendApi\Enums\KeyMode;
use Cbox\Id\Identity\Contracts\Subjects;
use Cbox\Id\Kernel\Audit\ValueObjects\AuditActor;
use Cbox\Id\Kernel\Crypto\Contracts\SecretBox;
use Cbox\Id\Kernel\Tenancy\Contracts\EnvironmentContext;
use Cbox\Id\OAuthServer\Contracts\ClientRegistry;
use Cbox\Id\OAuthServer\ValueObjects\NewClient;
use Cbox\Id\Organization\Contracts\Memberships;
use Cbox\Id\Organization\Contracts\Organizations;
use Cbox\Id\Organization\Enums\EnvironmentStatus;
use Cbox\Id\Organization\Enums\EnvironmentType;
use Cbox\Id\Organization\Enums\MembershipRole;
use Cbox\Id\Organization\Models\Environment;
use Cbox\Id\Organization\Models\Organization;
use Cbox\Id\Organization\ValueObjects\NewOrganization;
use Cbox\Id\Platform\Contracts\EnvironmentApiKeys;
use Cbox\Id\Platform\Contracts\Projects;
use Cbox\Id\Platform\Models\Project;
use Cbox\Id\Platform\PlatformRoot;
use Cbox\Id\Platform\TenantProvisioner;
use Cbox\Id\Platform\ValueObjects\KeyProvenance;
use Cbox\Id\Platform\ValueObjects\TenantBlueprint;
use Cbox\Id\Webhooks\Contracts\WebhookRegistry;
use Cbox\Id\Webhooks\Exceptions\UnsafeWebhookUrl;
use Cbox\LaravelSiem\Contracts\LogStreams;
use Cbox\LaravelSiem\Enums\AuthScheme;
use Cbox\LaravelSiem\Enums\Destination;
use Cbox\LaravelSiem\Exceptions\UnsafeStreamUrl;
use Illuminate\Console\Command;
use Illuminate\Database\Seeder;
use OpenSSLAsymmetricKey;
use OpenSSLCertificate;
use RuntimeException;
use Throwable;

/**
 * A DEMO ENVIRONMENT THAT LOOKS LIKE SOMEBODY USES IT.
 *
 * The documentation's screenshots are taken from this world (tests/Browser/
 * DocsScreenshotsTest.php), and a developer who wants to click around a console with
 * something in it can seed it too. Every list a reader of the docs looks at has rows in it:
 *
 *   - a WORKSPACE, "Lovelace Labs", owned by Ada Lovelace (ada@lovelace-labs.example), with a
 *     second member and two projects — "Ledger" (a production environment named Ledger, and
 *     a sandbox) and "Analytical Engine";
 *   - in the Ledger environment: four APPLICATIONS (a web app, a single-page app, a
 *     machine-to-machine service and an AI agent), the web app's declared roles and
 *     permissions, one role the environment defines itself, and two publishable keys;
 *   - three customer ORGANIZATIONS — Acme Corp (a SAML Enterprise SSO connection to Okta and
 *     the verified domain acme.example), Globex (SCIM Directory Sync: five synced people, one
 *     of them deactivated at the IdP, and a group) and Initech — each with members on
 *     different roles;
 *   - three AGENT KEYS, one of which (Claude Code) holds destructive actions for Ada's
 *     approval: one request is waiting, one was approved, one was denied;
 *   - App audit log events (the Audit Logs product) for Acme Corp and Globex;
 *   - a WEBHOOK endpoint and a LOG STREAM, created last so nothing the seeder does is
 *     delivered to them.
 *
 * THROUGH THE DOMAIN'S OWN DOORS. Everything is written through the contracts the console's
 * actions call — {@see TenantProvisioner}, {@see ClientRegistry}, {@see Connections},
 * {@see Directories}, {@see DirectorySync}, {@see WebhookRegistry}, {@see LogStreams},
 * {@see AuditLogIngest} — attributed to Ada where the action would attribute it to the person
 * at the console, and with the same {@see EnterpriseAudit} / {@see IntegrationAudit} lines the
 * actions record. The agent's held requests are not faked either: the agent key runs real
 * actions through the {@see ActionRunner}, its step-up policy holds them, and the approvals
 * are answered through {@see ActionApprovalInbox} — so the audit trail and the Approvals page
 * show exactly what they would after the real thing.
 *
 * Two shortcuts, both stated: the verified domain is proven against an in-memory DNS answer
 * (the package's own {@see FakeDnsResolver}) instead of a TXT record on the public internet,
 * and the SAML connection's IdP certificate is a self-signed one made on the spot.
 *
 * DETERMINISTIC TEXT. Names, emails, slugs, URLs and event payloads are fixed; times are
 * relative to now — and an action approval lives fifteen minutes, so the waiting request
 * has lapsed by the time a seeded database is a quarter of an hour old. What cannot be fixed without writing rows by hand: ids, secrets, the
 * four-character binding code an approval shows, and the certificate's validity dates.
 *
 * RUNNING IT
 *
 *   php artisan db:seed --class=DemoEnvironmentSeeder
 *
 * on a migrated database. It creates the platform-root environment if the installer has not
 * (the same row `cbox-id:install` writes), and refuses to run twice: a workspace with the
 * slug `lovelace-labs` already there means it has been seeded. It does NOT change your
 * deployment's shape — to see the environment console (`/admin`) you need the multi-tenant
 * shape (`CBOX_ID_MULTI_TENANT=true`, `CBOX_ID_CONSOLE_HOST`) and a host that resolves to the
 * environment (a `CBOX_ID_BASE_DOMAINS` subdomain of its slug, or a custom domain). Sign in
 * as ada@lovelace-labs.example with the password in {@see self::PASSWORD}.
 *
 * On a machine whose DNS cannot resolve the demo's webhook and log-stream hosts, the URL
 * guards refuse them; the seeder then skips those two and says so rather than failing. The
 * test suite answers DNS itself (tests/Support/FixedDns.php), so there they are always
 * created.
 */
final class DemoEnvironmentSeeder extends Seeder
{
    /** Demo credentials only — never a real deployment's. */
    public const string PASSWORD = 'lovelace-demo-passphrase';

    public const string OWNER_EMAIL = 'ada@lovelace-labs.example';

    public const string WORKSPACE_SLUG = 'lovelace-labs';

    /** Set by {@see run()}: what the screenshots need to address. */
    public ?Environment $environment = null;

    public ?Project $project = null;

    public ?Organization $workspace = null;

    public string $ownerId = '';

    /** @var array<string, Organization> keyed by slug */
    public array $organizations = [];

    public string $directoryId = '';

    /** @var array<string, string> app name => client row id */
    public array $apps = [];

    /** The artisan command running this, when one is; null when a test runs it directly. */
    private ?Command $console = null;

    public function setCommand(Command $command)
    {
        $this->console = $command;

        return parent::setCommand($command);
    }

    public function run(): void
    {
        $this->platformRoot();

        if (app(PlatformRoot::class)->run(fn (): bool => Organization::query()->where('slug', self::WORKSPACE_SLUG)->exists()) === true) {
            $this->say('The demo workspace is already seeded — nothing to do.');

            return;
        }

        $this->seedWorkspace();

        $environment = $this->environment;

        if ($environment === null) {
            throw new RuntimeException('The demo environment was not provisioned.');
        }

        app(EnvironmentContext::class)->runAs($environment, function () use ($environment): void {
            $actor = AuditActor::organizationMember($this->ownerId);

            $this->seedApps($actor);
            $this->seedPublishableKeys();
            $this->seedOrganizations();
            $this->seedEnterpriseSso($actor);
            $this->seedDirectorySync($actor);
            $this->seedAgents($environment);
            $this->seedAppAuditLogs();
            // Last: anything recorded after these would be queued for delivery to them.
            $this->seedWebhook($actor);
            $this->seedLogStream($actor);
        });

        $this->say('Seeded the Lovelace Labs demo: sign in as '.self::OWNER_EMAIL.' / '.self::PASSWORD.'.');
    }

    /** The platform root, as the installer makes it, when there is none yet. */
    private function platformRoot(): Environment
    {
        $existing = Environment::query()->where('is_default', true)->first();

        if ($existing instanceof Environment) {
            return $existing;
        }

        $root = Environment::query()->create([
            'name' => 'Platform',
            'slug' => Environment::query()->where('slug', 'platform')->exists() ? 'platform-root' : 'platform',
            'type' => EnvironmentType::Production,
            'status' => EnvironmentStatus::Active,
            'settings' => [],
        ]);

        $root->makeDefault();

        return $root;
    }

    /**
     * The workspace, its owner, a colleague, and its projects — through the provisioner the
     * installer and sign-up use, so the first project and its production environment are
     * exactly what a real customer gets.
     */
    private function seedWorkspace(): void
    {
        $provisioner = app(TenantProvisioner::class);

        $tenant = $provisioner->provision(new TenantBlueprint(
            organizationName: 'Lovelace Labs',
            ownerEmail: self::OWNER_EMAIL,
            ownerName: 'Ada Lovelace',
            ownerPassword: self::PASSWORD,
            // The environment's name is the brand its end users meet on the hosted sign-in
            // page, so it is the product's name rather than "Production".
            environmentName: 'Ledger',
        ));

        app(Projects::class)->rename($tenant->project->id, 'Ledger');

        $project = $tenant->project->refresh();
        $provisioner->addEnvironment($project, 'Ledger Sandbox', type: EnvironmentType::Sandbox);

        $engine = $provisioner->addProject($tenant->organization, 'Analytical Engine');
        $provisioner->addEnvironment($engine, 'Analytical Engine');

        app(PlatformRoot::class)->run(function () use ($tenant): void {
            $subjects = app(Subjects::class);
            $subjects->markEmailVerified($tenant->owner->id, self::OWNER_EMAIL);

            $grace = $subjects->create('grace@lovelace-labs.example', 'Grace Hopper', self::PASSWORD);
            $subjects->markEmailVerified($grace->id, 'grace@lovelace-labs.example');
            app(Memberships::class)->add($tenant->organization->id, $grace->id, MembershipRole::Admin, $tenant->owner->id);
        });

        $this->workspace = $tenant->organization;
        $this->project = $project;
        $this->environment = $tenant->environment;
        $this->ownerId = $tenant->owner->id;
    }

    /** One app of each kind the console's "Create app" offers that a reader is likely to build. */
    private function seedApps(AuditActor $actor): void
    {
        $registry = app(ClientRegistry::class);
        $clientIds = [];

        $apps = [
            ['Ledger Web', AppKind::WebApp, ['https://app.ledger.example/auth/callback'], ['https://app.ledger.example/'], true],
            ['Ledger Dashboard', AppKind::SpaOrMobile, ['https://dashboard.ledger.example/callback'], ['https://dashboard.ledger.example/'], true],
            ['Billing Service', AppKind::Service, [], [], false],
            ['Support Assistant', AppKind::Agent, [], [], false],
        ];

        foreach ($apps as [$name, $kind, $redirects, $logout, $firstParty]) {
            $registered = $registry->register(new NewClient(
                name: $name,
                type: $kind->clientType(),
                redirectUris: $redirects,
                grantTypes: $kind->grantTypes(),
                scopes: $kind->defaultScopes(),
                firstParty: $firstParty,
                postLogoutRedirectUris: $logout,
            ), $actor);

            $this->apps[$name] = (string) $registered->client->id;
            $clientIds[$name] = (string) $registered->client->client_id;
        }

        // The web app declares what its roles mean — the manifest an app publishes, synced the
        // way `apps.manifest.sync` does — and the environment adds one role of its own.
        app(AppManifests::class)->sync($clientIds['Ledger Web'] ?? throw new RuntimeException('Ledger Web was not registered.'), new Manifest(
            version: '1',
            permissions: [
                new DeclaredPermission('invoices:read', 'See invoices'),
                new DeclaredPermission('invoices:write', 'Create and send invoices'),
                new DeclaredPermission('invoices:void', 'Void a sent invoice'),
                new DeclaredPermission('reports:export', 'Export reports'),
            ],
            roles: [
                new DeclaredRole('accountant', 'Accountant', 'Creates, sends and voids invoices.', ['invoices:read', 'invoices:write', 'invoices:void']),
                new DeclaredRole('auditor', 'Auditor', 'Reads everything, changes nothing.', ['invoices:read', 'reports:export']),
            ],
        ));

        app(Roles::class)->define(null, 'Support', 'Front-line support: may look people up across organizations.');
    }

    /** The Frontend API's publishable keys: one live for the web app, one test for local work. */
    private function seedPublishableKeys(): void
    {
        $keys = app(PublishableKeys::class);
        $keys->issue('Ledger Web', KeyMode::Live, ['https://app.ledger.example']);
        $keys->issue('Local development', KeyMode::Test, ['http://localhost:3000']);
    }

    /** Three customers, each with people on more than one role. */
    private function seedOrganizations(): void
    {
        $organizations = app(Organizations::class);
        $memberships = app(Memberships::class);

        $customers = [
            'acme' => ['Acme Corp', [
                ['Wile E. Coyote', 'wile@acme.example', MembershipRole::Owner],
                ['Road Runner', 'road.runner@acme.example', MembershipRole::Admin],
                ['Marvin Martian', 'marvin@acme.example', MembershipRole::Developer],
                ['Elmer Fudd', 'elmer@acme.example', MembershipRole::Member],
                ['Daffy Duck', 'daffy@acme.example', MembershipRole::Viewer],
            ]],
            'globex' => ['Globex', [
                ['Hank Scorpio', 'hank@globex.example', MembershipRole::Owner],
                ['Frank Grimes', 'frank@globex.example', MembershipRole::Admin],
                // Everybody else at Globex arrives through Directory Sync, below.
            ]],
            'initech' => ['Initech', [
                ['Bill Lumbergh', 'bill@initech.example', MembershipRole::Owner],
                ['Peter Gibbons', 'peter@initech.example', MembershipRole::Member],
                ['Milton Waddams', 'milton@initech.example', MembershipRole::Viewer],
            ]],
        ];

        foreach ($customers as $slug => [$name, $people]) {
            $organization = $organizations->create(new NewOrganization($name, $slug));

            foreach ($people as [$person, $email, $role]) {
                $memberships->add($organization->id, $this->user($email, $person), $role);
            }

            $this->organizations[$slug] = $organization;
        }
    }

    /**
     * Acme Corp signs in through Okta: a verified domain, routed to an active SAML
     * connection — what CreateSsoConnection, VerifyOrganizationDomain and
     * ActivateSsoConnection leave behind.
     */
    private function seedEnterpriseSso(AuditActor $actor): void
    {
        $acme = $this->organizations['acme'];

        // The TXT record, answered from memory rather than from the public DNS — the one
        // fake in this seeder, and the package's own.
        $original = app()->bound(DnsResolver::class) ? app()->make(DnsResolver::class) : null;
        $dns = new FakeDnsResolver;
        app()->instance(DnsResolver::class, $dns);
        app()->forgetInstance(DomainVerification::class);

        try {
            $domains = app(DomainVerification::class);
            $claimed = $domains->add($acme->id, 'acme.example');
            $dns->publish($domains->challengeHost('acme.example'), (string) $claimed->verification_token);
            $domains->verify($claimed->id);
            $domains->setCapture($claimed->id, true);
        } finally {
            if ($original !== null) {
                app()->instance(DnsResolver::class, $original);
            }

            app()->forgetInstance(DomainVerification::class);
        }

        $connections = app(Connections::class);
        $config = [
            'idp_entity_id' => 'http://www.okta.com/exk1acmecorp0demo',
            'idp_sso_url' => 'https://acme.okta.example/app/cboxid/exk1acmecorp0demo/sso/saml',
            'idp_x509cert' => $this->selfSignedCertificate('acme.okta.example'),
        ];

        $connection = $connections->create($acme->id, ConnectionType::Saml, 'Okta', $config);

        // Our half, known only once the connection has an id — as the action fills it in.
        $connection->config_encrypted = app(SecretBox::class)->seal(
            json_encode([...$config, ...SsoFields::serviceProvider($connection)], JSON_THROW_ON_ERROR),
            $connection->secretContext(),
        );
        $connection->save();

        app(EnterpriseAudit::class)->record(EnterpriseAudit::SSO_CONNECTION_CREATED, $actor, $acme->id, 'connection', $connection->id, [
            'name' => 'Okta',
            'type' => ConnectionType::Saml->value,
        ]);

        $connections->activate($acme->id, $connection->id);
    }

    /** Globex provisions its people from its IdP over SCIM: a directory, five people, a group. */
    private function seedDirectorySync(AuditActor $actor): void
    {
        $globex = $this->organizations['globex'];

        $registered = app(Directories::class)->register($globex->id, 'Okta SCIM');
        $directory = $registered->directory->refresh();
        $this->directoryId = $directory->id;

        app(EnterpriseAudit::class)->record(EnterpriseAudit::DIRECTORY_REGISTERED, $actor, $globex->id, 'directory', $directory->id, [
            'name' => $directory->name,
            'provider' => $directory->provider->value,
        ]);

        $people = [
            ['00u1globex01', 'Homer Simpson', 'homer@globex.example', 'Homer', 'Simpson', true],
            ['00u1globex02', 'Lenny Leonard', 'lenny@globex.example', 'Lenny', 'Leonard', true],
            ['00u1globex03', 'Carl Carlson', 'carl@globex.example', 'Carl', 'Carlson', true],
            ['00u1globex04', 'Waylon Smithers', 'waylon@globex.example', 'Waylon', 'Smithers', true],
            ['00u1globex05', 'Mindy Simmons', 'mindy@globex.example', 'Mindy', 'Simmons', false],
        ];

        $sync = app(DirectorySync::class);
        $synced = [];

        foreach ($people as [$externalId, $name, $email, $given, $family, $active]) {
            $synced[] = $sync->provisionUser($directory->id, new ScimUser(
                externalId: $externalId,
                userName: $email,
                email: $email,
                displayName: $name,
                active: $active,
                raw: [
                    'schemas' => ['urn:ietf:params:scim:schemas:core:2.0:User'],
                    'externalId' => $externalId,
                    'userName' => $email,
                    'displayName' => $name,
                    'name' => ['givenName' => $given, 'familyName' => $family],
                    'emails' => [['value' => $email, 'primary' => true, 'type' => 'work']],
                    'active' => $active,
                ],
            ))->id;
        }

        app(DirectoryGroups::class)->create($directory, 'Engineering', '00g1globexeng', array_slice($synced, 0, 3));
    }

    /**
     * The agents: the environment's management keys, minted "in the console" by Ada. Claude
     * Code holds destructive actions for her approval, so three of its requests are held —
     * one waits, one she approved, one she denied.
     */
    private function seedAgents(Environment $environment): void
    {
        $keys = app(EnvironmentApiKeys::class);

        $claude = $keys->issue($environment->id, 'Claude Code', [
            'users:read', 'users:write', 'organizations:read', 'members:read', 'members:write',
            'invitations:read', 'invitations:write', 'audit:read',
        ], null, $this->mintedByOwner(['min_danger' => 'destructive', 'actions' => []], 'Support work from the terminal: looks people up, fixes memberships.'))->key;

        $keys->issue($environment->id, 'Billing reconciler', [
            'organizations:read', 'users:read', 'audit:read',
        ], null, $this->mintedByOwner(null, 'Nightly job matching invoices to organizations. Read only.'));

        $keys->issue($environment->id, 'Release pipeline', [
            'apps:read', 'apps:write', 'keys:read',
        ], null, $this->mintedByOwner(['min_danger' => 'critical', 'actions' => []], 'CI: promotes app settings from Sandbox to Production.'));

        $runner = app(ActionRunner::class);
        $agent = new EnvironmentKeyPrincipal($claude);

        // Something it was allowed to do on its own, so the trail shows the agent at work.
        $runner->run(CreateUser::class, $agent, ['email' => 'samir@initech.example', 'name' => 'Samir Nagheenanajar']);

        $peter = $this->userId('peter@initech.example');
        $homer = $this->userId('homer@globex.example');
        $milton = $this->userId('milton@initech.example');

        $approved = $this->held($runner, $agent, RevokeAllUserSessions::class, ['id' => $homer]);
        $denied = $this->held($runner, $agent, RemoveMember::class, ['organization_id' => $this->organizations['initech']->id, 'user_id' => $milton]);
        $this->held($runner, $agent, RemoveMember::class, ['organization_id' => $this->organizations['initech']->id, 'user_id' => $peter]);

        $inbox = app(ActionApprovalInbox::class);
        $approvedEntry = $inbox->find($approved, $environment->id);
        $deniedEntry = $inbox->find($denied, $environment->id);

        if ($approvedEntry !== null) {
            $inbox->approve($approvedEntry, $this->ownerId);
        }

        if ($deniedEntry !== null) {
            $inbox->deny($deniedEntry);
        }
    }

    /**
     * Provenance for a key minted in the console by Ada — which is what makes her the person
     * its held actions wait for.
     *
     * @param  array<string, mixed>|null  $policy
     */
    private function mintedByOwner(?array $policy, string $description): KeyProvenance
    {
        return new KeyProvenance(
            createdByType: 'organization_member',
            createdById: $this->ownerId,
            description: $description,
            stepUpPolicy: $policy,
        );
    }

    /**
     * Run an action the agent's policy holds, and answer the id of the approval it raised.
     *
     * @param  class-string<Action>  $action
     * @param  array<string, mixed>  $input
     */
    private function held(ActionRunner $runner, EnvironmentKeyPrincipal $agent, string $action, array $input): string
    {
        try {
            $runner->run($action, $agent, $input);
        } catch (Throwable $outcome) {
            // Not in the runner's @throws: the approval gate's answer travels as an exception
            // the API and MCP doors turn into a 202.
            if ($outcome instanceof ApprovalRequired) {
                return $outcome->approvalId;
            }

            throw $outcome;
        }

        throw new RuntimeException("Expected [{$action}] to be held for approval, and it ran.");
    }

    /** Events a customer's own app recorded through the Audit Logs product. */
    private function seedAppAuditLogs(): void
    {
        $now = CarbonImmutable::now()->startOfMinute();
        $acme = $this->organizations['acme']->id;
        $globex = $this->organizations['globex']->id;

        $event = static fn (string $organization, string $action, int $minutesAgo, array $actor, array $targets, array $metadata = []): array => [
            'organization_id' => $organization,
            'action' => $action,
            'occurred_at' => $now->subMinutes($minutesAgo)->format('Y-m-d\TH:i:s.vP'),
            'actor' => $actor,
            'targets' => $targets,
            'context' => ['location' => '203.0.113.24', 'user_agent' => 'Mozilla/5.0 (Macintosh; Intel Mac OS X 14_5) AppleWebKit/605.1.15 Safari/605.1.15'],
            'metadata' => $metadata,
        ];

        $wile = ['id' => 'usr_wile', 'type' => 'user', 'name' => 'Wile E. Coyote'];
        $road = ['id' => 'usr_roadrunner', 'type' => 'user', 'name' => 'Road Runner'];
        $hank = ['id' => 'usr_hank', 'type' => 'user', 'name' => 'Hank Scorpio'];
        $exporter = ['id' => 'key_reports', 'type' => 'api_key', 'name' => 'Reporting export'];

        app(AuditLogIngest::class)->record([
            $event($acme, 'invoice.created', 240, $wile, [['id' => 'inv_1042', 'type' => 'invoice', 'name' => 'INV-1042']], ['amount' => 1290, 'currency' => 'USD']),
            $event($acme, 'invoice.sent', 235, $wile, [['id' => 'inv_1042', 'type' => 'invoice', 'name' => 'INV-1042']]),
            $event($acme, 'report.exported', 180, $road, [['id' => 'rpt_q3', 'type' => 'report', 'name' => 'Q3 revenue']], ['format' => 'csv']),
            $event($acme, 'user.role_changed', 95, $wile, [['id' => 'usr_marvin', 'type' => 'user', 'name' => 'Marvin Martian']], ['from' => 'member', 'to' => 'developer']),
            $event($acme, 'invoice.voided', 30, $road, [['id' => 'inv_1038', 'type' => 'invoice', 'name' => 'INV-1038']], ['reason' => 'duplicate']),
            $event($acme, 'report.exported', 12, $exporter, [['id' => 'rpt_ledger', 'type' => 'report', 'name' => 'General ledger']], ['format' => 'xlsx']),
            $event($globex, 'project.created', 300, $hank, [['id' => 'prj_doomsday', 'type' => 'project', 'name' => 'Hammock District']]),
            $event($globex, 'document.shared', 60, $hank, [['id' => 'doc_plans', 'type' => 'document', 'name' => 'Quarterly plans']], ['shared_with' => 'frank@globex.example']),
        ], null);
    }

    private function seedWebhook(AuditActor $actor): void
    {
        try {
            $registered = app(WebhookRegistry::class)->registerForEnvironment('https://hooks.ledger.example/cbox-id', [
                'user.created', 'user.deactivated', 'organization.created', 'membership.created', 'membership.deleted', 'directory.user.provisioned',
            ]);
        } catch (UnsafeWebhookUrl) {
            $this->say('Skipped the webhook: this machine cannot resolve hooks.ledger.example, so the URL guard refused it.');

            return;
        }

        $endpoint = $registered->endpoint->refresh();

        app(IntegrationAudit::class)->record(IntegrationAudit::WEBHOOK_CREATED, 'webhook_endpoint', $endpoint->id, null, $actor, [
            'url' => $endpoint->url,
            'event_types' => array_values($endpoint->event_types),
        ]);
    }

    private function seedLogStream(AuditActor $actor): void
    {
        try {
            $registered = app(LogStreams::class)->create(
                'Splunk (security team)',
                Destination::SplunkHec,
                'https://splunk.ledger.example:8088/services/collector/event',
                'demo-splunk-hec-token',
                AuthScheme::Splunk,
            );
        } catch (UnsafeStreamUrl) {
            $this->say('Skipped the log stream: this machine cannot resolve splunk.ledger.example, so the URL guard refused it.');

            return;
        }

        $stream = $registered->stream;

        app(IntegrationAudit::class)->record(IntegrationAudit::LOG_STREAM_CREATED, 'log_stream', (string) $stream->id, null, $actor, [
            'name' => $stream->name,
            'destination' => Destination::SplunkHec->value,
            'endpoint_url' => $stream->endpoint_url,
        ]);
    }

    /** A verified user of this environment, created on first mention. */
    private function user(string $email, string $name): string
    {
        $subjects = app(Subjects::class);
        $subject = $subjects->findByEmail($email) ?? $subjects->create($email, $name, self::PASSWORD);
        $subjects->markEmailVerified($subject->id, $email);

        return $subject->id;
    }

    private function userId(string $email): string
    {
        $subject = app(Subjects::class)->findByEmail($email);

        if ($subject === null) {
            throw new RuntimeException("No demo user {$email}.");
        }

        return $subject->id;
    }

    /** A throwaway self-signed certificate, so the connection's IdP certificate is a real one. */
    private function selfSignedCertificate(string $commonName): string
    {
        $key = openssl_pkey_new(['private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA]);

        if (! $key instanceof OpenSSLAsymmetricKey) {
            throw new RuntimeException('openssl could not make a key for the demo certificate.');
        }

        $csr = openssl_csr_new(['commonName' => $commonName, 'organizationName' => 'Acme Corp'], $key, ['digest_alg' => 'sha256']);

        // `$key` is taken by reference, so it is asked about again rather than assumed.
        if ($csr === false || $csr === true || ! $key instanceof OpenSSLAsymmetricKey) {
            throw new RuntimeException('openssl could not make a request for the demo certificate.');
        }

        $certificate = openssl_csr_sign($csr, null, $key, 730, ['digest_alg' => 'sha256']);

        $pem = null;

        if (! $certificate instanceof OpenSSLCertificate || ! openssl_x509_export($certificate, $pem) || ! is_string($pem)) {
            throw new RuntimeException('openssl could not sign the demo certificate.');
        }

        return $pem;
    }

    private function say(string $line): void
    {
        $this->console?->info($line);
    }
}
