<?php

declare(strict_types=1);

namespace App\Http\Controllers\Console;

use App\Http\Props\Shared\HelpProps;
use App\Http\Requests\Console\SavePermissionRequest;
use App\Http\Requests\Console\StorePermissionRequest;
use App\Platform\Console\ConsolePlane;
use App\Platform\Help\HelpTopic;
use Cbox\Id\AccessControl\Contracts\Roles;
use Cbox\Id\AccessControl\Models\Permission;
use Cbox\Id\AccessControl\Models\Role;
use Cbox\Id\Kernel\Tenancy\Contracts\EnvironmentContext;
use Cbox\Id\OAuthServer\Models\Client;
use Illuminate\Contracts\Database\Query\Builder as QueryBuilder;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Inertia\Response;

/**
 * CONSOLE › PERMISSIONS — the catalogue every role is composed from.
 *
 * A permission is a `feature:action` key, and it arrives one of two ways:
 *   APP-DECLARED (`client_id` set) — synced from an app's manifest over the SDK. The app
 *     is its source of truth, so it is read-only here; an app that stops declaring one
 *     leaves it orphaned rather than deleted.
 *   MANUAL (`client_id` null) — authored right here, for an organization that runs no SDK
 *     integration and still needs its own vocabulary to build roles out of.
 *
 * TWO TIERS, AND THE PLANE DECIDES WHICH ONE IS BEING WRITTEN. A manual permission
 * authored on the environment plane is shared with every tenant in the environment; one
 * authored on the organization plane belongs to that tenant alone. It used to be shared
 * either way, so a tenant admin's "Add permission" quietly edited the whole environment's
 * catalogue — and their Delete stripped the key from every role in it.
 *
 * The organization is read from the PLANE and never from the organization picker: an
 * environment administrator who has narrowed the console to one tenant is still
 * administering the environment, and what "Add" writes must not change meaning because a
 * dropdown elsewhere in the chrome was touched.
 */
