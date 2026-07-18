<?php

declare(strict_types=1);

use Cbox\Id\AccessControl\Models\Role;
use Cbox\Id\Governance\Contracts\SegregationOfDuties;
use Cbox\Id\Governance\Models\SodPolicy;
use Cbox\Id\Organization\Models\Organization;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Validate;
use Livewire\Volt\Component;

/**
 * Environment control plane › Conflict rules — Segregation-of-Duties governance.
 * Declares sets of roles that must never be held together, then detects subjects
 * who already hold a toxic combination within a chosen organization.
 *
 * Policies and roles are environment-owned (BelongsToEnvironment), so the model's
 * global scope only ever resolves records inside this environment — an id from
 * another plane never matches, closing cross-tenant id tampering. Access is gated
 * by the env-admin session (route middleware), so the operator has full CRUD here;
 * there is no per-org entitlement lock at the control-plane level.
 *
 * The SoD contract takes an EXPLICIT organization id (nullable for definePolicy —
 * null means environment-wide — non-null for scan), so the forms below carry an
 * organization <select> rather than inferring one from the caller's session.
 */
new #[Layout('components.layouts.environment', ['title' => 'Conflict rules'])] class extends Component
{
    #[Validate('required|string|max:190')]
    public string $name = '';

    #[Validate('nullable|string|max:500')]
    public string $description = '';

    /**
     * Empty string = environment-wide policy (mapped to null for definePolicy);
     * otherwise an organization id the policy is scoped to.
     */
    public string $orgId = '';

    /** @var list<string> */
    public array $roleIds = [];

    public bool $creating = false;

    /** The organization to scan for existing violations (scan requires an explicit id). */
    public string $scanOrgId = '';

    /** Whether a scan has been requested — gates the violations report in with(). */
    public bool $scanned = false;

    public function define(SegregationOfDuties $sod): void
    {
        $this->validate();

        if (count($this->roleIds) < 2) {
            $this->addError('roleIds', 'Select at least two roles for a mutually-exclusive set.');

            return;
        }

        $policy = $sod->definePolicy(
            $this->orgId !== '' ? $this->orgId : null,
            $this->name,
            $this->roleIds,
            $this->description !== '' ? $this->description : null,
        );

        $this->reset('name', 'description', 'orgId', 'roleIds', 'creating');
        session()->flash('status', 'Policy "'.$policy->name.'" defined over '.count($policy->role_ids).' roles.');
    }

    public function toggle(string $id, SegregationOfDuties $sod): void
    {
        // Deny-by-default: resolve an env-owned policy first, or 404 — never mutate by
        // an unresolved id. The model's environment scope closes cross-tenant toggles.
        $policy = SodPolicy::query()->whereKey($id)->first();

        abort_if($policy === null, 404);

        $sod->setActive($policy->id, ! $policy->active);
    }

    public function remove(string $id): void
    {
        $policy = SodPolicy::query()->whereKey($id)->first();

        abort_if($policy === null, 404);

        $policy->delete();

        session()->flash('status', 'Policy "'.$policy->name.'" removed.');
    }

    public function scan(): void
    {
        if ($this->scanOrgId === '') {
            $this->addError('scanOrgId', 'Choose an organization to scan.');

            return;
        }

        $this->scanned = true;
    }

    /**
     * @return array<string, mixed>
     */
    public function with(): array
    {
        $roles = Role::query()->orderBy('name')->get();

        return [
            'roles' => $roles,
            'roleNames' => $roles->pluck('name', 'id'),
            'organizations' => Organization::query()->orderBy('name')->get(),
            'orgNames' => Organization::query()->orderBy('name')->pluck('name', 'id'),
            'policies' => SodPolicy::query()->orderByDesc('id')->get(),
            'violations' => $this->scanned && $this->scanOrgId !== ''
                ? app(SegregationOfDuties::class)->scan($this->scanOrgId)
                : [],
        ];
    }
}; ?>

