<?php

declare(strict_types=1);

namespace App\Http\Controllers\Console;

use App\Actions\Roles\CreateRole;
use App\Actions\Roles\DeleteRole;
use App\Actions\Roles\GrantRolePermission;
use App\Actions\Roles\RevokeRolePermission;
use App\Actions\Roles\RoleAuthority;
use App\Actions\Roles\UpdateRole;
use App\Http\Props\Shared\HelpProps;
use App\Http\Props\Shared\PaginationProps;
use App\Http\Requests\Console\SaveRoleRequest;
use App\Http\Requests\Console\StoreRoleRequest;
use App\Platform\Console\ConsolePlane;
use App\Platform\Console\Vocabulary;
use App\Platform\Help\HelpTopic;
use App\Platform\VerifiedEmailGate;
use Cbox\Id\AccessControl\Contracts\Roles;
use Cbox\Id\AccessControl\Enums\RoleSource;
use Cbox\Id\AccessControl\Models\EnvironmentRoleAssignment;
use Cbox\Id\AccessControl\Models\Permission;
use Cbox\Id\AccessControl\Models\Role;
use Cbox\Id\AccessControl\Models\RoleAssignment;
use Cbox\Id\Identity\Contracts\Subjects;
use Cbox\Id\OAuthServer\Models\Client;
use Cbox\Id\Organization\Models\Organization;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Inertia\Response;

/**
 * CONSOLE › ROLES — what people are allowed to do inside the apps, as opposed to who may
 * run this console.
 *
 * ONE CONTROLLER, BOTH PLANES, and each plane held half of this. The organization plane
 * had a single page that could define a role and compose it from the declared catalogue,
 * and could do nothing to one afterwards — no rename, no delete, no way to take a
 * permission back. The environment plane had a routable list → new → detail that could
 * rename, re-permission and delete, and could not grant from the catalogue at all. Both
 * halves are here: the routable shape wins, because a role URL is something you send to
 * whoever owns the access.
 *
 * EVERY READ AND EVERY WRITE RE-RESOLVES THE ROLE inside a scoped query rather than
 * checking ownership after loading it, so an id that is not this administrator's to touch
 * never becomes a Role at all. That is the seam a merge like this leaks through: the
 * environment page resolved on the primary key alone, which was safe only while an
 * environment administrator — who holds every organization here — was its sole caller.
 * Served to a tenant, the same code is write access to every other tenant's roles.
 *
 * Three sets, deliberately distinct:
 *   {@see visible()}    — may READ. The organization's own, plus environment-owned
 *                         ones that apply to it.
 *   {@see RoleAuthority::changeable()} — may WRITE. Environment-owned roles are excluded
 *                         on the organization plane: they are assignable inside every
 *                         tenant, so re-permissioning one grants access in organizations
 *                         that are not yours, and deleting one revokes it from all of them.
 *   {@see assignable()} — the permission catalogue this administrator may compose FROM. A
 *                         tenant sees only what apps in its reach declared AND marked
 *                         `tenant_assignable`; an app keeps its privileged internal keys
 *                         off the list by not marking them.
 *
 * EVERY WRITE IS AN ACTION (app/Actions/Roles) — the one the management API and MCP run —
 * and the write set and the app-declared refusal live there, in {@see RoleAuthority},
 * answered from WHO is asking rather than from which organization the page shows. App-
 * declared roles (`source = manifest`) are the declaring app's source of truth and are
 * read-only on both planes.
 */
