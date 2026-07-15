<?php

declare(strict_types=1);

use App\Platform\OperatorAuth;
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
use Livewire\Attributes\Layout;
use Livewire\Volt\Component;

/**
 * Platform-wide usage overview — the operator's one place to see the whole estate
 * at a glance, ABOVE the plane the console is currently pinned to.
 *
 * Every countable model is environment-owned, so an ordinary query only ever sees
 * the pinned plane. Operators legitimately span planes, so the whole read runs
 * inside {@see EnvironmentContext::withoutScope()} — the provisioning-only escape
 * that suspends the hard environment scope — letting a single set of aggregate
 * queries reach every plane at once. Membership is ALSO tenant-owned, so the
 * top-tenant roll-up additionally suspends the tenant scope
 * ({@see TenantContext::withoutScope()}) exactly as the cross-plane search does.
 *
 * The screen is strictly read-only and never switches the console; a top-tenant
 * row's "View" hands off to the existing jump route, which re-points the console
 * at that tenant's OWN plane before opening its (plane-scoped) detail page.
 */
new #[Layout('components.layouts.operator', ['title' => 'Usage'])] class extends Component
{
    /** Re-check operator auth on every request, including Livewire actions. */
    public function boot(OperatorAuth $auth): void
    {
        abort_unless($auth->check(), 403);
    }

    public function with(EnvironmentContext $environments, TenantContext $tenants): array
    {
        return $environments->withoutScope(function () use ($tenants): array {
            // One [id => Environment] list for every plane, resolved once.
            $planes = Environment::query()->orderBy('created_at')->get(['id', 'name', 'slug']);

            // Headline totals — summed across every plane by the suspended scope.
            $totals = [
                'environments' => $planes->count(),
                'organizations' => Organization::query()->count(),
                'users' => User::query()->count(),
                'sessions' => $this->activeSessions()->count(),
                'connections' => Connection::query()->count(),
                'domains' => VerifiedDomain::query()->count(),
                'clients' => Client::query()->count(),
            ];

            // Per-environment breakdown — one grouped aggregate per metric (no query
            // per plane), then joined against the plane list in memory.
            $orgByEnv = $this->countByEnvironment(Organization::query());
            $userByEnv = $this->countByEnvironment(User::query());
            $sessionByEnv = $this->countByEnvironment($this->activeSessions());

            $breakdown = $planes->map(fn (Environment $env): array => [
                'name' => $env->name,
                'slug' => $env->slug,
                'organizations' => $orgByEnv[$env->id] ?? 0,
                'users' => $userByEnv[$env->id] ?? 0,
                'sessions' => $sessionByEnv[$env->id] ?? 0,
            ])->all();

            // Top tenants by member count — a single grouped aggregate. Membership is
            // tenant-owned, so suspend the tenant scope too (the env scope is already
            // suspended by the enclosing withoutScope) to count across every tenant.
            $memberCounts = $tenants->withoutScope(
                static fn () => Membership::query()
                    ->selectRaw('organization_id, count(*) as aggregate')
                    ->groupBy('organization_id')
                    ->orderByDesc('aggregate')
                    ->limit(10)
                    ->pluck('aggregate', 'organization_id')
            );

            $orgIds = array_map('strval', array_keys($memberCounts->all()));
            $orgModels = Organization::query()
                ->whereIn('id', $orgIds)
                ->get(['id', 'name', 'environment_id'])
                ->keyBy('id');
            $planeById = $planes->keyBy('id');

            $topOrganizations = [];
            foreach ($memberCounts as $orgId => $count) {
                $org = $orgModels->get((string) $orgId);
                if ($org === null) {
                    continue;
                }

                $envId = $org->getAttribute('environment_id');
                $plane = is_string($envId) ? $planeById->get($envId) : null;

                $topOrganizations[] = [
                    'id' => $org->id,
                    'name' => $org->name,
                    'plane' => $plane?->name ?? 'Unknown plane',
                    'members' => is_numeric($count) ? (int) $count : 0,
                ];
            }

            return [
                'totals' => $totals,
                'breakdown' => $breakdown,
                'topOrganizations' => $topOrganizations,
            ];
        });
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
     * Grouped COUNT(*) by `environment_id`, as a plain [environment_id => int] map.
     * One query for the whole estate — never a query per plane.
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

        foreach ($rows as $envId => $count) {
            if ((is_string($envId) || is_int($envId)) && is_numeric($count)) {
                $counts[(string) $envId] = (int) $count;
            }
        }

        return $counts;
    }
}; ?>

