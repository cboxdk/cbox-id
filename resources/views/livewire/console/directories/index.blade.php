<?php

declare(strict_types=1);

use App\Platform\AdminPortal;
use App\Platform\Console\ConsolePlane;
use App\Platform\Console\ConsoleScope;
use App\Platform\Enums\PortalScope;
use Cbox\Id\Directory\Models\Directory;
use Cbox\Id\Organization\Models\Organization;
use Illuminate\Database\Eloquent\Builder;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Url;
use Livewire\Volt\Component;
use Livewire\WithPagination;

/**
 * Console › Sync users in (list). Every directory connection that feeds people INTO the
 * platform — a SCIM (push) endpoint the customer's identity provider posts to, or an
 * API-pull connector we fetch from on a schedule. Each row deep-links to its own detail
 * page, where the token, the status and the group→role mappings live; connecting one is
 * a dedicated page. The list only lists.
 *
 * One component, both planes — and this pair had the worst drift in the console. The
 * organization plane offered Google Workspace and Microsoft Entra as pull directories;
 * the environment plane offered SCIM and nothing else, so an administrator who holds
 * every organization in the environment could not connect either of the two providers
 * the product ships connectors for. Both are offered on both planes now.
 *
 * Directories are environment-owned (BelongsToEnvironment), so every read here is
 * already fenced to this environment; the organization scoping below is the second
 * fence, between the tenants inside it.
 */
