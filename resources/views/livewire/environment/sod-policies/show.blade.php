<?php

declare(strict_types=1);

use Cbox\Id\AccessControl\Models\Role;
use Cbox\Id\Governance\Contracts\SegregationOfDuties;
use Cbox\Id\Governance\Models\SodPolicy;
use Cbox\Id\Governance\ValueObjects\SodViolation;
use Cbox\Id\Organization\Models\Organization;
use Livewire\Attributes\Layout;
use Livewire\Volt\Component;

/**
 * Environment control plane › Conflict rules › detail. The full, deep-linkable
 * lifecycle for one Segregation-of-Duties policy: its mutually-exclusive roles and
 * scope, the activate/deactivate toggle, a per-organization evaluate tool that finds
 * subjects who already hold this policy's toxic combination, and deletion.
 *
 * Every mutation re-resolves the target within THIS environment (the SodPolicy
 * model's BelongsToEnvironment scope) and 404s otherwise — an id from another plane
 * never matches (deny-by-default).
 */
new #[Layout('components.layouts.environment', ['title' => 'Conflict rule'])] class extends Component
{
    public string $policyId = '';

    /** The organization to evaluate for existing violations (scan requires an explicit id). */
    public string $scanOrgId = '';

    /** Whether an evaluation has been requested — gates the violations report in with(). */
    public bool $scanned = false;

    public function mount(string $policy): void
    {
        $model = SodPolicy::query()->whereKey($policy)->first();
        abort_if($model === null, 404);

        $this->policyId = $model->id;
        // An org-scoped policy can only conflict within its own organization; pre-fill
        // the evaluate target so the operator never has to restate it.
        $this->scanOrgId = $model->organization_id ?? '';
    }

    private function policy(): SodPolicy
    {
        $model = SodPolicy::query()->whereKey($this->policyId)->first();
        abort_if($model === null, 404);

        return $model;
    }

    public function toggle(SegregationOfDuties $sod): void
    {
        $policy = $this->policy();
        $sod->setActive($policy->id, ! $policy->active);
    }

    public function scan(): void
    {
        if ($this->scanOrgId === '') {
            $this->addError('scanOrgId', 'Choose an organization to evaluate.');

            return;
        }

        $this->scanned = true;
    }

    public function remove(): mixed
    {
        $policy = $this->policy();
        $name = $policy->name;
        $policy->delete();

        session()->flash('status', 'Policy "'.$name.'" removed.');

        return $this->redirectRoute('environment.sod-policies', navigate: true);
    }

    /**
     * @return array<string, mixed>
     */
    public function with(): array
    {
        $policy = $this->policy();

        $violations = [];
        if ($this->scanned && $this->scanOrgId !== '') {
            $violations = array_values(array_filter(
                app(SegregationOfDuties::class)->scan($this->scanOrgId),
                fn (SodViolation $v): bool => $v->policyId === $policy->id,
            ));
        }

        return [
            'policy' => $policy,
            'roleNames' => Role::query()->orderBy('name')->pluck('name', 'id'),
            'orgNames' => Organization::query()->orderBy('name')->pluck('name', 'id'),
            'organizations' => Organization::query()->orderBy('name')->get(),
            'violations' => $violations,
        ];
    }
}; ?>

<div class="space-y-6">
    <div>
        <a href="{{ route('environment.sod-policies') }}" class="text-sm inline-flex items-center gap-1" style="color:var(--muted)"><x-icon name="chevron" class="w-3.5 h-3.5 rotate-180" /> Conflict rules</a>
        <div class="mt-2 flex items-center gap-3 flex-wrap">
            <h1 class="font-semibold tracking-tight" style="font-size:1.5rem">{{ $policy->name }}</h1>
            @if ($policy->active)
                <span class="text-xs rounded-full px-2 py-0.5" style="background:var(--accent-soft);color:var(--accent)">Active</span>
            @else
                <span class="text-xs rounded-full px-2 py-0.5" style="background:var(--surface-2);color:var(--muted)">Inactive</span>
            @endif
        </div>
        <p class="mt-1 text-sm mono" style="color:var(--faint)">{{ $policy->id }}</p>
    </div>

    {{-- Details --}}
    <div class="rounded-xl border p-5" style="border-color:var(--border)">
        <p class="text-sm font-medium">Details</p>
        @if ($policy->description)
            <p class="mt-2 text-sm" style="color:var(--muted)">{{ $policy->description }}</p>
        @endif
        <dl class="mt-4 grid gap-4 sm:grid-cols-2">
            <div>
                <dt class="label">Scope</dt>
                <dd class="mt-1"><span class="text-xs rounded-full px-2 py-0.5" style="background:var(--surface-2);color:var(--muted)">{{ $policy->organization_id ? ($orgNames[$policy->organization_id] ?? $policy->organization_id) : 'Environment-wide' }}</span></dd>
            </div>
            <div>
                <dt class="label">Conflicting roles</dt>
                <dd class="mt-1 flex flex-wrap gap-1.5">
                    @foreach ($policy->role_ids as $roleId)
                        <span class="text-xs rounded-full px-2 py-0.5" style="background:var(--surface-2);color:var(--muted)">{{ $roleNames[$roleId] ?? $roleId }}</span>
                    @endforeach
                </dd>
            </div>
        </dl>
        <p class="mt-4 text-xs" style="color:var(--faint)">Holding two or more roles from this set at once is a violation.</p>
        <div class="mt-4">
            <button type="button" class="btn btn-ghost btn-sm" wire:click="toggle">{{ $policy->active ? 'Deactivate' : 'Activate' }}</button>
        </div>
    </div>

    {{-- Evaluate --}}
    <div class="rounded-xl border p-5" style="border-color:var(--border)">
        <p class="text-sm font-medium">Evaluate</p>
        <p class="mt-1 text-sm" style="color:var(--muted)">Scan an organization for subjects who already hold this rule's conflicting combination.</p>

        <form wire:submit="scan" class="mt-4 flex flex-wrap items-end gap-3">
            @if ($policy->organization_id)
                <div class="grow min-w-48">
                    <span class="label">Organization</span>
                    <p class="mt-1 text-sm">{{ $orgNames[$policy->organization_id] ?? $policy->organization_id }}</p>
                </div>
            @else
                <div class="grow min-w-48">
                    <label class="label" for="scanOrgId">Organization</label>
                    <select wire:model="scanOrgId" id="scanOrgId" class="select">
                        <option value="">Select an organization…</option>
                        @foreach ($organizations as $organization)
                            <option value="{{ $organization->id }}">{{ $organization->name }}</option>
                        @endforeach
                    </select>
                    @error('scanOrgId') <p class="field-error" role="alert">{{ $message }}</p> @enderror
                </div>
            @endif
            <button type="submit" class="btn btn-primary" wire:loading.attr="disabled" wire:target="scan">Scan</button>
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

    {{-- Lifecycle --}}
    <div class="rounded-xl border p-5" style="border-color:var(--border)">
        <p class="text-sm font-medium">Lifecycle</p>
        <div class="mt-4 flex flex-wrap gap-2">
            <button type="button" class="btn btn-ghost btn-sm" style="color:var(--destructive)" wire:click="remove" wire:confirm="Remove {{ $policy->name }}? This cannot be undone.">Delete rule</button>
        </div>
    </div>
</div>
