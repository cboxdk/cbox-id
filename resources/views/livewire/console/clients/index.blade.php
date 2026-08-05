<?php

declare(strict_types=1);

use App\Platform\Console\ConsolePlane;
use App\Platform\Console\ConsoleScope;
use Cbox\Id\AccessControl\Models\Role;
use Cbox\Id\OAuthServer\Models\Client;
use Illuminate\Database\Eloquent\Builder;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Url;
use Livewire\Volt\Component;
use Livewire\WithPagination;

/**
 * Console › Apps & API keys (list). Every OAuth client registered here — the apps that
 * sign people in through Cbox ID and the machine credentials that call its API. Each row
 * deep-links to the app's own page, where the whole lifecycle lives: details, the roles
 * manifest, secret rotation and delete. Registration is its own page; the list only
 * lists.
 *
 * One component, both planes. The organization plane had a single page with an inline
 * create form, an inline manifest panel and delete; the environment plane had list →
 * create → detail with edit and secret rotation. The routable shape wins — an app has a
 * lifecycle worth linking to, and a freshly minted secret needs somewhere to land that is
 * not the form you just submitted — and every action from both pages comes with it.
 *
 * Clients are environment-owned (BelongsToEnvironment), so the query only ever sees this
 * environment's apps. Within it the acting organization is the boundary; see
 * actingOrganizationId() below for why that is not simply the nullable reader.
 */
