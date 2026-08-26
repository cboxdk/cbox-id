<?php

declare(strict_types=1);

namespace App\Http\Controllers\Console;

use Cbox\Id\Federation\Models\Connection;
use Cbox\Id\Federation\Models\VerifiedDomain;
use Cbox\Id\Identity\Models\Session;
use Cbox\Id\Identity\Models\User;
use Cbox\Id\Kernel\Tenancy\Contracts\EnvironmentContext;
use Cbox\Id\Kernel\Tenancy\Contracts\TenantContext;
use Cbox\Id\OAuthServer\Models\Client;
use Cbox\Id\Organization\Models\Environment;
use Cbox\Id\Organization\Models\Membership;
use Cbox\Id\Organization\Models\Organization;
use Illuminate\Database\Eloquent\Builder;
use Inertia\Response;

/**
 * PLATFORM › USAGE — the operator's one view of the whole estate, ABOVE the plane the
 * console is currently pinned to.
 *
 * Every countable model is environment-owned, so an ordinary query only ever sees the pinned
 * plane. Operators legitimately span planes, so the whole read runs inside
 * {@see EnvironmentContext::withoutScope()} — the provisioning-only escape that suspends the
 * hard environment scope — letting one set of aggregates reach every plane at once.
 * `Membership` is ALSO tenant-owned, so the top-tenant roll-up additionally suspends the
 * tenant scope, exactly as the cross-plane search does.
 *
 * STRICTLY READ-ONLY, and it never switches the console. A top-tenant row's "View" hands off
 * to the jump route, which re-points the console at that tenant's OWN plane before opening
 * its (plane-scoped) detail page.
 */
final readonly class PlatformUsageController extends ConsoleController
{
    /** How many tenants the roll-up ranks. A leaderboard, not a directory. */
    private const TOP = 10;

    public function __invoke(EnvironmentContext $environments, TenantContext $tenants): Response
    {
        // 404, not 403: the platform console does not confirm to a stranger that this
        // deployment has a staff console at that address.
        abort_unless($this->scope->isPlatformOperator(), 404);

        $data = $environments->withoutScope(function () use ($tenants): array {
            // One [id => Environment] list for every plane, resolved once.
            $planes = Environment::query()->orderBy('created_at')->get(['id', 'name', 'slug']);

            // Per-environment breakdown — one GROUPED aggregate per metric, never a query
            // per plane, then joined against the plane list in memory.
            $orgByEnvironment = $this->countByEnvironment(Organization::query());
            $userByEnvironment = $this->countByEnvironment(User::query());
            $sessionByEnvironment = $this->countByEnvironment($this->activeSessions());

            /*
             * Top tenants by member count — a single grouped aggregate. `Membership` is
             * tenant-owned, so the tenant scope is suspended too (the environment scope is
             * already suspended by the enclosing call) to count across every tenant.
             */
            $memberCounts = $tenants->withoutScope(
                static fn () => Membership::query()
                    ->selectRaw('organization_id, count(*) as aggregate')
                    ->groupBy('organization_id')
                    ->orderByDesc('aggregate')
                    ->limit(self::TOP)
                    ->pluck('aggregate', 'organization_id')
            );

            $organizations = Organization::query()
                ->whereIn('id', array_map('strval', array_keys($memberCounts->all())))
                ->get(['id', 'name', 'environment_id'])
                ->keyBy('id');

            $planeById = $planes->keyBy('id');
            $top = [];

            foreach ($memberCounts as $organizationId => $count) {
                $organization = $organizations->get((string) $organizationId);

                if ($organization === null) {
                    continue;
                }

                $environmentId = $organization->getAttribute('environment_id');
                $plane = is_string($environmentId) ? $planeById->get($environmentId) : null;

                $top[] = [
                    'id' => $organization->id,
                    'name' => $organization->name,
                    // Named rather than left blank: a tenant whose plane cannot be resolved
                    // is a thing an operator should be able to see, not a gap in a table.
                    'plane' => $plane->name ?? 'Unknown plane',
                    'members' => is_numeric($count) ? (int) $count : 0,
                    'href' => route('platform.search.jump', $organization->id),
                ];
            }

            return [
                'totals' => [
                    'environments' => $planes->count(),
                    'organizations' => Organization::query()->count(),
                    'users' => User::query()->count(),
                    'sessions' => $this->activeSessions()->count(),
                    'connections' => Connection::query()->count(),
                    'domains' => VerifiedDomain::query()->count(),
                    'clients' => Client::query()->count(),
                ],
                'breakdown' => $planes->map(fn (Environment $environment): array => [
                    'id' => $environment->id,
                    'name' => $environment->name,
                    'slug' => $environment->slug,
                    'organizations' => $orgByEnvironment[$environment->id] ?? 0,
                    'users' => $userByEnvironment[$environment->id] ?? 0,
                    'sessions' => $sessionByEnvironment[$environment->id] ?? 0,
                ])->all(),
                'topOrganizations' => $top,
            ];
        });

        return $this->page('console/platform/usage', 'Usage', $data);
    }

    /**
     * A non-revoked, non-expired session — the platform's definition of "active".
     *
     * @return Builder<Session>
     */
    private function activeSessions(): Builder
    {
        return Session::query()
            ->whereNull('revoked_at')
            ->where('expires_at', '>', now());
    }

    /**
     * Grouped COUNT(*) by `environment_id`, as a plain map. ONE query for the whole estate —
     * never a query per plane.
     *
     * @template TModel of \Illuminate\Database\Eloquent\Model
     *
     * @param  Builder<TModel>  $query
     * @return array<string, int>
     */
    private function countByEnvironment(Builder $query): array
    {
        $counts = [];

        $rows = $query->selectRaw('environment_id, count(*) as aggregate')
            ->groupBy('environment_id')
            ->pluck('aggregate', 'environment_id');

        foreach ($rows as $environmentId => $count) {
            if (is_string($environmentId) && is_numeric($count)) {
                $counts[$environmentId] = (int) $count;
            }
        }

        return $counts;
    }
}
