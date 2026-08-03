<?php

declare(strict_types=1);

use App\Platform\Console\ConsoleScope;
use App\Platform\Entitlements;
use Cbox\Id\AccessControl\Contracts\GroupRoleMappings;
use Cbox\Id\AccessControl\Models\GroupRoleMapping;
use Cbox\Id\AccessControl\Models\Role;
use Cbox\Id\Directory\Enums\DirectoryProvider;
use Cbox\Id\Directory\Enums\DirectoryStatus;
use Cbox\Id\Directory\Models\Directory;
use Cbox\Id\Directory\Models\DirectoryGroup;
use Cbox\Id\OAuthServer\Models\Client;
use Cbox\Id\Organization\Models\Organization;
use Illuminate\Database\Eloquent\Builder;
use Livewire\Attributes\Layout;
use Livewire\Volt\Component;

/**
 * Console › Sync users in › detail. The full, deep-linkable lifecycle for one directory:
 * rename, the SCIM endpoint, rotate the bearer token, pause/enable, map directory groups
 * onto roles, and delete — plus, for a pull directory, when it last synced and why the
 * last attempt failed.
 *
 * One component, both planes, and this page is NEW on the organization plane, which is
 * exactly where a merge like this leaks. The environment plane resolved a directory on
 * its primary key alone and was safe doing so, because its administrator sits above
 * every organization in the environment. Served unchanged to a tenant administrator that
 * same lookup would hand them every OTHER tenant's directory by id — and here that is
 * not a disclosure, it is a takeover: `regenerateToken` mints a live SCIM bearer token
 * for a directory that provisions someone else's users. So every read and every write
 * re-resolves the directory through the acting organization and 404s otherwise.
 *
 * Secrets are never re-displayed. The bearer token is stored only as a SHA-256 hash and
 * shown exactly once (at registration or rotation); a pull directory's provider
 * credentials are sealed at rest and unsealed only by the sync, never by this page.
 */
