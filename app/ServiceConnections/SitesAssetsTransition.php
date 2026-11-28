<?php

declare(strict_types=1);

namespace App\ServiceConnections;

use App\Platform\Actions\ActionRefused;
use App\Platform\Actions\ActionTrail;
use App\Platform\OAuth\PlatformRealmClients;
use App\Platform\PublicIds\PublicIdTree;
use Cbox\Id\Kernel\Audit\Contracts\AuditLog;
use Cbox\Id\Kernel\Audit\Enums\ActorType;
use Cbox\Id\Kernel\Audit\ValueObjects\AuditEvent;
use Cbox\Id\OAuthServer\Contracts\ServiceAccounts;
use Cbox\Id\OAuthServer\Enums\ClientType;
use Cbox\Id\OAuthServer\Enums\ServiceAccountStatus;
use Cbox\Id\OAuthServer\Models\Client;
use Cbox\Id\OAuthServer\Models\ServiceAccount;
use Cbox\Id\Organization\Enums\EnvironmentStatus;
use Cbox\Id\Organization\Enums\OrganizationStatus;
use Cbox\Id\Organization\Models\Environment;
use Cbox\Id\Organization\Models\Organization;
use Cbox\Id\Platform\PlatformRoot;
use Illuminate\Support\Facades\DB;

