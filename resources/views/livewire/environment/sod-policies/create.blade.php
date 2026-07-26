<?php

declare(strict_types=1);

use App\Platform\EnvironmentAdminAuth;
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

    /** How many options either picker will draw before the search has to narrow it. */
    private const PICKER_LIMIT = 50;

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

    /** Narrows the role checkboxes and the organization picker below. */
    public string $roleSearch = '';

    public string $orgSearch = '';

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

        $this->dispatch('toast', message: 'Policy "'.$policy->name.'" defined over '.count($policy->role_ids).' roles.');

        return $this->redirectRoute('environment.sod-policies.show', ['policy' => $policy->id], navigate: true);
    }

    /**
     * @return array<string, mixed>
     */
    /**
     * Both pickers are SEARCHED and BOUNDED. They used to be two unbounded `get()`s
     * in one render: every role AND every organization in the environment, materialised
     * to draw a checkbox list and a <select>, again on every Livewire action.
     *
     * Already-selected roles are always included regardless of the search term —
     * otherwise typing into the filter would hide a checked box, and the user would
     * submit a selection they could no longer see.
     *
     * @return array<string, mixed>
     */
    public function with(): array
    {
        $roles = Role::query()->orderBy('name');
        $term = trim($this->roleSearch);

        if ($term !== '') {
            $roles->where(fn ($query) => $query
                ->where('name', 'like', "%{$term}%")
                ->orWhereIn('id', $this->roleIds));
        }

        $organizations = Organization::query()->orderBy('name');
        $orgTerm = trim($this->orgSearch);

        if ($orgTerm !== '') {
            $organizations->where('name', 'like', "%{$orgTerm}%");
        }

        return [
            'roles' => $roles->limit(self::PICKER_LIMIT)->get(),
            'organizations' => $organizations->limit(self::PICKER_LIMIT)->get(),
            'pickerLimit' => self::PICKER_LIMIT,
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
                <input @error('name') aria-invalid="true" aria-describedby="name-error" @enderror wire:model="name" id="name" type="text" class="input" placeholder="Purchase order vs. approve payment" autofocus>
                @error('name') <p id="name-error" class="field-error" role="alert">{{ $message }}</p> @enderror
            </div>
            <div>
                <label class="label" for="orgId">Applies to</label>
                <input wire:model.live.debounce.300ms="orgSearch" type="search" class="input mb-2" placeholder="Search organizations" aria-label="Search organizations" aria-controls="orgId">
                <select wire:model="orgId" id="orgId" class="select">
                    <option value="">All organizations (environment-wide)</option>
                    @foreach ($organizations as $organization)
                        <option value="{{ $organization->id }}">{{ $organization->name }}</option>
                    @endforeach
                </select>
                @if ($organizations->count() === $pickerLimit)
                    <p class="mt-1 text-xs" style="color:var(--faint)">Showing the first {{ $pickerLimit }}. Search to narrow.</p>
                @endif
                @error('orgId') <p class="field-error" role="alert">{{ $message }}</p> @enderror
            </div>
        </div>

        <div>
            <label class="label" for="description">Description <span style="color:var(--faint)">(optional)</span></label>
            <input @error('description') aria-invalid="true" aria-describedby="description-error" @enderror wire:model="description" id="description" type="text" class="input" placeholder="Why these roles conflict">
            @error('description') <p id="description-error" class="field-error" role="alert">{{ $message }}</p> @enderror
        </div>

        <div>
            <span class="label" id="roles-label">Mutually-exclusive roles</span>
            <input wire:model.live.debounce.300ms="roleSearch" type="search" class="input mb-2" placeholder="Search roles" aria-label="Search roles">
            <div role="group" aria-labelledby="roles-label">
                @forelse ($roles as $role)
                    {{-- wire:key: without it Livewire's DOM diff reuses the checkbox
                         element when the search reorders this list, and a tick lands on
                         the wrong role. --}}
                    <label class="flex items-center gap-2 py-1 text-sm" wire:key="role-{{ $role->id }}">
                        <input type="checkbox" wire:model="roleIds" value="{{ $role->id }}" class="rounded">
                        <span>{{ $role->name }}</span>
                    </label>
                @empty
                    <p class="text-xs" style="color:var(--faint)">
                        {{ trim($roleSearch) === '' ? 'This environment has no roles to choose from yet.' : 'No role matches that search.' }}
                    </p>
                @endforelse
            </div>
            @if ($roles->count() === $pickerLimit)
                <p class="mt-1 text-xs" style="color:var(--faint)">Showing the first {{ $pickerLimit }}. Search to narrow.</p>
            @endif
            @error('roleIds') <p class="field-error" role="alert">{{ $message }}</p> @enderror
        </div>

        <div class="flex items-center gap-2">
            <button type="submit" class="btn btn-primary" wire:loading.attr="disabled" wire:target="define">Define rule</button>
            <a href="{{ route('environment.sod-policies') }}" class="btn btn-ghost">Cancel</a>
        </div>
    </form>
</div>
