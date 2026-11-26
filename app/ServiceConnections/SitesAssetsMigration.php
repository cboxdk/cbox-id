<?php

declare(strict_types=1);

namespace App\ServiceConnections;

use App\Platform\Actions\ActionRefused;
use App\Platform\OAuth\PlatformRealmClients;
use App\Platform\PublicIds\PublicIdTree;
use Cbox\Id\Kernel\Audit\Contracts\AuditLog;
use Cbox\Id\Kernel\Audit\Enums\ActorType;
use Cbox\Id\Kernel\Audit\ValueObjects\AuditEvent;
use Cbox\Id\OAuthServer\Contracts\ServiceAccounts;
use Cbox\Id\OAuthServer\Enums\ClientType;
use Cbox\Id\OAuthServer\Models\Client;
use Cbox\Id\Organization\Enums\EnvironmentStatus;
use Cbox\Id\Organization\Enums\OrganizationStatus;
use Cbox\Id\Organization\Models\Organization;
use Cbox\Id\Platform\PlatformRoot;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;

/**
 * Temporary, deployment-attested Sites migration, not a general machine bootstrap.
 * The operator Actions own authorization/one-use approval. This service owns the fixed
 * grant, live manifest checks and transactional creation through ServiceAccounts.
 *
 * @phpstan-type Plan array{manifest_digest: string, environment_id: string, consumer_client_id: string, provider_client_id: string, organization_ids: list<string>, evidence: string, expires_at: string, service: string, audience: string, scopes: list<string>}
 */
