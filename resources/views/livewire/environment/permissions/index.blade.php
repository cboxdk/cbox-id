<?php

declare(strict_types=1);

use App\Platform\EnvironmentAdminAuth;
use Cbox\Id\AccessControl\Models\Permission;
use Cbox\Id\OAuthServer\Models\Client;
use Illuminate\Support\Facades\DB;
use Livewire\Attributes\Layout;
use Livewire\Volt\Component;

/**
 * Environment control plane › Permissions. The catalog every role draws from.
 *
 * A permission is a `feature:action` key. Two sources, always distinguishable:
 *  - APP-DECLARED (client_id set) — synced from an app's manifest via the SDK/API.
 *    The app is the source of truth, so these are read-only here; an app that stops
 *    declaring one leaves it "orphaned" (kept, not deleted).
 *  - MANUAL (client_id null) — authored right here, for orgs that don't run an SDK
 *    integration but still need to compose their own permissions into roles.
 *
 * Only manual permissions can be edited or removed. Both kinds are assignable into
 * roles from the Roles editor.
 */
new #[Layout('components.layouts.environment', ['title' => 'Permissions'])] class extends Component
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

    public string $name = '';

    public string $description = '';

    public bool $tenantAssignable = true;

    /** The manual permission being inline-edited, if any. */
    public ?string $editingId = null;

    public string $editDescription = '';

    public bool $editTenantAssignable = true;

    public function create(): void
    {
        $data = $this->validate([
            // feature:action — lower-case, e.g. invoices:create, reports:read.
            'name' => ['required', 'string', 'max:120', 'regex:/^[a-z0-9][a-z0-9_.-]*:[a-z0-9][a-z0-9_.*-]*$/i'],
            'description' => ['nullable', 'string', 'max:500'],
        ]);

        $name = mb_strtolower(trim($data['name']));

        // Uniqueness among MANUAL permissions (the table is unique on (client_id, name);
        // an app may legitimately declare the same key under its own client_id).
        if (Permission::query()->whereNull('client_id')->where('name', $name)->exists()) {
            $this->addError('name', 'A manual permission with that key already exists.');

            return;
        }

        Permission::query()->create([
            'client_id' => null,
            'name' => $name,
            'description' => trim($this->description) !== '' ? trim($this->description) : null,
            'tenant_assignable' => $this->tenantAssignable,
        ]);

        $this->reset('name', 'description');
        $this->tenantAssignable = true;
        $this->dispatch('toast', message: 'Permission created.');
    }

    public function startEdit(string $id): void
    {
        $perm = $this->manual($id);
        if ($perm === null) {
            return;
        }

        $this->editingId = $perm->id;
        $this->editDescription = $perm->description ?? '';
        $this->editTenantAssignable = $perm->tenant_assignable;
    }

    public function cancelEdit(): void
    {
        $this->reset('editingId', 'editDescription', 'editTenantAssignable');
    }

    public function saveEdit(): void
    {
        if ($this->editingId === null) {
            return;
        }

        $perm = $this->manual($this->editingId);
        if ($perm === null) {
            $this->cancelEdit();

            return;
        }

        $this->validate(['editDescription' => ['nullable', 'string', 'max:500']]);

        $perm->description = trim($this->editDescription) !== '' ? trim($this->editDescription) : null;
        $perm->tenant_assignable = $this->editTenantAssignable;
        $perm->save();

        $this->cancelEdit();
        $this->dispatch('toast', message: 'Permission updated.');
    }

    public function delete(string $id): void
    {
        // Only a MANUAL permission may be removed — an app-declared one is the app's.
        $perm = $this->manual($id);
        if ($perm === null) {
            return;
        }

        DB::table('role_permission')->where('permission_id', $perm->id)->delete();
        $perm->delete();

        $this->dispatch('toast', message: 'Permission deleted.');
    }

    /** Resolve a permission id, but only if it's a MANUAL one (deny-by-default). */
    private function manual(string $id): ?Permission
    {
        return Permission::query()->whereKey($id)->whereNull('client_id')->first();
    }

    /**
     * @return array<string, mixed>
     */
    public function with(): array
    {
        $all = Permission::query()->orderBy('name')->get();

        $manual = $all->whereNull('client_id')->values();
        $declared = $all->whereNotNull('client_id')->values();

        $appNames = Client::query()
            ->whereIn('client_id', $declared->pluck('client_id')->filter()->unique()->all())
            ->pluck('name', 'client_id');

        // permissionId => how many roles reference it (context, so an admin sees what
        // deleting a manual permission would strip from roles).
        $usage = DB::table('role_permission')
            ->get(['permission_id'])
            ->groupBy('permission_id')
            ->map(fn ($group) => $group->count());

        return [
            'manual' => $manual,
            'declared' => $declared,
            'declaredByApp' => $declared->groupBy('client_id'),
            'appNames' => $appNames,
            'usage' => $usage,
        ];
    }
}; ?>