final readonly class RoleController extends ConsoleController
{
    private const PER_PAGE = 25;

    /** How many permission badges a row draws before it says "and N more". */
    private const BADGE_LIMIT = 6;

    public function index(Request $request, Roles $roles): Response
    {
        $this->scope->assertMayAdminister();

        // The member's own organization, the one an organization's Roles tab names, or the
        // one the environment-wide list is filtered to — read as that organization would see
        // its roles: its own plus the environment's that apply to it.
        $filter = $this->organizationFilter();

        $query = ($filter->unknown ? $filter->apply(Role::query()) : $this->visible($filter->id))
            // App-declared roles last: an administrator's own are the ones they act on.
            ->orderByRaw('client_id is not null')
            ->orderBy('name');

        $term = trim($request->string('q')->toString());

        if ($term !== '') {
            $query->where('name', 'like', '%'.$term.'%');
        }

        $page = $query->paginate(self::PER_PAGE)->withQueryString();

        /** @var EloquentCollection<int, Role> $rows */
        $rows = $page->getCollection();

        $permissionsByRole = $this->grantedNamesFor($rows);
        $appNames = $this->usableApps($filter->id);
        $offerable = $this->offerableFor($rows);

        $first = $rows->first();

        /*
         * WHOSE ROLE IT IS, when that is not already obvious. Unfiltered, the environment
         * plane lists every tenant's roles in one list, and two tenants' "Editor" were two
         * identical rows — one click away from re-permissioning the wrong customer's access.
         * Named through the scope's own list, so on a plane that holds one organization this
         * can never enumerate the others.
         */
        $showsEveryOrganization = ! $filter->active();
        $owners = $showsEveryOrganization
            ? $this->scope->organizationNames($rows->pluck('organization_id'))
            : [];
        $people = $this->scope->peopleRoute();

        return $this->page('console/roles/index', Vocabulary::ROLES, [
            'help' => HelpProps::for(HelpTopic::Roles),
            'roles' => array_map(fn (Role $role): array => [
                'id' => $role->id,
                'name' => $role->name,
                'description' => $role->description,
                'declaredByApp' => $role->source === RoleSource::Manifest,
                'app' => $role->client_id !== null
                    ? ($appNames[$role->client_id] ?? $role->client_id)
                    : null,
                'environmentWide' => $role->organization_id === null,
                'organization' => $showsEveryOrganization && $role->organization_id !== null
                    ? ($owners[$role->organization_id] ?? $role->organization_id)
                    : null,
                'permissions' => array_slice($permissionsByRole[$role->id] ?? [], 0, self::BADGE_LIMIT),
                'moreCount' => max(0, count($permissionsByRole[$role->id] ?? []) - self::BADGE_LIMIT),
                // The keys still on offer for this role: declared, assignable, in its own
                // app scope, and not already held. Precomputed for the whole page in two
                // queries rather than two PER ROW — the closure this replaces ran inside
                // the loop, so a 25-role page paid 50, and it re-ran on every keystroke in
                // the search box. Measured there: 28 queries at 0 roles, 79 at 25.
                'offerable' => $offerable[$role->id] ?? [],
                // A role this administrator may see but not compose still has to be
                // legible — they have to know what constrains them — so the row is drawn
                // either way and only the picker goes.
                'mayCompose' => isset($offerable[$role->id]),
                'href' => $this->url('roles.show', $role->id),
                'grantHref' => $this->url('roles.permissions', $role->id),
            ], $rows->all()),
            'pagination' => PaginationProps::from($page),
            'search' => $term,
            'mayAdminister' => $this->scope->mayAdminister(),
            'organizationFilter' => $this->organizationFilterProps($filter),
            // THE LAST MILE. The page says a role is "a label stamped into the token", and
            // then never shows the token — so the developer on the other end still has to
            // guess which claim to read, and the two commonest guesses (`scope`, and a
            // nested `authorization` object) are both wrong. Built from THIS environment's
            // first role and its own permissions, because a generic example is one more
            // thing to translate.
            'sample' => $first === null ? null : [
                'role' => $first->name,
                'permissions' => array_slice($permissionsByRole[$first->id] ?? [], 0, 3),
            ],
            'createHref' => $this->createUrl('roles.create'),
            // "Console access" is set on the organization's PEOPLE page — which is a different
            // page for a customer than for everybody else, and hard-coding the customer's
            // sent a tenant admin through two redirects to the dashboard. The environment
            // plane has no equivalent, so there the sentence stays and only the link goes.
            'consoleAccessHref' => $people === null ? null : route($people),
        ]);
    }

    public function create(): Response
    {
        $this->scope->assertMayAdminister();

        $apps = $this->usableApps();

        return $this->page('console/roles/create', 'New role', [
            'catalog' => $this->catalogProps($this->assignable()->orderBy('name')->get(), $apps),
            'apps' => array_map(
                static fn (string $id, string $name): array => ['id' => $id, 'name' => $name],
                array_keys($apps),
                array_values($apps),
            ),
            // Whether defining a role for the whole environment is even on offer here.
            'holdsEnvironment' => $this->scope->plane() === ConsolePlane::Environment,
            // "For which organization?" on the environment console, unless the role is for
            // the whole environment; prefilled and locked when opened from an organization.
            'organization' => $this->organizationPicker(),
            // Authoring a permission by hand is the control plane's own page and has no
            // organization-plane equivalent, so the pointer goes where the page exists
            // rather than being dropped from the merge.
            'permissionsHref' => $this->scope->serves('permissions')
                ? $this->url('permissions')
                : null,
            'indexHref' => $this->url('roles'),
            'storeHref' => $this->url('roles.store'),
        ]);
    }

    public function store(StoreRoleRequest $request): RedirectResponse
    {
        $this->scope->assertMayAdminister();

        /*
         * The unverified-address hold is the organization plane's. It exists because a
         * social sign-in mints a usable subject whose address only a provider vouched for;
         * an environment administrator arrives as an account member instead, so there is
         * no subject to have confirmed and applying it there would refuse every one of
         * them.
         */
        if ($this->scope->plane() === ConsolePlane::Organization) {
            app(VerifiedEmailGate::class)->require('create a role');
        }

        if ($request->environmentWide()) {
            /*
             * A role with no organization is assignable inside every tenant in the
             * environment, so an organization administrator who could mint one would be
             * defining access for organizations that are not theirs. The action refuses it
             * too; this says so on the checkbox rather than as a 403.
             */
            if ($this->scope->plane() !== ConsolePlane::Environment) {
                return back()->withInput()->withErrors([
                    'environmentWide' => 'Only an environment administrator may define a role for every organization.',
                ]);
            }

            $organizationId = null;
        } else {
            // WHOSE role it is, named explicitly: the form's answer on the environment
            // console, checked against this environment; the member's own elsewhere.
            $organizationId = $this->chosenOrganizationId($request);
        }

        $result = $this->act(CreateRole::class, [
            'name' => $request->name(),
            'description' => $request->description(),
            'organization_id' => $organizationId,
            'client_id' => $request->appId(),
            'permissions' => $request->permissionIds(),
        ], ['name' => 'name', 'description' => 'description', 'client_id' => 'app', 'permissions' => 'permissions'], 'name');

        if ($result instanceof RedirectResponse) {
            return $result;
        }

        /** @var Role $role */
        $role = $result->value;

        return to_route($this->scope->routeName('roles.show'), $role->id)
            ->with('status', 'Role "'.$role->name.'" created.');
    }

    public function show(string $role, Subjects $subjects): Response
    {
        $this->scope->assertMayAdminister();

        $model = $this->visible()->whereKey($role)->firstOrFail();

        $granted = array_values(array_filter(
            DB::table('role_permission')->where('role_id', $model->id)->pluck('permission_id')->all(),
            'is_string',
        ));

        $catalog = $this->assignable()->orderBy('name')->get(['id', 'name', 'description', 'client_id']);
        $appNames = $this->appNames($catalog->pluck('client_id')->all(), $model->client_id);

        $declaredByApp = $model->source === RoleSource::Manifest;
        $readOnly = $declaredByApp || ! $this->mayChange($model);

        return $this->page('console/roles/show', $model->name, [
            'role' => [
                'id' => $model->id,
                'name' => $model->name,
                'description' => $model->description,
                'app' => $model->client_id !== null
                    ? ($appNames[$model->client_id] ?? $model->client_id)
                    : null,
            ],
            // A role this administrator may read but not change still renders — they have
            // to see what constrains them — so the page asks rather than assuming, and
            // WHY it is read-only is two different sentences: the app owns this role, or
            // the environment does.
            'readOnly' => $readOnly,
            'declaredByApp' => $declaredByApp,
            'catalog' => $readOnly
                ? $this->catalogProps($catalog->whereIn('id', $granted), $appNames)
                : $this->catalogProps($catalog, $appNames),
            'granted' => $granted,
            // WHO ACTUALLY HOLDS IT. No surface in the console answered this: an access
            // review enumerates one organization's grants, and an environment-wide grant
            // belongs to none — so the only way to learn who held a role was to open every
            // user page in turn. A role you cannot see the holders of is a role you cannot
            // govern.
            'holders' => $this->holders($model, $subjects),
            'indexHref' => $this->url('roles'),
            'urls' => [
                'update' => $this->url('roles.update', $model->id),
                'permissions' => $this->url('roles.permissions', $model->id),
                'destroy' => $this->url('roles.destroy', $model->id),
            ],
        ]);
    }

    public function update(SaveRoleRequest $request, string $role): RedirectResponse
    {
        $this->scope->assertMayAdminister();

        $result = $this->act(UpdateRole::class, [
            'id' => $role,
            'name' => $request->name(),
            'description' => $request->description(),
        ], ['name' => 'name', 'description' => 'description'], 'name');

        return $result instanceof RedirectResponse ? $result : back()->with('status', 'Role updated.');
    }

    /**
     * Grant or revoke one permission.
     *
     * AN EXPLICIT SET RATHER THAN A TOGGLE: the detail page's checkbox and the list's
     * picker both land here, and a toggle turns a double-click — or a retried request —
     * into a silent flip back. The caller says which state it means, and each state is its
     * own action. The permission is resolved there against the catalogue this administrator
     * may compose from, so an app's privileged key it never marked tenant-assignable, or a
     * permission from an app out of reach, matches nothing.
     */
    public function permissions(Request $request, string $role): RedirectResponse
    {
        $this->scope->assertMayAdminister();

        $request->validate([
            'permission' => ['required', 'string'],
            'granted' => ['required', 'boolean'],
        ]);

        $input = ['id' => $role, 'permission_id' => $request->string('permission')->toString()];

        if ($request->boolean('granted')) {
            $result = $this->act(GrantRolePermission::class, $input, ['permission_id' => 'permission'], 'permission');

            return $result instanceof RedirectResponse ? $result : back()->with('status', 'Permission granted.');
        }

        $result = $this->act(RevokeRolePermission::class, $input, ['permission_id' => 'permission'], 'permission');

        return $result instanceof RedirectResponse ? $result : back()->with('status', 'Permission revoked.');
    }

    /**
     * Delete the role — and every grant of it, through the contract, so the change to every
     * holder's access is on the trail and announced to the apps that mirror grants.
     */
    public function destroy(string $role): RedirectResponse
    {
        $this->scope->assertMayAdminister();

        $result = $this->act(DeleteRole::class, ['id' => $role]);

        return $result instanceof RedirectResponse
            ? $result
            : to_route($this->scope->routeName('roles'))->with('status', 'Role deleted.');
    }

    /**
     * The roles this administrator may READ.
     *
     * The environment plane listed every role in the environment, which is right for an
     * administrator who holds it. Serving the same page to an organization admin would
     * hand them every other tenant's roles, so with an organization in scope this is: its
     * own roles, plus the environment-owned ones that apply to it — an admin-authored
     * environment-wide role can be held by its people, and an app-declared role belongs to
     * it only if it may use that app. Orphaned declarations (the app stopped declaring
     * them) are the control plane's problem, not a tenant's.
     *
     * @return Builder<Role>
     */
    private function visible(?string $organizationId = null): Builder
    {
        $organizationId ??= $this->routeOrganizationId();

        if ($organizationId === null) {
            // Environment-scoped by the model, so this is every role in THIS environment
            // and never another's.
            return Role::query();
        }

        $clientIds = array_keys($this->usableApps($organizationId));

        return Role::query()->where(fn (Builder $q): Builder => $q
            ->where('organization_id', $organizationId)
            ->orWhere(fn (Builder $q): Builder => $q
                ->whereNull('organization_id')
                ->whereNull('orphaned_at')
                ->where(fn (Builder $q): Builder => $q->whereNull('client_id')->orWhereIn('client_id', $clientIds))));
    }

    /** The readable half of {@see RoleAuthority::changeable()}, for what the page draws. */
    private function mayChange(Role $role): bool
    {
        if ($this->scope->plane() === ConsolePlane::Environment) {
            return true;
        }

        return $role->organization_id !== null
            && $role->organization_id === $this->scope->organizationId();
    }

    /**
     * The permissions this administrator may put on a role.
     *
     * @return Builder<Permission>
     */
    private function assignable(): Builder
    {
        $query = Permission::query()->whereNull('orphaned_at');

        if ($this->scope->plane() === ConsolePlane::Environment) {
            return $query;
        }

        $clientIds = array_keys($this->usableApps());

        return $query
            ->where('tenant_assignable', true)
            ->where(fn (Builder $q): Builder => $q->whereIn('client_id', $clientIds)->orWhereNull('client_id'));
    }

    /**
     * The permission names each role on the page holds, in one query for the page.
     *
     * @param  EloquentCollection<int, Role>  $roles
     * @return array<string, list<string>>
     */
    private function grantedNamesFor(EloquentCollection $roles): array
    {
        if ($roles->isEmpty()) {
            return [];
        }

        $byRole = [];

        foreach (DB::table('role_permission')
            ->join('permissions', 'permissions.id', '=', 'role_permission.permission_id')
            ->whereIn('role_permission.role_id', $roles->pluck('id'))
            ->orderBy('permissions.name')
            ->get(['role_permission.role_id', 'permissions.name']) as $row) {
            // A raw row is untyped by nature, so this is the boundary where it becomes
            // typed: narrowed rather than cast, because a row that is not what the schema
            // says is a bug to skip past, not a string to invent.
            if (! is_string($row->role_id) || ! is_string($row->name)) {
                continue;
            }

            $byRole[$row->role_id][] = $row->name;
        }

        return $byRole;
    }

    /**
     * The permissions still on offer, for every composable role on the page, in one pass.
     *
     * ONE QUERY FOR THE WHOLE PAGE rather than two per row. The catalogue is read once and
     * each role's own scope and grants are applied in memory, because both are already in
     * hand.
     *
     * The offer set is {@see assignable()} — the same catalogue the role's own page
     * composes from. It used to be a narrower query of its own, so on the environment
     * plane a permission the detail page would grant was missing from the list's picker,
     * and an environment-wide role could be composed on one page and not the other.
     *
     * A role absent from the returned map is one this administrator may not compose, which
     * is what the row reads to decide whether to draw a picker at all.
     *
     * @param  EloquentCollection<int, Role>  $roles
     * @return array<string, list<array{id: string, name: string, description: string|null}>>
     */
    private function offerableFor(EloquentCollection $roles): array
    {
        $composable = $roles->filter(fn (Role $role): bool => $role->source !== RoleSource::Manifest
            && $this->mayChange($role));

        if ($composable->isEmpty()) {
            return [];
        }

        $granted = $this->grantedNamesFor($roles);

        $catalogue = $this->assignable()
            /*
             * Only the apps the roles on THIS page belong to, plus the unscoped ones —
             * reading the whole catalogue would make the page's cost the size of the
             * environment rather than the size of the page.
             */
            ->where(fn (Builder $q): Builder => $q
                ->whereIn('client_id', $composable->pluck('client_id')->filter()->unique()->all())
                ->orWhereNull('client_id'))
            ->orderBy('name')
            ->get(['id', 'name', 'description', 'client_id']);

        $offerable = [];

        foreach ($composable as $role) {
            $held = $granted[$role->id] ?? [];
            $forRole = [];

            foreach ($catalogue as $permission) {
                // A role may be granted its own app's permissions and the unscoped ones,
                // and nothing another app declared.
                if ($permission->client_id !== null && $permission->client_id !== $role->client_id) {
                    continue;
                }

                if (in_array($permission->name, $held, true)) {
                    continue;
                }

                $forRole[] = [
                    'id' => $permission->id,
                    'name' => $permission->name,
                    'description' => $permission->description,
                ];
            }

            $offerable[$role->id] = $forRole;
        }

        return $offerable;
    }

    /**
     * The people holding this role, both ways it can be held.
     *
     * Environment-wide grants first and marked as such: they are the larger statement, and
     * reading a flat list where one row means "in Acme" and the next means "in every
     * organization" is how a reviewer certifies the wrong thing.
     *
     * @return list<array{id: string, name: string, email: string, scope: string|null}>
     */
    private function holders(Role $role, Subjects $subjects): array
    {
        /** @var list<string> $everywhere */
        $everywhere = array_values(array_filter(
            EnvironmentRoleAssignment::query()->where('role_id', $role->id)->pluck('user_id')->all(),
            'is_string',
        ));

        $inOrg = RoleAssignment::query()
            ->where('role_id', $role->id)
            ->get(['user_id', 'organization_id']);

        /** @var list<string> $userIds */
        $userIds = array_values(array_unique([
            ...$everywhere,
            ...array_filter($inOrg->pluck('user_id')->all(), 'is_string'),
        ]));

        if ($userIds === []) {
            return [];
        }

        $people = $subjects->findMany($userIds);
        $orgNames = Organization::query()
            ->whereIn('id', $inOrg->pluck('organization_id')->filter()->unique()->all())
            ->pluck('name', 'id');

        $describe = static function (string $userId) use ($people): array {
            $person = $people[$userId] ?? null;
            $email = $person?->email;
            $name = $person?->name;

            return [
                'name' => is_string($name) && $name !== '' ? $name : (is_string($email) ? $email : $userId),
                'email' => is_string($email) ? $email : '—',
            ];
        };

        $rows = [];

        foreach ($everywhere as $userId) {
            /*
             * A KEY THE LIST CAN BE REDRAWN BY. The same person legitimately appears
             * twice — once everywhere, once inside an organization — and two people can
             * appear with the same displayed name and no address at all, so neither the
             * row's contents nor its position identifies it.
             */
            $rows[] = [...$describe($userId), 'id' => $userId.'@*', 'scope' => null];
        }

        foreach ($inOrg as $assignment) {
            $scope = $orgNames[$assignment->organization_id] ?? $assignment->organization_id;

            $rows[] = [
                ...$describe($assignment->user_id),
                'id' => $assignment->user_id.'@'.$assignment->organization_id,
                'scope' => is_string($scope) ? $scope : null,
            ];
        }

        return $rows;
    }

    /**
     * The permission catalogue as the picker draws it.
     *
     * @param  Collection<int, Permission>  $catalog
     * @param  array<string, string>  $appNames
     * @return list<array{id: string, name: string, description: string|null, app: string|null}>
     */
    private function catalogProps(iterable $catalog, array $appNames): array
    {
        $rows = [];

        foreach ($catalog as $permission) {
            $rows[] = [
                'id' => $permission->id,
                'name' => $permission->name,
                'description' => $permission->description,
                'app' => $permission->client_id !== null
                    ? ($appNames[$permission->client_id] ?? 'App')
                    : null,
            ];
        }

        return $rows;
    }

    /**
     * client_id => name for the apps a page has to NAME: the ones the catalogue's
     * permissions came from, plus the role's own scope.
     *
     * @param  array<array-key, mixed>  $clientIds
     * @return array<string, string>
     */
    private function appNames(array $clientIds, ?string $roleClientId): array
    {
        $wanted = [];

        // A permission with no client is platform-global and names no app, so the nulls
        // are dropped here rather than looked up as ''.
        foreach ($clientIds as $clientId) {
            if (is_string($clientId)) {
                $wanted[] = $clientId;
            }
        }

        if ($roleClientId !== null) {
            $wanted[] = $roleClientId;
        }

        $names = [];

        foreach (Client::query()->whereIn('client_id', array_values(array_unique($wanted)))->get(['client_id', 'name']) as $client) {
            $names[$client->client_id] = $client->name;
        }

        return $names;
    }

    /**
     * client_id => name for every app IN SCOPE: the platform's own plus the organization's
     * (the one the console acts on, or the one asked about), and every app in the
     * environment when there is none.
     *
     * A plain map rather than a Collection — this is a lookup for badges and a picker, a
     * serialization edge, and `pluck()` returns a shape neither PHPStan nor a reader can
     * pin down, which is how a key silently becomes an int.
     *
     * @return array<string, string>
     */
    private function usableApps(?string $organizationId = null): array
    {
        $organizationId ??= $this->routeOrganizationId();

        $clients = Client::query()
            ->when($organizationId !== null, fn (Builder $q): Builder => $q->where(
                fn (Builder $q): Builder => $q->whereNull('organization_id')->orWhere('organization_id', $organizationId)
            ))
            ->orderBy('name')
            ->get(['client_id', 'name']);

        $apps = [];

        foreach ($clients as $client) {
            $apps[$client->client_id] = $client->name;
        }

        return $apps;
    }
}