new #[Layout('components.layouts.console', ['title' => 'Directory'])] class extends Component
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

    public string $directoryId = '';

    public string $editName = '';

    /**
     * The one-time SCIM bearer token — handed off from the create page's flash bag, or
     * freshly minted by a rotation on this page.
     *
     * Protected, so Livewire never dehydrates it into the wire:snapshot embedded in the
     * DOM: the plaintext lives only in the response that reveals it. Read in mount()
     * rather than in with(), because the flash is aged out at the end of this request —
     * read per-render it would vanish on the first unrelated action instead of when the
     * administrator says so.
     */
    protected ?string $newToken = null;

    public function mount(string $directory): void
    {
        $this->directoryId = $directory;

        // Resolved through the same visibility rule every later read uses, so an id this
        // scope may not see 404s here rather than at the first action.
        $this->editName = $this->directory()->name;

        $token = session('newToken');
        $this->newToken = is_string($token) && $token !== '' ? $token : null;
    }

    /**
     * The directory, re-resolved and re-scoped on every read and write.
     *
     * The organization scope is the fence between tenants; the model's own
     * BelongsToEnvironment scope is the fence between environments, and it is why a
     * chosen organization from another environment can never widen this. 404 rather than
     * 403, because the caller was not entitled to learn the directory exists.
     */
    private function directory(): Directory
    {
        $organizationId = app(ConsoleScope::class)->organizationId();

        $directory = Directory::query()
            ->whereKey($this->directoryId)
            ->when($organizationId !== null, fn (Builder $q): Builder => $q->where('organization_id', $organizationId))
            ->first();

        abort_if($directory === null, 404);

        return $directory;
    }

    /**
     * The directory, refused unless this administrator may CHANGE it.
     *
     * The entitlement is asked of the directory's OWN organization rather than of the
     * acting one. They are the same whenever an organization is chosen — the lookup
     * above guarantees it — and when none is (an environment administrator's
     * whole-environment overview) the directory's organization is the only honest answer;
     * asking the scope there would answer "not entitled" for every directory in the
     * environment. Enforced on both planes: the organization console has always
     * hard-refused a non-entitled tenant, and a merge that dropped it on the way to a
     * detail page would have made rotating a token the way around the gate.
     */
    private function changeable(): Directory
    {
        $directory = $this->directory();

        abort_unless($this->mayChange($directory), 403);

        return $directory;
    }

    private function mayChange(Directory $directory): bool
    {
        return app(ConsoleScope::class)->mayAdminister()
            && app(Entitlements::class)->entitled($directory->organization_id, 'scim');
    }

    public function saveName(): void
    {
        $directory = $this->changeable();

        $this->validate(['editName' => ['required', 'string', 'max:120']]);

        $directory->name = trim($this->editName);
        $directory->save();

        $this->dispatch('toast', message: 'Directory updated.');
    }

    /**
     * Rotate the SCIM bearer token: mint a fresh secret, store only its hash, and reveal
     * the plaintext once. Any token the customer's IdP already holds stops working
     * immediately. Only meaningful for a SCIM (push) directory — a pull directory
     * authenticates OUTWARD to the provider's API and has no inbound token.
     */
    public function regenerateToken(): void
    {
        $directory = $this->changeable();

        if ($directory->provider !== DirectoryProvider::Scim) {
            return;
        }

        $token = 'scim_'.bin2hex(random_bytes(32));
        $directory->bearer_token_hash = hash('sha256', $token);
        $directory->save();

        $this->newToken = $token;
        $this->dispatch('toast', message: 'Bearer token rotated — the previous token no longer works.');
    }

    /**
     * Clear the revealed bearer token.
     *
     * The banner shows a live credential in plaintext, and an administrator who has
     * copied it — or who is sharing a screen — needs it gone; leaving it up until the
     * next navigation is not an answer. By the time this runs the token is already off
     * the server, so making the banner go is the whole job.
     */
    public function dismissToken(): void
    {
        $this->newToken = null;
    }

    public function toggleStatus(): void
    {
        $directory = $this->changeable();

        $directory->status = $directory->status === DirectoryStatus::Active
            ? DirectoryStatus::Paused
            : DirectoryStatus::Active;
        $directory->save();

        $this->dispatch('toast', message: $directory->status === DirectoryStatus::Active
            ? 'Directory enabled — provisioning resumes.'
            : 'Directory paused — provisioning is suspended.');
    }

    public function deleteDirectory(): mixed
    {
        $this->changeable()->delete();

        $this->dispatch('toast', message: 'Directory deleted.');

        return $this->redirectRoute(app(ConsoleScope::class)->routeName('directories'), navigate: true);
    }

    /** Map a directory group onto a role — everyone in it gets the role as membership syncs. */
    public function mapGroup(string $groupId, string $roleId, GroupRoleMappings $mappings): void
    {
        if ($roleId === '') {
            return;
        }

        $directory = $this->changeable();

        $mappings->map($directory->organization_id, $this->group($directory, $groupId)->id, $roleId);
    }

    public function unmapGroup(string $groupId, string $roleId, GroupRoleMappings $mappings): void
    {
        $directory = $this->changeable();

        $mappings->unmap($directory->organization_id, $this->group($directory, $groupId)->id, $roleId);
    }

    /**
     * A group, resolved inside THIS directory.
     *
     * Both mapping actions take a group id straight off the page, so the id is resolved
     * within the directory the caller has already been authorized for rather than
     * trusted. The organization console scoped neither by directory, and the environment
     * console scoped only the map half — leaving an id from another directory able to be
     * unmapped through this page.
     */
    private function group(Directory $directory, string $groupId): DirectoryGroup
    {
        $group = DirectoryGroup::query()
            ->whereKey($groupId)
            ->where('directory_id', $directory->id)
            ->first();

        abort_if($group === null, 404);

        return $group;
    }

    /**
     * @return array<string, mixed>
     */
    public function with(): array
    {
        $directory = $this->directory();
        $organizationId = $directory->organization_id;

        $groups = DirectoryGroup::query()
            ->where('directory_id', $directory->id)
            ->orderBy('display_name')
            ->get();

        // Roles assignable to a group: org roles + app-declared roles for the org.
        $clientIds = Client::query()
            ->where(fn ($q) => $q->whereNull('organization_id')->orWhere('organization_id', $organizationId))
            ->pluck('client_id');
        $accessRoles = Role::query()
            ->where(function ($q) use ($organizationId, $clientIds): void {
                $q->where(fn ($x) => $x->where('organization_id', $organizationId)->whereNull('client_id'))
                    ->orWhere(fn ($x) => $x->whereIn('client_id', $clientIds)->whereNull('orphaned_at'));
            })
            ->orderBy('name')
            ->get();

        $mappingsByGroup = GroupRoleMapping::query()
            ->where('organization_id', $organizationId)
            ->get()
            ->groupBy('group_id')
            ->map(fn ($g) => $g->pluck('role_id')->all());

        return [
            // Route names differ per plane; one component, so it asks rather than assumes.
            'scopeRoute' => fn (string $name): string => app(ConsoleScope::class)->routeName($name),
            'directory' => $directory,
            'orgName' => Organization::query()->whereKey($organizationId)->value('name') ?? $organizationId,
            'scimBaseUrl' => url('/scim/v2'),
            'groups' => $groups,
            'accessRoles' => $accessRoles,
            'accessRolesById' => $accessRoles->keyBy('id'),
            'appNames' => Client::query()->whereIn('client_id', $accessRoles->pluck('client_id')->filter()->unique())->pluck('name', 'client_id'),
            'mappingsByGroup' => $mappingsByGroup,
            // Protected → never dehydrated; passed explicitly so the token renders once.
            'newToken' => $this->newToken,
            // The view's own authorization question, asked once so the buttons are not
            // offered and then refused. It used to read CurrentUser::isAdmin(), which is
            // a question only the organization plane can answer — on the environment
            // plane that renders a read-only shell with no way to change anything.
            'mayChange' => $this->mayChange($directory),
        ];
    }
}; ?>

