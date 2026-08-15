<?php

declare(strict_types=1);

use App\Platform\Console\ConsolePlane;
use App\Platform\Console\ConsoleScope;
use App\Platform\EnvironmentAdminAuth;
use Cbox\Id\AccessControl\Models\Permission;
use Cbox\Id\Kernel\Tenancy\Contracts\EnvironmentContext;
use Cbox\Id\OAuthServer\Models\Client;
use Illuminate\Support\Facades\DB;
use Livewire\Attributes\Layout;
use Livewire\Volt\Component;

/**
 * Environment control plane › Permissions. The catalog every role draws from.
 *
 * A permission is a `feature:action` key. Two sources, always distinguishable:
 *  - APP-DECLARED (client_id set) — synced from an app's manifest via the SDK/API.
 *    The app is the source of truth, so these are read-only here; an app that stops
 *    declaring one leaves it "orphaned" (kept, not deleted).
 *  - MANUAL (client_id null) — authored right here, for orgs that don't run an SDK
 *    integration but still need to compose their own permissions into roles.
 *
 * Only manual permissions can be edited or removed. Both kinds are assignable into
 * roles from the Roles editor.
 */
/**
 * ONE COMPONENT, BOTH PLANES — `layouts.console` delegates to the right chrome.
 *
 * This page lived under `livewire/environment/` and declared the environment layout
 * outright, which is what made it a page of the SECOND console: an organization
 * administrator had no permissions screen at all, though permissions are the vocabulary
 * their roles are written in. Nothing in it was environment-specific — it reads the
 * environment from `EnvironmentContext`, which both planes set.
 */
