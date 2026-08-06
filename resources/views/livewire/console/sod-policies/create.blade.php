<?php

declare(strict_types=1);

use App\Platform\Console\ConsolePlane;
use App\Platform\Console\ConsoleScope;
use Cbox\Id\AccessControl\Models\Role;
use Cbox\Id\Governance\Contracts\SegregationOfDuties;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\Builder;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Validate;
use Livewire\Volt\Component;

/**
 * Role conflicts › New — one component, both planes. A dedicated, deep-linkable page
 * that defines a Segregation-of-Duties rule: a set of roles that must never be held
 * together. On success it routes straight to the rule's own page.
 *
 * The organization is NOT a field here. It was, on the environment plane, and it was
 * the second place the answer lived — the console chrome owns the choice now. What
 * survives of that picker is the one thing it also encoded and the scope cannot: a rule
 * can be written for the whole ENVIRONMENT rather than for one organization, which is a
 * different rule, not a different organization. Only an administrator who holds the
 * environment may write one, because it binds every organization in it.
 */
new #[Layout('components.layouts.console', ['title' => 'New role conflict'])] class extends Component
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
        app(ConsoleScope::class)->assertMayAdminister();
    }

    /** How many options the role picker will draw before the search has to narrow it. */
    private const PICKER_LIMIT = 50;

    #[Validate('required|string|max:190')]
    public string $name = '';

    #[Validate('nullable|string|max:500')]
    public string $description = '';

    /**
     * Write the rule for every organization in the environment rather than for the one
     * being administered. Offered — and accepted — only on the environment plane.
     */
    public bool $environmentWide = false;

    /**
     * Livewire rehydrates this straight off the wire, so the keys are whatever the
     * request sent — not necessarily a gapless list. Hence the array_values() before
     * it is handed on.
     *
     * @var array<array-key, string>
     */
    public array $roleIds = [];

    /** Narrows the role checkboxes below. */
    public string $roleSearch = '';

    public function define(SegregationOfDuties $sod): mixed
    {
        $this->validate();

        if (count($this->roleIds) < 2) {
            $this->addError('roleIds', 'Select at least two roles for a mutually-exclusive set.');

            return null;
        }

        $scope = app(ConsoleScope::class);

        if ($this->environmentWide) {
            // Server-side, not just an absent checkbox: a rule with no organization binds
            // every tenant in the environment, and an organization admin who could write
            // one would be legislating for organizations that are not theirs.
            if ($scope->plane() !== ConsolePlane::Environment) {
                $this->addError('environmentWide', 'Only an environment administrator may write a rule for every organization.');

                return null;
            }

            $organizationId = null;
        } else {
            // The organization comes from the scope, not from a field on this form.
            try {
                $organizationId = $scope->requireOrganizationId();
            } catch (AuthorizationException $e) {
                $this->addError('name', $e->getMessage());

                return null;
            }
        }

        $policy = $sod->definePolicy(
            $organizationId,
            $this->name,
            array_values($this->roleIds),
            $this->description !== '' ? $this->description : null,
        );

        $this->dispatch('toast', message: 'Rule "'.$policy->name.'" defined over '.count($policy->role_ids).' roles.');

        return $this->redirectRoute($scope->routeName('sod-policies.show'), ['policy' => $policy->id], navigate: true);
    }

    /**
     * The role picker is SEARCHED and BOUNDED. It used to be an unbounded `get()` of
     * every role in the environment, materialised to draw a checkbox list again on every
     * Livewire action.
     *
     * Already-selected roles are always included regardless of the search term —
     * otherwise typing into the filter would hide a checked box, and the user would
     * submit a selection they could no longer see.
     *
     * @return array<string, mixed>
     */
    public function with(): array
    {
        $scope = app(ConsoleScope::class);
        $organizationId = $scope->plane() === ConsolePlane::Environment
            ? $scope->organizationId()
            : $scope->requireOrganizationId();

        // The organization's own roles plus the environment-wide ones, which its people
        // can hold too and can therefore conflict. With no organization chosen — only
        // possible for an environment administrator — every role in the environment.
        $roles = Role::query()
            ->when($organizationId !== null, fn (Builder $q): Builder => $q->where(
                fn (Builder $q): Builder => $q->whereNull('organization_id')->orWhere('organization_id', $organizationId)
            ))
            ->orderBy('name');

        $term = trim($this->roleSearch);

        if ($term !== '') {
            $roles->where(fn (Builder $query): Builder => $query
                ->where('name', 'like', "%{$term}%")
                ->orWhereIn('id', array_values($this->roleIds)));
        }

        return [
            // Route names differ per plane; one component, so it asks rather than assumes.
            'scopeRoute' => fn (string $name): string => app(ConsoleScope::class)->routeName($name),
            'roles' => $roles->limit(self::PICKER_LIMIT)->get(),
            'pickerLimit' => self::PICKER_LIMIT,
            // Whether writing a rule for the whole environment is even on offer here.
            'holdsEnvironment' => $scope->plane() === ConsolePlane::Environment,
            'organizationChosen' => $organizationId !== null,
        ];
    }
}; ?>

<div>
    <a href="{{ route($scopeRoute('sod-policies')) }}" class="text-sm inline-flex items-center gap-1" style="color:var(--muted)"><x-icon name="chevron" class="w-3.5 h-3.5 rotate-180" /> Role conflicts</a>
    <x-page-header class="mt-2" title="New role conflict" subtitle="Holding two or more roles from the set at once is a violation." />

    <form wire:submit="define" class="mt-6 max-w-xl rounded-xl border p-5 space-y-4" style="border-color:var(--border)">
        <div>
            <label class="label" for="name">Rule name</label>
            <input @error('name') aria-invalid="true" aria-describedby="name-error" @enderror wire:model="name" id="name" type="text" class="input" placeholder="Purchase order vs. approve payment" autofocus>
            @error('name') <p id="name-error" class="field-error" role="alert">{{ $message }}</p> @enderror
        </div>

        <div>
            <label class="label" for="description">Description <span style="color:var(--faint)">(optional)</span></label>
            <input @error('description') aria-invalid="true" aria-describedby="description-error" @enderror wire:model="description" id="description" type="text" class="input" placeholder="Why these roles conflict">
            @error('description') <p id="description-error" class="field-error" role="alert">{{ $message }}</p> @enderror
        </div>

        @if ($holdsEnvironment)
            <div>
                <label class="flex items-center gap-2" for="environmentWide">
                    <input wire:model.live="environmentWide" id="environmentWide" type="checkbox" class="rounded">
                    <span>Apply to every organization in this environment</span>
                </label>
                <p class="mt-1 text-xs" style="color:var(--faint)">An environment-wide rule binds every organization here and is not theirs to switch off.</p>
                @error('environmentWide') <p class="field-error" role="alert">{{ $message }}</p> @enderror
            </div>
        @endif

        @if (! $organizationChosen && ! $environmentWide)
            {{-- The distinction that matters: nothing is wrong with this administrator,
                 they simply have not said which organization the rule is for. --}}
            <p class="text-sm" style="color:var(--warning-strong)" role="status">Choose an organization in the console header, or apply the rule to the whole environment.</p>
        @endif

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
                        {{ trim($roleSearch) === '' ? 'There are no roles to choose from yet.' : 'No role matches that search.' }}
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
            <a href="{{ route($scopeRoute('sod-policies')) }}" class="btn btn-ghost">Cancel</a>
        </div>
    </form>
</div>
