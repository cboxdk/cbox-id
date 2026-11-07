<?php

declare(strict_types=1);

namespace App\ServiceConnections;

use App\ServiceConnections\Contracts\Connections;
use Cbox\Id\Kernel\Audit\Contracts\AuditLog;
use Cbox\Id\Kernel\Audit\Enums\ActorType;
use Cbox\Id\Kernel\Audit\ValueObjects\AuditEvent;
use Cbox\Id\Kernel\Tenancy\Contracts\TenantContext;
use Cbox\Id\Kernel\Tenancy\GenericTenant;
use Cbox\Id\OAuthServer\Contracts\ServiceAccounts;
use Cbox\Id\OAuthServer\Contracts\TokenIssuer;
use Cbox\Id\OAuthServer\Models\Client;
use Cbox\Id\OAuthServer\ValueObjects\Introspection;
use Cbox\Id\OAuthServer\ValueObjects\IssuedToken;
use Cbox\Id\Organization\Enums\MembershipStatus;
use Cbox\Id\Organization\Enums\OrganizationStatus;
use Cbox\Id\Organization\Models\Membership;
use Cbox\Id\Organization\Models\Organization;
use Illuminate\Support\Facades\DB;

/** Organization grants, never a person's refresh token or a shared cross-tenant key. */
class OrganizationConnections implements Connections
{
    public function __construct(private readonly ServiceAccounts $accounts, private readonly TokenIssuer $issuer, private readonly AuditLog $audit) {}

    public function connect(Introspection $caller, string $service): ServiceConnection
    {
        $organization = $this->administrator($caller);
        $this->consumer($caller);
        $definition = config('service-connections.services.'.$service);
        abort_unless(is_array($definition) && is_string($definition['audience'] ?? null) && is_array($definition['scopes'] ?? null), 404);
        $scopes = array_values(array_filter($definition['scopes'], is_string(...)));
        abort_if($scopes === [], 503);

        return DB::transaction(function () use ($caller, $organization, $service, $definition, $scopes): ServiceConnection {
            // Lock the stable parent, including when no grant row exists yet.
            Organization::query()->whereKey($organization)->lockForUpdate()->firstOrFail();
            $connection = ServiceConnection::query()->where('organization_id', $organization)->where('consumer_client_id', $caller->clientId)->where('service', $service)->first();
            if ($connection !== null && $connection->revoked_at === null) {
                return $connection;
            }
            $registered = $this->accounts->create($organization, 'Service connection: '.$service, $scopes);
            $connection ??= new ServiceConnection;
            $connection->fill([
                'organization_id' => $organization, 'consumer_client_id' => $caller->clientId,
                'service' => $service, 'service_client_id' => $registered->client->client_id,
                'audience' => $definition['audience'], 'scopes' => $scopes,
                'created_by' => $caller->subject, 'revoked_at' => null,
            ])->save();
            $this->record('service_connection.created', $caller, $connection);

            return $connection;
        });
    }

    public function listing(Introspection $caller): array
    {
        $organization = $this->administrator($caller);

        return array_values(ServiceConnection::query()->where('organization_id', $organization)->get()->all());
    }

    public function revoke(Introspection $caller, string $id): void
    {
        $organization = $this->administrator($caller);
        DB::transaction(function () use ($caller, $organization, $id): void {
            $connection = ServiceConnection::query()->where('organization_id', $organization)->whereKey($id)->lockForUpdate()->firstOrFail();
            if ($connection->revoked_at !== null) {
                return;
            }
            $this->accounts->retire($organization, $connection->service_client_id);
            $connection->update(['revoked_at' => now()]);
            $this->record('service_connection.revoked', $caller, $connection);
        });
    }

    public function lease(Introspection $caller, string $id): IssuedToken
    {
        $this->consumer($caller);
        abort_unless($caller->subject === $caller->clientId && $caller->actorSubject() === null, 403);

        return DB::transaction(function () use ($caller, $id): IssuedToken {
            $connection = ServiceConnection::query()->whereKey($id)->where('consumer_client_id', $caller->clientId)->whereNull('revoked_at')->lockForUpdate()->firstOrFail();
            abort_unless(Organization::query()->whereKey($connection->organization_id)->where('status', OrganizationStatus::Active)->exists(), 403);
            // Removing or changing a catalog entry immediately stops old grants.
            $definition = config('service-connections.services.'.$connection->service);
            abort_unless(is_array($definition) && ($definition['audience'] ?? null) === $connection->audience && ($definition['scopes'] ?? null) === $connection->scopes, 403);
            $client = Client::query()->where('client_id', $connection->service_client_id)->firstOrFail();
            $issued = $this->issuer->issueClientCredentials($client, $connection->scopes, $connection->audience);
            abort_unless($issued->audience === $connection->audience
                && array_diff($issued->scopes, $connection->scopes) === []
                && array_diff($connection->scopes, $issued->scopes) === [], 503);
            $this->record('service_connection.token.leased', $caller, $connection);

            return $issued;
        });
    }

    public function inspect(Introspection $caller, string $id): ?ServiceConnection
    {
        abort_unless($caller->clientId !== null && $caller->subject === $caller->clientId && $caller->actorSubject() === null, 403);
        $connection = ServiceConnection::query()->whereKey($id)->first();
        if ($connection === null) {
            return null;
        }
        $definition = config('service-connections.services.'.$connection->service);
        abort_unless(is_array($definition) && (($definition['client_id'] ?? null) === $caller->clientId || $connection->consumer_client_id === $caller->clientId), 403);
        if ($connection->revoked_at !== null || ! in_array($connection->consumer_client_id, config()->array('service-connections.consumers'), true)
            || ($definition['audience'] ?? null) !== $connection->audience || ($definition['scopes'] ?? null) !== $connection->scopes
            || ! Client::query()->where('client_id', $connection->service_client_id)->exists()
            || ! Client::query()->where('client_id', $connection->consumer_client_id)->exists()
            || ! Organization::query()->whereKey($connection->organization_id)->where('status', OrganizationStatus::Active)->exists()) {
            return null;
        }

        return $connection;
    }

    private function consumer(Introspection $caller): void
    {
        abort_unless($caller->clientId !== null && in_array($caller->clientId, config()->array('service-connections.consumers'), true), 403);
        // Registration belongs to this environment. A deleted client never retains its grant.
        abort_unless(Client::query()->where('client_id', $caller->clientId)->exists(), 403);
    }

    private function administrator(Introspection $caller): string
    {
        $org = $caller->claims['org'] ?? null;
        abort_unless(is_string($org) && $org !== '' && $caller->subject !== null && $caller->subject !== $caller->clientId && $caller->actorSubject() === null, 403);
        abort_unless(Organization::query()->whereKey($org)->where('status', OrganizationStatus::Active)->exists(), 403);
        $membership = app(TenantContext::class)->runAs(GenericTenant::of($org), fn () => Membership::query()->where('user_id', $caller->subject)->where('status', MembershipStatus::Active)->first());
        abort_unless($membership instanceof Membership && $membership->role->canManageOrganization(), 403);

        return $org;
    }

    private function record(string $action, Introspection $caller, ServiceConnection $connection): void
    {
        $this->audit->record(new AuditEvent(action: $action, actorType: $caller->subject === $caller->clientId ? ActorType::Service : ActorType::OrganizationMember, actorId: $caller->subject,
            organizationId: $connection->organization_id, targetType: 'service_connection', targetId: $connection->id,
            context: ['consumer_client_id' => $caller->clientId, 'service' => $connection->service]));
    }
}
