<?php

declare(strict_types=1);

use App\Platform\Console\ConsolePlane;
use App\Platform\Console\ConsoleScope;
use App\Platform\VerifiedEmailGate;
use Cbox\Id\AccessControl\Contracts\Roles;
use Cbox\Id\AccessControl\Models\Permission;
use Cbox\Id\OAuthServer\Models\Client;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Route;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Validate;
use Livewire\Volt\Component;

/**
 * Roles › New — one component, both planes. A dedicated, deep-linkable page that defines
 * a role and, optionally, ticks its opening set of permissions straight from the declared
 * catalog. On success it routes to the role's own page, where the rest of its lifecycle
 * lives.
 *
 * The organization is NOT a field here, and never was on either plane — the console chrome
 * owns that choice. What the environment plane's form DID encode implicitly is the one
 * thing the scope cannot: it always wrote an ENVIRONMENT-wide role, reusable by every
 * tenant in the plane. That is a different kind of role, not a different organization, so
 * it survives as an explicit choice offered — and accepted — only where an administrator
 * holds the environment.
 *
 * "Applies to" is the other axis and is unrelated: a role scoped to one app carries its
 * permissions only into that app's token, and org-wide carries them into every app's.
 */
new #[Layout('components.layouts.console', ['title' => 'New role'])] class extends Component
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

    #[Validate('required|string|max:120')]
    public string $name = '';

    #[Validate('nullable|string|max:500')]
    public string $description = '';

    /** Empty = applies in every app; a client_id = scoped to that app's token. */
    public string $scope = '';

    /**
     * Define the role for every organization in the environment rather than for the one
     * being administered. Offered — and accepted — only on the environment plane.
     */
    public bool $environmentWide = false;

    /**
     * Livewire rehydrates this straight off the wire, so the keys are whatever the
     * request sent — not necessarily a gapless list, and every value is a claim until it
     * has been resolved against the catalog.
     *
     * @var array<array-key, string>
     */
    public array $permissions = [];

    public function create(Roles $roles): mixed
    {
        $scope = app(ConsoleScope::class);

        // The unverified-address hold is the organization plane's. It exists because a
        // social sign-in mints a usable subject whose address only a provider vouched
        // for; an environment administrator arrives as an account member instead, so
        // there is no subject to have confirmed and applying it there would refuse every
        // one of them.
        if ($scope->plane() === ConsolePlane::Organization) {
            app(VerifiedEmailGate::class)->require('create a role');
        }

        $this->validate();

        if ($this->environmentWide) {
            // Server-side, not just an absent checkbox: a role with no organization is
            // assignable inside every tenant in the environment, and an organization
            // admin who could mint one would be defining access for organizations that
            // are not theirs.
            if ($scope->plane() !== ConsolePlane::Environment) {
                $this->addError('environmentWide', 'Only an environment administrator may define a role for every organization.');

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

        // Only ever scope to an app that is in reach — never trust the posted client_id.
        $clientId = array_key_exists($this->scope, $this->usableApps()) ? $this->scope : null;

        $role = $roles->define(
            $organizationId,
            trim($this->name),
            trim($this->description) !== '' ? trim($this->description) : null,
            $clientId,
        );

        // Seed the opening permissions, resolved against the catalog this administrator
        // may actually assign from — a posted id that matches nothing there is dropped
        // rather than trusted.
        //
        // Through the framework, never raw SQL. These used to be `DB::table()` inserts:
        // no audit entry and no `role.permission_granted`, so a change to privileged
        // access left nothing on /audit and nothing for a SIEM. attachPermission() writes
        // the same row and reports it.
        foreach ($this->assignable()->whereKey(array_values($this->permissions))->get(['id']) as $permission) {
            $roles->attachPermission($role->id, $permission->id);
        }

        $this->dispatch('toast', message: 'Role created.');

        return $this->redirectRoute($scope->routeName('roles.show'), ['role' => $role->id], navigate: true);
    }

    /**
     * @return array<string, mixed>
     */
    public function with(): array
    {
        $scope = app(ConsoleScope::class);

        $catalog = $this->assignable()->orderBy('name')->get(['id', 'name', 'description', 'client_id']);

        return [
            // Route names differ per plane; one component, so it asks rather than assumes.
            'scopeRoute' => fn (string $name): string => app(ConsoleScope::class)->routeName($name),
            'catalog' => $catalog,
            'usableApps' => $this->usableApps(),
            // Authoring a permission by hand is the control plane's own page and has no
            // organization-plane equivalent, so the pointer to it goes where it exists
            // rather than being dropped from the merge.
            'permissionsUrl' => Route::has($scope->routeName('permissions'))
                ? route($scope->routeName('permissions'))
                : null,
            // Whether defining a role for the whole environment is even on offer here.
            'holdsEnvironment' => $scope->plane() === ConsolePlane::Environment,
            'organizationChosen' => $this->actingOrganizationId() !== null,
        ];
    }

    /**
     * The permissions this administrator may put on a role.
     *
     * An administrator who holds the environment sees the whole declared catalogue. A
     * tenant sees only what the apps in its reach declared AND marked tenant-assignable —
     * an app keeps its privileged internal keys off this list by not marking them, and
     * that is a boundary a checkbox cannot be trusted to hold, so the same query resolves
     * the posted ids in {@see create()}.
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
     * client_id => name for every app in scope: the platform's own plus the acting
     * organization's, and every app in the environment when no organization is chosen.
     *
     * A plain map rather than a Collection — this is a picker's option list, a
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
     * Null has to mean "an environment administrator has not chosen yet" and never "this
     * member has no organization" — the nullable reader answers null for both, and on the
     * organization plane that second case would widen the app and permission pickers from
     * one organization to the whole environment.
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
    <a href="{{ route($scopeRoute('roles')) }}" class="text-sm inline-flex items-center gap-1" style="color:var(--muted)"><x-icon name="chevron" class="w-3.5 h-3.5 rotate-180" /> Roles</a>
    <x-page-header class="mt-2" title="New role" subtitle="A bundle of permissions you assign to people; each app honours the roles it understands." />

    <form wire:submit="create" class="mt-6 max-w-xl rounded-xl border p-5 space-y-4" style="border-color:var(--border)">
        <div>
            <label class="label" for="name">Name</label>
            <input @error('name') aria-invalid="true" aria-describedby="name-error" @enderror wire:model="name" id="name" type="text" class="input" placeholder="Manager" autofocus>
            @error('name') <p id="name-error" class="field-error" role="alert">{{ $message }}</p> @enderror
        </div>

        <div>
            <label class="label" for="description">Description <span style="color:var(--faint)">(optional)</span></label>
            <input @error('description') aria-invalid="true" aria-describedby="description-error" @enderror wire:model="description" id="description" type="text" class="input" placeholder="Team leads across the organization">
            @error('description') <p id="description-error" class="field-error" role="alert">{{ $message }}</p> @enderror
        </div>

        <div>
            <label class="label" for="scope">Applies to</label>
            <select wire:model="scope" id="scope" class="select">
                <option value="">All apps</option>
                @foreach ($usableApps as $clientId => $appName)
                    <option value="{{ $clientId }}">{{ $appName }} only</option>
                @endforeach
            </select>
            <p class="mt-1 text-xs" style="color:var(--faint)">An app-scoped role is stamped only into that app's tokens.</p>
        </div>

        @if ($holdsEnvironment)
            <div>
                <label class="flex items-center gap-2" for="environmentWide">
                    <input wire:model.live="environmentWide" id="environmentWide" type="checkbox" class="rounded">
                    <span>Define it for every organization in this environment</span>
                </label>
                <p class="mt-1 text-xs" style="color:var(--faint)">An environment-wide role can be assigned inside any organization here and is not theirs to change.</p>
                @error('environmentWide') <p class="field-error" role="alert">{{ $message }}</p> @enderror
            </div>
        @endif

        @if (! $organizationChosen && ! $environmentWide)
            {{-- The distinction that matters: nothing is wrong with this administrator,
                 they simply have not said which organization the role is for. --}}
            <p class="text-sm" style="color:var(--warning-strong)" role="status">Choose an organization in the console header, or define the role for the whole environment.</p>
        @endif

        <div>
            <label class="label">Permissions <span style="color:var(--faint)">(optional)</span></label>
            <div class="space-y-1.5 rounded-lg border p-3 max-h-72 overflow-y-auto" style="border-color:var(--border)">
                @forelse ($catalog as $perm)
                    <label class="flex items-start gap-2 rounded-md px-2 py-1.5 cursor-pointer hover:bg-[var(--surface-2)]" wire:key="perm-{{ $perm->id }}">
                        <input type="checkbox" wire:model="permissions" value="{{ $perm->id }}" class="mt-0.5 rounded">
                        <span class="min-w-0 flex-1">
                            <span class="flex items-center gap-2 flex-wrap">
                                <span class="text-sm mono">{{ $perm->name }}</span>
                                @if ($perm->client_id)<span class="badge badge-info">{{ $usableApps[$perm->client_id] ?? 'App' }}</span>@else<span class="badge">All apps</span>@endif
                            </span>
                            @if ($perm->description)<span class="block text-xs" style="color:var(--faint)">{{ $perm->description }}</span>@endif
                        </span>
                    </label>
                @empty
                    <div class="cbx-empty">
                        <div class="cbx-empty-icon"><x-icon name="key" class="w-5 h-5" /></div>
                        <h3>No permissions declared</h3>
                        <p>An app registers its catalog over the SDK. @if ($permissionsUrl)You can also <a href="{{ $permissionsUrl }}" style="color:var(--accent-strong)">add permissions manually</a>. @endif You can create the role now and compose it once the keys arrive.</p>
                    </div>
                @endforelse
            </div>
        </div>

        <div class="flex items-center gap-2">
            <button type="submit" class="btn btn-primary" wire:loading.attr="disabled" wire:target="create">Create role</button>
            <a href="{{ route($scopeRoute('roles')) }}" class="btn btn-ghost">Cancel</a>
        </div>
    </form>
</div>