new #[Layout('components.layouts.console', ['title' => 'Permissions'])] class extends Component
{
    /**
     * Second layer, and PLANE-AGNOSTIC — the same gate every shared page uses.
     *
     * It asked `EnvironmentAdminAuth::membership()` outright, which is an environment-plane
     * identity and null everywhere else, so the page answered 403 the moment it was
     * offered on the organization plane. `ConsoleScope::assertMayAdminister()` asks the
     * question the page actually means — may this caller administer HERE — and each plane
     * answers it in its own terms. See console/roles, which does the same for the roles
     * these permissions are assembled into.
     *
     * boot() rather than mount(): only boot() runs on each Livewire action, and this
     * console once had no in-component authorization at all — so when the route's
     * middleware went missing from the persistent list, every action answered
     * unauthenticated.
     */
    public function boot(): void
    {
        app(ConsoleScope::class)->assertMayAdminister();
    }

    public string $name = '';

    public string $description = '';

    public bool $tenantAssignable = true;

    /** The manual permission being inline-edited, if any. */
    public ?string $editingId = null;

    public string $editDescription = '';

    public bool $editTenantAssignable = true;

    public function create(): void
    {
        $data = $this->validate([
            // feature:action — lower-case, e.g. invoices:create, reports:read.
            'name' => ['required', 'string', 'max:120', 'regex:/^[a-z0-9][a-z0-9_.-]*:[a-z0-9][a-z0-9_.*-]*$/i'],
            'description' => ['nullable', 'string', 'max:500'],
        ]);

        $name = mb_strtolower(trim($this->name));
        $environmentId = $this->environmentId();
        $owner = $this->owner();

        // Uniqueness among the manual permissions this author can SEE. Manual rows carry
        // a null client_id, so the DB (client_id, name) unique never actually constrains
        // them — uniqueness is enforced here, scoped to the environment so two
        // environments may each own a "billing:refund" without colliding, and to what is
        // visible so a tenant is told about a collision with the shared tier (which would
        // otherwise give them two identical keys in the Roles editor) but never about one
        // with a PEER's private key, which would make this form an existence oracle for
        // other tenants' `feature:action` names.
        if (Permission::query()
            ->whereNull('client_id')
            ->where('environment_id', $environmentId)
            ->visibleToOrganization($owner)
            ->where('name', $name)
            ->exists()) {
            $this->addError('name', 'A permission with that key already exists here.');

            return;
        }

        // Stamp the authoring environment AND the authoring organization. The environment
        // is the hard boundary — no other environment's admin can see, edit or delete
        // this. The organization is the soft one, and it is what the plane decides: a row
        // written on the organization plane belongs to that tenant alone, a row written on
        // the environment plane is shared with every tenant in the environment. It used to
        // be shared either way, so a tenant admin's "Add permission" quietly edited the
        // whole environment's catalog — and their Delete cascaded `role_permission` for
        // every role in it.
        //
        // Tenant-assignable is what the SHARED tier uses to say "orgs may compose this
        // into their own roles". On a row an org already owns it has nothing left to
        // decide, so the org plane does not offer the choice and writes true.
        Permission::query()->create([
            'client_id' => null,
            'environment_id' => $environmentId,
            'organization_id' => $owner,
            'name' => $name,
            'description' => trim($this->description) !== '' ? trim($this->description) : null,
            'tenant_assignable' => $owner !== null || $this->tenantAssignable,
        ]);

        $this->reset('name', 'description');
        $this->tenantAssignable = true;
        $this->dispatch('toast', message: 'Permission created.');
    }

    public function startEdit(string $id): void
    {
        $perm = $this->manual($id);
        if ($perm === null) {
            return;
        }

        $this->editingId = $perm->id;
        $this->editDescription = $perm->description ?? '';
        $this->editTenantAssignable = $perm->tenant_assignable;
    }

    public function cancelEdit(): void
    {
        $this->reset('editingId', 'editDescription', 'editTenantAssignable');
    }

    public function saveEdit(): void
    {
        if ($this->editingId === null) {
            return;
        }

        $perm = $this->manual($this->editingId);
        if ($perm === null) {
            $this->cancelEdit();

            return;
        }

        $this->validate(['editDescription' => ['nullable', 'string', 'max:500']]);

        $perm->description = trim($this->editDescription) !== '' ? trim($this->editDescription) : null;
        $perm->tenant_assignable = $this->editTenantAssignable;
        $perm->save();

        $this->cancelEdit();
        $this->dispatch('toast', message: 'Permission updated.');
    }

    public function delete(string $id): void
    {
        // Only a MANUAL permission may be removed — an app-declared one is the app's.
        $perm = $this->manual($id);
        if ($perm === null) {
            return;
        }

        DB::table('role_permission')->where('permission_id', $perm->id)->delete();
        $perm->delete();

        $this->dispatch('toast', message: 'Permission deleted.');
    }

    /**
     * Who owns what this page authors: the acting organization, or null for the shared
     * environment-wide tier.
     *
     * THE PLANE DECIDES, not the organization picker. An environment administrator who
     * has narrowed the console to one tenant is still administering the environment, and
     * "which permission does Add write" must not silently change meaning because a
     * dropdown elsewhere on the chrome was touched. On the organization plane there is no
     * choice to make — `requireOrganizationId()` aborts rather than falling back to null,
     * so a scope that somehow resolved no tenant cannot write into the shared tier.
     */
    private function owner(): ?string
    {
        $scope = app(ConsoleScope::class);

        return $scope->plane() === ConsolePlane::Organization
            ? $scope->requireOrganizationId()
            : null;
    }

    /**
     * Resolve a permission id for WRITING, deny-by-default: a MANUAL row, in this
     * environment, owned by this author.
     *
     * The environment clause has been here since one environment's admin could delete
     * another's. The owner clause is the same bug one level in — every tenant admin shared
     * a single environment-wide catalog, so any of them could rename or delete a key their
     * peers' roles were built from, and {@see self::delete()} purges `role_permission` for
     * EVERY role in the environment that granted it.
     *
     * `ownedByOrganization()` and not `visibleToOrganization()`: a tenant can see the
     * shared tier because their roles are composed from it, and must still not write to
     * it. The two predicates differ by exactly that, which is why the model names both.
     */
    private function manual(string $id): ?Permission
    {
        return Permission::query()
            ->whereKey($id)
            ->whereNull('client_id')
            ->where('environment_id', $this->environmentId())
            ->ownedByOrganization($this->owner())
            ->first();
    }

    /** The current environment id, fail-closed — the manual-permission tenant boundary. */
    private function environmentId(): string
    {
        $environment = app(EnvironmentContext::class)->current();

        abort_if($environment === null, 403);

        return $environment->environmentKey();
    }

    /**
     * @return array<string, mixed>
     */
    public function with(): array
    {
        $owner = $this->owner();

        $all = Permission::query()->visibleToOrganization($owner)->orderBy('name')->get();

        // Only THIS environment's manual permissions are editable here. Operator-seeded
        // platform-global (null environment) rows remain visible-and-bindable in the
        // Roles editor but are not surfaced with edit/delete controls they'd no-op on.
        $manual = $all->whereNull('client_id')->where('environment_id', $this->environmentId());

        // Split by ownership, because the page's controls differ and a row that renders an
        // Edit button the caller's writes cannot resolve is a lie the console tells once
        // per render. `$mine` is what {@see self::manual()} will resolve — same predicate,
        // written once each side. On the environment plane the owner is null, so `$mine`
        // IS the shared tier and nothing is inherited.
        $mine = $manual->filter(fn (Permission $p): bool => $p->organization_id === $owner)->values();
        $inherited = $owner === null
            ? $mine->take(0)
            : $manual->filter(fn (Permission $p): bool => $p->organization_id === null)->values();

        $declared = $all->whereNotNull('client_id');

        // A tenant sees an app's catalog only where the app said tenants may use it, and
        // only for apps they could actually be using. Every declared key in the
        // environment was rendered to every tenant admin, `tenant_assignable` or not — and
        // an internal key is named after the thing it guards, so a peer's catalog read as
        // a description of what that peer had bought. The environment plane keeps the full
        // view: it administers the apps.
        if ($owner !== null) {
            $usable = Client::query()
                ->where(function ($query) use ($owner): void {
                    $query->whereNull('organization_id')->orWhere('organization_id', $owner);
                })
                ->pluck('client_id')
                ->all();

            $declared = $declared->filter(
                fn (Permission $p): bool => $p->tenant_assignable && in_array($p->client_id, $usable, true),
            );
        }

        $declared = $declared->values();

        $appNames = Client::query()
            ->whereIn('client_id', $declared->pluck('client_id')->filter()->unique()->all())
            ->pluck('name', 'client_id');

        // permissionId => how many of THIS environment's roles reference it (context,
        // so an admin sees what deleting a manual permission would strip from roles).
        //
        // Counted in SQL, over this page's permissions only. It used to pull the ENTIRE
        // platform-wide `role_permission` pivot into PHP — the pivot has no
        // `environment_id`, so there was no WHERE at all — and count it in memory, on
        // every render and again on every Livewire action. At 400 environments × 40
        // roles × 25 permissions that is ~400k rows materialised to display a handful
        // of small integers.
        //
        // The join is not only for the aggregate: without it the count included OTHER
        // environments' roles, so one tenant's page reported another tenant's usage.
        // The sibling Roles list already does it this way.
        //
        // On the organization plane it counts THAT TENANT'S roles and no others. "in 3
        // roles" against the shared tier otherwise reported how many roles a peer had
        // built on the key — a number that moves when the peer edits their roles, which
        // is a side channel however small.
        $usage = DB::table('role_permission')
            ->join('roles', 'roles.id', '=', 'role_permission.role_id')
            ->where('roles.environment_id', $this->environmentId())
            ->when($owner !== null, fn ($query) => $query->where('roles.organization_id', $owner))
            ->whereIn('role_permission.permission_id', $all->pluck('id'))
            ->selectRaw('role_permission.permission_id as permission_id, count(*) as aggregate')
            ->groupBy('role_permission.permission_id')
            ->pluck('aggregate', 'permission_id');

        return [
            'manual' => $mine,
            'inherited' => $inherited,
            'sharesEnvironment' => $owner === null,
            'declared' => $declared,
            'declaredByApp' => $declared->groupBy('client_id'),
            'appNames' => $appNames,
            'usage' => $usage,
        ];
    }
}; ?>

