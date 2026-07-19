<?php

declare(strict_types=1);

use Cbox\Id\AccessControl\Models\Role;
use Cbox\Id\Governance\Contracts\SegregationOfDuties;
use Cbox\Id\Organization\Models\Organization;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Validate;
use Livewire\Volt\Component;

/**
 * Environment control plane › Conflict rules › New. A dedicated, deep-linkable create
 * page for a Segregation-of-Duties policy: a set of roles that must never be held
 * together within a chosen organization (or environment-wide).
 *
 * The SoD contract takes an EXPLICIT organization id (nullable — null means
 * environment-wide), so the form carries an organization <select> rather than
 * inferring one from the caller's session. On success we route straight to the new
 * rule's detail page.
 */
new #[Layout('components.layouts.environment', ['title' => 'New conflict rule'])] class extends Component
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

    public function define(SegregationOfDuties $sod): mixed
    {
        $this->validate();

        if (count($this->roleIds) < 2) {
            $this->addError('roleIds', 'Select at least two roles for a mutually-exclusive set.');

            return null;
        }

        if ($this->orgId !== '' && Organization::query()->whereKey($this->orgId)->doesntExist()) {
            $this->addError('orgId', 'That organization is not in this environment.');

            return null;
        }

        $policy = $sod->definePolicy(
            $this->orgId !== '' ? $this->orgId : null,
            $this->name,
            $this->roleIds,
            $this->description !== '' ? $this->description : null,
        );

        session()->flash('status', 'Policy "'.$policy->name.'" defined over '.count($policy->role_ids).' roles.');

        return $this->redirectRoute('environment.sod-policies.show', ['policy' => $policy->id], navigate: true);
    }

    /**
     * @return array<string, mixed>
     */
    public function with(): array
    {
        return [
            'roles' => Role::query()->orderBy('name')->get(),
            'organizations' => Organization::query()->orderBy('name')->get(),
        ];
    }
}; ?>

<div>
    <a href="{{ route('environment.sod-policies') }}" class="text-sm inline-flex items-center gap-1" style="color:var(--muted)"><x-icon name="chevron" class="w-3.5 h-3.5 rotate-180" /> Conflict rules</a>
    <x-page-header class="mt-2" title="New conflict rule" subtitle="Holding two or more roles from the set at once is a violation." />

    <form wire:submit="define" class="mt-6 max-w-xl rounded-xl border p-5 space-y-4" style="border-color:var(--border)">
        <div class="grid gap-4 sm:grid-cols-2">
            <div>
                <label class="label" for="name">Rule name</label>
                <input wire:model="name" id="name" type="text" class="input" placeholder="Purchase order vs. approve payment" autofocus>
                @error('name') <p class="field-error" role="alert">{{ $message }}</p> @enderror
            </div>
            <div>
                <label class="label" for="orgId">Applies to</label>
                <select wire:model="orgId" id="orgId" class="select">
                    <option value="">All organizations (environment-wide)</option>
                    @foreach ($organizations as $organization)
                        <option value="{{ $organization->id }}">{{ $organization->name }}</option>
                    @endforeach
                </select>
                @error('orgId') <p class="field-error" role="alert">{{ $message }}</p> @enderror
            </div>
        </div>

        <div>
            <label class="label" for="description">Description <span style="color:var(--faint)">(optional)</span></label>
            <input wire:model="description" id="description" type="text" class="input" placeholder="Why these roles conflict">
            @error('description') <p class="field-error" role="alert">{{ $message }}</p> @enderror
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
            @error('roleIds') <p class="field-error" role="alert">{{ $message }}</p> @enderror
        </div>

        <div class="flex items-center gap-2">
            <button type="submit" class="btn btn-primary" wire:loading.attr="disabled" wire:target="define">Define rule</button>
            <a href="{{ route('environment.sod-policies') }}" class="btn btn-ghost">Cancel</a>
        </div>
    </form>
</div>
