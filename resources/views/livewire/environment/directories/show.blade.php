<?php

declare(strict_types=1);

use Cbox\Id\AccessControl\Contracts\GroupRoleMappings;
use Cbox\Id\AccessControl\Models\GroupRoleMapping;
use Cbox\Id\AccessControl\Models\Role;
use Cbox\Id\Directory\Enums\DirectoryProvider;
use Cbox\Id\Directory\Enums\DirectoryStatus;
use Cbox\Id\Directory\Models\Directory;
use Cbox\Id\Directory\Models\DirectoryGroup;
use Cbox\Id\OAuthServer\Models\Client;
use Cbox\Id\Organization\Models\Organization;
use Livewire\Attributes\Layout;
use Livewire\Volt\Component;

/**
 * Environment control plane › Directories › detail. The full, deep-linkable lifecycle
 * for one directory connection: rename, the SCIM endpoint, rotate the bearer token,
 * enable/disable, map directory groups onto roles, and delete.
 *
 * Every read/write re-resolves the target within THIS environment (the Directory
 * model's BelongsToEnvironment scope) and 404s otherwise — an id from another plane
 * never matches (deny-by-default). The bearer token is never persisted in plaintext:
 * only its SHA-256 hash is stored, so it is shown exactly once (on registration or
 * rotation) and can never be re-echoed.
 */
new #[Layout('components.layouts.environment')] class extends Component
{
    public string $directoryId = '';

    public string $editName = '';

    /** A freshly rotated bearer token, held in memory for a single reveal, then dismissed. */
    public ?string $freshToken = null;

    public function mount(string $directory): void
    {
        $model = Directory::query()->whereKey($directory)->first();
        abort_if($model === null, 404);

        $this->directoryId = $model->id;
        $this->editName = $model->name;
    }

    private function directory(): Directory
    {
        $model = Directory::query()->whereKey($this->directoryId)->first();
        abort_if($model === null, 404);

        return $model;
    }

    public function saveName(): void
    {
        $directory = $this->directory();

        $data = $this->validate(['editName' => ['required', 'string', 'max:120']]);

        $directory->name = trim($data['editName']);
        $directory->save();

        session()->flash('status', 'Directory updated.');
    }

    /**
     * Rotate the SCIM bearer token: mint a fresh secret, store only its hash, and
     * reveal the plaintext once. Any token the customer's IdP already holds stops
     * working immediately. Only meaningful for a SCIM (push) directory — a pull
     * directory authenticates to the provider's API, not with an inbound token.
     */
    public function regenerateToken(): void
    {
        $directory = $this->directory();

        if ($directory->provider !== DirectoryProvider::Scim) {
            return;
        }

        $token = 'scim_'.bin2hex(random_bytes(32));
        $directory->bearer_token_hash = hash('sha256', $token);
        $directory->save();

        $this->freshToken = $token;
        session()->flash('status', 'Bearer token rotated — the previous token no longer works.');
    }

    public function dismissToken(): void
    {
        $this->reset('freshToken');
    }

    public function toggleStatus(): void
    {
        $directory = $this->directory();

        $directory->status = $directory->status === DirectoryStatus::Active
            ? DirectoryStatus::Paused
            : DirectoryStatus::Active;
        $directory->save();

        session()->flash('status', $directory->status === DirectoryStatus::Active
            ? 'Directory enabled — provisioning resumes.'
            : 'Directory paused — provisioning is suspended.');
    }

    public function deleteDirectory(): mixed
    {
        $this->directory()->delete();

        session()->flash('status', 'Directory deleted.');

        return $this->redirectRoute('environment.directories', navigate: true);
    }

    /** Map a directory group onto a role — everyone in it gets the role as membership syncs. */
    public function mapGroup(string $groupId, string $roleId, GroupRoleMappings $mappings): void
    {
        if ($roleId === '') {
            return;
        }

        $directory = $this->directory();

        // Only map a group that belongs to THIS directory — never a foreign group id.
        if (DirectoryGroup::query()->whereKey($groupId)->where('directory_id', $directory->id)->doesntExist()) {
            return;
        }

        $mappings->map($directory->organization_id, $groupId, $roleId);
    }

    public function unmapGroup(string $groupId, string $roleId, GroupRoleMappings $mappings): void
    {
        $mappings->unmap($this->directory()->organization_id, $groupId, $roleId);
    }

    /**
     * @return array<string, mixed>
     */
    public function with(): array
    {
        $directory = $this->directory();
        $orgId = $directory->organization_id;

        $groups = DirectoryGroup::query()
            ->where('directory_id', $directory->id)
            ->orderBy('display_name')
            ->get();

        // Roles assignable to a group: org roles + app-declared roles for the org.
        $clientIds = Client::query()
            ->where(fn ($q) => $q->whereNull('organization_id')->orWhere('organization_id', $orgId))
            ->pluck('client_id');
        $accessRoles = Role::query()
            ->where(function ($q) use ($orgId, $clientIds): void {
                $q->where(fn ($x) => $x->where('organization_id', $orgId)->whereNull('client_id'))
                    ->orWhere(fn ($x) => $x->whereIn('client_id', $clientIds)->whereNull('orphaned_at'));
            })
            ->orderBy('name')
            ->get();

        $mappingsByGroup = GroupRoleMapping::query()
            ->where('organization_id', $orgId)
            ->get()
            ->groupBy('group_id')
            ->map(fn ($g) => $g->pluck('role_id')->all());

        return [
            'directory' => $directory,
            'orgName' => Organization::query()->whereKey($orgId)->value('name') ?? $orgId,
            'scimBaseUrl' => url('/scim/v2'),
            'groups' => $groups,
            'accessRoles' => $accessRoles,
            'accessRolesById' => $accessRoles->keyBy('id'),
            'appNames' => Client::query()->whereIn('client_id', $accessRoles->pluck('client_id')->filter()->unique())->pluck('name', 'client_id'),
            'mappingsByGroup' => $mappingsByGroup,
            // One-time token from a create/rotate hand-off — flashed, so it is present
            // for this single render and gone on the next (shown once, never re-echoed).
            'oneTimeToken' => session('newToken'),
            'oneTimeTokenName' => session('newTokenName'),
        ];
    }
}; ?>