<div class="space-y-6">
    {{-- The subtitle is for somebody who has never read an RFC. "Catalog", "declare" and
         "manifest" are all words this page used to open with; the thing being described is
         simply a list of what a role is allowed to do, and you can write one here without
         any integration at all. --}}
    <x-page-header title="Permissions" :help="\App\Platform\Help\HelpTopic::Permissions"
                   subtitle="Everything a role can be allowed to do. Write your own below — no code needed — or let your apps register theirs automatically." />

    {{-- Create a manual permission --}}
    <div class="rounded-xl border p-5" style="border-color:var(--border)">
        <h2 class="cbx-section-title">New permission</h2>
        <p class="mt-1 text-sm" style="color:var(--muted)">A <code class="mono">feature:action</code> key you can compose into roles — e.g. <code class="mono">invoices:create</code>.</p>
        {{-- Says who gets it, on the page, before the button is pressed. The two planes
             write into different tiers and the form is otherwise identical, which is
             exactly the kind of invisible difference that had a tenant admin editing the
             whole environment's catalog believing it was their own. --}}
        <p class="mt-1 text-sm" style="color:var(--muted)">
            @if ($sharesEnvironment)
                Available to every organization in this environment.
            @else
                Yours alone — other organizations never see it.
            @endif
        </p>
        <form wire:submit="create" class="mt-4 space-y-3">
            <div class="grid sm:grid-cols-[1fr_1.4fr_auto] gap-2 items-start">
                <div>
                    <input wire:model="name" type="text" class="input mono" placeholder="invoices:create" aria-label="Permission key">
                    @error('name') <p class="field-error" role="alert">{{ $message }}</p> @enderror
                </div>
                <div>
                    <input wire:model="description" type="text" class="input" placeholder="Create invoices (optional)" aria-label="Description">
                    @error('description') <p class="field-error" role="alert">{{ $message }}</p> @enderror
                </div>
                <button type="submit" class="btn btn-primary shrink-0" wire:loading.attr="disabled" wire:target="create">Add permission</button>
            </div>
            {{-- Only on the plane where it decides anything. On the organization plane the
                 row already belongs to the organization, so "may org admins use this"
                 has one possible answer and offering the checkbox invites an admin to
                 untick their own permission into uselessness. --}}
            @if ($sharesEnvironment)
                <label class="flex items-center gap-2 text-sm cursor-pointer" style="color:var(--muted)">
                    <input type="checkbox" wire:model="tenantAssignable" class="rounded">
                    Tenant-assignable — org admins may compose this into their own roles. Untick to keep it internal.
                </label>
            @endif
        </form>
    </div>

    {{-- Manual permissions --}}
    <div class="rounded-xl border p-5" style="border-color:var(--border)">
        <div class="flex items-center gap-2">
            <p class="text-sm font-medium">{{ $sharesEnvironment ? 'Manual' : 'Yours' }}</p>
            <span class="badge">{{ $manual->count() }}</span>
        </div>
        <p class="mt-1 text-xs" style="color:var(--faint)">Authored here. Editable and removable.</p>
        <div class="mt-4 space-y-2">
            @forelse ($manual as $perm)
                <div class="rounded-lg border px-3 py-2" style="border-color:var(--border)" wire:key="manual-{{ $perm->id }}">
                    @if ($editingId === $perm->id)
                        <div class="space-y-2">
                            <p class="text-sm mono">{{ $perm->name }}</p>
                            <input wire:model="editDescription" type="text" class="input" placeholder="Description" aria-label="Description">
                            @error('editDescription') <p class="field-error" role="alert">{{ $message }}</p> @enderror
                            <label class="flex items-center gap-2 text-sm cursor-pointer" style="color:var(--muted)">
                                <input type="checkbox" wire:model="editTenantAssignable" class="rounded"> Tenant-assignable
                            </label>
                            <div class="flex items-center gap-2">
                                <button type="button" class="btn btn-primary btn-sm" wire:click="saveEdit">Save</button>
                                <button type="button" class="btn btn-ghost btn-sm" wire:click="cancelEdit">Cancel</button>
                            </div>
                        </div>
                    @else
                        <div class="flex items-center gap-2">
                            <div class="min-w-0 flex-1">
                                <div class="flex items-center gap-2 flex-wrap">
                                    <span class="text-sm mono truncate">{{ $perm->name }}</span>
                                    <span class="badge">Manual</span>
                                    @unless ($perm->tenant_assignable)<span class="badge badge-warn">Internal</span>@endunless
                                    @php $count = (int) ($usage[$perm->id] ?? 0); @endphp
                                    @if ($count > 0)<span class="text-xs" style="color:var(--faint)">in {{ $count }} {{ \Illuminate\Support\Str::plural('role', $count) }}</span>@endif
                                </div>
                                @if ($perm->description)<p class="text-xs truncate" style="color:var(--faint)">{{ $perm->description }}</p>@endif
                            </div>
                            <button type="button" class="btn btn-ghost btn-sm shrink-0" wire:click="startEdit('{{ $perm->id }}')">Edit</button>
                            <x-confirm-delete
                                :name="$perm->name"
                                action="delete('{{ $perm->id }}')"
                                label="Delete"
                                consequence="This permission is removed from every role that currently grants it. This cannot be undone." />
                        </div>
                    @endif
                </div>
            @empty
                <div class="cbx-empty">
                    <div class="cbx-empty-icon"><x-icon name="key" class="w-5 h-5" /></div>
                    <h3>You haven't written any yet</h3>
                    <p>Add one above to build your own roles — no integration required.</p>
                </div>
            @endforelse
        </div>
    </div>

    {{-- Inherited from the environment. Organization plane only — on the environment
         plane these ARE the "Manual" list above, and rendering them twice would suggest
         two catalogs where there is one.

         Shown rather than hidden, and read-only rather than editable: a tenant composes
         roles out of these, so a page that omitted them would explain only half of what
         the Roles editor offers. They belong to the environment, so the fence in
         `manual()` refuses to resolve them for writing and there are no controls here
         that would ask it to. --}}
    @unless ($sharesEnvironment)
        <div class="rounded-xl border p-5" style="border-color:var(--border)">
            <div class="flex items-center gap-2">
                <p class="text-sm font-medium">From your environment</p>
                <span class="badge">{{ $inherited->count() }}</span>
            </div>
            <p class="mt-1 text-xs" style="color:var(--faint)">Provided for every organization here. Yours to use in roles, not to change.</p>
            <div class="mt-4 space-y-2">
                @forelse ($inherited as $perm)
                    <div class="flex items-center gap-2 rounded-lg border px-3 py-2" style="border-color:var(--border)" wire:key="inherited-{{ $perm->id }}">
                        <div class="min-w-0 flex-1">
                            <div class="flex items-center gap-2 flex-wrap">
                                <span class="text-sm mono truncate">{{ $perm->name }}</span>
                                <span class="badge">Shared</span>
                                @php $count = (int) ($usage[$perm->id] ?? 0); @endphp
                                @if ($count > 0)<span class="text-xs" style="color:var(--faint)">in {{ $count }} of your {{ \Illuminate\Support\Str::plural('role', $count) }}</span>@endif
                            </div>
                            @if ($perm->description)<p class="text-xs truncate" style="color:var(--faint)">{{ $perm->description }}</p>@endif
                        </div>
                    </div>
                @empty
                    <div class="cbx-empty">
                        <div class="cbx-empty-icon"><x-icon name="key" class="w-5 h-5" /></div>
                        <h3>Nothing shared with you yet</h3>
                        <p>Permissions your environment publishes for every organization will appear here.</p>
                    </div>
                @endforelse
            </div>
        </div>
    @endunless

    {{-- App-declared (synced) permissions --}}
    <div class="rounded-xl border p-5" style="border-color:var(--border)">
        <div class="flex items-center gap-2">
            <p class="text-sm font-medium">App-declared</p>
            <span class="badge">{{ $declared->count() }}</span>
        </div>
        <p class="mt-1 text-xs" style="color:var(--faint)">Synced from each app's manifest over the SDK/API. Read-only — the app is their source of truth.</p>
        <div class="mt-4 space-y-4">
            @forelse ($declaredByApp as $clientId => $perms)
                <div wire:key="app-{{ $clientId }}">
                    <p class="text-xs font-semibold uppercase mb-1.5" style="color:var(--muted);letter-spacing:0.05em">{{ $appNames[$clientId] ?? $clientId }}</p>
                    <div class="space-y-2">
                        @foreach ($perms as $perm)
                            <div class="flex items-center gap-2 rounded-lg border px-3 py-2" style="border-color:var(--border)" wire:key="app-perm-{{ $perm->id }}">
                                <div class="min-w-0 flex-1">
                                    <div class="flex items-center gap-2 flex-wrap">
                                        <span class="text-sm mono truncate">{{ $perm->name }}</span>
                                        <span class="badge badge-info">App</span>
                                        @if ($perm->orphaned_at)<span class="badge badge-warn">Orphaned</span>@endif
                                        @unless ($perm->tenant_assignable)<span class="badge">Internal</span>@endunless
                                    </div>
                                    @if ($perm->description)<p class="text-xs truncate" style="color:var(--faint)">{{ $perm->description }}</p>@endif
                                </div>
                            </div>
                        @endforeach
                    </div>
                </div>
            @empty
                <div class="cbx-empty">
                    <div class="cbx-empty-icon"><x-icon name="key" class="w-5 h-5" /></div>
                    <h3>No app has registered a catalog yet</h3>
                    <p>Once an app declares its permissions through the SDK or API, they appear here.</p>
                </div>
            @endforelse
        </div>
    </div>
</div>