<div class="space-y-6">
    <x-page-header title="Permissions" subtitle="The catalog your roles draw from. Apps register their own via the SDK; add your own here for orgs that don't." />

    {{-- Create a manual permission --}}
    <div class="rounded-xl border p-5" style="border-color:var(--border)">
        <p class="text-sm font-medium">New permission</p>
        <p class="mt-1 text-sm" style="color:var(--muted)">A <code class="mono">feature:action</code> key you can compose into roles — e.g. <code class="mono">invoices:create</code>.</p>
        <form wire:submit="create" class="mt-4 space-y-3">
            <div class="grid sm:grid-cols-[1fr_1.4fr_auto] gap-2 items-start">
                <div>
                    <input wire:model="name" type="text" class="input mono" placeholder="invoices:create" aria-label="Permission key">
                    @error('name') <p class="field-error">{{ $message }}</p> @enderror
                </div>
                <div>
                    <input wire:model="description" type="text" class="input" placeholder="Create invoices (optional)" aria-label="Description">
                    @error('description') <p class="field-error">{{ $message }}</p> @enderror
                </div>
                <button type="submit" class="btn btn-primary shrink-0" wire:loading.attr="disabled" wire:target="create">Add permission</button>
            </div>
            <label class="flex items-center gap-2 text-sm cursor-pointer" style="color:var(--muted)">
                <input type="checkbox" wire:model="tenantAssignable" class="rounded">
                Tenant-assignable — org admins may compose this into their own roles. Untick to keep it internal.
            </label>
        </form>
    </div>

    {{-- Manual permissions --}}
    <div class="rounded-xl border p-5" style="border-color:var(--border)">
        <div class="flex items-center gap-2">
            <p class="text-sm font-medium">Manual</p>
            <span class="badge">{{ $manual->count() }}</span>
        </div>
        <p class="mt-1 text-xs" style="color:var(--faint)">Authored here. Editable and removable.</p>
        <div class="mt-4 space-y-2">
            @forelse ($manual as $perm)
                <div class="rounded-lg border px-3 py-2" style="border-color:var(--border)" wire:key="manual-{{ $perm->id }}">
                    @if ($editingId === $perm->id)
                        <div class="space-y-2">
                            <p class="text-sm mono">{{ $perm->name }}</p>
                            <input wire:model="editDescription" type="text" class="input" placeholder="Description" aria-label="Description">
                            @error('editDescription') <p class="field-error">{{ $message }}</p> @enderror
                            <label class="flex items-center gap-2 text-sm cursor-pointer" style="color:var(--muted)">
                                <input type="checkbox" wire:model="editTenantAssignable" class="rounded"> Tenant-assignable
                            </label>
                            <div class="flex items-center gap-2">
                                <button type="button" class="btn btn-primary btn-sm" wire:click="saveEdit">Save</button>
                                <button type="button" class="btn btn-ghost btn-sm" wire:click="cancelEdit">Cancel</button>
                            </div>
                        </div>
                    @else
                        <div class="flex items-center gap-2">
                            <div class="min-w-0 flex-1">
                                <div class="flex items-center gap-2 flex-wrap">
                                    <span class="text-sm mono truncate">{{ $perm->name }}</span>
                                    <span class="badge">Manual</span>
                                    @unless ($perm->tenant_assignable)<span class="badge badge-warn">Internal</span>@endunless
                                    @php $count = $usage[$perm->id] ?? 0; @endphp
                                    @if ($count > 0)<span class="text-xs" style="color:var(--faint)">in {{ $count }} {{ \Illuminate\Support\Str::plural('role', $count) }}</span>@endif
                                </div>
                                @if ($perm->description)<p class="text-xs truncate" style="color:var(--faint)">{{ $perm->description }}</p>@endif
                            </div>
                            <button type="button" class="btn btn-ghost btn-sm shrink-0" wire:click="startEdit('{{ $perm->id }}')">Edit</button>
                            <button type="button" class="btn btn-ghost btn-sm shrink-0" style="color:var(--destructive)" wire:click="delete('{{ $perm->id }}')" wire:confirm="Delete this permission? It is removed from every role that uses it.">Delete</button>
                        </div>
                    @endif
                </div>
            @empty
                <div class="cbx-empty">
                    <div class="cbx-empty-icon"><x-icon name="key" class="w-5 h-5" /></div>
                    <h3>No manual permissions yet</h3>
                    <p>Add one above to compose your own roles without an SDK integration.</p>
                </div>
            @endforelse
        </div>
    </div>

    {{-- App-declared (synced) permissions --}}
    <div class="rounded-xl border p-5" style="border-color:var(--border)">
        <div class="flex items-center gap-2">
            <p class="text-sm font-medium">App-declared</p>
            <span class="badge">{{ $declared->count() }}</span>
        </div>
        <p class="mt-1 text-xs" style="color:var(--faint)">Synced from each app's manifest over the SDK/API. Read-only — the app is their source of truth.</p>
        <div class="mt-4 space-y-4">
            @forelse ($declaredByApp as $clientId => $perms)
                <div wire:key="app-{{ $clientId }}">
                    <p class="text-xs font-semibold uppercase mb-1.5" style="color:var(--muted);letter-spacing:0.05em">{{ $appNames[$clientId] ?? $clientId }}</p>
                    <div class="space-y-2">
                        @foreach ($perms as $perm)
                            <div class="flex items-center gap-2 rounded-lg border px-3 py-2" style="border-color:var(--border)" wire:key="app-perm-{{ $perm->id }}">
                                <div class="min-w-0 flex-1">
                                    <div class="flex items-center gap-2 flex-wrap">
                                        <span class="text-sm mono truncate">{{ $perm->name }}</span>
                                        <span class="badge badge-info">App</span>
                                        @if ($perm->orphaned_at)<span class="badge badge-warn">Orphaned</span>@endif
                                        @unless ($perm->tenant_assignable)<span class="badge">Internal</span>@endunless
                                    </div>
                                    @if ($perm->description)<p class="text-xs truncate" style="color:var(--faint)">{{ $perm->description }}</p>@endif
                                </div>
                            </div>
                        @endforeach
                    </div>
                </div>
            @empty
                <div class="cbx-empty">
                    <div class="cbx-empty-icon"><x-icon name="key" class="w-5 h-5" /></div>
                    <h3>No app has registered a catalog yet</h3>
                    <p>Once an app declares its permissions through the SDK or API, they appear here.</p>
                </div>
            @endforelse
        </div>
    </div>
</div>