<div>
    <div class="cbx-page-header">
        <div>
            <p class="cbx-page-eyebrow">Platform</p>
            <h1 class="cbx-page-title">Usage</h1>
            <p class="cbx-page-desc">Platform-wide usage across every environment — above the plane the console is currently pinned to.</p>
        </div>
    </div>

    {{-- Headline totals --}}
    <div class="grid gap-3 grid-cols-2 sm:grid-cols-3 lg:grid-cols-4 mb-5 mt-8">
        @php
            $tiles = [
                ['label' => 'Environments', 'value' => $totals['environments']],
                ['label' => 'Organizations', 'value' => $totals['organizations']],
                ['label' => 'Users', 'value' => $totals['users']],
                ['label' => 'Active sessions', 'value' => $totals['sessions']],
                ['label' => 'SSO connections', 'value' => $totals['connections']],
                ['label' => 'Verified domains', 'value' => $totals['domains']],
                ['label' => 'API clients', 'value' => $totals['clients']],
            ];
        @endphp
        @foreach ($tiles as $tile)
            <div class="cbx-stat">
                <div class="min-w-0">
                    <p class="cbx-stat-value">{{ number_format($tile['value']) }}</p>
                    <p class="cbx-stat-label">{{ $tile['label'] }}</p>
                </div>
            </div>
        @endforeach
    </div>

    {{-- Per-environment breakdown --}}
    <div class="cbx-panel overflow-hidden mb-5">
        <div class="cbx-panel-header">
            <h3 class="cbx-panel-title">Per-environment breakdown</h3>
            <span class="text-xs" style="color:var(--faint)">{{ count($breakdown) }} {{ count($breakdown) === 1 ? 'plane' : 'planes' }}</span>
        </div>
        <div class="hidden sm:grid px-5 py-2 border-b text-xs font-medium uppercase tracking-wide"
             style="border-color:var(--border);color:var(--faint);grid-template-columns:2.5fr 1fr 1fr 1fr">
            <span>Environment</span><span class="text-right">Organizations</span><span class="text-right">Users</span><span class="text-right">Active sessions</span>
        </div>
        @forelse ($breakdown as $env)
            <div class="px-5 py-3 border-b flex flex-col gap-1 sm:grid sm:items-center sm:gap-4"
                 style="border-color:var(--border);grid-template-columns:2.5fr 1fr 1fr 1fr">
                <div class="min-w-0">
                    <p class="text-sm font-medium truncate">{{ $env['name'] }}</p>
                    <p class="text-xs font-mono truncate" style="color:var(--faint)">{{ $env['slug'] }}</p>
                </div>
                <div class="text-sm sm:text-right tabular-nums"><span class="sm:hidden" style="color:var(--faint)">Organizations: </span>{{ number_format($env['organizations']) }}</div>
                <div class="text-sm sm:text-right tabular-nums"><span class="sm:hidden" style="color:var(--faint)">Users: </span>{{ number_format($env['users']) }}</div>
                <div class="text-sm sm:text-right tabular-nums"><span class="sm:hidden" style="color:var(--faint)">Active sessions: </span>{{ number_format($env['sessions']) }}</div>
            </div>
        @empty
            <div class="px-5 py-8 text-center text-sm" style="color:var(--faint)">No environments provisioned yet.</div>
        @endforelse
    </div>

    {{-- Top tenants by member count --}}
    <div class="cbx-panel overflow-hidden">
        <div class="cbx-panel-header">
            <h3 class="cbx-panel-title">Top organizations by members</h3>
            <span class="text-xs" style="color:var(--faint)">Across every plane</span>
        </div>
        <div class="hidden sm:grid px-5 py-2 border-b text-xs font-medium uppercase tracking-wide"
             style="border-color:var(--border);color:var(--faint);grid-template-columns:2.5fr 1.4fr 1fr auto">
            <span>Organization</span><span>Plane</span><span class="text-right">Members</span><span></span>
        </div>
        @forelse ($topOrganizations as $org)
            <div class="px-5 py-3 border-b flex flex-col gap-2 sm:grid sm:items-center sm:gap-4"
                 style="border-color:var(--border);grid-template-columns:2.5fr 1.4fr 1fr auto">
                <div class="min-w-0">
                    <p class="text-sm font-semibold truncate">{{ $org['name'] }}</p>
                </div>
                <div>
                    <span class="cbx-pill cbx-pill--info" title="Environment">
                        <x-icon name="layers" class="w-3 h-3" aria-hidden="true" /> {{ $org['plane'] }}
                    </span>
                </div>
                <div class="text-sm sm:text-right tabular-nums"><span class="sm:hidden" style="color:var(--faint)">Members: </span>{{ number_format($org['members']) }}</div>
                <div class="sm:justify-self-end">
                    <a href="{{ route('operator.search.jump', $org['id']) }}" class="btn btn-ghost btn-sm">View</a>
                </div>
            </div>
        @empty
            <div class="px-5 py-8 text-center text-sm" style="color:var(--faint)">No organizations with members yet.</div>
        @endforelse
    </div>
</div>
