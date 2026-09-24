<?php

declare(strict_types=1);

namespace App\Platform\ApiKeys;

use App\Platform\ApiKeys\ValueObjects\KeyPresence;
use Cbox\Id\Kernel\Tenancy\Contracts\TenantContext;
use Cbox\Id\Kernel\Tenancy\GenericTenant;
use Cbox\Id\OAuthServer\Models\Client;
use Cbox\Id\Organization\Models\CustomerApiKey;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Query\Builder as QueryBuilder;
use Illuminate\Support\Facades\DB;

/**
 * Whether the two API key pages belong on the rail, answered in ONE statement per request.
 *
 * The rail is drawn on every console page, and more than once per request, so its gates
 * are part of every page's query budget. Three EXISTS probes — an app offering keys, a key
 * in the organization, a key held by this person — are one SELECT here, and the answer is
 * kept for the rest of the request. Bound `scoped`, so it never outlives the request it was
 * asked in (a queue worker gets a fresh one per job).
 *
 * The rail only decides whether to offer a link. Each page authorizes its own requests.
 */
final class ApiKeyPresence
{
    /** @var array<string, KeyPresence> */
    private array $answered = [];

    public function for(string $organizationId, string $userId): KeyPresence
    {
        return $this->answered[$organizationId.'|'.$userId] ??= $this->ask($organizationId, $userId);
    }

    private function ask(string $organizationId, string $userId): KeyPresence
    {
        // Built INSIDE the organization's tenant: each probe's SQL is compiled on the spot,
        // with the environment and tenant scopes its model carries, and the organization is
        // named in the WHERE clause as well.
        $query = app(TenantContext::class)->runAs(
            GenericTenant::of($organizationId),
            fn (): QueryBuilder => DB::query()
                ->selectSub($this->probe(Client::query()
                    ->whereNotNull('api_key_prefix')
                    ->where(fn (Builder $query) => $query
                        ->whereNull('organization_id')
                        ->orWhere('organization_id', $organizationId))), 'offered')
                ->selectSub($this->probe(CustomerApiKey::query()
                    ->where('organization_id', $organizationId)), 'in_organization')
                ->selectSub($this->probe(CustomerApiKey::query()
                    ->where('organization_id', $organizationId)
                    ->where('user_id', $userId)), 'held'),
        );

        $row = $query->first();

        if (! is_object($row)) {
            return new KeyPresence;
        }

        return new KeyPresence(
            offered: ($row->offered ?? null) !== null,
            inOrganization: ($row->in_organization ?? null) !== null,
            held: ($row->held ?? null) !== null,
        );
    }

    /**
     * `(SELECT 1 … LIMIT 1)`: one when a row exists, NULL when none does — the portable
     * spelling of EXISTS as a column, on every engine the app runs on.
     *
     * @template TModel of \Illuminate\Database\Eloquent\Model
     *
     * @param  Builder<TModel>  $query
     */
    private function probe(Builder $query): QueryBuilder
    {
        return $query->toBase()->select(DB::raw('1'))->limit(1);
    }
}
