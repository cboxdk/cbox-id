<?php

declare(strict_types=1);

use App\Platform\CurrentUser;
use Cbox\Id\AccessControl\Contracts\Roles;
use Cbox\Id\AccessControl\Models\Role;
use Cbox\Id\OAuthServer\Models\Client;
use Illuminate\Support\Facades\DB;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Validate;
use Livewire\Volt\Component;

/**
 * Roles the org's apps enforce. Two kinds: ORG roles an admin defines here (apply
 * across every app), and APP roles an app declares in its manifest (read-only — the
 * app owns what they mean). Both are assigned to people and stamped into their token.
 * Separate from "workspace access" (owner/admin/member), which is who runs THIS
 * console.
 */
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

    /**
     * @return array<string, mixed>
     */
    public function with(): array
    {
        // Org roles: admin-defined, org-wide (no client_id).
        $orgRoles = Role::query()
            ->where('organization_id', $this->orgId())
            ->whereNull('client_id')
            ->orderBy('name')
            ->get();

        // App roles: declared by apps this org can use (platform-owned or its own),
        // still declared (not orphaned), grouped by app.
        $clientIds = Client::query()
            ->where(function ($query): void {
                $query->whereNull('organization_id')->orWhere('organization_id', $this->orgId());
            })
            ->pluck('client_id');

        $appRoles = Role::query()
            ->whereIn('client_id', $clientIds)
            ->whereNull('orphaned_at')
            ->orderBy('name')
            ->get();

        $appNames = Client::query()->whereIn('client_id', $appRoles->pluck('client_id')->unique())->pluck('name', 'client_id');

        $permsByRole = DB::table('role_permission')
            ->join('permissions', 'permissions.id', '=', 'role_permission.permission_id')
            ->whereIn('role_permission.role_id', $orgRoles->pluck('id')->merge($appRoles->pluck('id')))
            ->orderBy('permissions.name')
            ->get(['role_permission.role_id', 'permissions.name'])
            ->groupBy('role_id')
            ->map(fn ($group) => $group->pluck('name')->all());

        return [
            'me' => app(CurrentUser::class),
            'orgRoles' => $orgRoles,
            'appRolesByApp' => $appRoles->groupBy('client_id'),
            'appNames' => $appNames,
            'permsByRole' => $permsByRole,
        ];
    }

    private function orgId(): string
    {
        return app(CurrentUser::class)->organizationId() ?? '';
    }

    public function mount(): void
    {
        $this->authorizeAdmin();
    }

    private function authorizeAdmin(): void
    {
        abort_unless(app(CurrentUser::class)->isAdmin(), 403);
    }
}; ?>

