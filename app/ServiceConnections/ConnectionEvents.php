<?php

declare(strict_types=1);

namespace App\ServiceConnections;

use App\Http\Resources\Environment\DomainEventResource;
use App\ServiceConnections\Contracts\ReadsConnectionEvents;
use Cbox\Id\Kernel\Events\Models\Event;
use Cbox\Id\Kernel\Tenancy\Contracts\EnvironmentContext;
use Cbox\Id\OAuthServer\Models\Client;
use Cbox\Id\OAuthServer\ValueObjects\Introspection;
use Cbox\Id\Organization\Enums\OrganizationStatus;
use Cbox\Id\Organization\Models\Organization;

/** Provider-side public read contract; every page revalidates its organization grant. */
class ConnectionEvents implements ReadsConnectionEvents
{
    public function __construct(private readonly EnvironmentContext $environments) {}

    public function page(Introspection $caller, string $grant, array $types, ?string $after, int $limit): array
    {
        abort_unless($caller->active && $caller->clientId !== null && $caller->subject === $caller->clientId
            && $caller->actorSubject() === null && $caller->hasScope('signals:events:read'), 403);
        $connection = ServiceConnection::query()->whereKey($grant)->where('service', 'cbox_id_events')
            ->where('service_client_id', $caller->clientId)->whereNull('revoked_at')->firstOrFail();
        $definition = config('service-connections.services.cbox_id_events');
        abort_unless(is_array($definition) && ($definition['audience'] ?? null) === $connection->audience
            && ($definition['scopes'] ?? null) === $connection->scopes && $caller->isAudience($connection->audience)
            && ($caller->claims['org'] ?? null) === $connection->organization_id
            && in_array($connection->consumer_client_id, config()->array('service-connections.consumers'), true)
            && Client::query()->where('client_id', $connection->consumer_client_id)->exists()
            && Client::query()->where('client_id', $connection->service_client_id)->exists()
            && Organization::query()->whereKey($connection->organization_id)->where('status', OrganizationStatus::Active)->exists(), 403);
        $environment = $this->environments->current()?->environmentKey();
        abort_unless($environment !== null && $environment === $connection->environment_id, 403);
        $query = Event::query()->where('environment_id', $environment)->where('organization_id', $connection->organization_id);
        if ($types !== []) {
            $query->whereIn('type', $types);
        }
        if ($after !== null) {
            $query->where('id', '>', $after);
        }
        $limit = min(100, max(1, $limit));
        $rows = $query->orderBy('id')->limit($limit + 1)->get();
        $visible = $rows->take($limit);

        return ['data' => $visible->map(DomainEventResource::from(...))->values()->all(), 'meta' => [
            'limit' => $limit, 'has_more' => $rows->count() > $limit,
            'next_cursor' => $rows->count() > $limit ? $visible->last()?->id : null,
        ]];
    }
}
