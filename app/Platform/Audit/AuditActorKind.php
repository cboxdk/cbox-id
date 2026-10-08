<?php

declare(strict_types=1);

namespace App\Platform\Audit;

use App\Platform\EnvironmentKeyAuditLog;
use Cbox\Id\Kernel\Audit\Enums\ActorType;
use Cbox\Id\Kernel\Audit\Models\AuditEntry;
use Illuminate\Database\Eloquent\Builder;

/**
 * WHAT KIND OF THING DID IT, as the audit log's filter chips ask it.
 *
 * The stored actor type answers a narrower question — person, service, system — and it
 * cannot tell the two machine callers a reader actually separates apart. An agent holding
 * a management key and an app calling with its own credentials are both a `service`; a
 * person in the console and the same person's agent acting on their token are both a
 * `user`. The context the trail already records draws the line ({@see EnvironmentKeyAuditLog}):
 *
 *  - an AGENT is anything that came through a management key (`environment_api_key`) or a
 *    person's delegated token (`oauth_client_id`) — the console calls management keys
 *    agents, and a token an agent signed in for is that agent acting;
 *  - an API KEY is a service acting with its own credentials, no management key involved;
 *  - a HUMAN is a person acting for themselves;
 *  - SYSTEM is the platform's own doing.
 */
enum AuditActorKind: string
{
    case Human = 'human';
    case Agent = 'agent';
    case ApiKey = 'api_key';
    case System = 'system';

    private const array PEOPLE = [ActorType::User, ActorType::Operator, ActorType::OrganizationMember];

    public static function of(AuditEntry $entry): self
    {
        $context = $entry->context;

        if (isset($context[EnvironmentKeyAuditLog::CONTEXT_KEY]) || isset($context[EnvironmentKeyAuditLog::CLIENT_CONTEXT_KEY])) {
            return self::Agent;
        }

        return match ($entry->actor_type) {
            ActorType::Service => self::ApiKey,
            ActorType::System => self::System,
            default => self::Human,
        };
    }

    public function label(): string
    {
        return match ($this) {
            self::Human => 'Human',
            self::Agent => 'Agent',
            self::ApiKey => 'API key',
            self::System => 'System',
        };
    }

    /**
     * Narrow $query to entries of this kind — the same rule as {@see self::of()}, in SQL.
     *
     * @param  Builder<AuditEntry>  $query
     */
    public function constrain(Builder $query): void
    {
        $key = 'context->'.EnvironmentKeyAuditLog::CONTEXT_KEY;
        $client = 'context->'.EnvironmentKeyAuditLog::CLIENT_CONTEXT_KEY;

        match ($this) {
            self::Agent => $query->where(fn (Builder $q): Builder => $q->whereNotNull($key)->orWhereNotNull($client)),
            self::ApiKey => $query->where('actor_type', ActorType::Service->value)->whereNull($key)->whereNull($client),
            self::System => $query->where('actor_type', ActorType::System->value)->whereNull($key)->whereNull($client),
            self::Human => $query
                ->whereIn('actor_type', array_map(static fn (ActorType $type): string => $type->value, self::PEOPLE))
                ->whereNull($key)
                ->whereNull($client),
        };
    }
}
