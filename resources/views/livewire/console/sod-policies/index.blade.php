<?php

declare(strict_types=1);

use App\Platform\Console\ConsolePlane;
use App\Platform\Console\ConsoleScope;
use Cbox\Id\AccessControl\Models\Role;
use Cbox\Id\Governance\Contracts\SegregationOfDuties;
use Cbox\Id\Governance\Models\SodPolicy;
use Cbox\Id\Organization\Models\Organization;
use Illuminate\Database\Eloquent\Builder;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Url;
use Livewire\Volt\Component;

/**
 * Role conflicts (list) — one component, both planes. Segregation-of-Duties rules that
 * declare sets of roles which must never be held together, plus the detective half:
 * who already holds a forbidden pair. Each row deep-links to the rule's own page,
 * where the toggle, the per-organization evaluation and deletion live.
 *
 * This was two pages. The organization plane had a single page that could define and
 * activate rules and listed existing violations; the environment plane had a routable
 * list → new → detail that could also evaluate and delete, and showed no violations at
 * all. Both halves are here now: the routable shape wins (a rule URL is something you
 * send to whoever owns the control), and the violations report comes with it.
 *
 * Policies are environment-owned (BelongsToEnvironment), so the model's global scope
 * only ever resolves records inside this environment — an id from another plane never
 * matches, closing cross-tenant id tampering.
 */
new #[Layout('components.layouts.console', ['title' => 'Role conflicts'])] class extends Component
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

    #[Url(as: 'q')]
    public string $search = '';

    /**
     * Activate/deactivate a rule from the list.
     *
     * Kept on the list, not moved to the detail page: switching a control off is the
     * thing an administrator comes here to do, and the organization console had it
     * inline. Resolving the target through the CHANGEABLE set rather than hiding a
     * button is what makes it a gate — a forged id that is not this administrator's to
     * touch resolves to nothing and 404s (deny-by-default) instead of toggling.
     */
    public function toggle(string $id, SegregationOfDuties $sod): void
    {
        $policy = $this->changeable()->whereKey($id)->firstOrFail();

        if ($policy->organization_id === null) {
            // The unscoped call, which the contract reserves for the control plane —
            // only reachable here by an administrator who holds the environment.
            $sod->setActive($policy->id, ! $policy->active);

            return;
        }

        // The framework asserts ownership too (setActiveForOrganization), so this is the
        // UI's half of the same gate rather than the only one.
        $sod->setActiveForOrganization($policy->organization_id, $policy->id, ! $policy->active);
    }

    /**
     * @return array<string, mixed>
     */
    public function with(): array
    {
        $organizationId = $this->actingOrganizationId();

        // Scoped to the acting organization when one is chosen — its own rules plus the
        // environment-wide ones that bind it, because an organization must see what
        // constrains it. With none chosen — only possible for an environment
        // administrator — this is every rule in the environment, which is a deliberate
        // overview rather than a leak: the model's environment scope still bounds it.
        $query = SodPolicy::query()
            ->when($organizationId !== null, fn (Builder $q): Builder => $q->where(
                fn (Builder $q): Builder => $q->whereNull('organization_id')->orWhere('organization_id', $organizationId)
            ))
            ->orderByDesc('id');

        $term = trim($this->search);
        if ($term !== '') {
            $query->where('name', 'like', "%{$term}%");
        }

        return [
            // Route names differ per plane; one component, so it asks rather than assumes.
            'scopeRoute' => fn (string $name): string => app(ConsoleScope::class)->routeName($name),
            'policies' => $query->get(),
            'roleNames' => Role::query()->orderBy('name')->pluck('name', 'id'),
            'orgNames' => Organization::query()->orderBy('name')->pluck('name', 'id'),
            // A rule nobody may act on still has to be legible, so the view asks per row
            // rather than hiding what this administrator cannot change.
            'mayChange' => fn (SodPolicy $policy): bool => $this->mayChange($policy),
            // The detective half. `scan()` takes an explicit organization, so with none
            // chosen there is nothing to scan — which the view has to SAY, because "no
            // conflicts detected" would be a lie rather than a result.
            'organizationChosen' => $organizationId !== null,
            'violations' => $organizationId === null ? [] : app(SegregationOfDuties::class)->scan($organizationId),
        ];
    }

    /**
     * Whether this rule is this administrator's to switch.
     *
     * An environment-wide rule (organization_id null) is the control plane's own and
     * binds every organization here. An organization admin who could switch it off
     * could then grant themselves the very pair it forbids — a complete bypass of the
     * control, from inside the organization console. So it is theirs to SEE and not to
     * change; an administrator who holds the environment owns it and may.
     */
    private function mayChange(SodPolicy $policy): bool
    {
        $scope = app(ConsoleScope::class);

        if ($scope->plane() === ConsolePlane::Environment) {
            return true;
        }

        return $policy->organization_id !== null
            && $policy->organization_id === $scope->organizationId();
    }

    /**
     * The rules this administrator may write to, as a query rather than a predicate, so
     * a mutation resolves its target INSIDE the gate instead of checking afterwards.
     *
     * @return Builder<SodPolicy>
     */
    private function changeable(): Builder
    {
        $scope = app(ConsoleScope::class);

        if ($scope->plane() === ConsolePlane::Environment) {
            // Environment-scoped by the model, so this is every rule in THIS environment
            // and never another's.
            return SodPolicy::query();
        }

        return SodPolicy::query()
            ->whereNotNull('organization_id')
            ->where('organization_id', $scope->requireOrganizationId());
    }

    /**
     * The organization whose rules this page is about, or null for the whole-environment
     * overview.
     *
     * Null has to mean "an environment administrator has not chosen yet" and never "this
     * member has no organization" — the nullable reader answers null for both, and on
     * the organization plane that second case would widen the list from one organization
     * to every organization in the environment.
     */
    private function actingOrganizationId(): ?string
    {
        $scope = app(ConsoleScope::class);

        return $scope->plane() === ConsolePlane::Environment
            ? $scope->organizationId()
            : $scope->requireOrganizationId();
    }
}; ?>

