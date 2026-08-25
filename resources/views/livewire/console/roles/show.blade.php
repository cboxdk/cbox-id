<?php

declare(strict_types=1);

use Cbox\Id\Organization\Models\Organization;
use Cbox\Id\Identity\Contracts\Subjects;
use Cbox\Id\AccessControl\Models\RoleAssignment;
use Cbox\Id\AccessControl\Models\EnvironmentRoleAssignment;
use App\Platform\Console\ConsolePlane;
use App\Platform\Console\ConsoleScope;
use Cbox\Id\AccessControl\Contracts\Roles;
use Cbox\Id\AccessControl\Enums\RoleSource;
use Cbox\Id\AccessControl\Models\Permission;
use Cbox\Id\AccessControl\Models\Role;
use Cbox\Id\OAuthServer\Models\Client;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;
use Livewire\Attributes\Layout;
use Livewire\Volt\Component;

/**
 * Roles › detail — one component, both planes. The full, deep-linkable lifecycle for one
 * role: rename, edit its description, compose its permissions and delete it.
 *
 * All three existed only on the environment plane. A tenant admin could define a role and
 * then never rename it, never take a permission back and never delete it — so a role
 * created by mistake stayed, and every holder kept whatever it granted.
 *
 * This page is therefore NEW on the organization plane, which is exactly where a merge
 * like this leaks: the environment version resolved the role on its primary key alone,
 * which was safe only because an administrator who holds the environment was its sole
 * caller. Served to a tenant, that is write access to every role in the environment,
 * including other tenants'. Every read and every write re-resolves the role inside what
 * this administrator may see or change and 404s otherwise (deny-by-default), so a forged
 * id matches nothing.
 *
 * App-declared roles (source = manifest) are the declaring app's source of truth, so they
 * are read-only on both planes: rename, permission edits and delete all refuse. There is
 * no rename service for roles, so name/description go through the Roles contract's
 * updateRole(), which records the change; permissions are the role_permission pivot,
 * written through attachPermission()/revokePermission() for the same reason.
 */
