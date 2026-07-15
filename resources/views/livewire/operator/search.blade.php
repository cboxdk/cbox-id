<?php

declare(strict_types=1);

use App\Platform\OperatorAuth;
use Cbox\Id\Identity\Models\User;
use Cbox\Id\Kernel\Tenancy\Contracts\EnvironmentContext;
use Cbox\Id\Kernel\Tenancy\Contracts\TenantContext;
use Cbox\Id\Organization\Models\Environment;
use Cbox\Id\Organization\Models\Membership;
use Cbox\Id\Organization\Models\Organization;
use Illuminate\Support\Facades\DB;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Url;
use Livewire\Volt\Component;

/**
 * Cross-tenant search — the operator's one place to find an organization or a user
 * across EVERY environment, above the plane the console is currently pinned to.
 *
 * Organizations and users are environment-owned, so an ordinary query only ever
 * sees the current plane. Operators legitimately span planes, so the search runs
 * inside {@see EnvironmentContext::withoutScope()} — the provisioning-only escape
 * that suspends the hard environment scope — letting a single query reach every
 * plane. Each row carries its own environment_id, resolved to a human plane label.
 *
 * The screen itself never mutates and never switches the console; a result's
 * "View" hands off to a small controller jump that re-points the console at the
 * result's OWN plane first, so the plane-scoped detail page then resolves.
 */
new #[Layout('components.layouts.operator', ['title' => 'Search'])] class extends Component
{
    /** The query string, bound to the URL so a search is shareable/bookmarkable. */
    #[Url]
    public string $term = '';

    /** Below this length we show a hint instead of running a query. */
    private const MIN_TERM = 2;

    /** Per-kind result cap — a broad term can't blow up the page or the query. */
    private const RESULT_CAP = 25;

    /** Re-check operator auth on every request, including Livewire actions. */
    public function boot(OperatorAuth $auth): void
    {
        abort_unless($auth->check(), 403);
    }

    public function with(EnvironmentContext $environments, TenantContext $tenants): array
    {
        $term = trim($this->term);

        if (mb_strlen($term) < self::MIN_TERM) {
            return ['term' => $term, 'ready' => false, 'organizations' => [], 'users' => []];
        }

        // Escape LIKE wildcards so a literal % or _ in the term can't act as one,
        // then lower-case for case-insensitive matching across drivers.
        $like = '%'.addcslashes(mb_strtolower($term), '%_\\').'%';

        /** @var array{organizations: list<array<string, mixed>>, users: list<array<string, mixed>>} $results */
        $results = $environments->withoutScope(function () use ($like, $tenants): array {
            // One [id => Environment] map for every plane label, resolved once.
            $planes = Environment::query()->get(['id', 'name', 'slug'])->keyBy('id');

            // Organizations — name/slug match, across every plane.
            $orgs = Organization::query()
                ->whereRaw($this->likeSql('name'), [$like])
                ->orWhereRaw($this->likeSql('slug'), [$like])
                ->orderBy('name')
                ->limit(self::RESULT_CAP)
                ->get(['id', 'name', 'slug', 'status', 'environment_id']);

            $organizations = $orgs->map(function (Organization $org) use ($planes): array {
                $envId = $org->getAttribute('environment_id');
                $plane = is_string($envId) ? $planes->get($envId) : null;

                return [
                    'id' => $org->id,
                    'name' => $org->name,
                    'slug' => $org->slug,
                    'status' => $org->status->value,
                    'plane' => $plane?->name ?? 'Unknown plane',
                ];
            })->values()->all();

            // Users — email/name match, across every plane.
            $userModels = User::query()
                ->whereRaw($this->likeSql('email'), [$like])
                ->orWhereRaw($this->likeSql('name'), [$like])
                ->orderBy('email')
                ->limit(self::RESULT_CAP)
                ->get(['id', 'name', 'email', 'environment_id']);

            $userIds = $userModels->pluck('id')->all();

            // Membership is ALSO tenant-owned, so its tenant scope is deny-by-default
            // when the console pins no tenant. Suspend that scope too (env scope is
            // already suspended by the enclosing withoutScope) to read each user's
            // org membership for context — best-effort, purely to label the result.
            $membershipsByUser = $tenants->withoutScope(
                static fn () => Membership::query()
                    ->whereIn('user_id', $userIds)
                    ->get(['user_id', 'organization_id'])
            )->groupBy('user_id');

            $orgIds = $membershipsByUser->flatten(1)
                ->pluck('organization_id')->unique()->values()->all();
            $membershipOrgs = Organization::query()
                ->whereIn('id', $orgIds)
                ->get(['id', 'name'])
                ->keyBy('id');

            $users = $userModels->map(function (User $user) use ($planes, $membershipsByUser, $membershipOrgs): array {
                $plane = $planes->get($user->environment_id);

                $orgs = [];
                $userMemberships = $membershipsByUser->get($user->id);
                if ($userMemberships !== null) {
                    foreach ($userMemberships as $membership) {
                        $org = $membershipOrgs->get($membership->organization_id);
                        if ($org !== null) {
                            $orgs[] = ['id' => $org->id, 'name' => $org->name];
                        }
                    }
                }

                return [
                    'id' => $user->id,
                    'name' => $user->name,
                    'email' => $user->email,
                    'plane' => $plane?->name ?? 'Unknown plane',
                    'organizations' => $orgs,
                ];
            })->values()->all();

            return ['organizations' => $organizations, 'users' => $users];
        });

        return [
            'term' => $term,
            'ready' => true,
            'organizations' => $results['organizations'],
            'users' => $results['users'],
        ];
    }

    /**
     * The LIKE expression for a known column, case-insensitive and wildcard-safe.
     *
     * SQLite has no default LIKE escape character, so it must be declared; MySQL and
     * Postgres already treat backslash as the default LIKE escape (and parse their
     * string literals differently), so only SQLite gets the explicit clause.
     */
    private function likeSql(string $column): string
    {
        $escape = DB::connection()->getDriverName() === 'sqlite' ? " escape '\\'" : '';

        return "lower({$column}) like ?".$escape;
    }
}; ?>