<div class="space-y-6">
    <div class="cbx-page-header">
        <div>
            <p class="cbx-page-eyebrow">Access control</p>
            <h1 class="cbx-page-title">Roles</h1>
            <p class="cbx-page-desc">What people can do inside your apps. You assign roles here; each app decides what its roles are allowed to do.</p>
        </div>
        @if ($me->isAdmin())
            <button wire:click="$toggle('creating')" class="btn btn-primary"><x-icon name="plus" class="w-4 h-4" /> New org role</button>
        @endif
    </div>

    {{-- The one-paragraph mental model that removes the confusion. --}}
    <div class="card p-4" style="background:var(--accent-soft);border-color:var(--accent-edge)">
        <div class="flex items-start gap-3">
            <span class="grid place-items-center rounded-lg shrink-0" style="width:2rem;height:2rem;background:var(--card);color:var(--primary)"><x-icon name="key" class="w-4 h-4" /></span>
            <p class="text-sm" style="color:var(--foreground)">
                <b>Cbox ID assigns roles; your app decides what they can do.</b>
                A role is a label stamped into the token — <b>app roles</b> are declared by each app (below, read-only), and <b>org roles</b> are ones you define for your whole organization. This is different from
                <a href="{{ route('settings') }}" class="underline" style="color:var(--accent)">workspace access</a> (owner/admin/member), which is who can run this console.
            </p>
        </div>
    </div>

    {{-- ── App-declared roles (read-only) ── --}}
    @if ($appRolesByApp->isNotEmpty())
        <section>
            <h2 class="text-xs font-medium uppercase mb-3" style="color:var(--muted);letter-spacing:0.06em">App roles <span style="text-transform:none;font-weight:400">— declared by your apps</span></h2>
            <div class="space-y-4">
                @foreach ($appRolesByApp as $clientId => $roles)
                    <div class="card overflow-hidden">
                        <div class="px-5 py-3 flex items-center gap-2" style="border-bottom:1px solid var(--border);background:var(--secondary)">
                            <x-icon name="clients" class="w-4 h-4" style="color:var(--muted)" />
                            <span class="font-semibold text-sm">{{ $appNames[$clientId] ?? $clientId }}</span>
                            <span class="badge" style="margin-left:auto">Managed by the app</span>
                        </div>
                        @foreach ($roles as $role)
                            <div class="px-5 py-3" style="border-bottom:1px solid var(--border)">
                                <div class="flex items-center gap-2">
                                    <p class="font-medium text-sm">{{ $role->name }}</p>
                                    <span class="badge mono" style="font-size:10px">{{ $role->key }}</span>
                                </div>
                                @if ($role->description)<p class="text-xs mt-0.5" style="color:var(--muted)">{{ $role->description }}</p>@endif
                                <div class="flex flex-wrap items-center gap-1.5 mt-2">
                                    @forelse ($permsByRole[$role->id] ?? [] as $permission)
                                        <span class="badge mono" style="font-size:10px">{{ $permission }}</span>
                                    @empty
                                        <span class="text-xs" style="color:var(--faint)">No permissions.</span>
                                    @endforelse
                                </div>
                            </div>
                        @endforeach
                    </div>
                @endforeach
            </div>
        </section>
    @endif

    {{-- ── Org roles (admin-defined) ── --}}
    <section>
        <h2 class="text-xs font-medium uppercase mb-3" style="color:var(--muted);letter-spacing:0.06em">Org roles <span style="text-transform:none;font-weight:400">— yours, applied across every app</span></h2>

        @if ($creating && $me->isAdmin())
            <form wire:submit="create" class="card p-4 flex flex-wrap items-end gap-3 mb-4">
                <div class="flex-1 min-w-[12rem]">
                    <label class="label" for="name">Name</label>
                    <input wire:model="name" id="name" type="text" class="input" placeholder="Manager" autofocus>
                    @error('name') <p class="field-error">{{ $message }}</p> @enderror
                </div>
                <div class="flex-1 min-w-[14rem]">
                    <label class="label" for="description">Description <span style="color:var(--muted-foreground);font-weight:400">(optional)</span></label>
                    <input wire:model="description" id="description" type="text" class="input" placeholder="Team leads across the organization">
                </div>
                <button type="submit" class="btn btn-primary" wire:loading.attr="disabled">Create role</button>
                <button type="button" wire:click="$set('creating', false)" class="btn btn-ghost">Cancel</button>
            </form>
        @endif

        <div class="card overflow-hidden">
            @forelse ($orgRoles as $role)
                <div class="px-5 py-4" style="border-bottom:1px solid var(--border)">
                    <div class="flex items-start justify-between gap-4 flex-wrap">
                        <div class="flex items-center gap-2">
                            <span class="grid place-items-center rounded-lg" style="width:1.75rem;height:1.75rem;background:var(--accent-soft);color:var(--primary)"><x-icon name="shield" class="w-4 h-4" /></span>
                            <div>
                                <p class="font-semibold">{{ $role->name }}</p>
                                @if ($role->description)<p class="text-sm" style="color:var(--muted-foreground)">{{ $role->description }}</p>@endif
                            </div>
                        </div>
                    </div>
                    <div class="flex flex-wrap items-center gap-1.5 mt-3">
                        @forelse ($permsByRole[$role->id] ?? [] as $permission)
                            <span class="badge"><x-icon name="key" class="w-3 h-3" /> <span class="mono">{{ $permission }}</span></span>
                        @empty
                            <span class="text-xs" style="color:var(--muted-foreground)">No permissions yet.</span>
                        @endforelse
                    </div>
                    @if ($me->isAdmin())
                        <form wire:submit="grant('{{ $role->id }}')" class="flex items-center gap-2 mt-3">
                            <input wire:model="permissionInput.{{ $role->id }}" type="text" class="input" style="max-width:16rem" placeholder="reports:read" aria-label="Add a permission to the {{ $role->name }} role">
                            <button type="submit" class="btn btn-ghost btn-sm" wire:loading.attr="disabled"><x-icon name="plus" class="w-3.5 h-3.5" /> Grant</button>
                        </form>
                    @endif
                </div>
            @empty
                <div class="cbx-empty">
                    <div class="cbx-empty-icon"><x-icon name="shield" class="w-5 h-5" /></div>
                    <h3>No org roles yet</h3>
                    <p>Create a role that applies across every app in your organization — or let your apps declare their own above.</p>
                </div>
            @endforelse
        </div>
    </section>
</div>