new #[Layout('components.layouts.console', ['title' => 'Role'])] class extends Component
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

    public string $roleId = '';

    public string $editName = '';

    public string $editDescription = '';

    public function mount(string $role): void
    {
        $model = $this->visible()->whereKey($role)->firstOrFail();

        $this->roleId = $model->id;
        $this->editName = $model->name;
        $this->editDescription = $model->description ?? '';
    }

    private function role(): Role
    {
        return $this->visible()->whereKey($this->roleId)->firstOrFail();
    }

    /**
     * The role as something this administrator may WRITE to, or a 404.
     *
     * Resolved through the changeable set rather than checked after the fact, so an id
     * that is not theirs to touch never becomes a Role object at all — and app-declared
     * roles refuse here rather than in three separate actions.
     */
    private function writable(): Role
    {
        $role = $this->changeable()->whereKey($this->roleId)->firstOrFail();

        abort_if($role->source === RoleSource::Manifest, 403);

        return $role;
    }

    public function saveDetails(Roles $roles): void
    {
        $role = $this->writable();

        $this->validate([
            'editName' => ['required', 'string', 'max:120'],
            'editDescription' => ['nullable', 'string', 'max:500'],
        ]);

        $description = trim($this->editDescription);
        $roles->updateRole(
            $role->id,
            trim($this->editName),
            $description !== '' ? $description : null,
            $this->fenceOrganizationId(),
        );

        $this->dispatch('toast', message: 'Role updated.');
    }

    public function togglePermission(string $permissionId, Roles $roles): void
    {
        $role = $this->writable();

        // Resolved through the catalogue this administrator may actually assign from, so
        // a posted id that matches nothing there — an app's privileged internal key it
        // never marked tenant-assignable, or a permission from an app out of reach — is
        // ignored rather than trusted. The checkbox list is drawn from the same query;
        // this is the half that holds when the checkbox is bypassed.
        $permission = $this->assignable()->whereKey($permissionId)->first();

        if ($permission === null) {
            return;
        }

        $attached = DB::table('role_permission')
            ->where('role_id', $role->id)
            ->where('permission_id', $permission->id)
            ->exists();

        if ($attached) {
            $roles->revokePermission($role->id, $permission->id, $this->fenceOrganizationId());
            $this->dispatch('toast', message: 'Permission revoked.');
        } else {
            $roles->attachPermission($role->id, $permission->id, $this->fenceOrganizationId());
            $this->dispatch('toast', message: 'Permission granted.');
        }
    }

    public function deleteRole(Roles $roles): mixed
    {
        $role = $this->writable();

        // Through the framework, never raw SQL. These writes used to be DB::table()
        // deletes: no observer, no FK cascade, so a change to privileged access
        // affecting EVERY holder of the role left nothing on /audit, nothing for a
        // SIEM, and no `role.unassigned` for the downstream apps that mirror grants off
        // it. deleteRole() drops the same rows and reports what it did.
        $roles->deleteRole($role->id, $this->fenceOrganizationId());

        $this->dispatch('toast', message: 'Role deleted.');

        return $this->redirectRoute(app(ConsoleScope::class)->routeName('roles'), navigate: true);
    }

    /**
     * @return array<string, mixed>
     */
    public function with(): array
    {
        $role = $this->role();

        $granted = DB::table('role_permission')->where('role_id', $role->id)->pluck('permission_id')->all();

        $catalog = $this->assignable()->orderBy('name')->get(['id', 'name', 'description', 'client_id']);

        $appNames = $this->appNames($catalog->pluck('client_id')->all(), $role->client_id);

        return [
            // Route names differ per plane; one component, so it asks rather than assumes.
            'scopeRoute' => fn (string $name): string => app(ConsoleScope::class)->routeName($name),
            'role' => $role,
            'catalog' => $catalog,
            'appNames' => $appNames,
            'granted' => $granted,
            // The view half of the question the actions ask: get this wrong and the page
            // renders as a read-only shell on whichever plane the markup forgot.
            // A role this administrator may read but not change still renders — they have
            // to see what constrains them — so the view asks rather than assuming.
            'readOnly' => $role->source === RoleSource::Manifest || ! $this->mayChange($role),
            // Why it is read-only, which are different sentences: the app owns this role,
            // or the environment does.
            'declaredByApp' => $role->source === RoleSource::Manifest,
            // WHO ACTUALLY HOLDS IT. No surface in the console answered this: an access
            // review enumerates one organization's grants, and an environment-wide grant
            // belongs to none — so the only way to learn who held a role was to open
            // every user page in turn. A role you cannot see the holders of is a role you
            // cannot govern.
            'holders' => $this->holders($role),
        ];
    }

    /**
     * The people holding this role, both ways it can be held.
     *
     * Environment-wide grants first and marked as such: they are the larger statement,
     * and reading a flat list where one row means "in Acme" and the next means "in every
     * organization" is how a reviewer certifies the wrong thing.
     *
     * @return list<array{name: string, email: string, scope: string|null}>
     */
    private function holders(Role $role): array
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

        $people = app(Subjects::class)->findMany($userIds);
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
            $rows[] = [...$describe($userId), 'scope' => null];
        }

        foreach ($inOrg as $assignment) {
            $scope = $orgNames[$assignment->organization_id] ?? $assignment->organization_id;

            $rows[] = [
                ...$describe($assignment->user_id),
                'scope' => is_string($scope) ? $scope : null,
            ];
        }

        return $rows;
    }

    /**
     * Whether this role is this administrator's to change — the readable half of
     * {@see changeable()}, which is the half that actually gates the writes.
     */
    private function mayChange(Role $role): bool
    {
        $scope = app(ConsoleScope::class);

        if ($scope->plane() === ConsolePlane::Environment) {
            return true;
        }

        return $role->organization_id !== null
            && $role->organization_id === $scope->organizationId();
    }

    /**
     * The roles this administrator may READ: the acting organization's own, plus the
     * environment-owned ones that apply to it — an admin-authored environment-wide role
     * can be held by its people, and an app-declared role belongs to it only if it may use
     * that app. With no organization chosen (only an environment administrator) every role
     * in the environment, which the model's environment scope still bounds.
     *
     * @return Builder<Role>
     */
    private function visible(): Builder
    {
        $organizationId = $this->actingOrganizationId();

        if ($organizationId === null) {
            return Role::query();
        }

        $clientIds = array_keys($this->usableApps());

        return Role::query()->where(fn (Builder $q): Builder => $q
            ->where('organization_id', $organizationId)
            ->orWhere(fn (Builder $q): Builder => $q
                ->whereNull('organization_id')
                ->whereNull('orphaned_at')
                ->where(fn (Builder $q): Builder => $q->whereNull('client_id')->orWhereIn('client_id', $clientIds))));
    }

    /**
     * The roles this administrator may WRITE to, as a query rather than a predicate, so a
     * mutation resolves its target INSIDE the gate instead of checking afterwards.
     *
     * An environment-owned role is the control plane's own and is assignable inside every
     * organization here: a tenant admin who could re-permission it would be granting
     * themselves access in organizations that are not theirs, and one who could delete it
     * would revoke it from all of them.
     *
     * @return Builder<Role>
     */
    private function changeable(): Builder
    {
        $scope = app(ConsoleScope::class);

        if ($scope->plane() === ConsolePlane::Environment) {
            // Environment-scoped by the model, so this is every role in THIS environment
            // and never another's.
            return Role::query();
        }

        return Role::query()
            ->whereNotNull('organization_id')
            ->where('organization_id', $scope->requireOrganizationId());
    }

    /**
     * The organization the role service should fence on, or null for the environment
     * plane — where an operator legitimately manages the environment's own roles and
     * there is no tenant to name.
     *
     * Belt and braces alongside {@see changeable()}: this page already resolves a role
     * inside an ownership-scoped set, and the service now fences on its own, so a later
     * edit that loosens the resolve here cannot quietly reopen a cross-tenant write.
     */
    private function fenceOrganizationId(): ?string
    {
        $scope = app(ConsoleScope::class);

        return $scope->plane() === ConsolePlane::Environment ? null : $scope->requireOrganizationId();
    }

    /**
     * The permissions this administrator may put on a role.
     *
     * An administrator who holds the environment sees the whole declared catalogue. A
     * tenant sees only what the apps in its reach declared AND marked tenant-assignable —
     * an app keeps its privileged internal keys off this list by not marking them.
     *
     * @return Builder<Permission>
     */
    private function assignable(): Builder
    {
        $query = Permission::query()->whereNull('orphaned_at');

        if (app(ConsoleScope::class)->plane() === ConsolePlane::Environment) {
            return $query;
        }

        $clientIds = array_keys($this->usableApps());

        return $query
            ->where('tenant_assignable', true)
            ->where(fn (Builder $q): Builder => $q->whereIn('client_id', $clientIds)->orWhereNull('client_id'));
    }

    /**
     * client_id => name for the apps this page has to name: the ones the catalogue's
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

        $wanted = array_values(array_unique($wanted));

        $names = [];

        foreach (Client::query()->whereIn('client_id', $wanted)->get(['client_id', 'name']) as $client) {
            $names[$client->client_id] = $client->name;
        }

        return $names;
    }

    /**
     * client_id => name for every app in scope: the platform's own plus the acting
     * organization's, and every app in the environment when no organization is chosen.
     *
     * @return array<string, string>
     */
    private function usableApps(): array
    {
        $organizationId = $this->actingOrganizationId();

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

    /**
     * Null has to mean "an environment administrator has not chosen yet" and never "this
     * member has no organization" — the nullable reader answers null for both, and on the
     * organization plane that second case would open every role in the environment.
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
        <a href="{{ route($scopeRoute('roles')) }}" class="text-sm inline-flex items-center gap-1" style="color:var(--muted)"><x-icon name="chevron" class="w-3.5 h-3.5 rotate-180" /> Roles</a>
        <div class="mt-2 flex items-center gap-3 flex-wrap">
            <h1 class="font-semibold tracking-tight" style="font-size:1.5rem">{{ $role->name }}</h1>
            @if ($declaredByApp)
                <span class="badge">Managed by the app</span>
            @elseif ($readOnly)
                <span class="badge">Managed for the environment</span>
            @endif
            @if ($role->client_id)
                <span class="badge"><x-icon name="clients" class="w-3 h-3" /> {{ $appNames[$role->client_id] ?? $role->client_id }} only</span>
            @else
                <span class="badge">All apps</span>
            @endif
        </div>
        <p class="mt-1 text-sm mono" style="color:var(--faint)">{{ $role->id }}</p>
    </div>

    {{-- Details --}}
    <div class="rounded-xl border p-5" style="border-color:var(--border)">
        <h2 class="cbx-section-title">Details</h2>
        @if ($readOnly)
            <p class="mt-1 text-xs" style="color:var(--faint)">
                {{ $declaredByApp
                    ? "This role is declared by an application, which is its source of truth — it can't be edited here."
                    : 'This role belongs to the environment and applies across every organization in it — it applies here but is not yours to change.' }}
            </p>
            <div class="mt-4 space-y-3">
                <div>
                    <p class="label">Name</p>
                    <p class="text-sm">{{ $role->name }}</p>
                </div>
                <div>
                    <p class="label">Description</p>
                    <p class="text-sm" style="color:var(--muted)">{{ $role->description ?: '—' }}</p>
                </div>
            </div>
        @else
            <form wire:submit="saveDetails" class="mt-4 space-y-4">
                <div>
                    <label class="label" for="editName">Name</label>
                    <input wire:model="editName" id="editName" type="text" class="input">
                    @error('editName') <p class="field-error" role="alert">{{ $message }}</p> @enderror
                </div>
                <div>
                    <label class="label" for="editDescription">Description <span style="color:var(--faint)">(optional)</span></label>
                    <input wire:model="editDescription" id="editDescription" type="text" class="input">
                    @error('editDescription') <p class="field-error" role="alert">{{ $message }}</p> @enderror
                </div>
                <button type="submit" class="btn btn-primary" wire:loading.attr="disabled" wire:target="saveDetails">Save changes</button>
            </form>
        @endif
    </div>

    {{-- Permissions --}}
    <div class="rounded-xl border p-5" style="border-color:var(--border)">
        <h2 class="cbx-section-title">Permissions</h2>
        <p class="mt-1 text-xs" style="color:var(--faint)">What this role is allowed to do. {{ $readOnly ? ($declaredByApp ? 'Set by the application.' : 'Set for the whole environment.') : 'Tick a permission to grant it; untick to revoke.' }}</p>
        @if ($readOnly)
            <div class="mt-4 flex flex-wrap gap-1.5">
                @forelse ($catalog->whereIn('id', $granted) as $perm)
                    <span class="badge mono">{{ $perm->name }}</span>
                @empty
                    <div class="cbx-empty">
                        <div class="cbx-empty-icon"><x-icon name="key" class="w-5 h-5" /></div>
                        <h3>No permissions</h3>
                        <p>{{ $declaredByApp
                            ? 'This role grants no permissions. The application that declares it controls what it can do.'
                            : 'This role grants no permissions you can see.' }}</p>
                    </div>
                @endforelse
            </div>
        @else
            <div class="mt-4 space-y-1.5 rounded-lg border p-3 max-h-96 overflow-y-auto" style="border-color:var(--border)">
                @forelse ($catalog as $perm)
                    <label class="flex items-start gap-2 rounded-md px-2 py-1.5 cursor-pointer hover:bg-[var(--surface-2)]" wire:key="perm-{{ $perm->id }}">
                        {{-- Persists on click. Disabling during the round-trip stops a second
                             click from toggling it straight back. --}}
                        <input type="checkbox" class="mt-0.5 rounded" wire:click="togglePermission('{{ $perm->id }}')" @checked(in_array($perm->id, $granted, true))
                               wire:loading.attr="disabled" wire:target="togglePermission('{{ $perm->id }}')">
                        <span class="min-w-0 flex-1">
                            <span class="flex items-center gap-2 flex-wrap">
                                <span class="text-sm mono">{{ $perm->name }}</span>
                                @if ($perm->client_id)<span class="badge badge-info">{{ $appNames[$perm->client_id] ?? 'App' }}</span>@else<span class="badge">All apps</span>@endif
                            </span>
                            @if ($perm->description)<span class="block text-xs" style="color:var(--faint)">{{ $perm->description }}</span>@endif
                        </span>
                    </label>
                @empty
                    <div class="cbx-empty">
                        <div class="cbx-empty-icon"><x-icon name="key" class="w-5 h-5" /></div>
                        <h3>No permissions declared</h3>
                        <p>An app registers its catalog over the SDK; the keys it declares appear here.</p>
                    </div>
                @endforelse
            </div>
            {{-- Each tick writes immediately; SC 4.1.3 needs that reported. --}}
            <p role="status" aria-live="polite" class="sr-only">{{ count($granted) }} of {{ count($catalog) }} permissions granted.</p>
        @endif
    </div>

    {{-- WHO HOLDS IT. Nothing in the console answered this: an access review enumerates
         one organization's grants, and an environment-wide grant belongs to none — so the
         only way to learn who held a role was to open every user page in turn. A role
         whose holders you cannot see is a role you cannot govern. --}}
    <div class="rounded-xl border p-5" style="border-color:var(--border)">
        <h2 class="cbx-section-title">Who holds this</h2>
        @if ($holders === [])
            <p class="mt-2 text-sm" style="color:var(--muted)">Nobody yet. Grant it on a person's page — inside one organization, or everywhere in this environment.</p>
        @else
            <p class="mt-1 text-sm" style="color:var(--muted)">{{ count($holders) }} {{ \Illuminate\Support\Str::plural('grant', count($holders)) }}. A grant made everywhere applies in every organization, including to people who belong to none.</p>
            <div class="mt-4 rounded-lg border overflow-hidden" style="border-color:var(--border)">
                @foreach ($holders as $holder)
                    <div class="flex items-center gap-3 flex-wrap px-3 py-2 {{ ! $loop->last ? 'border-b' : '' }}" style="border-color:var(--border)" wire:key="holder-{{ $loop->index }}">
                        <div class="min-w-0 flex-1">
                            <p class="text-sm font-medium truncate">{{ $holder['name'] }}</p>
                            <p class="text-xs truncate" style="color:var(--faint)">{{ $holder['email'] }}</p>
                        </div>
                        @if ($holder['scope'] === null)
                            <span class="badge" title="Applies in every organization in this environment.">Everywhere</span>
                        @else
                            <span class="badge">{{ $holder['scope'] }}</span>
                        @endif
                    </div>
                @endforeach
            </div>
        @endif
    </div>

    {{-- Danger zone --}}
    @unless ($readOnly)
        <div class="rounded-xl border p-5" style="border-color:var(--border)">
            <h2 class="cbx-section-title">Danger zone</h2>
            <div class="mt-4">
                <x-confirm-delete
                    :name="$role->name"
                    action="deleteRole"
                    label="Delete role"
                    consequence="Everyone currently assigned this role loses the access it grants. This cannot be undone." />
            </div>
        </div>
    @endunless
</div>
