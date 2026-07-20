<?php

declare(strict_types=1);

use Cbox\Id\AccessControl\Enums\RoleSource;
use Cbox\Id\AccessControl\Models\Permission;
use Cbox\Id\AccessControl\Models\Role;
use Illuminate\Support\Facades\DB;
use Livewire\Attributes\Layout;
use Livewire\Volt\Component;

/**
 * Environment control plane › Roles › detail. The full, deep-linkable lifecycle for
 * one role: rename, edit its description, compose its permissions and delete it.
 *
 * Every read/write re-resolves the role within THIS environment (the Role model's
 * BelongsToEnvironment scope) and 404s otherwise — an id from another plane never
 * matches (deny-by-default). App-declared roles (source = manifest) are the app's
 * source of truth, so they are read-only here: rename, permission edits and delete
 * all refuse. No rename service exists for roles, so name/description persist directly
 * on the environment-scoped model; permissions are the role_permission pivot.
 */
new #[Layout('components.layouts.environment', ['title' => 'Role'])] class extends Component
{
    public string $roleId = '';

    public string $editName = '';

    public string $editDescription = '';

    public function mount(string $role): void
    {
        $model = Role::query()->whereKey($role)->first();
        abort_if($model === null, 404);

        $this->roleId = $model->id;
        $this->editName = $model->name;
        $this->editDescription = $model->description ?? '';
    }

    private function role(): Role
    {
        $model = Role::query()->whereKey($this->roleId)->first();
        abort_if($model === null, 404);

        return $model;
    }

    public function saveDetails(): void
    {
        $role = $this->role();
        abort_if($role->source === RoleSource::Manifest, 403);

        $data = $this->validate([
            'editName' => ['required', 'string', 'max:120'],
            'editDescription' => ['nullable', 'string', 'max:500'],
        ]);

        $role->name = trim($data['editName']);
        $role->description = trim($data['editDescription']) !== '' ? trim($data['editDescription']) : null;
        $role->save();

        session()->flash('status', 'Role updated.');
    }

    public function togglePermission(string $permissionId): void
    {
        $role = $this->role();
        abort_if($role->source === RoleSource::Manifest, 403);

        // Only ever toggle a real, non-orphaned permission — a posted id that resolves
        // to nothing is ignored rather than trusted.
        $permission = Permission::query()->whereKey($permissionId)->whereNull('orphaned_at')->first();
        if ($permission === null) {
            return;
        }

        $attached = DB::table('role_permission')
            ->where('role_id', $role->id)
            ->where('permission_id', $permission->id)
            ->exists();

        if ($attached) {
            DB::table('role_permission')
                ->where('role_id', $role->id)
                ->where('permission_id', $permission->id)
                ->delete();
            session()->flash('status', 'Permission revoked.');
        } else {
            DB::table('role_permission')->insertOrIgnore(['role_id' => $role->id, 'permission_id' => $permission->id]);
            session()->flash('status', 'Permission granted.');
        }
    }

    public function deleteRole(): mixed
    {
        $role = $this->role();
        abort_if($role->source === RoleSource::Manifest, 403);

        // Drop the pivot rows and any live grants of this role, then the role itself,
        // so nothing dangles pointing at a deleted id.
        DB::table('role_permission')->where('role_id', $role->id)->delete();
        DB::table('role_assignments')->where('role_id', $role->id)->delete();
        $role->delete();

        session()->flash('status', 'Role deleted.');

        return $this->redirectRoute('environment.roles', navigate: true);
    }

    /**
     * @return array<string, mixed>
     */
    public function with(): array
    {
        $role = $this->role();

        /** @var list<string> $granted */
        $granted = DB::table('role_permission')->where('role_id', $role->id)->pluck('permission_id')->all();

        $catalog = Permission::query()->whereNull('orphaned_at')->orderBy('name')->get(['id', 'name', 'description', 'client_id']);

        return [
            'role' => $role,
            'readOnly' => $role->source === RoleSource::Manifest,
            'catalog' => $catalog,
            'appNames' => \Cbox\Id\OAuthServer\Models\Client::query()
                ->whereIn('client_id', $catalog->pluck('client_id')->filter()->unique()->all())
                ->pluck('name', 'client_id'),
            'granted' => $granted,
        ];
    }
}; ?>