<div class="space-y-6">
    <div>
        <a href="{{ route('environment.directories') }}" class="text-sm inline-flex items-center gap-1" style="color:var(--muted)"><x-icon name="chevron" class="w-3.5 h-3.5 rotate-180" /> Directories</a>
        <div class="mt-2 flex items-center gap-3 flex-wrap">
            <h1 class="font-semibold tracking-tight" style="font-size:1.5rem">{{ $directory->name }}</h1>
            <span class="text-xs rounded-full px-2 py-0.5" style="background:var(--surface-2);color:var(--muted)">{{ ucfirst($directory->status->value) }}</span>
            <span class="text-xs rounded-full px-2 py-0.5" style="background:var(--accent-soft);color:var(--accent)">{{ $directory->provider->label() }}</span>
        </div>
        <p class="mt-1 text-sm mono" style="color:var(--faint)">{{ $directory->id }} · {{ $orgName }}</p>
    </div>

    {{-- One-time bearer token: revealed once on registration/rotation, never retrievable again. --}}
    @if ($oneTimeToken || $freshToken)
        <div class="rounded-xl border p-5" style="border-color:color-mix(in oklch, var(--warning) 45%, transparent);background:var(--warning-soft)">
            <div class="flex items-start justify-between gap-3">
                <div class="min-w-0">
                    <div class="flex items-center gap-2 font-semibold" style="color:var(--warning)"><x-icon name="key" class="w-4 h-4" /> Bearer token for "{{ $oneTimeTokenName ?? $directory->name }}"</div>
                    <p class="mt-1 text-sm" style="color:var(--warning)">Copy this now — it is shown only once and cannot be retrieved again.</p>
                </div>
                @if ($freshToken)
                    <button type="button" wire:click="dismissToken" class="btn btn-ghost btn-sm shrink-0">Done</button>
                @endif
            </div>
            <p class="mt-3 mono text-xs rounded-lg px-3 py-2 select-all break-all" style="background:var(--surface-2);border:1px solid var(--border)">{{ $freshToken ?? $oneTimeToken }}</p>
        </div>
    @endif

    {{-- SCIM endpoint --}}
    <div class="rounded-xl border p-5" style="border-color:var(--border)">
        <div class="flex items-center gap-2 text-sm font-medium"><x-icon name="directory" class="w-4 h-4" /> SCIM endpoint</div>
        <p class="mt-1 text-xs" style="color:var(--faint)">Point the customer's identity provider at this base URL and authenticate with this directory's bearer token.</p>
        <p class="mt-3 mono text-xs rounded-lg px-3 py-2 select-all break-all" style="background:var(--surface-2);border:1px solid var(--border)">{{ $scimBaseUrl }}</p>
    </div>

    {{-- Details --}}
    <div class="rounded-xl border p-5" style="border-color:var(--border)">
        <p class="text-sm font-medium">Details</p>
        <form wire:submit="saveName" class="mt-4 grid sm:grid-cols-[1fr_auto] gap-2 items-start">
            <div>
                <label class="label" for="editName">Directory name</label>
                <input wire:model="editName" id="editName" type="text" class="input">
                @error('editName') <p class="field-error">{{ $message }}</p> @enderror
            </div>
            <button type="submit" class="btn btn-primary shrink-0 self-end">Save</button>
        </form>
    </div>

    {{-- Bearer token & status --}}
    <div class="rounded-xl border p-5" style="border-color:var(--border)">
        <p class="text-sm font-medium">Access</p>
        <p class="mt-1 text-xs" style="color:var(--faint)">The bearer token is stored only as a hash. Rotating it reveals a new token once and immediately invalidates the old one.</p>
        <div class="mt-4 flex flex-wrap gap-2">
            @if ($directory->provider === DirectoryProvider::Scim)
                <button type="button" class="btn btn-ghost btn-sm" wire:click="regenerateToken" wire:confirm="Rotate the bearer token? The current token stops working immediately and the IdP must be reconfigured."><x-icon name="refresh" class="w-4 h-4" /> Rotate token</button>
            @endif
            @if ($directory->status === DirectoryStatus::Active)
                <button type="button" class="btn btn-ghost btn-sm" wire:click="toggleStatus" wire:confirm="Pause this directory? Provisioning is suspended until re-enabled.">Pause</button>
            @else
                <button type="button" class="btn btn-ghost btn-sm" wire:click="toggleStatus">Enable</button>
            @endif
        </div>
    </div>

    {{-- Directory group → role mapping (the SCIM bridge) --}}
    @if ($groups->isNotEmpty())
        <div class="rounded-xl border p-5" style="border-color:var(--border)">
            <div class="flex items-center gap-2 text-sm font-medium"><x-icon name="shield" class="w-4 h-4" /> Group → role mapping</div>
            <p class="mt-1 text-xs" style="color:var(--faint)">Map a directory group onto a role — everyone in the group gets it automatically as membership syncs. A hand-assigned role is never affected.</p>
            <div class="mt-4 space-y-2">
                @foreach ($groups as $group)
                    <div class="flex items-start justify-between gap-4 flex-wrap rounded-lg border px-3 py-2" style="border-color:var(--border)" wire:key="group-{{ $group->id }}">
                        <div class="min-w-0">
                            <p class="font-medium text-sm">{{ $group->display_name }}</p>
                            <div class="flex flex-wrap gap-1 mt-1.5">
                                @forelse ($mappingsByGroup[$group->id] ?? [] as $rid)
                                    @php $role = $accessRolesById[$rid] ?? null; @endphp
                                    @if ($role)
                                        <span class="text-xs rounded-full px-2 py-0.5 inline-flex items-center gap-1" style="background:var(--surface-2);color:var(--muted)">{{ $role->name }}
                                            <button type="button" wire:click="unmapGroup('{{ $group->id }}', '{{ $rid }}')" style="color:var(--destructive)" title="Remove mapping" aria-label="Remove {{ $role->name }} mapping">×</button>
                                        </span>
                                    @endif
                                @empty
                                    <span class="text-xs" style="color:var(--faint)">No roles mapped</span>
                                @endforelse
                            </div>
                        </div>
                        @if ($accessRoles->isNotEmpty())
                            <select class="select" style="max-width:15rem" aria-label="Map a role to the {{ $group->display_name }} group" wire:change="mapGroup('{{ $group->id }}', $event.target.value)">
                                <option value="">+ Map a role…</option>
                                @foreach ($accessRoles->groupBy(fn ($r) => $r->client_id ?? '__org') as $groupKey => $rolesInGroup)
                                    <optgroup label="{{ $groupKey === '__org' ? 'Org roles' : ($appNames[$groupKey] ?? $groupKey) }}">
                                        @foreach ($rolesInGroup as $role)
                                            <option value="{{ $role->id }}">{{ $role->name }}</option>
                                        @endforeach
                                    </optgroup>
                                @endforeach
                            </select>
                        @endif
                    </div>
                @endforeach
            </div>
        </div>
    @endif

    {{-- Lifecycle --}}
    <div class="rounded-xl border p-5" style="border-color:var(--border)">
        <p class="text-sm font-medium">Lifecycle</p>
        <div class="mt-4 flex flex-wrap gap-2">
            <button type="button" class="btn btn-ghost btn-sm" style="color:var(--destructive)" wire:click="deleteDirectory" wire:confirm="Delete this directory? Its bearer token stops working and provisioning ends. This cannot be undone.">Delete directory</button>
        </div>
    </div>
</div>
