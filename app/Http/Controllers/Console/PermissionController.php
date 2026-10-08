<?php

declare(strict_types=1);

namespace App\Http\Controllers\Console;

use App\Actions\Permissions\CreatePermission;
use App\Actions\Permissions\DeletePermission;
use App\Actions\Permissions\PermissionFields;
use App\Actions\Permissions\UpdatePermission;
use App\Http\Props\Shared\HelpProps;
use App\Http\Requests\Console\SavePermissionRequest;
use App\Http\Requests\Console\StorePermissionRequest;
use App\Platform\Console\ConsolePlane;
use App\Platform\Console\Vocabulary;
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
 * dropdown elsewhere in the chrome was touched — and it is handed to the action as an
 * explicit `organization_id`: every write here is an action (app/Actions/Permissions), the
 * one the management API and MCP run.
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
         * render. `$mine` is exactly what {@see PermissionFields::writable()} resolves for this
         * plane — the same predicate, written once on each side. On the environment plane the owner is
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

        return $this->page('console/permissions', Vocabulary::PERMISSIONS, [
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

    /**
     * Author a manual permission in the tier this PLANE writes: the acting organization's
     * own on the organization plane, the environment's shared tier on the environment plane
     * — passed to the action explicitly, never inferred there from the page.
     */
    public function store(StorePermissionRequest $request): RedirectResponse
    {
        $this->scope->assertMayAdminister();

        $result = $this->act(CreatePermission::class, [
            'name' => $request->key(),
            'description' => $request->description(),
            'organization_id' => $this->owner(),
            'tenant_assignable' => $request->tenantAssignable(),
        ], ['name' => 'name', 'description' => 'description', 'tenant_assignable' => 'tenantAssignable'], 'name');

        return $result instanceof RedirectResponse
            ? $result
            : back()->with('status', 'Permission "'.$request->key().'" created.');
    }

    public function update(SavePermissionRequest $request, string $permission): RedirectResponse
    {
        $this->scope->assertMayAdminister();

        $result = $this->act(UpdatePermission::class, [
            'id' => $permission,
            'description' => $request->description(),
            'tenant_assignable' => $request->tenantAssignable(),
        ], ['description' => 'description', 'tenant_assignable' => 'tenantAssignable'], 'description');

        return $result instanceof RedirectResponse ? $result : back()->with('status', 'Permission updated.');
    }

    /**
     * Delete it — revoked from each role first, through the contract, so the change to every
     * holder's access is on the trail.
     */
    public function destroy(string $permission): RedirectResponse
    {
        $this->scope->assertMayAdminister();

        $result = $this->act(DeletePermission::class, ['id' => $permission]);

        return $result instanceof RedirectResponse ? $result : back()->with('status', 'Permission deleted.');
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
