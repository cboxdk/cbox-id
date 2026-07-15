<?php

declare(strict_types=1);

use App\Platform\CurrentUser;
use Cbox\Id\AccessControl\Contracts\Roles;
use Cbox\Id\AccessControl\Models\Role;
use Illuminate\Support\Facades\DB;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Validate;
use Livewire\Volt\Component;

new #[Layout('components.layouts.app', ['title' => 'Roles'])] class extends Component
{
    #[Validate('required|string|max:120')]
    public string $name = '';

    public string $description = '';

    public bool $creating = false;

    /** @var array<string, string> */
    public array $permissionInput = [];

    public function create(Roles $roles): void
    {
        $this->authorizeAdmin();
        $this->validate();

        $roles->define($this->orgId(), trim($this->name), trim($this->description) ?: null);

        $this->reset('name', 'description', 'creating');
        session()->flash('status', 'Role created.');
    }

    public function grant(string $roleId, Roles $roles): void
    {
        $this->authorizeAdmin();

        $permission = trim($this->permissionInput[$roleId] ?? '');
        if ($permission === '') {
            return;
        }

        $roles->grantPermission($this->orgId(), $roleId, $permission);

        $this->permissionInput[$roleId] = '';
        session()->flash('status', 'Permission granted.');
    }

    public function with(): array
    {
        $roles = Role::query()
            ->where('organization_id', $this->orgId())
            ->orderBy('name')
            ->get();

        $permsByRole = DB::table('role_permission')
            ->join('permissions', 'permissions.id', '=', 'role_permission.permission_id')
            ->whereIn('role_permission.role_id', $roles->pluck('id'))
            ->orderBy('permissions.name')
            ->get(['role_permission.role_id', 'permissions.name'])
            ->groupBy('role_id')
            ->map(fn ($group) => $group->pluck('name')->all());

        return [
            'me' => app(CurrentUser::class),
            'roles' => $roles,
            'permsByRole' => $permsByRole,
        ];
    }

    private function orgId(): string
    {
        return app(CurrentUser::class)->organizationId() ?? '';
    }

    public function mount(): void
    {
        // Read gate: these pages expose org-wide config (client secrets shown
        // once, SSO connection settings, directory tokens, audit) — admins only.
        $this->authorizeAdmin();
    }

    private function authorizeAdmin(): void
    {
        abort_unless(app(CurrentUser::class)->isAdmin(), 403);
    }
}; ?>

<div>
    <div class="cbx-page-header">
        <div>
            <p class="cbx-page-eyebrow">Access control</p>
            <h1 class="cbx-page-title">Roles</h1>
            <p class="cbx-page-desc">Named bundles of permissions you can assign to members.</p>
        </div>
        @if ($me->isAdmin())
            <div class="flex items-center gap-2">
                <button wire:click="$toggle('creating')" class="btn btn-primary"><x-icon name="plus" class="w-4 h-4" /> New role</button>
            </div>
        @endif
    </div>

    <div class="mt-8 space-y-6">
    @if ($creating && $me->isAdmin())
        <form wire:submit="create" class="card p-4 flex flex-wrap items-end gap-3">
            <div class="flex-1 min-w-[12rem]">
                <label class="label" for="name">Name</label>
                <input wire:model="name" id="name" type="text" class="input" placeholder="billing-admin" autofocus>
                @error('name') <p class="field-error">{{ $message }}</p> @enderror
            </div>
            <div class="flex-1 min-w-[14rem]">
                <label class="label" for="description">Description <span style="color:var(--muted-foreground);font-weight:400">(optional)</span></label>
                <input wire:model="description" id="description" type="text" class="input" placeholder="Manages invoices and payment methods">
            </div>
            <button type="submit" class="btn btn-primary" wire:loading.attr="disabled">Create role</button>
            <button type="button" wire:click="$set('creating', false)" class="btn btn-ghost">Cancel</button>
        </form>
    @endif

    <div class="card overflow-hidden">
        @forelse ($roles as $role)
            <div class="px-5 py-4 border-b" style="border-color:var(--border)">
                <div class="flex items-start justify-between gap-4 flex-wrap">
                    <div class="min-w-0">
                        <div class="flex items-center gap-2">
                            <span class="grid place-items-center rounded-lg" style="width:1.75rem;height:1.75rem;background:var(--accent-soft);color:var(--primary)"><x-icon name="shield" class="w-4 h-4" /></span>
                            <p class="font-semibold truncate">{{ $role->name }}</p>
                        </div>
                        @if ($role->description)
                            <p class="text-sm mt-1" style="color:var(--muted-foreground)">{{ $role->description }}</p>
                        @endif
                    </div>
                    <p class="text-xs mono" style="color:var(--muted-foreground)">{{ $role->id }}</p>
                </div>

                <div class="flex flex-wrap items-center gap-1.5 mt-3">
                    @forelse ($permsByRole[$role->id] ?? [] as $permission)
                        <span class="badge"><x-icon name="key" class="w-3 h-3" /> <span class="mono">{{ $permission }}</span></span>
                    @empty
                        <span class="text-xs" style="color:var(--muted-foreground)">No permissions granted yet.</span>
                    @endforelse
                </div>

                @if ($me->isAdmin())
                    <form wire:submit="grant('{{ $role->id }}')" class="flex items-center gap-2 mt-3">
                        <input wire:model="permissionInput.{{ $role->id }}" type="text" class="input" style="max-width:16rem" placeholder="members.invite">
                        <button type="submit" class="btn btn-ghost btn-sm" wire:loading.attr="disabled"><x-icon name="plus" class="w-3.5 h-3.5" /> Grant</button>
                    </form>
                @endif
            </div>
        @empty
            <div class="cbx-empty">
                <div class="cbx-empty-icon"><x-icon name="shield" class="w-5 h-5" /></div>
                <h3>No roles defined yet</h3>
                <p>Create a role to bundle permissions you can assign to members.</p>
            </div>
        @endforelse
    </div>
    </div>
</div>
