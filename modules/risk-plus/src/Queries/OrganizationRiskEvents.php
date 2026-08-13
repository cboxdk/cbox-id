<?php

declare(strict_types=1);

namespace Cbox\Id\RiskPlus\Queries;

use Cbox\Id\Identity\Models\User;
use Cbox\Id\Organization\Contracts\Memberships;
use Cbox\Id\RiskPlus\Models\RiskEvent;
use Illuminate\Database\Eloquent\Builder;

/**
 * Risk events narrowed to one organization's members — the one implementation of that
 * question, for the console page and the dashboard card that both ask it.
 *
 * `RiskEvent` carries no organization, only an email, so the narrowing has to go through
 * membership. Matching on the address is the narrowest thing available without a schema
 * change: an event for an address belonging to nobody in the organization is not shown at
 * all, which is the correct direction — an organization admin has no standing to see a
 * stranger's failed sign-in.
 *
 * WHY THE MEMBERSHIP IDS ARE FETCHED THROUGH THE CONTRACT rather than left as a subquery.
 * `Membership` is `TenantOwned`, and `TenantScope` appends `whereRaw('1 = 0')` when no
 * tenant is in context — and nothing in this console ever sets `TenantContext`. So
 * `Membership::query()->select('user_id')` as a subquery matched NOTHING, the outer
 * `whereIn` matched nothing, and the page reported "No elevated risk events yet" to an
 * organization under credential stuffing. A filter that fails to a blank screen rather
 * than to a refusal, and one resting on ambient state a security decision should not rest
 * on. The sibling devices module measured the same trap and writes it down at
 * `modules/devices/resources/views/livewire/devices/index.blade.php`; this module had
 * copied the intent and not the mechanism.
 *
 * The `User` subquery below is deliberately left as a subquery: its scope is the
 * ENVIRONMENT, which this console always has, and which is the bound we want.
 */
final class OrganizationRiskEvents
{
    public function __construct(private readonly Memberships $memberships) {}

    /**
     * Every risk event whose address belongs to a member of `$organizationId`.
     *
     * @return Builder<RiskEvent>
     */
    public function query(string $organizationId): Builder
    {
        // IDS ONLY. This hydrated a model per member of the organization to reduce it to
        // a list of ids that is then thrown away. The subquery a reader would reach for
        // instead is not available: `memberships` is tenant-owned, so an unwrapped one
        // meets `TenantScope`'s deny-by-default trap and matches nothing.
        $memberIds = $this->memberships->userIdsForOrganization($organizationId);

        return RiskEvent::query()
            ->whereNotNull('email')
            ->whereIn('email', User::query()->select('email')->whereIn('id', $memberIds));
    }
}