<div class="space-y-6">
    <div>
        <a href="{{ route($scopeRoute('directories')) }}" class="text-sm inline-flex items-center gap-1" style="color:var(--muted)"><x-icon name="chevron" class="w-3.5 h-3.5 rotate-180" /> Sync users in</a>
        <div class="mt-2 flex items-center gap-3 flex-wrap">
            <h1 class="font-semibold tracking-tight" style="font-size:1.5rem">{{ $directory->name }}</h1>
            <span class="badge {{ $directory->status === \Cbox\Id\Directory\Enums\DirectoryStatus::Active ? 'badge-success' : 'badge-warn' }}">{{ ucfirst($directory->status->value) }}</span>
            <span class="cbx-pill cbx-pill--info">{{ $directory->provider->label() }}</span>
        </div>
        <p class="mt-1 text-sm mono" style="color:var(--faint)">{{ $directory->id }} · {{ $orgName }}</p>
    </div>

    {{-- One-time bearer token: revealed once on registration or rotation, never
         retrievable again. --}}
    @if ($newToken)
        <div class="rounded-xl border p-5" style="border-color:color-mix(in oklch, var(--warning) 45%, transparent);background:var(--warning-soft)">
            <div class="flex items-start justify-between gap-3">
                <div class="min-w-0">
                    <div class="flex items-center gap-2 font-semibold" style="color:var(--warning-strong)"><x-icon name="key" class="w-4 h-4" /> Bearer token for "{{ $directory->name }}"</div>
                    <p class="mt-1 text-sm" style="color:var(--warning-strong)">Copy this now — it is shown only once and cannot be retrieved again.</p>
                </div>
                <button type="button" wire:click="dismissToken" class="btn btn-ghost btn-sm shrink-0">Done</button>
            </div>
            <p class="mt-3 mono text-xs rounded-lg px-3 py-2 select-all break-all" style="background:var(--surface-2);border:1px solid var(--border)">{{ $newToken }}</p>
        </div>
    @endif

    @unless ($mayChange)
        <div class="rounded-xl border p-4 text-sm" style="border-color:var(--border);color:var(--muted)">
            Syncing users in is not enabled for this organization, so this directory is shown read-only. Contact your account team to enable it.
        </div>
    @endunless

    @if ($directory->provider === \Cbox\Id\Directory\Enums\DirectoryProvider::Scim)
        {{-- SCIM endpoint --}}
        <div class="rounded-xl border p-5" style="border-color:var(--border)">
            <h2 class="cbx-section-title">SCIM endpoint</h2>
            <p class="mt-1 text-xs" style="color:var(--faint)">Point the identity provider at this base URL and authenticate with this directory's bearer token.</p>
            <p class="mt-3 mono text-xs rounded-lg px-3 py-2 select-all break-all" style="background:var(--surface-2);border:1px solid var(--border)">{{ $scimBaseUrl }}</p>
        </div>
    @else
        {{-- Pull directories authenticate outward, so there is no endpoint to hand out —
             what an administrator needs here is whether the last fetch worked. --}}
        <div class="rounded-xl border p-5" style="border-color:var(--border)">
            <h2 class="cbx-section-title">Sync</h2>
            <p class="mt-1 text-xs" style="color:var(--faint)">We fetch the user set from {{ $directory->provider->label() }} on a schedule. Joiners are provisioned, leavers deprovisioned.</p>
            <dl class="mt-4 grid gap-4 sm:grid-cols-2">
                <div>
                    <dt class="label">Last synced</dt>
                    <dd class="mt-1 text-sm" style="color:var(--muted)">{{ $directory->last_synced_at?->diffForHumans() ?? 'Never' }}</dd>
                </div>
                <div>
                    <dt class="label">Last error</dt>
                    <dd class="mt-1 text-sm" style="color:var(--muted)">{{ $directory->last_sync_error ?? 'None' }}</dd>
                </div>
            </dl>
        </div>
    @endif

    {{-- Details --}}
    <div class="rounded-xl border p-5" style="border-color:var(--border)">
        <h2 class="cbx-section-title">Details</h2>
        @if ($mayChange)
            <form wire:submit="saveName" class="mt-4 grid sm:grid-cols-[1fr_auto] gap-2 items-start">
                <div>
                    <label class="label" for="editName">Directory name</label>
                    <input wire:model="editName" id="editName" type="text" class="input"
                           @error('editName') aria-invalid="true" aria-describedby="editName-error" @enderror>
                    @error('editName') <p id="editName-error" class="field-error" role="alert">{{ $message }}</p> @enderror
                </div>
                <button type="submit" class="btn btn-primary shrink-0 self-end" wire:loading.attr="disabled" wire:target="saveName">Save</button>
            </form>
        @else
            <dl class="mt-4">
                <dt class="label">Directory name</dt>
                <dd class="mt-1 text-sm" style="color:var(--muted)">{{ $directory->name }}</dd>
            </dl>
        @endif
    </div>

    {{-- Bearer token & status --}}
    @if ($mayChange)
        <div class="rounded-xl border p-5" style="border-color:var(--border)">
            <h2 class="cbx-section-title">Access</h2>
            <p class="mt-1 text-xs" style="color:var(--faint)">The bearer token is stored only as a hash. Rotating it reveals a new token once and immediately invalidates the old one.</p>
            <div class="mt-4 flex flex-wrap gap-2">
                @if ($directory->provider === \Cbox\Id\Directory\Enums\DirectoryProvider::Scim)
                    <x-confirm-delete
                        :name="$directory->name"
                        action="regenerateToken"
                        label="Rotate token"
                        verb="Rotate"
                        trigger-class="btn btn-ghost btn-sm"
                        consequence="The current SCIM bearer token stops working immediately and cannot be recovered — provisioning stops until the upstream identity provider is reconfigured with the new one.">
                        <x-icon name="refresh" class="w-4 h-4" /> Rotate token
                    </x-confirm-delete>
                @endif
                @if ($directory->status === \Cbox\Id\Directory\Enums\DirectoryStatus::Active)
                    <button type="button" class="btn btn-ghost btn-sm" wire:click="toggleStatus" wire:confirm="Pause this directory? Provisioning is suspended until re-enabled.">Pause</button>
                @else
                    <button type="button" class="btn btn-ghost btn-sm" wire:click="toggleStatus">Enable</button>
                @endif
            </div>
        </div>
    @endif

    {{-- Directory group → role mapping (the bridge from the provider's groups to ours) --}}
    @if ($groups->isNotEmpty())
        <div class="rounded-xl border p-5" style="border-color:var(--border)">
            <h2 class="cbx-section-title">Group → role mapping</h2>
            <p class="mt-1 text-xs" style="color:var(--faint)">Map a directory group onto a role — everyone in the group gets it automatically as membership syncs. A hand-assigned role is never affected.</p>
            <div class="mt-4 space-y-2">
                @foreach ($groups as $group)
                    <div class="flex items-start justify-between gap-4 flex-wrap rounded-lg border px-3 py-2" style="border-color:var(--border)" wire:key="group-{{ $group->id }}">
                        <div class="min-w-0">
                            <p class="font-medium text-sm">{{ $group->display_name }}</p>
                            <div class="flex flex-wrap gap-1 mt-1.5">
                                @forelse ($mappingsByGroup[$group->id] ?? [] as $rid)
                                    @php $role = $accessRolesById[$rid] ?? null; @endphp
                                    @if ($role)
                                        <span class="badge">{{ $role->name }}
                                            @if ($mayChange)
                                                @php $unmapAction = "unmapGroup('{$group->id}', '{$rid}')"; @endphp
                                                <x-confirm-delete
                                                    :name="$group->display_name"
                                                    :action="$unmapAction"
                                                    label="×"
                                                    :verb="'Unmap '.$role->name.' from'"
                                                    trigger-class=""
                                                    trigger-style="color:var(--destructive)"
                                                    title="Remove mapping"
                                                    aria-label="Remove {{ $role->name }} mapping"
                                                    :consequence="'Every member synced through this group loses the '.$role->name.' role. It is re-applied only if you map the group again.'" />
                                            @endif
                                        </span>
                                    @endif
                                @empty
                                    <span class="text-xs" style="color:var(--faint)">No roles mapped</span>
                                @endforelse
                            </div>
                        </div>
                        @if ($mayChange && $accessRoles->isNotEmpty())
                            <select class="select" style="max-width:15rem" aria-label="Map a role to the {{ $group->display_name }} group" wire:change="mapGroup('{{ $group->id }}', $event.target.value)">
                                <option value="">+ Map a role…</option>
                                @foreach ($accessRoles->groupBy(fn ($r) => $r->client_id ?? '__org') as $groupKey => $rolesInGroup)
                                    <optgroup label="{{ $groupKey === '__org' ? 'Org roles' : ($appNames[$groupKey] ?? $groupKey) }}">
                                        @foreach ($rolesInGroup as $role)
                                            <option value="{{ $role->id }}">{{ $role->name }}</option>
                                        @endforeach
                                    </optgroup>
                                @endforeach
                            </select>
                        @endif
                    </div>
                @endforeach
            </div>
        </div>
    @endif

    {{-- Lifecycle --}}
    @if ($mayChange)
        <div class="rounded-xl border p-5" style="border-color:var(--border)">
            <h2 class="cbx-section-title">Lifecycle</h2>
            <div class="mt-4 flex flex-wrap gap-2">
                <x-confirm-delete
                    :name="$directory->name"
                    action="deleteDirectory"
                    label="Delete directory"
                    consequence="The bearer token stops working immediately and provisioning ends. This cannot be undone." />
            </div>
        </div>
    @endif
</div>
