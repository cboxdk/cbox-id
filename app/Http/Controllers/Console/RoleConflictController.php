<?php

declare(strict_types=1);

namespace App\Http\Controllers\Console;

use App\Http\Props\Shared\HelpProps;
use App\Http\Requests\Console\StoreRoleConflictRequest;
use App\Platform\Console\ConsolePlane;
use App\Platform\Help\HelpTopic;
use Cbox\Id\AccessControl\Models\Role;
use Cbox\Id\Governance\Contracts\SegregationOfDuties;
use Cbox\Id\Governance\Models\SodPolicy;
use Cbox\Id\Governance\ValueObjects\SodViolation;
use Cbox\Id\Identity\Contracts\Subjects;
use Cbox\Id\Organization\Models\Organization;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Response;

/**
 * CONSOLE › ROLE CONFLICTS — segregation of duties. A rule names two or more roles that
 * must never sit with the same person: whoever raises a payment should not also approve
 * it. Two halves, and this page is both — the PREVENTIVE one, which blocks a grant that
 * would break a rule, and the DETECTIVE one, which finds the people who already hold a
 * forbidden pair.
 *
 * The detective half is the reason the "choose an organization" state has to be said out
 * loud rather than rendered as an empty list. `scan()` takes an explicit organization, so
 * with none chosen there is nothing to scan — and reporting "no conflicts detected" for a
 * scan that never ran is a more dangerous answer than reporting nothing at all.
 *
 * AN ENVIRONMENT-WIDE RULE IS NOT A TENANT'S TO SWITCH OFF. It binds every organization
 * here, and an administrator who could deactivate it could then grant themselves the very
 * pair it forbids — a complete bypass of the control, from inside the organization
 * console. So a tenant SEES those rules (they must know what constrains them) and every
 * write re-resolves the rule inside {@see self::changeable()}, which excludes them.
 */