new #[Layout('components.layouts.console', ['title' => 'Apps & API keys'])] class extends Component
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

    public function updatingSearch(): void
    {
        $this->resetPage();
    }

    /**
     * @return array<string, mixed>
     */
    public function with(): array
    {
        $organizationId = $this->actingOrganizationId();

        // Scoped to the acting organization when one is chosen. With none chosen — only
        // possible for an environment administrator — this is every app in the
        // environment, which is a deliberate overview rather than a leak: the model's
        // environment scope still bounds it, and an organization member can never reach
        // that branch because their organization is implicit.
        $query = Client::query()
            ->when($organizationId !== null, fn (Builder $q): Builder => $q->where('organization_id', $organizationId))
            ->orderByDesc('id');

        $term = trim($this->search);
        if ($term !== '') {
            $query->where(fn (Builder $q): Builder => $q->where('name', 'like', "%{$term}%")->orWhere('client_id', 'like', "%{$term}%"));
        }

        $clients = $query->paginate(25);

        // Apps the ENVIRONMENT owns rather than the organization being administered —
        // listed apart, because they are not this organization's to account for. A
        // first-party one (Billing, …) appears in every organization's launcher and skips
        // its consent screen, so a tenant admin has to see it or an app in their own
        // launcher looks missing here. (The view drops the section when the whole
        // environment is in view, where they are already in the list above.)
        //
        // `first_party` is what makes an environment-owned app a tenant's business, so
        // that is all the organization plane is shown: the environment's own back-office
        // clients are not theirs to enumerate. An environment administrator owns all of
        // them, and hiding the rest behind "clear your organization selection" is how an
        // app someone registered ten minutes ago becomes impossible to find.
        $platformApps = Client::query()
            ->whereNull('organization_id')
            ->when(
                app(ConsoleScope::class)->plane() === ConsolePlane::Organization,
                fn (Builder $q): Builder => $q->where('first_party', true),
            )
            ->orderBy('name')
            ->get();

        // How many roles each app currently declares.
        //
        // NB no orphaned_at filter on the CLIENT queries above: that column lives on
        // `roles`/`permissions`, which are tombstoned when an app's manifest stops
        // declaring them. `oauth_clients` has no such column — an app is deleted, not
        // orphaned — and filtering on it there was a query against a column that exists
        // on no engine.
        $roleCounts = Role::query()
            ->whereIn('client_id', $clients->getCollection()->pluck('client_id')->merge($platformApps->pluck('client_id')))
            ->whereNull('orphaned_at')
            ->get(['client_id'])
            ->groupBy('client_id')
            ->map(fn ($group): int => $group->count());

        return [
            // Route names differ per plane; one component, so it asks rather than assumes.
            'scopeRoute' => fn (string $name): string => app(ConsoleScope::class)->routeName($name),
            'clients' => $clients,
            'platformApps' => $platformApps,
            'roleCounts' => $roleCounts,
            // The scope's own list, not a bare Organization query: on the organization
            // plane that is the member's one organization, so naming an app's owner can
            // never enumerate the environment's other tenants.
            'orgNames' => app(ConsoleScope::class)->organizationNames($clients->pluck('organization_id')),
            // Shown only when the whole environment is in view, where who owns a row is
            // the one thing its name does not say.
            'showsEveryOrganization' => $organizationId === null,
            // The view half of the guard. A merge that rewires only the PHP renders a
            // read-only shell, so every write control on this page asks this first.
            'mayAdminister' => app(ConsoleScope::class)->mayAdminister(),
        ];
    }

    /**
     * The organization whose apps this page is about, or null for the whole-environment
     * overview.
     *
     * Null has to mean "an environment administrator has not chosen one yet" and never
     * "this member has no organization" — the nullable reader answers null for both, and
     * on the organization plane that second case would widen the list from one
     * organization to every app in the environment. So this plane asks the refusing
     * reader instead, which 403s rather than quietly listing everyone's apps.
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
    <x-page-header title="Apps & API keys" :help="\App\Platform\Help\HelpTopic::Apps"
                   subtitle="Every app that signs people in through Cbox ID, or calls its API, is registered here.">
        @if ($mayAdminister)
            <x-slot:actions>
                <a href="{{ route($scopeRoute('clients.create')) }}" class="btn btn-primary shrink-0"><x-icon name="plus" class="w-4 h-4" /> New app</a>
            </x-slot:actions>
        @endif
    </x-page-header>

    <div class="mt-6">
        <input wire:model.live.debounce.300ms="search" type="search" class="input" style="max-width:24rem" placeholder="Search by name or client ID" aria-label="Search apps">
        {{-- SC 4.1.3: the list re-renders on a debounced keystroke with no focus
             change, so the result count is the only thing that can report the filter
             narrowed to nothing. --}}
        <p role="status" aria-live="polite" class="sr-only">{{ $clients->total() }} {{ \Illuminate\Support\Str::plural('app', $clients->total()) }} found.</p>
    </div>

    <div class="mt-4 rounded-xl border overflow-hidden" style="border-color:var(--border)">
        @forelse ($clients as $client)
            <a href="{{ route($scopeRoute('clients.show'), $client->id) }}"
               class="flex items-center gap-3 p-4 transition-colors hover:bg-[var(--surface-2)] {{ ! $loop->last ? 'border-b' : '' }}" style="border-color:var(--border)">
                <div class="min-w-0 flex-1">
                    <span class="font-medium truncate">{{ $client->name }}</span>
                    <p class="text-sm truncate mono" style="color:var(--muted)">{{ $client->client_id }}</p>
                </div>
                @if ($showsEveryOrganization)
                    <span class="badge">{{ $client->organization_id !== null ? ($orgNames[$client->organization_id] ?? $client->organization_id) : 'All organizations' }}</span>
                @endif
                @if (($roleCounts[$client->client_id] ?? 0) > 0)
                    <span class="badge">{{ $roleCounts[$client->client_id] }} role(s)</span>
                @endif
                @if ($client->first_party)
                    <span class="text-xs rounded-full px-2 py-0.5" style="background:var(--accent-soft);color:var(--accent-strong)">First-party</span>
                @endif
                <div class="flex flex-wrap gap-1">
                    @if (in_array('authorization_code', $client->grant_types ?? [], true))<span class="badge">Sign-in</span>@endif
                    @if (in_array('client_credentials', $client->grant_types ?? [], true))<span class="badge">API</span>@endif
                </div>
                <span class="badge">{{ ucfirst($client->type->value) }}</span>
                <x-icon name="chevron" class="w-4 h-4 shrink-0" style="color:var(--faint)" />
            </a>
        @empty
            @if (trim($search) !== '')
                <div class="cbx-empty">
                    <div class="cbx-empty-icon"><x-icon name="clients" class="w-5 h-5" /></div>
                    <h3>No matching apps</h3>
                    <p>No app matches "{{ trim($search) }}". Try a different name or client ID.</p>
                </div>
            @else
                {{-- The organization console's empty state, kept: an empty page is the
                     most-read explanation in the console, and "No applications yet" tells
                     someone who has not read the guide nothing at all. --}}
                <x-empty-state icon="clients" title="No apps of your own yet"
                               :help="\App\Platform\Help\HelpTopic::Apps"
                               body="{{ 'An app registered here can sign your people in and read their roles, so you manage access in one place instead of per product.'.(! $showsEveryOrganization && $platformApps->isNotEmpty() ? ' The platform apps below belong to this environment rather than to you.' : '') }}"
                               :steps="[
                                   'Register the app — one per app, per environment.',
                                   'Copy its client ID and secret into the app’s configuration; the secret is shown once.',
                                   'Add the exact URL Cbox ID may send people back to after they sign in.',
                                   'Have the app declare the roles it understands, so you can assign them here.',
                               ]" />
            @endif
        @endforelse
    </div>

    <div class="mt-4">{{ $clients->links() }}</div>

    {{-- Apps the environment owns rather than the organization being administered: not
         registered here, but a first-party one is in this organization's launcher, so the
         page stays consistent with the dashboard rather than looking like it lost an app. --}}
    @if (! $showsEveryOrganization && $platformApps->isNotEmpty())
        <section class="mt-8">
            <h2 class="text-xs font-medium uppercase mb-3" style="color:var(--muted);letter-spacing:0.06em">Platform apps <span style="text-transform:none;font-weight:400">— owned by this environment, not by this organization. First-party ones appear in every organization's launcher.</span></h2>
            <div class="rounded-xl border overflow-hidden" style="border-color:var(--border)">
                @foreach ($platformApps as $client)
                    <a href="{{ route($scopeRoute('clients.show'), $client->id) }}"
                       class="flex items-center gap-3 p-4 transition-colors hover:bg-[var(--surface-2)] {{ ! $loop->last ? 'border-b' : '' }}" style="border-color:var(--border)">
                        <div class="min-w-0 flex-1">
                            <span class="font-medium truncate">{{ $client->name }}</span>
                            <p class="text-sm truncate mono" style="color:var(--muted)">{{ $client->client_id }}</p>
                        </div>
                        <span class="badge">Platform-owned</span>
                        @if (($roleCounts[$client->client_id] ?? 0) > 0)
                            <span class="badge">{{ $roleCounts[$client->client_id] }} role(s)</span>
                        @endif
                        <span class="badge">{{ ucfirst($client->type->value) }}</span>
                        <x-icon name="chevron" class="w-4 h-4 shrink-0" style="color:var(--faint)" />
                    </a>
                @endforeach
            </div>
        </section>
    @endif
</div>
