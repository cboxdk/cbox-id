<?php

declare(strict_types=1);

use App\Platform\Console\ConsolePlane;
use App\Platform\Console\ConsoleScope;
use Cbox\Id\AccessControl\Models\Role;
use Cbox\Id\Governance\Contracts\SegregationOfDuties;
use Cbox\Id\Governance\Models\SodPolicy;
use Cbox\Id\Governance\ValueObjects\SodViolation;
use Cbox\Id\Organization\Models\Organization;
use Illuminate\Database\Eloquent\Builder;
use Livewire\Attributes\Layout;
use Livewire\Volt\Component;

/**
 * Role conflicts › detail — one component, both planes. The full, deep-linkable
 * lifecycle for one Segregation-of-Duties rule: its mutually-exclusive roles and scope,
 * the activate/deactivate toggle, an evaluation that finds people who already hold the
 * toxic combination, and deletion.
 *
 * Evaluation and deletion existed only on the environment plane and the organization
 * plane only ever got a toggle; both planes have all of it now, bounded by whose rule
 * it is rather than by which door the administrator came through.
 *
 * Every read/write re-resolves the rule within THIS environment (the SodPolicy model's
 * BelongsToEnvironment scope) AND within what this administrator may see or change, and
 * 404s otherwise — an id from another organization or another plane never matches
 * (deny-by-default), so a foreign rule can neither be read nor switched off.
 */
new #[Layout('components.layouts.console', ['title' => 'Role conflict'])] class extends Component
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

    public string $policyId = '';

    /** Whether an evaluation has been requested — gates the violations report in with(). */
    public bool $scanned = false;

    public function mount(string $policy): void
    {
        $this->policyId = $this->visible()->whereKey($policy)->firstOrFail()->id;
    }

    private function policy(): SodPolicy
    {
        return $this->visible()->whereKey($this->policyId)->firstOrFail();
    }

    public function toggle(SegregationOfDuties $sod): void
    {
        $policy = $this->changeable()->whereKey($this->policyId)->firstOrFail();

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
     * Evaluate the rule against an organization's existing grants.
     *
     * The organization is the rule's own where it has one, and otherwise the one being
     * administered — never a picker on this page, which was the second place the answer
     * lived.
     */
    public function scan(): void
    {
        if ($this->scanOrganizationIdFor($this->policy()) === null) {
            $this->addError('scan', 'Choose an organization to evaluate this rule against.');

            return;
        }

        $this->scanned = true;
    }

    public function remove(): mixed
    {
        $policy = $this->changeable()->whereKey($this->policyId)->firstOrFail();
        $name = $policy->name;
        $policy->delete();

        $this->dispatch('toast', message: 'Rule "'.$name.'" removed.');

        return $this->redirectRoute(app(ConsoleScope::class)->routeName('sod-policies'), navigate: true);
    }

    /**
     * @return array<string, mixed>
     */
    public function with(): array
    {
        $policy = $this->policy();
        $scanOrganizationId = $this->scanOrganizationIdFor($policy);

        $violations = [];
        if ($this->scanned && $scanOrganizationId !== null) {
            $violations = array_values(array_filter(
                app(SegregationOfDuties::class)->scan($scanOrganizationId),
                fn (SodViolation $v): bool => $v->policyId === $policy->id,
            ));
        }

        return [
            // Route names differ per plane; one component, so it asks rather than assumes.
            'scopeRoute' => fn (string $name): string => app(ConsoleScope::class)->routeName($name),
            'policy' => $policy,
            'roleNames' => Role::query()->orderBy('name')->pluck('name', 'id'),
            'orgNames' => Organization::query()->orderBy('name')->pluck('name', 'id'),
            // A rule this administrator may read but not switch still renders — they have
            // to see what constrains them — so the view asks rather than assuming.
            'mayChange' => $this->mayChange($policy),
            'scanOrganizationId' => $scanOrganizationId,
            'violations' => $violations,
        ];
    }

    /**
     * The organization this rule is evaluated against, or null when there is none to
     * evaluate — which only an environment administrator who has chosen no organization
     * can see, and which is a different answer from "no conflicts".
     */
    private function scanOrganizationIdFor(SodPolicy $policy): ?string
    {
        return $policy->organization_id ?? $this->actingOrganizationId();
    }

    /**
     * Whether this rule is this administrator's to switch — the readable half of
     * {@see self::changeable()}, which is the half that actually gates the writes.
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
     * The rules this administrator may READ: the acting organization's own, plus the
     * environment-wide ones that bind it — an organization must see what constrains it.
     * With no organization chosen (only an environment administrator) every rule in the
     * environment, which the model's environment scope still bounds.
     *
     * @return Builder<SodPolicy>
     */
    private function visible(): Builder
    {
        $organizationId = $this->actingOrganizationId();

        return SodPolicy::query()
            ->when($organizationId !== null, fn (Builder $q): Builder => $q->where(
                fn (Builder $q): Builder => $q->whereNull('organization_id')->orWhere('organization_id', $organizationId)
            ));
    }

    /**
     * The rules this administrator may WRITE to, as a query rather than a predicate, so
     * a mutation resolves its target INSIDE the gate instead of checking afterwards.
     *
     * An environment-wide rule is the control plane's own and binds every organization
     * here: an organization admin who could switch it off could then grant themselves
     * the very pair it forbids.
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
     * Null has to mean "an environment administrator has not chosen yet" and never "this
     * member has no organization" — the nullable reader answers null for both, and on
     * the organization plane that second case would open every rule in the environment.
     */
    private function actingOrganizationId(): ?string
    {
        $scope = app(ConsoleScope::class);

        return $scope->plane() === ConsolePlane::Environment
            ? $scope->organizationId()
            : $scope->requireOrganizationId();
    }
}; ?>