<div>
    <x-page-header title="Role conflicts" :help="\App\Platform\Help\HelpTopic::RoleConflicts"
                   subtitle="Declare the roles that must never sit with the same person — segregation of duties — and see who already holds a conflicting pair.">
        <x-slot:actions>
            <a href="{{ route($scopeRoute('sod-policies.create')) }}" class="btn btn-primary shrink-0"><x-icon name="plus" class="w-4 h-4" /> New rule</a>
        </x-slot:actions>
    </x-page-header>

    <div class="mt-6">
        <input wire:model.live.debounce.300ms="search" type="search" class="input" style="max-width:24rem" placeholder="Search by name" aria-label="Search role conflicts">
        {{-- SC 4.1.3: the list re-renders on a debounced keystroke with no focus
             change, so the result count is the only thing that can report the filter
             narrowed to nothing. --}}
        <p role="status" aria-live="polite" class="sr-only">{{ $policies->count() }} {{ \Illuminate\Support\Str::plural('rule', $policies->count()) }} found.</p>
    </div>

    <div class="mt-4 rounded-xl border overflow-hidden" style="border-color:var(--border)">
        @forelse ($policies as $policy)
            {{-- A row rather than one big link: the switch lives in it, and a button
                 inside an anchor is neither valid nor operable by keyboard. --}}
            <div class="flex items-center gap-3 p-4 {{ ! $loop->last ? 'border-b' : '' }}" style="border-color:var(--border)" wire:key="policy-{{ $policy->id }}">
                <div class="min-w-0 flex-1">
                    <a href="{{ route($scopeRoute('sod-policies.show'), $policy->id) }}" class="font-medium truncate">{{ $policy->name }}</a>
                    <div class="mt-1 flex flex-wrap items-center gap-1.5">
                        @if ($policy->organization_id === null)
                            <span class="badge" title="Set for the whole environment — it applies here but is not yours to change.">Environment-wide</span>
                        @else
                            <span class="badge">{{ $orgNames[$policy->organization_id] ?? $policy->organization_id }}</span>
                        @endif
                        @foreach ($policy->role_ids as $roleId)
                            <span class="badge">{{ $roleNames[$roleId] ?? $roleId }}</span>
                        @endforeach
                    </div>
                </div>

                @if ($policy->active)
                    <span class="badge badge-success">Active</span>
                @else
                    <span class="badge">Inactive</span>
                @endif

                @if ($mayChange($policy))
                    <button type="button" wire:click="toggle('{{ $policy->id }}')" class="btn btn-ghost btn-sm shrink-0"
                            wire:loading.attr="disabled" wire:target="toggle('{{ $policy->id }}')">{{ $policy->active ? 'Deactivate' : 'Activate' }}</button>
                @else
                    <span class="text-xs shrink-0" style="color:var(--faint)">Managed for the environment</span>
                @endif
            </div>
        @empty
            @if (trim($search) !== '')
                <div class="cbx-empty">
                    <div class="cbx-empty-icon"><x-icon name="search" class="w-5 h-5" /></div>
                    <h3>No rules match "{{ trim($search) }}"</h3>
                    <p>No role conflict matches that name. Try a different search.</p>
                </div>
            @else
                <x-empty-state icon="shield" title="No rules yet"
                    :help="\App\Platform\Help\HelpTopic::RoleConflicts"
                    body="A rule names two or more roles that must never sit with the same person — whoever raises a payment should not also approve it."
                    :steps="[
                        'Name the conflict the way your auditor would — “Raise payment vs. approve payment”.',
                        'Pick the roles that must not be combined.',
                        'Save it: new grants that would break the rule are blocked, and anyone who already holds the pair is listed for you.',
                    ]" />
            @endif
        @endforelse
    </div>

    {{-- Violations --}}
    <div class="mt-8">
        <h2 class="font-semibold mb-3">Violations</h2>
        @if (! $organizationChosen)
            {{-- Not "no conflicts detected": a scan needs an organization, and reporting
                 a clean result for a scan that never ran is the more dangerous answer. --}}
            <p class="text-sm" style="color:var(--faint)">Choose an organization above to scan it for people who already hold a conflicting pair.</p>
        @else
            @forelse ($violations as $violation)
                <div class="card p-4 mb-3" style="border-color:var(--warning-strong)">
                    <div class="flex items-center gap-2 mb-1">
                        <span class="cbx-pill cbx-pill--warning"><span class="dot"></span>{{ $violation->policyName }}</span>
                        <span class="mono">{{ $violation->subjectId }}</span>
                    </div>
                    <p class="text-sm" style="color:var(--muted)">
                        Holds conflicting roles:
                        @foreach ($violation->conflictingRoleIds as $roleId)
                            <span class="badge">{{ $roleNames[$roleId] ?? $roleId }}</span>
                        @endforeach
                    </p>
                </div>
            @empty
                <p class="text-sm" style="color:var(--faint)">No conflicts detected.</p>
            @endforelse
        @endif
    </div>
</div>
