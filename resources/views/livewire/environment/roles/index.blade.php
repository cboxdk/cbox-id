<?php

declare(strict_types=1);

use App\Platform\EnvironmentAdminAuth;
use Cbox\Id\AccessControl\Enums\RoleSource;
use Cbox\Id\AccessControl\Models\Role;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Url;
use Livewire\Volt\Component;
use Livewire\WithPagination;

/**
 * Environment control plane › Roles (list). Every role this environment owns — the
 * roles an admin authors here plus the read-only ones an application declares through
 * its manifest (the Role model's BelongsToEnvironment scope keeps the query on this
 * plane). Each row deep-links to the role's own detail page, where its permissions
 * are edited; creation is a dedicated page. No inline forms — the list only lists.
 */
new #[Layout('components.layouts.environment', ['title' => 'Roles'])] class extends Component
{
    /**
     * Second layer. The route's `env.admin` middleware is the primary gate and IS
     * re-run on Livewire actions (PersistentMiddlewareTest holds that), but this
     * console previously had NO in-component authorization at all — so when that
     * middleware was missing from the persistent list, every action here answered
     * unauthenticated. boot() rather than mount(): only boot() runs on each action.
     */
    public function boot(): void
    {
        abort_if(app(EnvironmentAdminAuth::class)->current() === null, 403);
    }

    use WithPagination;

    #[Url(as: 'q')]
    public string $search = '';

    public function updatingSearch(): void
    {
        $this->resetPage();
    }

    /**
     * @return array<string, mixed>
     */
    public function with(): array
    {
        $query = Role::query()->orderBy('name');

        $term = trim($this->search);
        if ($term !== '') {
            $query->where('name', 'like', "%{$term}%");
        }

        $roles = $query->paginate(25);

        /** @var Collection<string, int> $counts */
        $counts = DB::table('role_permission')
            ->whereIn('role_id', $roles->pluck('id'))
            ->selectRaw('role_id, count(*) as aggregate')
            ->groupBy('role_id')
            ->pluck('aggregate', 'role_id');

        return ['roles' => $roles, 'counts' => $counts, 'manifest' => RoleSource::Manifest];
    }
}; ?>

<div>
    <x-page-header title="Roles" subtitle="What people can do inside this environment's apps. Assign roles to people; a role is a bundle of permissions.">
        <x-slot:actions>
            <a href="{{ route('environment.roles.create') }}" class="btn btn-primary shrink-0"><x-icon name="plus" class="w-4 h-4" /> New role</a>
        </x-slot:actions>
    </x-page-header>

    <div class="mt-6">
        <input wire:model.live.debounce.300ms="search" type="search" class="input" style="max-width:24rem" placeholder="Search by name">
    </div>

    <div class="mt-4 rounded-xl border overflow-hidden" style="border-color:var(--border)">
        @forelse ($roles as $role)
            @php $count = (int) ($counts[$role->id] ?? 0); @endphp
            <a href="{{ route('environment.roles.show', $role->id) }}"
               class="flex items-center gap-3 p-4 transition-colors hover:bg-[var(--surface-2)] {{ ! $loop->last ? 'border-b' : '' }}" style="border-color:var(--border)">
                <div class="min-w-0 flex-1">
                    <span class="font-medium truncate">{{ $role->name }}</span>
                    @if ($role->description)
                        <p class="text-sm truncate" style="color:var(--muted)">{{ $role->description }}</p>
                    @endif
                </div>
                @if ($role->source === $manifest)
                    <span class="badge">App</span>
                @endif
                <span class="badge">{{ $count }} {{ \Illuminate\Support\Str::plural('permission', $count) }}</span>
                <x-icon name="chevron" class="w-4 h-4 shrink-0" style="color:var(--faint)" />
            </a>
        @empty
            @if (trim($search) !== '')
                <div class="cbx-empty">
                    <div class="cbx-empty-icon"><x-icon name="search" class="w-5 h-5" /></div>
                    <h3>No matches</h3>
                    <p>No roles match “{{ trim($search) }}”. Try a different name.</p>
                </div>
            @else
                <div class="cbx-empty">
                    <div class="cbx-empty-icon"><x-icon name="roles" class="w-5 h-5" /></div>
                    <h3>No roles yet</h3>
                    <p>A role is a bundle of permissions you assign to people. Create the first one to get started.</p>
                </div>
            @endif
        @endforelse
    </div>

    <div class="mt-4 max-w-full overflow-x-auto">{{ $roles->links() }}</div>
</div>