<div>
    <div class="flex items-start justify-between gap-4">
        <div>
            <h1 class="font-semibold tracking-tight" style="font-size:1.5rem">Conflict rules</h1>
            <p class="mt-1 text-sm" style="color:var(--muted)">Declare sets of roles that must never be held together, then detect subjects who already hold a toxic combination.</p>
        </div>
        <button wire:click="$toggle('creating')" class="btn btn-primary shrink-0"><x-icon name="plus" class="w-4 h-4" /> New policy</button>
    </div>

    @if ($creating)
        <form wire:submit="define" class="mt-6 rounded-xl border p-5 space-y-4" style="border-color:var(--border)">
            <div class="grid gap-4 sm:grid-cols-2">
                <div>
                    <label class="label" for="name">Policy name</label>
                    <input wire:model="name" id="name" type="text" class="input" placeholder="Purchase order vs. approve payment" autofocus>
                    @error('name') <p class="field-error">{{ $message }}</p> @enderror
                </div>
                <div>
                    <label class="label" for="orgId">Applies to</label>
                    <select wire:model="orgId" id="orgId" class="select">
                        <option value="">All organizations (environment-wide)</option>
                        @foreach ($organizations as $organization)
                            <option value="{{ $organization->id }}">{{ $organization->name }}</option>
                        @endforeach
                    </select>
                    @error('orgId') <p class="field-error">{{ $message }}</p> @enderror
                </div>
            </div>

            <div>
                <label class="label" for="description">Description <span style="color:var(--faint)">(optional)</span></label>
                <input wire:model="description" id="description" type="text" class="input" placeholder="Why these roles conflict">
                @error('description') <p class="field-error">{{ $message }}</p> @enderror
            </div>

            <div>
                <span class="label">Mutually-exclusive roles</span>
                @forelse ($roles as $role)
                    <label class="flex items-center gap-2 py-1 text-sm">
                        <input type="checkbox" wire:model="roleIds" value="{{ $role->id }}" class="rounded">
                        <span>{{ $role->name }}</span>
                    </label>
                @empty
                    <p class="text-xs" style="color:var(--faint)">This environment has no roles to choose from yet.</p>
                @endforelse
                @error('roleIds') <p class="field-error">{{ $message }}</p> @enderror
            </div>

            <p class="text-xs" style="color:var(--faint)">Holding two or more roles from the set at once is a violation.</p>

            <div class="flex items-center gap-2">
                <button type="submit" class="btn btn-primary" wire:loading.attr="disabled">Define policy</button>
                <button type="button" wire:click="$set('creating', false)" class="btn btn-ghost">Cancel</button>
            </div>
        </form>
    @endif

    {{-- Existing policies --}}
    <div class="mt-6 space-y-4">
        @forelse ($policies as $policy)
            <div class="rounded-xl border p-5" style="border-color:var(--border)">
                <div class="flex flex-wrap items-start justify-between gap-3">
                    <div class="min-w-0">
                        <div class="flex items-center gap-2 flex-wrap">
                            <p class="font-semibold truncate">{{ $policy->name }}</p>
                            @if ($policy->active)
                                <span class="text-xs rounded-full px-2 py-0.5" style="background:var(--accent-soft);color:var(--accent)">Active</span>
                            @else
                                <span class="text-xs rounded-full px-2 py-0.5" style="background:var(--surface-2);color:var(--muted)">Inactive</span>
                            @endif
                            <span class="text-xs rounded-full px-2 py-0.5" style="background:var(--surface-2);color:var(--muted)">{{ $policy->organization_id ? ($orgNames[$policy->organization_id] ?? $policy->organization_id) : 'Environment-wide' }}</span>
                        </div>
                        @if ($policy->description)
                            <p class="mt-1 text-xs" style="color:var(--faint)">{{ $policy->description }}</p>
                        @endif
                        <div class="mt-3 flex flex-wrap gap-1.5">
                            @foreach ($policy->role_ids as $roleId)
                                <span class="text-xs rounded-full px-2 py-0.5" style="background:var(--surface-2);color:var(--muted)">{{ $roleNames[$roleId] ?? $roleId }}</span>
                            @endforeach
                        </div>
                    </div>
                    <div class="flex items-center gap-2 shrink-0">
                        <button wire:click="toggle('{{ $policy->id }}')" class="btn btn-ghost btn-sm">{{ $policy->active ? 'Deactivate' : 'Activate' }}</button>
                        <button wire:click="remove('{{ $policy->id }}')" wire:confirm="Remove {{ $policy->name }}?" class="btn btn-ghost btn-sm" style="color:var(--destructive)">Remove</button>
                    </div>
                </div>
            </div>
        @empty
            <p class="rounded-xl border p-4 text-sm" style="border-color:var(--border);color:var(--muted)">No policies yet. Define one to forbid a toxic combination of roles.</p>
        @endforelse
    </div>

    {{-- Violations report --}}
    <div class="mt-8">
        <h2 class="font-semibold tracking-tight" style="font-size:1.125rem">Violations</h2>
        <p class="mt-1 text-sm" style="color:var(--muted)">Scan an organization for subjects who already hold a conflicting combination.</p>

        <form wire:submit="scan" class="mt-4 rounded-xl border p-5" style="border-color:var(--border)">
            <div class="flex flex-wrap items-end gap-3">
                <div class="grow min-w-48">
                    <label class="label" for="scanOrgId">Organization</label>
                    <select wire:model="scanOrgId" id="scanOrgId" class="select">
                        <option value="">Select an organization…</option>
                        @foreach ($organizations as $organization)
                            <option value="{{ $organization->id }}">{{ $organization->name }}</option>
                        @endforeach
                    </select>
                    @error('scanOrgId') <p class="field-error">{{ $message }}</p> @enderror
                </div>
                <button type="submit" class="btn btn-primary" wire:loading.attr="disabled">Scan</button>
            </div>
        </form>

        @if ($scanned)
            <div class="mt-4 space-y-3">
                @forelse ($violations as $violation)
                    <div class="rounded-xl border p-5" style="border-color:var(--destructive)">
                        <div class="flex items-center gap-2 flex-wrap">
                            <span class="text-xs rounded-full px-2 py-0.5" style="background:var(--accent-soft);color:var(--accent)">{{ $violation->policyName }}</span>
                            <span class="mono text-xs" style="color:var(--faint)">{{ $violation->subjectId }}</span>
                        </div>
                        <p class="mt-2 text-sm" style="color:var(--muted)">Holds conflicting roles:</p>
                        <div class="mt-2 flex flex-wrap gap-1.5">
                            @foreach ($violation->conflictingRoleIds as $roleId)
                                <span class="text-xs rounded-full px-2 py-0.5" style="background:var(--surface-2);color:var(--muted)">{{ $roleNames[$roleId] ?? $roleId }}</span>
                            @endforeach
                        </div>
                    </div>
                @empty
                    <p class="rounded-xl border p-4 text-sm" style="border-color:var(--border);color:var(--muted)">No conflicts detected in this organization.</p>
                @endforelse
            </div>
        @endif
    </div>
</div>
