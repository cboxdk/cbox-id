<?php

declare(strict_types=1);

use Illuminate\Support\Collection;
use App\Platform\Console\ConsolePlane;
use App\Platform\Console\ConsoleScope;
use Cbox\Id\AccessControl\Contracts\Roles;
use Cbox\Id\AccessControl\Enums\RoleSource;
use Cbox\Id\AccessControl\Models\Permission;
use Cbox\Id\AccessControl\Models\Role;
use Cbox\Id\OAuthServer\Models\Client;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Url;
use Livewire\Volt\Component;
use Livewire\WithPagination;

/**
 * Roles (list) — one component, both planes. What people can do inside the apps: roles
 * an administrator authors here, and the read-only ones an application declares through
 * its manifest (the app owns what they mean). Separate from "console access"
 * (owner/admin/member), which is who runs this console.
 *
 * This was two pages. The organization plane had a single page that could define a role
 * and compose it from the declared permission catalog, and could do nothing else with one
 * afterwards — no rename, no delete, no way to take a permission back. The environment
 * plane had a routable list → new → detail that could rename, re-permission and delete,
 * and could not grant from the catalog at all. Both halves are here now: the routable
 * shape wins (a role URL is something you send to whoever owns the access), and the
 * catalog picker comes with it.
 *
 * Roles are environment-owned (BelongsToEnvironment), so the model's global scope only
 * ever resolves records inside this environment. Within that, the list is bounded by the
 * acting organization — see {@see visible()} — because the environment plane never had to
 * do that and the organization plane must.
 */