final readonly class RoleConflictController extends ConsoleController
{
    /** How many roles the picker offers before it asks for a narrower search. */
    private const PICKER_LIMIT = 50;

    public function index(Request $request, SegregationOfDuties $sod, Subjects $subjects): Response
    {
        $this->scope->assertMayAdminister();

        $organizationId = $this->actingOrganizationId();

        /*
         * The acting organization's own rules plus the environment-wide ones that bind
         * it. With none chosen — only possible for an environment administrator — every
         * rule in the environment, which is a deliberate overview rather than a leak: the
         * model's environment scope still bounds it.
         */
        $query = SodPolicy::query()
            ->when($organizationId !== null, fn (Builder $q): Builder => $q->where(
                fn (Builder $q): Builder => $q->whereNull('organization_id')->orWhere('organization_id', $organizationId),
            ))
            ->orderByDesc('id');

        $term = trim($request->string('q')->toString());

        if ($term !== '') {
            $query->where('name', 'like', '%'.$term.'%');
        }

        $policies = $query->get();

        $violations = $organizationId === null ? [] : $sod->scan($organizationId);

        $roleNames = $this->roleNames(
            $policies->pluck('role_ids')->flatten()
                ->merge(array_merge(...array_map(
                    static fn (SodViolation $violation): array => $violation->conflictingRoleIds,
                    $violations,
                )))
                ->all(),
        );

        $owners = $this->organizationNames($policies->pluck('organization_id')->all());

        return $this->page('console/role-conflicts/index', 'Role conflicts', [
            'help' => HelpProps::for(HelpTopic::RoleConflicts),
            'rules' => $policies->map(fn (SodPolicy $policy): array => [
                'id' => $policy->id,
                'name' => $policy->name,
                'owner' => $policy->organization_id !== null
                    ? ($owners[$policy->organization_id] ?? $policy->organization_id)
                    : null,
                'roles' => array_map(
                    fn (string $roleId): string => $roleNames[$roleId] ?? $roleId,
                    array_values(array_filter($policy->role_ids, 'is_string')),
                ),
                'active' => $policy->active,
                // A rule nobody may act on still has to be legible, so the row is drawn
                // either way and only the switch goes.
                'mayChange' => $this->mayChange($policy),
                'href' => $this->url('sod-policies.show', $policy->id),
                'toggleHref' => $this->url('sod-policies.toggle', $policy->id),
            ])->all(),
            'search' => $term,
            /*
             * A SCAN NEEDS AN ORGANIZATION. Told apart from "no conflicts" deliberately:
             * the page has to say which of the two it is, because the second is a result
             * and the first is the absence of one.
             */
            'organizationChosen' => $organizationId !== null,
            'violations' => $this->violationProps($violations, $roleNames, $subjects),
            'createHref' => $this->url('sod-policies.create'),
        ]);
    }

    public function create(Request $request): Response
    {
        $this->scope->assertMayAdminister();

        $organizationId = $this->actingOrganizationId();
        $chosen = array_values(array_filter((array) $request->input('roles', []), 'is_string'));
        $term = trim($request->string('roleSearch')->toString());

        /*
         * The organization's own roles plus the environment-wide ones, which its people
         * can hold too and can therefore conflict. With no organization chosen — only
         * possible for an environment administrator — every role in the environment.
         *
         * SEARCHED AND BOUNDED. It used to be an unbounded read of every role in the
         * environment, materialised to draw a checkbox list again on every action.
         */
        $roles = Role::query()
            ->when($organizationId !== null, fn (Builder $q): Builder => $q->where(
                fn (Builder $q): Builder => $q->whereNull('organization_id')->orWhere('organization_id', $organizationId),
            ))
            ->when($term !== '', fn (Builder $q): Builder => $q->where(
                fn (Builder $query): Builder => $query
                    ->where('name', 'like', '%'.$term.'%')
                    // ALREADY-TICKED ROLES ALWAYS SURVIVE THE FILTER. Otherwise typing
                    // into the search hides a checked box and the person submits a
                    // selection they can no longer see.
                    ->orWhereIn('id', $chosen),
            ))
            ->orderBy('name')
            ->limit(self::PICKER_LIMIT)
            ->get(['id', 'name']);

        return $this->page('console/role-conflicts/create', 'New role conflict', [
            'roles' => $roles->map(fn (Role $role): array => [
                'id' => $role->id,
                'name' => $role->name,
            ])->all(),
            'roleSearch' => $term,
            'pickerLimit' => self::PICKER_LIMIT,
            // Whether writing a rule for the whole environment is even on offer here.
            'holdsEnvironment' => $this->scope->plane() === ConsolePlane::Environment,
            'organizationChosen' => $organizationId !== null,
            'indexHref' => $this->url('sod-policies'),
            'storeHref' => $this->url('sod-policies.store'),
        ]);
    }

    public function store(StoreRoleConflictRequest $request, SegregationOfDuties $sod): RedirectResponse
    {
        $this->scope->assertMayAdminister();

        if ($request->environmentWide()) {
            /*
             * Server-side, not merely an absent checkbox: a rule with no organization
             * binds every tenant in the environment, and an organization administrator
             * who could write one would be legislating for organizations that are not
             * theirs.
             */
            if ($this->scope->plane() !== ConsolePlane::Environment) {
                return back()->withInput()->withErrors([
                    'environmentWide' => 'Only an environment administrator may write a rule for every organization.',
                ]);
            }

            $organizationId = null;
        } else {
            $organizationId = $this->actingOrganizationId();

            if ($organizationId === null) {
                return back()->withInput()->withErrors([
                    'name' => 'Choose an organization in the console header, or write the rule for the whole environment.',
                ]);
            }
        }

        $policy = $sod->definePolicy(
            $organizationId,
            $request->name(),
            $request->roleIds(),
            $request->description(),
        );

        return to_route($this->scope->routeName('sod-policies.show'), $policy->id)
            ->with('status', 'Rule "'.$policy->name.'" defined over '.count($policy->role_ids).' roles.');
    }

    public function show(Request $request, string $policy, SegregationOfDuties $sod, Subjects $subjects): Response
    {
        $this->scope->assertMayAdminister();

        $model = $this->visible()->whereKey($policy)->firstOrFail();

        $roleIds = array_values(array_filter($model->role_ids, 'is_string'));
        $roleNames = $this->roleNames($roleIds);
        $owners = $this->organizationNames([$model->organization_id]);

        /*
         * The organization this rule is evaluated against: its own where it has one, and
         * otherwise the one being administered. Never a picker on this page, which was the
         * second place that answer lived.
         */
        $scanOrganizationId = $model->organization_id ?? $this->actingOrganizationId();

        /*
         * ASKED FOR, not run on every render. A scan walks every grant in the
         * organization, and doing that on page load made opening a rule cost the size of
         * the tenant — for a report most visits never read.
         */
        $scanned = $request->boolean('scan') && $scanOrganizationId !== null;

        $violations = $scanned
            ? array_values(array_filter(
                $sod->scan((string) $scanOrganizationId),
                fn (SodViolation $violation): bool => $violation->policyId === $model->id,
            ))
            : [];

        return $this->page('console/role-conflicts/show', $model->name, [
            'rule' => [
                'id' => $model->id,
                'name' => $model->name,
                'description' => $model->description,
                'owner' => $model->organization_id !== null
                    ? ($owners[$model->organization_id] ?? $model->organization_id)
                    : null,
                'roles' => array_map(fn (string $id): string => $roleNames[$id] ?? $id, $roleIds),
                'active' => $model->active,
            ],
            // A rule this administrator may read but not switch still renders — they have
            // to see what constrains them — so the page asks rather than assuming.
            'mayChange' => $this->mayChange($model),
            // Null is a different answer from "no conflicts": there is no organization to
            // evaluate against, so no scan has run.
            'scannable' => $scanOrganizationId !== null,
            'scanned' => $scanned,
            'violations' => $this->violationProps(
                $violations,
                $this->roleNames(array_merge(...array_map(
                    static fn (SodViolation $violation): array => $violation->conflictingRoleIds,
                    $violations,
                ))),
                $subjects,
            ),
            'indexHref' => $this->url('sod-policies'),
            'scanHref' => $this->url('sod-policies.show', $model->id).'?scan=1',
            'urls' => [
                'toggle' => $this->url('sod-policies.toggle', $model->id),
                'destroy' => $this->url('sod-policies.destroy', $model->id),
            ],
        ]);
    }

    /**
     * Activate or deactivate, said as one endpoint.
     *
     * The state it moves TO is read from the record: there are two of them and the record
     * knows which it is in, so a posted intent would only add a way for the switch and the
     * row to disagree.
     */
    public function toggle(string $policy, SegregationOfDuties $sod): RedirectResponse
    {
        $this->scope->assertMayAdminister();

        $model = $this->changeable()->whereKey($policy)->firstOrFail();

        if ($model->organization_id === null) {
            // The unscoped call, which the contract reserves for the control plane — only
            // reachable here by an administrator who holds the environment.
            $sod->setActive($model->id, ! $model->active);
        } else {
            // The framework asserts ownership too, so this is the console's half of the
            // same gate rather than the only one.
            $sod->setActiveForOrganization($model->organization_id, $model->id, ! $model->active);
        }

        return back()->with('status', $model->active
            ? 'Rule deactivated — grants that would break it are allowed again.'
            : 'Rule activated — grants that would break it are blocked from now on.');
    }

    public function destroy(string $policy): RedirectResponse
    {
        $this->scope->assertMayAdminister();

        $model = $this->changeable()->whereKey($policy)->firstOrFail();
        $name = $model->name;

        $model->delete();

        return to_route($this->scope->routeName('sod-policies'))
            ->with('status', 'Rule "'.$name.'" removed.');
    }

    /**
     * Whether this rule is this administrator's to switch — the readable half of
     * {@see self::changeable()}, which is the half that gates the writes.
     */
    private function mayChange(SodPolicy $policy): bool
    {
        if ($this->scope->plane() === ConsolePlane::Environment) {
            return true;
        }

        return $policy->organization_id !== null
            && $policy->organization_id === $this->scope->organizationId();
    }

    /**
     * The rules this administrator may READ: the acting organization's own, plus the
     * environment-wide ones that bind it — an organization must see what constrains it.
     *
     * @return Builder<SodPolicy>
     */
    private function visible(): Builder
    {
        $organizationId = $this->actingOrganizationId();

        return SodPolicy::query()
            ->when($organizationId !== null, fn (Builder $q): Builder => $q->where(
                fn (Builder $q): Builder => $q->whereNull('organization_id')->orWhere('organization_id', $organizationId),
            ));
    }

    /**
     * The rules this administrator may WRITE to, as a query rather than a predicate, so a
     * mutation resolves its target INSIDE the gate instead of checking afterwards.
     *
     * @return Builder<SodPolicy>
     */
    private function changeable(): Builder
    {
        if ($this->scope->plane() === ConsolePlane::Environment) {
            // Environment-scoped by the model, so this is every rule in THIS environment
            // and never another's.
            return SodPolicy::query();
        }

        return SodPolicy::query()
            ->whereNotNull('organization_id')
            ->where('organization_id', $this->scope->requireOrganizationId());
    }

    /**
     * The people already holding a forbidden pair.
     *
     * NAMED, not a bare ULID. The whole output of this half of the feature is a list of
     * people somebody has to go and talk to, and "01JQ…" is not a person — the Volt page
     * printed the subject id and left the reader to look each one up by hand.
     *
     * @param  list<SodViolation>  $violations
     * @param  array<string, string>  $roleNames
     * @return list<array{policy: string, subject: string, subjectId: string, roles: list<string>}>
     */
    private function violationProps(array $violations, array $roleNames, Subjects $subjects): array
    {
        if ($violations === []) {
            return [];
        }

        // ONE QUERY for the whole report, rather than one per person named in it.
        $people = $subjects->findMany(array_values(array_unique(array_map(
            static fn (SodViolation $violation): string => $violation->subjectId,
            $violations,
        ))));

        $labels = [];

        foreach ($people as $subject) {
            $name = $subject->name ?? $subject->email;

            if (is_string($name) && $name !== '') {
                $labels[$subject->id] = $name;
            }
        }

        return array_map(static fn (SodViolation $violation): array => [
            'policy' => $violation->policyName,
            'subject' => $labels[$violation->subjectId] ?? $violation->subjectId,
            'subjectId' => $violation->subjectId,
            'roles' => array_map(
                static fn (string $roleId): string => $roleNames[$roleId] ?? $roleId,
                $violation->conflictingRoleIds,
            ),
        ], $violations);
    }

    /**
     * role id => name, for the ids this page has to name.
     *
     * BOUNDED to those ids. Both pages used to read every role in the environment to
     * translate a handful of them, on every render.
     *
     * @param  array<array-key, mixed>  $roleIds
     * @return array<string, string>
     */
    private function roleNames(array $roleIds): array
    {
        $wanted = array_values(array_unique(array_filter($roleIds, 'is_string')));

        if ($wanted === []) {
            return [];
        }

        $names = [];

        foreach (Role::query()->whereIn('id', $wanted)->get(['id', 'name']) as $role) {
            $names[$role->id] = $role->name;
        }

        return $names;
    }

    /**
     * organization id => name, for the ids this page has to name.
     *
     * @param  array<array-key, mixed>  $organizationIds
     * @return array<string, string>
     */
    private function organizationNames(array $organizationIds): array
    {
        $wanted = array_values(array_unique(array_filter($organizationIds, 'is_string')));

        if ($wanted === []) {
            return [];
        }

        $names = [];

        foreach (Organization::query()->whereIn('id', $wanted)->get(['id', 'name']) as $organization) {
            $names[$organization->id] = $organization->name;
        }

        return $names;
    }
}