<div>
    <x-page-header title="Search"
                   subtitle="Find an organization or a user across every environment — above the plane the console is currently pinned to.">
    </x-page-header>

    <div class="card p-4 mb-5">
        <label class="label" for="search-term">Search term</label>
        <div class="relative">
            <span class="absolute inset-y-0 left-0 flex items-center pl-3" style="color:var(--faint)" aria-hidden="true">
                <x-icon name="search" class="w-4 h-4" />
            </span>
            <input wire:model.live.debounce.300ms="term" id="search-term" type="search" class="input" style="padding-left:2.25rem"
                   placeholder="Name, slug or email…" autofocus autocomplete="off"
                   aria-describedby="search-hint">
        </div>
        <p id="search-hint" class="mt-2 text-xs" style="color:var(--faint)">
            Matches organization name/slug and user email/name. Case-insensitive; literal % and _ are not wildcards.
        </p>
    </div>

    @if (! $ready)
        <div class="card px-5 py-10 text-center text-sm" style="color:var(--faint)">
            Type at least two characters to search organizations and users across all environments.
        </div>
    @else
        {{-- Organizations --}}
        <div class="card overflow-hidden mb-5">
            <div class="px-5 py-3 border-b flex items-center justify-between" style="border-color:var(--border)">
                <h3 class="text-sm font-semibold">Organizations</h3>
                <span class="text-xs" style="color:var(--faint)">{{ count($organizations) }} match{{ count($organizations) === 1 ? '' : 'es' }}</span>
            </div>
            @forelse ($organizations as $org)
                <div class="px-5 py-3 border-b flex flex-col gap-2 sm:grid sm:items-center sm:gap-4"
                     style="border-color:var(--border);grid-template-columns:2.5fr 1.4fr auto">
                    <div class="min-w-0">
                        <p class="text-sm font-semibold truncate">
                            {{ $org['name'] }}
                            @if ($org['status'] === 'suspended')
                                <span class="badge badge-danger align-middle ml-1">Suspended</span>
                            @endif
                        </p>
                        <p class="text-xs font-mono truncate" style="color:var(--faint)">{{ $org['slug'] }}</p>
                    </div>
                    <div>
                        <span class="badge" title="Environment">
                            <x-icon name="layers" class="w-3 h-3" style="margin-right:.25rem" aria-hidden="true" /> {{ $org['plane'] }}
                        </span>
                    </div>
                    <div class="sm:justify-self-end">
                        <a href="{{ route('operator.search.jump', $org['id']) }}" class="btn btn-ghost btn-sm">View</a>
                    </div>
                </div>
            @empty
                <div class="px-5 py-8 text-center text-sm" style="color:var(--faint)">No organizations match “{{ $term }}”.</div>
            @endforelse
        </div>

        {{-- Users --}}
        <div class="card overflow-hidden">
            <div class="px-5 py-3 border-b flex items-center justify-between" style="border-color:var(--border)">
                <h3 class="text-sm font-semibold">Users</h3>
                <span class="text-xs" style="color:var(--faint)">{{ count($users) }} match{{ count($users) === 1 ? '' : 'es' }}</span>
            </div>
            @forelse ($users as $user)
                <div class="px-5 py-3 border-b flex flex-col gap-2 sm:grid sm:items-center sm:gap-4"
                     style="border-color:var(--border);grid-template-columns:2.5fr 1.4fr auto">
                    <div class="min-w-0">
                        <p class="text-sm font-medium truncate">{{ $user['email'] }}</p>
                        <p class="text-xs truncate" style="color:var(--faint)">
                            {{ $user['name'] ?? '—' }}
                            @if (count($user['organizations']) > 0)
                                · {{ collect($user['organizations'])->pluck('name')->implode(', ') }}
                            @endif
                        </p>
                    </div>
                    <div>
                        <span class="badge" title="Environment">
                            <x-icon name="layers" class="w-3 h-3" style="margin-right:.25rem" aria-hidden="true" /> {{ $user['plane'] }}
                        </span>
                    </div>
                    <div class="sm:justify-self-end">
                        @if (count($user['organizations']) > 0)
                            <a href="{{ route('operator.search.jump', $user['organizations'][0]['id']) }}" class="btn btn-ghost btn-sm">View</a>
                        @else
                            <span class="text-xs" style="color:var(--faint)">No organization</span>
                        @endif
                    </div>
                </div>
            @empty
                <div class="px-5 py-8 text-center text-sm" style="color:var(--faint)">No users match “{{ $term }}”.</div>
            @endforelse
        </div>
    @endif
</div>