final readonly class SitesAssetsMigration
{
    private const string SERVICE = 'cbox_assets';

    private const string AUDIENCE = 'https://api.cboxassets.com';

    private const array SCOPES = ['assets:consumer:read', 'assets:consumer:write'];

    public function __construct(private PlatformRoot $root, private ServiceAccounts $accounts, private AuditLog $audit) {}

    /** @return Plan */
    public function plan(): array
    {
        $manifest = config('service-connections.sites_assets_migration');
        if (! is_array($manifest) || Validator::make($manifest, [
            'environment_id' => ['required', 'string', 'ulid'],
            'consumer_client_id' => ['required', 'string', 'max:191'],
            'organization_ids' => ['required', 'array', 'list', 'min:1', 'max:50'],
            'organization_ids.*' => ['required', 'string', 'ulid', 'distinct:strict'],
            'evidence' => ['required', 'string', 'max:300'],
            'expires_at' => ['required', 'date_format:Y-m-d\TH:i:s\Z', 'after:now'],
        ])->fails() || count($manifest) !== 5) {
            throw new ActionRefused('migration_unavailable', 'The deployment needs a valid, unexpired Sites migration manifest.', 503);
        }

        /** @var array{environment_id: string, consumer_client_id: string, organization_ids: list<string>, evidence: string, expires_at: string} $manifest */
        $environment = $this->root->model();
        $definition = config('service-connections.services.'.self::SERVICE);
        if ($environment === null || $environment->id !== $manifest['environment_id'] || $environment->status !== EnvironmentStatus::Active
            || $manifest['consumer_client_id'] !== config('service-connections.sites_consumer_client_id')
            || ! in_array($manifest['consumer_client_id'], config()->array('service-connections.consumers'), true)
            || ! is_array($definition) || ($definition['audience'] ?? null) !== self::AUDIENCE
            || ($definition['scopes'] ?? null) !== self::SCOPES || ! is_string($definition['client_id'] ?? null)) {
            throw new ActionRefused('migration_unavailable', 'The Sites migration registration does not match the fixed grant.', 503);
        }

        sort($manifest['organization_ids']);
        $plan = [
            'environment_id' => $manifest['environment_id'], 'consumer_client_id' => $manifest['consumer_client_id'],
            'provider_client_id' => $definition['client_id'], 'organization_ids' => $manifest['organization_ids'],
            'evidence' => $manifest['evidence'], 'expires_at' => $manifest['expires_at'],
            'service' => self::SERVICE, 'audience' => self::AUDIENCE, 'scopes' => self::SCOPES,
        ];
        $plan['manifest_digest'] = hash('sha256', json_encode($plan, JSON_THROW_ON_ERROR));
        $this->root->run(function () use ($plan): void {
            foreach ([$plan['consumer_client_id'], $plan['provider_client_id']] as $clientId) {
                $client = Client::query()->where('client_id', $clientId)->first();
                if ($client === null || $client->type !== ClientType::Confidential || ! in_array('client_credentials', $client->grant_types, true)) {
                    throw new ActionRefused('migration_unavailable', 'Both migration clients must be registered confidential machine clients in the attested realm.', 503);
                }
            }
            $this->organizations($plan);
        });

        return $plan;
    }

    /** @param array<mixed> $requested */
    public function assertPlan(array $requested): void
    {
        // The nested /v1 plan contains public ids; opaque OAuth client ids stay untouched.
        $requested = array_replace($requested, PublicIdTree::decode($requested));
        $current = $this->plan();
        ksort($requested);
        ksort($current);
        if ($requested !== $current) {
            throw new ActionRefused('migration_plan_changed', 'Request the current dry-run plan and approve that exact plan.', 409);
        }
    }

    /**
     * @param  array<mixed>  $requested
     * @return list<array{id: string, organization_id: string, service_client_id: string}>
     */
    public function provision(array $requested, string $operatorId, string $subjectId): array
    {
        return $this->root->run(fn (): array => DB::transaction(function () use ($requested, $operatorId, $subjectId): array {
            // Re-read after approval, inside the write transaction. Never revive a revoked grant.
            $this->assertPlan($requested);
            $plan = $this->plan();
            $this->organizations($plan, lock: true);
            $connections = [];
            foreach ($plan['organization_ids'] as $organization) {
                $connection = ServiceConnection::query()->where('organization_id', $organization)
                    ->where('consumer_client_id', $plan['consumer_client_id'])->where('service', self::SERVICE)->first();
                if ($connection === null) {
                    $registered = PlatformRealmClients::forServiceConnection(
                        fn () => $this->accounts->create($organization, 'Service connection: '.self::SERVICE, self::SCOPES),
                    );
                    $connection = ServiceConnection::query()->create([
                        'organization_id' => $organization, 'consumer_client_id' => $plan['consumer_client_id'],
                        'service' => self::SERVICE, 'service_client_id' => $registered->client->client_id,
                        'audience' => self::AUDIENCE, 'scopes' => self::SCOPES, 'created_by' => $subjectId,
                    ]);
                    $this->audit->record(new AuditEvent(
                        action: 'service_connection.created', actorType: ActorType::Operator, actorId: $operatorId,
                        organizationId: $organization, targetType: 'service_connection', targetId: $connection->id,
                        context: ['consumer_client_id' => $plan['consumer_client_id'], 'service' => self::SERVICE,
                            'migration_digest' => $plan['manifest_digest'], 'evidence' => $plan['evidence']],
                    ));
                }
                $connections[] = ['id' => $connection->id, 'organization_id' => $organization, 'service_client_id' => $connection->service_client_id];
            }

            return $connections;
        })) ?? throw new ActionRefused('migration_unavailable', 'The migration realm is unavailable.', 503);
    }

    /** @param Plan $plan */
    private function organizations(array $plan, bool $lock = false): void
    {
        // Same parent locks and order as normal connect, including absent connection rows.
        foreach ($plan['organization_ids'] as $id) {
            $query = Organization::query()->whereKey($id);
            $organization = ($lock ? $query->lockForUpdate() : $query)->first();
            if ($organization === null || $organization->status !== OrganizationStatus::Active) {
                throw new ActionRefused('migration_organization_unavailable', 'Every attested organization must be active in the migration realm.', 409);
            }
            $connection = ServiceConnection::query()->where('organization_id', $id)
                ->where('consumer_client_id', $plan['consumer_client_id'])->where('service', self::SERVICE)->first();
            if ($connection !== null && ($connection->revoked_at !== null || $connection->audience !== self::AUDIENCE
                || $connection->scopes !== self::SCOPES || ! Client::query()->where('client_id', $connection->service_client_id)->exists())) {
                throw new ActionRefused('migration_connection_conflict', 'An existing connection is revoked or differs from the migration grant.', 409);
            }
        }
    }
}