<div class="space-y-6">
    <div>
        <a href="{{ route('environment.roles') }}" class="text-sm inline-flex items-center gap-1" style="color:var(--muted)"><x-icon name="chevron" class="w-3.5 h-3.5 rotate-180" /> Roles</a>
        <div class="mt-2 flex items-center gap-3 flex-wrap">
            <h1 class="font-semibold tracking-tight" style="font-size:1.5rem">{{ $role->name }}</h1>
            @if ($readOnly)
                <span class="badge">Managed by the app</span>
            @endif
        </div>
        <p class="mt-1 text-sm mono" style="color:var(--faint)">{{ $role->id }}</p>
    </div>

    {{-- Details --}}
    <div class="rounded-xl border p-5" style="border-color:var(--border)">
        <p class="text-sm font-medium">Details</p>
        @if ($readOnly)
            <p class="mt-1 text-xs" style="color:var(--faint)">This role is declared by an application, which is its source of truth — it can't be edited here.</p>
            <div class="mt-4 space-y-3">
                <div>
                    <p class="label">Name</p>
                    <p class="text-sm">{{ $role->name }}</p>
                </div>
                <div>
                    <p class="label">Description</p>
                    <p class="text-sm" style="color:var(--muted)">{{ $role->description ?: '—' }}</p>
                </div>
            </div>
        @else
            <form wire:submit="saveDetails" class="mt-4 space-y-4">
                <div>
                    <label class="label" for="editName">Name</label>
                    <input wire:model="editName" id="editName" type="text" class="input">
                    @error('editName') <p class="field-error">{{ $message }}</p> @enderror
                </div>
                <div>
                    <label class="label" for="editDescription">Description <span style="color:var(--faint)">(optional)</span></label>
                    <input wire:model="editDescription" id="editDescription" type="text" class="input">
                    @error('editDescription') <p class="field-error">{{ $message }}</p> @enderror
                </div>
                <button type="submit" class="btn btn-primary" wire:loading.attr="disabled" wire:target="saveDetails">Save changes</button>
            </form>
        @endif
    </div>

    {{-- Permissions --}}
    <div class="rounded-xl border p-5" style="border-color:var(--border)">
        <p class="text-sm font-medium">Permissions</p>
        <p class="mt-1 text-xs" style="color:var(--faint)">What this role is allowed to do. {{ $readOnly ? 'Set by the application.' : 'Tick a permission to grant it; untick to revoke.' }}</p>
        @if ($readOnly)
            <div class="mt-4 flex flex-wrap gap-1.5">
                @forelse ($catalog->whereIn('id', $granted) as $perm)
                    <span class="badge mono">{{ $perm->name }}</span>
                @empty
                    <div class="cbx-empty">
                        <div class="cbx-empty-icon"><x-icon name="key" class="w-5 h-5" /></div>
                        <h3>No permissions</h3>
                        <p>This role grants no permissions. The application that declares it controls what it can do.</p>
                    </div>
                @endforelse
            </div>
        @else
            <div class="mt-4 space-y-1.5 rounded-lg border p-3 max-h-96 overflow-y-auto" style="border-color:var(--border)">
                @forelse ($catalog as $perm)
                    <label class="flex items-start gap-2 rounded-md px-2 py-1.5 cursor-pointer hover:bg-[var(--surface-2)]" wire:key="perm-{{ $perm->id }}">
                        <input type="checkbox" class="mt-0.5 rounded" wire:click="togglePermission('{{ $perm->id }}')" @checked(in_array($perm->id, $granted, true))>
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
        @endif
    </div>

    {{-- Danger zone --}}
    @unless ($readOnly)
        <div class="rounded-xl border p-5" style="border-color:var(--border)">
            <p class="text-sm font-medium">Danger zone</p>
            <div class="mt-4">
                <button type="button" class="btn btn-ghost btn-sm" style="color:var(--destructive)" wire:click="deleteRole" wire:confirm="Delete this role? Anyone assigned it loses the access it grants.">Delete role</button>
            </div>
        </div>
    @endunless
</div>