new #[Layout('components.layouts.console', ['title' => 'Sync users in'])] class extends Component
{
    use WithPagination;

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

    #[Url(as: 'q')]
    public string $search = '';

    /**
     * The single-use Admin Portal URL, held only for the render that reveals it.
     *
     * PROTECTED, not public. Livewire serialises public properties into the wire
     * snapshot embedded in the DOM and echoes them back in the body of every subsequent
     * /livewire/update request — so this link, which admits its holder to the tenant's
     * SCIM setup with no account at all, would sit in the page for any browser extension
     * to read and be re-transmitted into request-body logs on every round trip.
     */
    protected ?string $portalUrl = null;

    public function updatingSearch(): void
    {
        $this->resetPage();
    }

    /**
     * Mint a single-use Admin Portal link and reveal its URL once, so an administrator
     * can hand SCIM setup to an external IT admin without granting them an account.
     *
     * The organization console had this and the environment console did not — the plane
     * whose administrator is most likely to be the one setting a tenant up in the first
     * place.
     */
    public function invite(AdminPortal $portal): void
    {
        $scope = app(ConsoleScope::class);
        $scope->assertMayAdminister();

        // Resolved before the entitlement is asked, so an environment administrator who
        // has simply not picked an organization is refused for the reason that is true
        // rather than told their plan does not cover this.
        $organizationId = $scope->requireOrganizationId();
        abort_unless($scope->entitled('scim'), 403);

        $this->portalUrl = route('portal.enter', $portal->generate(
            $organizationId,
            PortalScope::Scim,
            // WHO minted it, asked through the scope: the two consoles recorded ids from
            // two different tables here.
            $scope->actorId(),
        ));
    }

    /**
     * Take the setup link off the screen.
     *
     * An explicit action rather than the organization console's `$set('portalUrl', null)`:
     * the property is protected precisely so Livewire cannot reach it, and a magic set
     * against a property Livewire cannot see is not a dismissal, it is a no-op wearing
     * the costume of one.
     */
    public function dismissPortalLink(): void
    {
        $this->portalUrl = null;
    }

    /**
     * @return array<string, mixed>
     */
    public function with(): array
    {
        $scope = app(ConsoleScope::class);
        $organizationId = $this->actingOrganizationId();

        // Scoped to the acting organization when one is chosen. With none chosen — only
        // possible for an environment administrator — this is every directory in the
        // environment, which is a deliberate overview rather than a leak: the model's
        // environment scope still bounds it, and an organization member can never reach
        // this branch because their organization is implicit.
        $query = Directory::query()
            ->when($organizationId !== null, fn (Builder $q): Builder => $q->where('organization_id', $organizationId))
            ->orderByDesc('created_at');

        $term = trim($this->search);
        if ($term !== '') {
            $query->where('name', 'like', "%{$term}%");
        }

        $directories = $query->paginate(25);

        return [
            // Route names differ per plane; one component, so it asks rather than assumes.
            'scopeRoute' => fn (string $name): string => app(ConsoleScope::class)->routeName($name),
            // The view's own authorization question, asked through the scope so it gets
            // the same answer on both planes. It used to read CurrentUser::isAdmin(),
            // which is a question only the organization plane can answer — on the
            // environment plane that renders a read-only shell with no way to connect
            // anything.
            'mayAdminister' => $scope->mayAdminister(),
            // Told apart deliberately. `entitled()` answers false for "no organization
            // chosen" too, and showing an environment administrator who has not picked a
            // tenant an upsell telling them to contact their account team sends them
            // somewhere useless about a decision they have not made yet.
            'organizationChosen' => $organizationId !== null,
            'entitled' => $organizationId !== null && $scope->entitled('scim'),
            'directories' => $directories,
            // Only the organizations this PAGE actually names. Plucking the whole
            // environment's organizations to label at most 25 rows pulled a table that
            // grows with the tenant count into memory on every render and every
            // Livewire action.
            'orgNames' => Organization::query()
                ->whereIn('id', $directories->pluck('organization_id')->filter()->unique())
                ->pluck('name', 'id'),
            'scimBaseUrl' => url('/scim/v2'),
            // Protected → never dehydrated; passed explicitly so the link renders once.
            'portalUrl' => $this->portalUrl,
        ];
    }

    /**
     * The organization whose directories this page is about, or null for the
     * whole-environment overview.
     *
     * Null has to mean "an environment administrator has not chosen yet" and never "this
     * member has no organization" — the nullable reader answers null for both, and on
     * the organization plane that second case would widen the list from one organization
     * to every organization in the environment.
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
    <x-page-header title="Sync users in" :help="\App\Platform\Help\HelpTopic::SyncUsersIn"
                   subtitle="Let your identity provider create, update and deactivate people here on its own — and map its groups onto your roles.">
        @if ($mayAdminister && $entitled)
            <x-slot:actions>
                <button type="button" wire:click="invite" class="btn btn-ghost shrink-0"><x-icon name="members" class="w-4 h-4" /> Invite your IT admin</button>
                <a href="{{ route($scopeRoute('directories.create')) }}" class="btn btn-primary shrink-0"><x-icon name="plus" class="w-4 h-4" /> New directory</a>
            </x-slot:actions>
        @endif
    </x-page-header>

    @if ($organizationChosen && ! $entitled)
        <div class="card mt-8">
            <x-empty-state icon="directory" title="Syncing users in is an Enterprise feature"
                           :help="\App\Platform\Help\HelpTopic::SyncUsersIn"
                           body="Having your identity provider create and deactivate people here automatically — over SCIM 2.0, or by connecting Google Workspace or Microsoft Entra directly — is available on the Enterprise plan. Contact your account team to enable it for this organization." />
        </div>
    @else
        @unless ($organizationChosen)
            {{-- Not the upsell. This administrator holds every organization in the
                 environment; they have simply not said which one they are acting on, and
                 the answer is the picker in the bar above rather than their account team. --}}
            <div class="mt-6 rounded-xl border p-4 text-sm" style="border-color:var(--border);color:var(--muted)">
                Every directory in this environment is listed below. Choose an organization in the bar above to connect a new one.
            </div>
        @endunless

        {{-- The SCIM base URL the customer's IdP posts to; each directory authenticates
             it with its own bearer token. --}}
        <div class="mt-6 rounded-xl border p-5" style="border-color:var(--border)">
            <div class="flex items-center gap-2 text-sm font-medium"><x-icon name="directory" class="w-4 h-4" /> SCIM endpoint</div>
            <p class="mt-1 text-xs" style="color:var(--faint)">Base URL for Okta, Microsoft Entra and other SCIM 2.0 clients. Google Workspace has no SCIM support — connect it as a pull directory instead.</p>
            <p class="mt-3 mono text-xs rounded-lg px-3 py-2 select-all break-all" style="background:var(--surface-2);border:1px solid var(--border)">{{ $scimBaseUrl }}</p>
        </div>

        @if ($portalUrl)
            <div class="mt-6 rounded-xl border p-5" style="border-color:color-mix(in oklch, var(--accent) 40%, transparent)">
                <div class="flex items-start justify-between gap-3">
                    <div class="min-w-0">
                        <div class="flex items-center gap-2 font-semibold"><x-icon name="members" class="w-4 h-4" /> Setup link for your IT admin</div>
                        <p class="mt-1 text-sm" style="color:var(--muted)">Send this single-use link to whoever configures your identity provider. It expires soon and works without an account. Copy it now — it is shown only once.</p>
                    </div>
                    <button type="button" wire:click="dismissPortalLink" class="btn btn-ghost btn-sm shrink-0">Done</button>
                </div>
                <p class="mt-3 mono text-xs rounded-lg px-3 py-2 select-all break-all" style="background:var(--surface-2);border:1px solid var(--border)">{{ $portalUrl }}</p>
            </div>
        @endif

        <div class="mt-6">
            <input wire:model.live.debounce.300ms="search" type="search" class="input" style="max-width:24rem" placeholder="Search by name" aria-label="Search directories">
            {{-- SC 4.1.3: the list re-renders on a debounced keystroke with no focus
                 change, so the result count is the only thing that can report the filter
                 narrowed to nothing. --}}
            <p role="status" aria-live="polite" class="sr-only">{{ $directories->total() }} {{ \Illuminate\Support\Str::plural('directory', $directories->total()) }} found.</p>
        </div>

        <div class="mt-4 rounded-xl border overflow-hidden" style="border-color:var(--border)">
            @forelse ($directories as $directory)
                <a href="{{ route($scopeRoute('directories.show'), $directory->id) }}"
                   class="flex items-center gap-3 p-4 transition-colors hover:bg-[var(--surface-2)] {{ ! $loop->last ? 'border-b' : '' }}" style="border-color:var(--border)">
                    <div class="min-w-0 flex-1">
                        <span class="font-medium truncate">{{ $directory->name }}</span>
                        <p class="text-xs truncate" style="color:var(--faint)">{{ $directory->provider->label() }} · {{ $orgNames[$directory->organization_id] ?? $directory->organization_id }}</p>
                    </div>
                    @if ($directory->last_sync_error !== null)
                        <span class="badge badge-warn" title="{{ $directory->last_sync_error }}">Sync failing</span>
                    @endif
                    <span class="badge {{ $directory->status === \Cbox\Id\Directory\Enums\DirectoryStatus::Active ? 'badge-success' : 'badge-warn' }}">{{ ucfirst($directory->status->value) }}</span>
                    <x-icon name="chevron" class="w-4 h-4 shrink-0" style="color:var(--faint)" />
                </a>
            @empty
                @if (trim($search) !== '')
                    <div class="cbx-empty">
                        <div class="cbx-empty-icon"><x-icon name="directory" class="w-5 h-5" /></div>
                        <h3>No matches for "{{ trim($search) }}"</h3>
                        <p>No directories match that name. Try a different search term.</p>
                    </div>
                @else
                    {{-- The organization console's empty state, kept: an empty page is the
                         most-read explanation in the console, and "No directories connected
                         yet" tells someone who has not read the guide nothing at all. --}}
                    <x-empty-state icon="directory" title="No directory connected yet"
                                   :help="\App\Platform\Help\HelpTopic::SyncUsersIn"
                                   body="Until one is connected, every joiner and leaver is a manual change here. A directory closes that gap: someone deactivated in your provider loses access to every connected app within seconds."
                                   :steps="[
                                       'Connect Google Workspace or Microsoft Entra directly — or register a SCIM directory to get an endpoint and token for any other provider.',
                                       'Point your provider at that endpoint and assign the people who should have access.',
                                       'Map the groups it sends onto your roles, so access follows the group.',
                                   ]" />
                @endif
            @endforelse
        </div>

        <div class="mt-4">{{ $directories->links() }}</div>
    @endif
</div>
