<?php

declare(strict_types=1);

use Cbox\Id\AccessControl\Contracts\Roles;
use Cbox\Id\AccessControl\Models\Permission;
use Illuminate\Support\Facades\DB;
use Livewire\Attributes\Layout;
use Livewire\Volt\Component;

/**
 * Environment control plane › Roles › New. A dedicated, deep-linkable create page.
 * The role is authored here (environment-wide, source = manual) with an optional
 * starting set of permissions ticked from the declared catalog. On success we route
 * straight to the new role's detail page, where its permissions are edited.
 */
new #[Layout('components.layouts.environment', ['title' => 'New role'])] class extends Component
{
    public string $name = '';

    public string $description = '';

    /** @var list<string> */
    public array $permissions = [];

    public function create(Roles $roles): mixed
    {
        $data = $this->validate([
            'name' => ['required', 'string', 'max:120'],
            'description' => ['nullable', 'string', 'max:500'],
            'permissions' => ['array'],
            'permissions.*' => ['string'],
        ]);

        // An environment-owned role: no organization (reusable across every tenant in
        // this plane) and no client_id (admin-authored, not an app manifest). define()
        // is idempotent on (organization, client, name); BelongsToEnvironment stamps
        // the environment column on create.
        $role = $roles->define(
            null,
            trim($data['name']),
            trim($this->description) !== '' ? trim($this->description) : null,
            null,
        );

        // Seed the initial permissions. Resolve the posted ids against the real,
        // non-orphaned catalog first — an id that matches nothing is never trusted.
        $ids = Permission::query()->whereKey($this->permissions)->whereNull('orphaned_at')->pluck('id');
        foreach ($ids as $permissionId) {
            DB::table('role_permission')->insertOrIgnore(['role_id' => $role->id, 'permission_id' => $permissionId]);
        }

        session()->flash('status', 'Role created.');

        return $this->redirectRoute('environment.roles.show', ['role' => $role->id], navigate: true);
    }

    /**
     * @return array<string, mixed>
     */
    public function with(): array
    {
        $catalog = Permission::query()->whereNull('orphaned_at')->orderBy('name')->get(['id', 'name', 'description', 'client_id']);

        return [
            'catalog' => $catalog,
            'appNames' => \Cbox\Id\OAuthServer\Models\Client::query()
                ->whereIn('client_id', $catalog->pluck('client_id')->filter()->unique()->all())
                ->pluck('name', 'client_id'),
        ];
    }
}; ?>

<div>
    <a href="{{ route('environment.roles') }}" class="text-sm inline-flex items-center gap-1" style="color:var(--muted)"><x-icon name="chevron" class="w-3.5 h-3.5 rotate-180" /> Roles</a>
    <x-page-header class="mt-2" title="New role" subtitle="A bundle of permissions you can assign to people across this environment." />

    <form wire:submit="create" class="mt-6 max-w-xl rounded-xl border p-5 space-y-4" style="border-color:var(--border)">
        <div>
            <label class="label" for="name">Name</label>
            <input wire:model="name" id="name" type="text" class="input" placeholder="Manager" autofocus>
            @error('name') <p class="field-error" role="alert">{{ $message }}</p> @enderror
        </div>
        <div>
            <label class="label" for="description">Description <span style="color:var(--faint)">(optional)</span></label>
            <input wire:model="description" id="description" type="text" class="input" placeholder="Team leads across the organization">
            @error('description') <p class="field-error" role="alert">{{ $message }}</p> @enderror
        </div>
        <div>
            <label class="label">Permissions <span style="color:var(--faint)">(optional)</span></label>
            <div class="space-y-1.5 rounded-lg border p-3 max-h-72 overflow-y-auto" style="border-color:var(--border)">
                @forelse ($catalog as $perm)
                    <label class="flex items-start gap-2 rounded-md px-2 py-1.5 cursor-pointer hover:bg-[var(--surface-2)]" wire:key="perm-{{ $perm->id }}">
                        <input type="checkbox" wire:model="permissions" value="{{ $perm->id }}" class="mt-0.5 rounded">
                        <span class="min-w-0 flex-1">
                            <span class="flex items-center gap-2 flex-wrap">
                                <span class="text-sm mono">{{ $perm->name }}</span>
                                @if ($perm->client_id)<span class="badge badge-info">{{ $appNames[$perm->client_id] ?? 'App' }}</span>@else<span class="badge">Manual</span>@endif
                            </span>
                            @if ($perm->description)<span class="block text-xs" style="color:var(--faint)">{{ $perm->description }}</span>@endif
                        </span>
                    </label>
                @empty
                    <div class="cbx-empty">
                        <div class="cbx-empty-icon"><x-icon name="key" class="w-5 h-5" /></div>
                        <h3>No permissions declared</h3>
                        <p>An app can register its catalog over the SDK, or you can <a href="{{ route('environment.permissions') }}" style="color:var(--accent)">add permissions manually</a>.</p>
                    </div>
                @endforelse
            </div>
        </div>
        <div class="flex items-center gap-2">
            <button type="submit" class="btn btn-primary" wire:loading.attr="disabled" wire:target="create">Create role</button>
            <a href="{{ route('environment.roles') }}" class="btn btn-ghost">Cancel</a>
        </div>
    </form>
</div>