/** A bounded, operator-approved replacement, never an automatic scope upgrade. */
final readonly class SitesAssetsTransition
{
    public function __construct(private PlatformRoot $root, private ServiceAccounts $accounts, private AuditLog $audit) {}

    public function plan(): SitesAssetsTransitionPlan
    {
        $plan = SitesAssetsTransitionPlan::configured();
        if ($this->root->run(fn () => $this->expected($plan)) === null) {
            throw new ActionRefused('transition_unavailable', 'The platform realm is unavailable.', 503);
        }

        return $plan;
    }

    /** @param array<mixed> $requested */
    public function assertPlan(array $requested): SitesAssetsTransitionPlan
    {
        $plan = $this->plan();
        $requested = array_replace($requested, PublicIdTree::decode($requested));
        $current = [...$plan->toArray(), 'plan_digest' => $plan->digest()];
        ksort($requested);
        ksort($current);
        if ($requested !== $current) {
            throw new ActionRefused('transition_plan_changed', 'Request and approve the exact current transition plan.', 409);
        }

        return $plan;
    }

    /** @param array<mixed> $requested */
    public function transition(array $requested, string $operatorId): ServiceConnectionTransition
    {
        return $this->root->run(fn () => DB::transaction(function () use ($requested, $operatorId): ServiceConnectionTransition {
            $plan = $this->assertPlan($requested);
            $connection = $this->expected($plan, lock: true);
            $approval = app(ActionTrail::class)->context()[ActionTrail::APPROVAL] ?? null;
            if ($approval === null) {
                throw new ActionRefused('approval_required', 'The transition requires an exact one-use operator approval.', 403);
            }
            $registered = PlatformRealmClients::forServiceConnection(fn () => $this->accounts->create(
                $plan->organizationId, 'Service connection: '.SitesAssetsTransitionPlan::SERVICE, SitesAssetsTransitionPlan::NEW_SCOPES,
            ));
            $this->accounts->retire($plan->organizationId, $plan->oldServiceClientId);
            $connection->update(['service_client_id' => $registered->client->client_id, 'scopes' => SitesAssetsTransitionPlan::NEW_SCOPES]);
            $entry = $this->audit->record(new AuditEvent(
                action: 'service_connection.transitioned', actorType: ActorType::Operator, actorId: $operatorId,
                organizationId: $plan->organizationId, targetType: 'service_connection', targetId: $plan->connectionId,
                context: [...$plan->toArray(), 'plan_digest' => $plan->digest(), 'new_service_client_id' => $registered->client->client_id],
            ));

            return ServiceConnectionTransition::query()->create([
                'id' => $plan->digest(), 'connection_id' => $plan->connectionId,
                'payload' => [
                    // SDK ServiceConnection has these six fields, no backing-client field.
                    'grant' => ['id' => $connection->id, 'organization_id' => $plan->organizationId,
                        'consumer_client_id' => $plan->consumerClientId, 'service' => SitesAssetsTransitionPlan::SERVICE,
                        'audience' => SitesAssetsTransitionPlan::AUDIENCE, 'scopes' => SitesAssetsTransitionPlan::NEW_SCOPES],
                    'receipt' => [...$plan->toArray(), 'plan_digest' => $plan->digest(),
                        'new_service_client_id' => $registered->client->client_id, 'approval_id' => $approval,
                        'audit_entry_id' => $entry->id, 'operator_id' => $operatorId, 'transitioned_at' => now()->toIso8601ZuluString()],
                ],
            ]);
        })) ?? throw new ActionRefused('transition_unavailable', 'The platform realm is unavailable.', 503);
    }

    /** Read survives manifest expiry and later revocation. It is evidence, never live authorization. */
    public function receipt(string $digest): ServiceConnectionTransition
    {
        return $this->root->run(fn () => ServiceConnectionTransition::query()->findOrFail($digest))
            ?? throw new ActionRefused('transition_unavailable', 'The platform realm is unavailable.', 503);
    }

    private function expected(SitesAssetsTransitionPlan $plan, bool $lock = false): ServiceConnection
    {
        $rootId = $this->root->model()?->id;
        $environment = Environment::query()->whereKey($rootId)->when($lock, fn ($query) => $query->lockForUpdate())->first();
        $definition = config('service-connections.services.cbox_assets');
        if ($environment === null || $environment->id !== $plan->environmentId || $environment->status !== EnvironmentStatus::Active
            || $plan->consumerClientId !== config('service-connections.sites_consumer_client_id')
            || ! in_array($plan->consumerClientId, config()->array('service-connections.consumers'), true)
            || ! is_array($definition) || ($definition['client_id'] ?? null) !== $plan->providerClientId
            || ($definition['audience'] ?? null) !== SitesAssetsTransitionPlan::AUDIENCE
            || ($definition['scopes'] ?? null) !== SitesAssetsTransitionPlan::NEW_SCOPES) {
            throw new ActionRefused('transition_unavailable', 'The active platform realm and registered grant must match the manifest.', 503);
        }
        // Same stable parent as provision/connect, then the row lease/revoke lock, then its client.
        $organization = Organization::query()->whereKey($plan->organizationId)->when($lock, fn ($query) => $query->lockForUpdate())->first();
        $connection = ServiceConnection::query()->whereKey($plan->connectionId)->when($lock, fn ($query) => $query->lockForUpdate())->first();
        if (ServiceConnectionTransition::query()->where('connection_id', $plan->connectionId)->exists()) {
            throw new ActionRefused('transition_completed', 'This connection already transitioned. Recover its receipt using the original plan_digest.', 409);
        }
        if ($organization === null || $organization->status !== OrganizationStatus::Active
            || $connection === null || $connection->revoked_at !== null || $connection->organization_id !== $plan->organizationId
            || $connection->consumer_client_id !== $plan->consumerClientId || $connection->service !== SitesAssetsTransitionPlan::SERVICE
            || $connection->service_client_id !== $plan->oldServiceClientId || $connection->audience !== SitesAssetsTransitionPlan::AUDIENCE
            || $connection->scopes !== SitesAssetsTransitionPlan::OLD_SCOPES) {
            throw new ActionRefused('transition_connection_conflict', 'The active connection no longer matches the exact expected-old grant.', 409);
        }
        $client = Client::query()->where('client_id', $plan->oldServiceClientId)->when($lock, fn ($query) => $query->lockForUpdate())->first();
        $account = ServiceAccount::query()->whereKey($plan->oldServiceAccountId)->when($lock, fn ($query) => $query->lockForUpdate())->first();
        if ($client === null || $client->organization_id !== $plan->organizationId || $client->type !== ClientType::Confidential
            || $client->isDynamicallyRegistered() || $client->grant_types !== ['client_credentials'] || $client->scopes !== SitesAssetsTransitionPlan::OLD_SCOPES
            || $account === null || $account->organization_id !== $plan->organizationId || $account->client_id !== $plan->oldServiceClientId
            || $account->status !== ServiceAccountStatus::Active || $account->retired_at !== null) {
            throw new ActionRefused('transition_backing_conflict', 'The expected backing client and service account must both be active and match the old grant.', 409);
        }
        $registrations = [$plan->consumerClientId, $plan->providerClientId];
        sort($registrations);
        foreach ($registrations as $id) {
            $registered = Client::query()->where('client_id', $id)->when($lock, fn ($query) => $query->lockForUpdate())->first();
            if ($registered === null || $registered->organization_id !== null || $registered->type !== ClientType::Confidential
                || $registered->isDynamicallyRegistered() || ! in_array('client_credentials', $registered->grant_types, true)) {
                throw new ActionRefused('transition_unavailable', 'Both registered platform machine clients must still exist in this realm.', 503);
            }
        }
        // Locks can wait past expiry. Do not spend a once-valid manifest after acquiring them.
        if (now()->greaterThanOrEqualTo($plan->expiresAt)) {
            throw new ActionRefused('transition_expired', 'The transition manifest expired while waiting for its locks.', 409);
        }

        return $connection;
    }
}