final readonly class PermissionController extends ConsoleController
{
    /**
     * How many app-declared keys are listed before the page says how many more there are.
     * An integration can push a manifest of any size without asking anybody here.
     */
    private const DECLARED_SHOWN = 50;

    public function index(Request $request): Response
    {
        $this->scope->assertMayAdminister();

        $owner = $this->owner();
        $environmentId = $this->environmentId();
        $search = trim($request->string('q')->toString());

        /*
         * THE FILTERS BELONG IN SQL, and the tenant-assignable one especially: it decides
         * whether a peer's app catalogue is visible at all, and it used to decide it in
         * PHP — after every one of those rows had already been read. A tenant admin's
         * render loaded the environment's entire permission catalogue to display a
         * filtered subset of it.
         */
        $visible = fn (): Builder => Permission::query()
            ->visibleToOrganization($owner)
            ->when($search !== '', fn (Builder $q): Builder => $q->where(
                fn (Builder $inner) => $inner->where('name', 'like', '%'.$search.'%')
                    ->orWhere('description', 'like', '%'.$search.'%'),
            ))
            ->orderBy('name');

        /*
         * Only THIS environment's manual permissions are editable here. Operator-seeded
         * platform-global rows (null environment) stay visible and bindable in the roles
         * editor but are not given edit and delete controls that would no-op on them.
         */
        $manual = $visible()
            ->whereNull('client_id')
            ->where('environment_id', $environmentId)
            ->get();

        /*
         * Split by ownership, because the controls differ and a row that draws an Edit
         * button this caller's writes cannot resolve is a lie the console tells once per
         * render. `$mine` is exactly what {@see writable()} will resolve — the same
         * predicate, written once on each side. On the environment plane the owner is
         * null, so `$mine` IS the shared tier and nothing is inherited.
         */
        $mine = $manual->filter(fn (Permission $p): bool => $p->organization_id === $owner)->values();
        $inherited = $owner === null
            ? new EloquentCollection
            : $manual->filter(fn (Permission $p): bool => $p->organization_id === null)->values();

        /*
         * A tenant sees an app's catalogue only where the app said tenants may use it, and
         * only for apps they could actually be using. Every declared key in the
         * environment used to be rendered to every tenant admin, `tenant_assignable` or
         * not — and an internal key is named after the thing it guards, so a peer's
         * catalogue read as a description of what that peer had bought. The environment
         * plane keeps the full view: it administers the apps.
         */
        $declaredQuery = $visible()->whereNotNull('client_id');

        if ($owner !== null) {
            $declaredQuery
                ->where('tenant_assignable', true)
                ->whereIn('client_id', Client::query()
                    ->where(fn (QueryBuilder $query) => $query
                        ->whereNull('organization_id')
                        ->orWhere('organization_id', $owner))
                    ->select('client_id'));
        }

        // One page at a time, with the total beside it. This is a browse view over
        // something an integration can add to without asking: a manifest push of two
        // hundred keys turned it into a scroll with no way to find anything.
        $declaredTotal = (clone $declaredQuery)->count();
        $declared = $declaredQuery->limit(self::DECLARED_SHOWN)->get();

        $appNames = $this->appNames($declared->pluck('client_id')->filter()->unique()->all());

        $usage = $this->usageFor($manual->merge($declared), $owner, $environmentId);

        return $this->page('console/permissions', 'Permissions', [
            'help' => HelpProps::for(HelpTopic::Permissions),
            'mine' => $this->rows($mine, $appNames, $usage),
            'inherited' => $this->rows($inherited, $appNames, $usage),
            // Grouped by the app that declares them: the grouping IS the answer to "where
            // did this key come from", which is the question a catalogue of two hundred
            // rows is otherwise unable to answer.
            'declared' => $declared
                ->groupBy('client_id')
                ->map(fn (EloquentCollection $group, string $clientId): array => [
                    'app' => $appNames[$clientId] ?? $clientId,
                    'permissions' => $this->rows($group, $appNames, $usage),
                ])
                ->values()
                ->all(),
            'declaredTotal' => $declaredTotal,
            'declaredShown' => $declared->count(),
            // Which tier this page writes into. The two planes are otherwise identical
            // here, which is exactly the invisible difference that had a tenant admin
            // editing the whole environment's catalogue believing it was their own.
            'sharesEnvironment' => $owner === null,
            'search' => $search,
            'clientsHref' => $this->url('clients'),
            'urls' => [
                'store' => $this->url('permissions.store'),
            ],
        ]);
    }

    public function store(StorePermissionRequest $request): RedirectResponse
    {
        $this->scope->assertMayAdminister();

        $owner = $this->owner();
        $environmentId = $this->environmentId();
        $name = $request->key();

        /*
         * Uniqueness among the manual permissions this author can SEE.
         *
         * Manual rows carry a null `client_id`, so the database's (client_id, name) unique
         * index never actually constrains them. It is enforced here instead: scoped to the
         * environment, so two environments may each own a `billing:refund`; and scoped to
         * what is visible, so a tenant is told about a collision with the shared tier
         * (which would otherwise hand them two identical keys in the roles editor) and
         * never about one with a PEER's private key — which would make this form an
         * existence oracle for other tenants' `feature:action` names.
         */
        $taken = Permission::query()
            ->whereNull('client_id')
            ->where('environment_id', $environmentId)
            ->visibleToOrganization($owner)
            ->where('name', $name)
            ->exists();

        if ($taken) {
            return back()->withInput()->withErrors(['name' => 'A permission with that key already exists here.']);
        }

        Permission::query()->create([
            'client_id' => null,
            'environment_id' => $environmentId,
            'organization_id' => $owner,
            'name' => $name,
            'description' => $request->description(),
            /*
             * Tenant-assignable is how the SHARED tier says "organizations may compose
             * this into their own roles". On a row an organization already owns there is
             * nothing left to decide, so that plane does not offer the choice and writes
             * true — offering it would invite an administrator to untick their own
             * permission into uselessness.
             */
            'tenant_assignable' => $owner !== null || $request->tenantAssignable(),
        ]);

        return back()->with('status', 'Permission "'.$name.'" created.');
    }

    public function update(SavePermissionRequest $request, string $permission): RedirectResponse
    {
        $this->scope->assertMayAdminister();

        $model = $this->writable($permission);

        $model->description = $request->description();

        // Same rule as on the way in: an organization's own row has one possible answer,
        // so the page does not ask and the server does not read a posted claim about it.
        if ($this->owner() === null) {
            $model->tenant_assignable = $request->tenantAssignable();
        }

        $model->save();

        return back()->with('status', 'Permission updated.');
    }

    public function destroy(string $permission, Roles $roles): RedirectResponse
    {
        $this->scope->assertMayAdminister();

        $model = $this->writable($permission);

        /*
         * REVOKED FROM EACH ROLE, not deleted out from under them.
         *
         * This was one raw `DB::table('role_permission')->delete()`: no audit entry and no
         * `role.permission_revoked`, so a change that removed access from every holder of
         * every role granting this key left nothing on /audit and nothing for a SIEM. The
         * contract writes the same rows and reports each one.
         *
         * Fenced on the ROLE's own organization rather than on this page's, because a role
         * in either tier may grant this key and the service refuses a mismatch.
         */
        $roleIds = DB::table('role_permission')
            ->where('permission_id', $model->id)
            ->pluck('role_id')
            ->all();

        foreach (Role::query()->whereIn('id', $roleIds)->get() as $role) {
            $roles->revokePermission($role->id, $model->id, $role->organization_id);
        }

        /*
         * AND ANY ROW WHOSE ROLE NO LONGER RESOLVES. The pivot has no foreign key, so a
         * role deleted by some earlier path can leave its grants behind — and the contract
         * cannot revoke a grant on a role it refuses to load. Left alone, those rows
         * outlive the permission they point at and are then a pair of ids referring to
         * nothing, which is the shape that makes a later join silently wrong.
         *
         * Nothing to announce here: no role holds this, so nobody's access changes.
         */
        DB::table('role_permission')->where('permission_id', $model->id)->delete();

        $model->delete();

        return back()->with('status', 'Permission deleted.');
    }

    /**
     * Resolve a permission id for WRITING, deny-by-default: a MANUAL row, in this
     * environment, owned by this author.
     *
     * The environment clause has been here since one environment's admin could delete
     * another's. The owner clause is the same bug one level in: every tenant admin shared
     * a single environment-wide catalogue, so any of them could rename or delete a key
     * their peers' roles were built from.
     *
     * `ownedByOrganization()` and not `visibleToOrganization()` — a tenant can SEE the
     * shared tier, because their roles are composed from it, and must still not write to
     * it. The two predicates differ by exactly that, which is why the model names both.
     */
    private function writable(string $permission): Permission
    {
        return Permission::query()
            ->whereKey($permission)
            ->whereNull('client_id')
            ->where('environment_id', $this->environmentId())
            ->ownedByOrganization($this->owner())
            ->firstOrFail();
    }

    /**
     * Who owns what this page authors: the acting organization, or null for the shared
     * environment-wide tier.
     *
     * On the organization plane there is no choice to make — `requireOrganizationId()`
     * aborts rather than falling back to null, so a scope that somehow resolved no tenant
     * cannot write into the shared tier.
     */
    private function owner(): ?string
    {
        return $this->scope->plane() === ConsolePlane::Organization
            ? $this->scope->requireOrganizationId()
            : null;
    }

    /**
     * client_id => name for the apps this page has to NAME.
     *
     * A plain map rather than a Collection: this is a lookup for a group heading and a
     * badge, a serialization edge, and `pluck()` returns a shape neither PHPStan nor a
     * reader can pin down — which is how a key silently becomes an int.
     *
     * @param  array<array-key, mixed>  $clientIds
     * @return array<string, string>
     */
    private function appNames(array $clientIds): array
    {
        $wanted = [];

        foreach ($clientIds as $clientId) {
            if (is_string($clientId)) {
                $wanted[] = $clientId;
            }
        }

        $names = [];

        foreach (Client::query()->whereIn('client_id', $wanted)->get(['client_id', 'name']) as $client) {
            $names[$client->client_id] = $client->name;
        }

        return $names;
    }

    /** The current environment id, fail-closed — the manual-permission tenant boundary. */
    private function environmentId(): string
    {
        $environment = app(EnvironmentContext::class)->current();

        abort_if($environment === null, 403);

        return $environment->environmentKey();
    }

    /**
     * How many of the roles in view reference each permission — the context that says what
     * deleting one would strip.
     *
     * COUNTED IN SQL, over this page's permissions only. It used to pull the ENTIRE
     * platform-wide `role_permission` pivot into PHP — the pivot has no `environment_id`,
     * so there was no WHERE at all — and count it in memory on every render. At 400
     * environments × 40 roles × 25 permissions that is ~400k rows materialised to display
     * a handful of small integers.
     *
     * The join is not only for the aggregate: without it the count included OTHER
     * environments' roles, so one tenant's page reported another tenant's usage. On the
     * organization plane it counts THAT TENANT's roles and no others — "in 3 roles"
     * against the shared tier otherwise reported how many roles a peer had built on the
     * key, a number that moves when the peer edits theirs.
     *
     * @param  EloquentCollection<int, Permission>  $permissions
     * @return array<string, int>
     */
    private function usageFor(EloquentCollection $permissions, ?string $owner, string $environmentId): array
    {
        if ($permissions->isEmpty()) {
            return [];
        }

        $counts = [];

        foreach (DB::table('role_permission')
            ->join('roles', 'roles.id', '=', 'role_permission.role_id')
            ->where('roles.environment_id', $environmentId)
            ->when($owner !== null, fn ($query) => $query->where('roles.organization_id', $owner))
            ->whereIn('role_permission.permission_id', $permissions->pluck('id')->all())
            ->selectRaw('role_permission.permission_id as permission_id, count(*) as aggregate')
            ->groupBy('role_permission.permission_id')
            ->get() as $row) {
            // A raw row is untyped by nature, so this is the boundary where it becomes
            // typed: narrowed rather than cast, because a row that is not what the schema
            // says is a bug to skip past, not a number to invent.
            if (is_string($row->permission_id) && is_numeric($row->aggregate)) {
                $counts[$row->permission_id] = (int) $row->aggregate;
            }
        }

        return $counts;
    }

    /**
     * @param  EloquentCollection<int, Permission>  $permissions
     * @param  array<string, string>  $appNames
     * @param  array<string, int>  $usage
     * @return list<array{id: string, name: string, description: string|null, app: string|null, tenantAssignable: bool, orphaned: bool, roleCount: int, urls: array{update: string, destroy: string}}>
     */
    private function rows(EloquentCollection $permissions, array $appNames, array $usage): array
    {
        $rows = [];

        foreach ($permissions as $permission) {
            $rows[] = [
                'id' => $permission->id,
                'name' => $permission->name,
                'description' => $permission->description,
                'app' => $permission->client_id !== null
                    ? ($appNames[$permission->client_id] ?? $permission->client_id)
                    : null,
                'tenantAssignable' => $permission->tenant_assignable,
                'orphaned' => $permission->orphaned_at !== null,
                'roleCount' => $usage[$permission->id] ?? 0,
                'urls' => [
                    'update' => $this->url('permissions.update', $permission->id),
                    'destroy' => $this->url('permissions.destroy', $permission->id),
                ],
            ];
        }

        return $rows;
    }
}