new #[Layout('components.layouts.console', ['title' => 'Roles'])] class extends Component
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

    use WithPagination;

    /** How many permission badges a row draws before it says "and N more". */
    private const BADGE_LIMIT = 6;

    #[Url(as: 'q')]
    public string $search = '';

    public function updatingSearch(): void
    {
        $this->resetPage();
    }

    /**
     * Add a declared permission to a role, by name, from the catalog.
     *
     * Kept on the list rather than moved to the detail page: composing a role out of the
     * keys the apps have declared is the thing an administrator comes here to do, and the
     * organization console had it inline. It existed ONLY there, so an environment
     * administrator — who holds every organization in the environment — could not do it
     * at all.
     *
     * Two guardrails, both server-side, because a select is not a gate. The role must
     * belong to the organization being administered (the contract refuses otherwise, and
     * resolving inside that set means a forged id matches nothing), and the permission
     * must ALREADY be declared and marked tenant-assignable within the role's own scope —
     * a tenant composes from the catalog, it never invents a key, and an app keeps its
     * privileged permissions out of reach by not marking them assignable.
     */
    public function grant(string $roleId, string $permission, Roles $roles): void
    {
        $organizationId = $this->actingOrganizationId();

        // Only an environment administrator can be here with no organization resolved,
        // and there is then no organization whose role this could be.
        if ($organizationId === null) {
            return;
        }

        $permission = trim($permission);

        if ($permission === '') {
            return;
        }

        $role = Role::query()
            ->whereKey($roleId)
            ->where('organization_id', $organizationId)
            ->where('source', '!=', RoleSource::Manifest)
            ->first();

        if ($role === null) {
            return;
        }

        $declared = Permission::query()
            ->where('name', $permission)
            ->where('tenant_assignable', true)
            ->where(fn (Builder $q): Builder => $q->where('client_id', $role->client_id)->orWhereNull('client_id'))
            ->exists();

        if (! $declared) {
            return;
        }

        $roles->grantPermission($organizationId, $role->id, $permission);

        $this->dispatch('toast', message: 'Permission granted.');
    }

    /**
     * @return array<string, mixed>
     */
    public function with(): array
    {
        $scope = app(ConsoleScope::class);
        $organizationId = $this->actingOrganizationId();

        $query = $this->visible()->orderByRaw('client_id is not null')->orderBy('name');

        $term = trim($this->search);

        if ($term !== '') {
            $query->where('name', 'like', "%{$term}%");
        }

        $roles = $query->paginate(25);

        $permissionsByRole = [];

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

            $permissionsByRole[$row->role_id][] = $row->name;
        }

        return [
            // Route names differ per plane; one component, so it asks rather than assumes.
            'scopeRoute' => fn (string $name): string => app(ConsoleScope::class)->routeName($name),
            'roles' => $roles,
            'permissionsByRole' => $permissionsByRole,
            // THE LAST MILE. The card above says a role is "a label stamped into the
            // token", and then the console never shows the token — so the developer on
            // the other end still has to guess which claim to read, and the two most
            // common guesses (`scope`, and a nested `authorization` object) are both
            // wrong. Built from THIS environment's first role and its own permissions,
            // because a generic example is one more thing to translate.
            'sampleRole' => $roles->first(),
            'samplePermissions' => $roles->first() !== null
                ? array_slice($permissionsByRole[$roles->first()->id] ?? [], 0, 3)
                : [],
            'appNames' => $this->usableApps(),
            'manifest' => RoleSource::Manifest,
            'badgeLimit' => self::BADGE_LIMIT,
            // The view half of the question the actions ask. Without it the page renders
            // as a read-only shell on whichever plane the markup forgot.
            'mayAdminister' => $scope->mayAdminister(),
            // A role this administrator may see but not compose still has to be legible,
            // so the view asks per row rather than hiding what constrains the tenant.
            'mayGrant' => fn (Role $role): bool => $this->mayGrant($role),
            // The keys still on offer for one role: declared, assignable, in its scope,
            // and not already held.
            // Precomputed for the page in two queries rather than two PER ROW. The
            // closure ran inside the `@forelse`, so a 25-role page paid 50 — and it
            // re-runs on every keystroke in the search box, which is where it was
            // measured: 28 queries at 0 roles, 79 at 25.
            'offerable' => $this->offerableFor($roles->getCollection(), $permissionsByRole),
            // Not an entitlement problem and not an empty environment: an environment
            // administrator who has chosen no organization is looking at all of it, and
            // cannot compose a tenant's role until they say which tenant they mean.
            'organizationChosen' => $organizationId !== null,
            // "Console access" is the organization plane's own page; the environment
            // plane has no equivalent, so the sentence stays and only the link goes.
            'consoleAccessUrl' => Route::has($scope->routeName('members'))
                ? route($scope->routeName('members'))
                : null,
        ];
    }

    /**
     * The roles this administrator may READ.
     *
     * The environment plane listed every role in the environment, which is right for an
     * administrator who holds it. Serving the same component to an organization admin
     * would hand them every other tenant's roles, so with an organization in scope this
     * is: its own roles, plus the environment-owned ones that apply to it — an
     * admin-authored environment-wide role can be held by its people, and an app-declared
     * role belongs to it only if it may use that app. Orphaned declarations (the app
     * stopped declaring them) are the control plane's problem, not a tenant's.
     *
     * @return Builder<Role>
     */
    private function visible(): Builder
    {
        $organizationId = $this->actingOrganizationId();

        if ($organizationId === null) {
            // Environment-scoped by the model, so this is every role in THIS environment
            // and never another's.
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
     * Whether this role is this administrator's to compose.
     *
     * An environment-owned role (organization_id null) applies to every organization
     * here — an app declares what its own roles mean, and an environment-wide role binds
     * every tenant — so it is theirs to SEE and not to change. An app-declared role is the
     * app's source of truth on both planes.
     */
    private function mayGrant(Role $role): bool
    {
        $organizationId = $this->actingOrganizationId();

        return $organizationId !== null
            && $role->organization_id === $organizationId
            && $role->source !== RoleSource::Manifest;
    }

    /**
     * The permissions still on offer, for every role on the page, in one pass.
     *
     * ONE QUERY FOR THE WHOLE PAGE rather than two per row. The catalogue is read once —
     * `tenant_assignable`, not orphaned — and each role's own scope and grants are applied
     * in memory, because both are already in hand: the grants were batched for the page
     * above, and a role's scope is one column on the role.
     *
     * @param  \Illuminate\Support\Collection<int, Role>  $roles
     * @param  array<string, list<string>>  $permissionsByRole
     * @return array<string, array<string, string>> role id => (name => description)
     */
    private function offerableFor(Collection $roles, array $permissionsByRole): array
    {
        $grantable = $roles->filter(fn (Role $role): bool => $this->mayGrant($role));

        if ($grantable->isEmpty()) {
            return [];
        }

        /** @var list<object{name: string, description: string|null, client_id: string|null}> $catalogue */
        $catalogue = Permission::query()
            ->whereNull('orphaned_at')
            ->where('tenant_assignable', true)
            // Only the apps the roles on THIS page belong to, plus the unscoped ones —
            // reading the whole catalogue would make the page's cost the size of the
            // environment rather than the size of the page.
            ->where(fn (Builder $q): Builder => $q
                ->whereIn('client_id', $grantable->pluck('client_id')->filter()->unique()->all())
                ->orWhereNull('client_id'))
            ->orderBy('name')
            ->get(['name', 'description', 'client_id'])
            ->all();

        $offerable = [];

        foreach ($grantable as $role) {
            $granted = $permissionsByRole[$role->id] ?? [];
            $forRole = [];

            foreach ($catalogue as $permission) {
                // A role may be granted its own app's permissions and the unscoped ones,
                // and nothing another app declared.
                if ($permission->client_id !== null && $permission->client_id !== $role->client_id) {
                    continue;
                }

                if (in_array($permission->name, $granted, true)) {
                    continue;
                }

                $forRole[$permission->name] = $permission->description ?? '';
            }

            $offerable[$role->id] = $forRole;
        }

        return $offerable;
    }

    /**
     * client_id => name for every app in scope: the platform's own plus the acting
     * organization's, and every app in the environment when no organization is chosen.
     *
     * A plain map rather than a Collection — this is a lookup for badges and a picker, a
     * serialization edge, and `pluck()` returns a shape neither PHPStan nor a reader can
     * pin down, which is how a key silently becomes an int.
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
     * The organization whose roles this page is about, or null for the whole-environment
     * overview.
     *
     * Null has to mean "an environment administrator has not chosen yet" and never "this
     * member has no organization" — the nullable reader answers null for both, and on the
     * organization plane that second case would widen the list from one organization to
     * every organization in the environment.
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
    <x-page-header title="Roles" :help="\App\Platform\Help\HelpTopic::Roles"
                   subtitle="What people can do inside your apps. You assign roles here; each app decides what its roles are allowed to do.">
        @if ($mayAdminister)
            <x-slot:actions>
                <a href="{{ route($scopeRoute('roles.create')) }}" class="btn btn-primary shrink-0"><x-icon name="plus" class="w-4 h-4" /> New role</a>
            </x-slot:actions>
        @endif
    </x-page-header>

    {{-- The one-paragraph mental model that removes the confusion. --}}
    <div class="card p-4 mt-6" style="background:var(--accent-soft);border-color:var(--accent-edge)">
        <div class="flex items-start gap-3">
            <span class="grid place-items-center rounded-lg shrink-0" style="width:2rem;height:2rem;background:var(--card);color:var(--primary)"><x-icon name="key" class="w-4 h-4" /></span>
            <p class="text-sm" style="color:var(--foreground)">
                <b>Cbox ID assigns roles; your app decides what they can do.</b>
                A role is a label stamped into the token — <b>app roles</b> are declared by each app and read-only here, and the rest are ones you define. This is different from
                @if ($consoleAccessUrl)
                    <a href="{{ $consoleAccessUrl }}" class="underline" style="color:var(--accent-strong)">console access</a>
                @else
                    console access
                @endif
                (owner/admin/member), which is who can run this console.
            </p>
        </div>
    </div>

    {{-- What the app on the other end actually receives. --}}
    @if ($sampleRole !== null)
        <details class="card mt-4 p-4">
            <summary class="text-sm font-medium cursor-pointer">What your app receives</summary>
            <p class="mt-2 text-sm" style="color:var(--muted)">
                Roles and permissions arrive in the access token as two arrays. Your app
                reads them straight from the token — there is no call back to Cbox ID:
            </p>
            <pre class="mt-3 rounded-lg p-3 overflow-x-auto text-xs mono" style="background:var(--surface-2);border:1px solid var(--border);line-height:1.6">{
  "sub": "the person's id",
  "org": "the organization they are acting for",
  "roles": [{!! "\"".e($sampleRole->name)."\"" !!}],
  "permissions": [{!! collect($samplePermissions)->map(fn ($p) => '"'.e($p).'"')->implode(', ') !!}]
}</pre>
            <p class="mt-2 text-xs" style="color:var(--faint)">
                @if ($samplePermissions === [])
                    <b>{{ $sampleRole->name }}</b> has no permissions yet, so `permissions` arrives empty — the role name is still there to act on.
                @endif
                The <code class="mono">scope</code> claim is a different thing: it is what the
                <em>app</em> was allowed to ask for, not what this <em>person</em> may do.
            </p>
        </details>
    @endif

    <div class="mt-6">
        <input wire:model.live.debounce.300ms="search" type="search" class="input" style="max-width:24rem" placeholder="Search by name" aria-label="Search roles">
        {{-- SC 4.1.3: the list re-renders on a debounced keystroke with no focus
             change, so the result count is the only thing that can report the filter
             narrowed to nothing. --}}
        <p role="status" aria-live="polite" class="sr-only">{{ $roles->total() }} {{ \Illuminate\Support\Str::plural('role', $roles->total()) }} found.</p>
    </div>

    <div class="mt-4 rounded-xl border overflow-hidden" style="border-color:var(--border)">
        @forelse ($roles as $role)
            @php
                $granted = $permissionsByRole[$role->id] ?? [];
                $available = $offerable[$role->id] ?? [];
            @endphp
            {{-- A row rather than one big link: the permission picker lives in it, and a
                 select inside an anchor is neither valid nor operable by keyboard. --}}
            <div wire:key="role-{{ $role->id }}" class="p-4 {{ ! $loop->last ? 'border-b' : '' }}" style="border-color:var(--border)">
                <div class="flex items-center gap-3 flex-wrap">
                    <div class="min-w-0 flex-1">
                        <a href="{{ route($scopeRoute('roles.show'), $role->id) }}" class="font-medium truncate">{{ $role->name }}</a>
                        @if ($role->description)
                            <p class="text-sm truncate" style="color:var(--muted)">{{ $role->description }}</p>
                        @endif
                    </div>
                    @if ($role->source === $manifest)
                        <span class="badge" title="Declared by the application, which is its source of truth.">App</span>
                    @endif
                    @if ($role->client_id)
                        <span class="badge"><x-icon name="clients" class="w-3 h-3" /> {{ $appNames[$role->client_id] ?? $role->client_id }} only</span>
                    @else
                        <span class="badge">All apps</span>
                    @endif
                    @if ($role->organization_id === null)
                        <span class="badge" title="Owned by the environment — it applies here but is not yours to change.">Environment-wide</span>
                    @endif
                    <x-icon name="chevron" class="w-4 h-4 shrink-0" style="color:var(--faint)" />
                </div>

                <div class="flex flex-wrap items-center gap-1.5 mt-3">
                    @forelse (array_slice($granted, 0, $badgeLimit) as $permission)
                        <span class="badge"><x-icon name="key" class="w-3 h-3" /> <span class="mono">{{ $permission }}</span></span>
                    @empty
                        <span class="text-xs" style="color:var(--muted-foreground)">No permissions yet.</span>
                    @endforelse
                    @if (count($granted) > $badgeLimit)
                        <span class="text-xs" style="color:var(--faint)">and {{ count($granted) - $badgeLimit }} more</span>
                    @endif
                </div>

                @if ($mayAdminister && $mayGrant($role))
                    @if ($available !== [])
                        <select class="select mt-3" style="max-width:22rem"
                                aria-label="Add a permission to the {{ $role->name }} role"
                                wire:change="grant('{{ $role->id }}', $event.target.value)">
                            <option value="">+ Add a permission…</option>
                            @foreach ($available as $name => $description)
                                <option value="{{ $name }}">{{ $name }}@if ($description) — {{ \Illuminate\Support\Str::limit($description, 40) }}@endif</option>
                            @endforeach
                        </select>
                    @else
                        <p class="text-xs mt-3" style="color:var(--faint)">Every available permission is already granted.</p>
                    @endif
                @endif
            </div>
        @empty
            @if (trim($search) !== '')
                <div class="cbx-empty">
                    <div class="cbx-empty-icon"><x-icon name="search" class="w-5 h-5" /></div>
                    <h3>No matches</h3>
                    <p>No roles match “{{ trim($search) }}”. Try a different name.</p>
                </div>
            @else
                <x-empty-state icon="shield" title="No roles yet"
                               :help="\App\Platform\Help\HelpTopic::Roles"
                               body="Without roles, everyone who can sign in gets whatever an app gives a plain user. A role is how you say “these people are editors, those are support” once, and have every connected app honour it."
                               :steps="[
                                   'Let your apps declare the roles they understand — those arrive here on their own.',
                                   'Or create a role here and compose it from the permissions your apps have declared.',
                                   'Assign it to people; the role travels with them into every connected app.',
                               ]" />
            @endif
        @endforelse
    </div>

    @unless ($organizationChosen)
        {{-- The distinction that matters: nothing is wrong with this administrator, they
             simply have not said which organization they are acting for. --}}
        <p class="mt-4 text-sm" style="color:var(--faint)">Showing every role in this environment. Choose an organization in the console header to compose one of its roles.</p>
    @endunless

    <div class="mt-4 max-w-full overflow-x-auto">{{ $roles->links() }}</div>
</div>