<div class="space-y-6">
    <div>
        <a href="{{ route($scopeRoute('sod-policies')) }}" class="text-sm inline-flex items-center gap-1" style="color:var(--muted)"><x-icon name="chevron" class="w-3.5 h-3.5 rotate-180" /> Role conflicts</a>
        <div class="mt-2 flex items-center gap-3 flex-wrap">
            <h1 class="font-semibold tracking-tight" style="font-size:1.5rem">{{ $policy->name }}</h1>
            @if ($policy->active)
                <span class="badge badge-success">Active</span>
            @else
                <span class="badge">Inactive</span>
            @endif
        </div>
        <p class="mt-1 text-sm mono" style="color:var(--faint)">{{ $policy->id }}</p>
    </div>

    {{-- Details --}}
    <div class="rounded-xl border p-5" style="border-color:var(--border)">
        <h2 class="cbx-section-title">Details</h2>
        @if ($policy->description)
            <p class="mt-2 text-sm" style="color:var(--muted)">{{ $policy->description }}</p>
        @endif
        <dl class="mt-4 grid gap-4 sm:grid-cols-2">
            <div>
                <dt class="label">Scope</dt>
                <dd class="mt-1"><span class="badge">{{ $policy->organization_id ? ($orgNames[$policy->organization_id] ?? $policy->organization_id) : 'Environment-wide' }}</span></dd>
            </div>
            <div>
                <dt class="label">Conflicting roles</dt>
                <dd class="mt-1 flex flex-wrap gap-1.5">
                    @foreach ($policy->role_ids as $roleId)
                        <span class="badge">{{ $roleNames[$roleId] ?? $roleId }}</span>
                    @endforeach
                </dd>
            </div>
        </dl>
        <p class="mt-4 text-xs" style="color:var(--faint)">Holding two or more roles from this set at once is a violation.</p>
        <div class="mt-4">
            @if (! $mayChange)
                <p class="text-xs" style="color:var(--faint)">Managed for the environment — it applies here but is not yours to change.</p>
            @elseif ($policy->active)
                {{-- Deactivating stops a live compliance control from being enforced, so it
                     gets the same type-to-confirm as a delete. Activating does not. --}}
                <x-confirm-delete
                    :name="$policy->name"
                    action="toggle"
                    label="Deactivate"
                    consequence="This separation-of-duties control stops being enforced until it is activated again." />
            @else
                <button type="button" class="btn btn-ghost btn-sm" wire:click="toggle" wire:loading.attr="disabled" wire:target="toggle">Activate</button>
            @endif
        </div>
    </div>

    {{-- Evaluate --}}
    <div class="rounded-xl border p-5" style="border-color:var(--border)">
        <h2 class="cbx-section-title">Evaluate</h2>
        <p class="mt-1 text-sm" style="color:var(--muted)">Scan for people who already hold this rule's conflicting combination.</p>

        @if ($scanOrganizationId === null)
            {{-- Not an entitlement problem and not a clean result: an evaluation needs an
                 organization, and this administrator simply has not chosen one. --}}
            <p class="mt-4 text-sm" style="color:var(--warning-strong)" role="status">Choose an organization in the console header to evaluate this rule against it.</p>
        @else
            <form wire:submit="scan" class="mt-4 flex flex-wrap items-end gap-3">
                <div class="grow min-w-48">
                    <span class="label">Organization</span>
                    <p class="mt-1 text-sm">{{ $orgNames[$scanOrganizationId] ?? $scanOrganizationId }}</p>
                </div>
                <button type="submit" class="btn btn-primary" wire:loading.attr="disabled" wire:target="scan">Scan</button>
            </form>
            @error('scan') <p class="mt-2 field-error" role="alert">{{ $message }}</p> @enderror
        @endif

        @if ($scanned)
            <div class="mt-4 space-y-3">
                @forelse ($violations as $violation)
                    <div class="rounded-xl border p-5" style="border-color:var(--destructive)">
                        <div class="flex items-center gap-2 flex-wrap">
                            <span class="badge badge-danger">{{ $violation->policyName }}</span>
                            <span class="mono text-xs" style="color:var(--faint)">{{ $violation->subjectId }}</span>
                        </div>
                        <p class="mt-2 text-sm" style="color:var(--muted)">Holds conflicting roles:</p>
                        <div class="mt-2 flex flex-wrap gap-1.5">
                            @foreach ($violation->conflictingRoleIds as $roleId)
                                <span class="badge">{{ $roleNames[$roleId] ?? $roleId }}</span>
                            @endforeach
                        </div>
                    </div>
                @empty
                    <div class="cbx-empty">
                        <div class="cbx-empty-icon"><x-icon name="shield-check" class="w-5 h-5" /></div>
                        <h3>No conflicts detected</h3>
                        <p>Nobody in this organization holds this rule's conflicting combination.</p>
                    </div>
                @endforelse
            </div>
        @endif
    </div>

    {{-- Lifecycle --}}
    @if ($mayChange)
        <div class="rounded-xl border p-5" style="border-color:var(--border)">
            <h2 class="cbx-section-title">Lifecycle</h2>
            <div class="mt-4 flex flex-wrap gap-2">
                <x-confirm-delete
                    :name="$policy->name"
                    action="remove"
                    label="Delete rule"
                    consequence="This separation-of-duties control stops being enforced. This cannot be undone." />
            </div>
        </div>
    @endif
</div>
