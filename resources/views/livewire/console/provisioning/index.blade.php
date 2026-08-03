<?php

declare(strict_types=1);

use App\Platform\Console\ConsoleScope;
use Cbox\Id\Provisioning\Models\ProvisioningConnection;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Url;
use Livewire\Volt\Component;
use Livewire\WithPagination;

/**
 * Console › Sync users out (list). The registry of downstream SCIM targets users are
 * provisioned OUT to. Each row deep-links to its own routable detail page where the
 * full lifecycle lives; registration is a dedicated page. No inline forms — the list
 * only lists.
 *
 * One component, both planes. The organization plane had a single page with an inline
 * register form and pause; the environment plane had the routable list → create →
 * detail shape with pause, resume and delete. The routable shape wins — a connection
 * URL is something you send to whoever runs the downstream app — and the organization
 * plane gains resume and delete, which it never had.
 *
 * Connections are environment-owned (BelongsToEnvironment), so every read is fenced
 * to this environment by the hard scope — an id from another plane never matches.
 */
new #[Layout('components.layouts.console', ['title' => 'Sync users out'])] class extends Component
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
        // Scoped to the acting organization when one is chosen. With none chosen — only
        // possible for an environment administrator — this is every connection in the
        // environment, which is a deliberate cross-tenant overview rather than a leak:
        // the model's environment scope still bounds it, and an organization member can
        // never reach this branch because their organization is implicit.
        //
        // It is also the only place an environment-wide connection (organization_id
        // null) is listed. That is deliberate: environment-wide coverage is a platform
        // capability, so a tenant administrator neither sees one here nor can reach its
        // detail page to pause or delete it.
        $actingOrganizationId = app(ConsoleScope::class)->organizationId();

        $query = ProvisioningConnection::query()
            ->when($actingOrganizationId !== null, fn ($q) => $q->where('organization_id', $actingOrganizationId))
            ->orderByDesc('id');

        $term = trim($this->search);
        if ($term !== '') {
            $query->where('name', 'like', "%{$term}%");
        }

        return [
            // Route names differ per plane; one component, so it asks rather than assumes.
            'scopeRoute' => fn (string $name): string => app(ConsoleScope::class)->routeName($name),
            // The view's own authorization question, asked through the scope so it gets
            // the same answer on both planes. It used to read CurrentUser::isAdmin(),
            // which is a question only the organization plane can answer — on the
            // environment plane that renders a read-only shell with no way to add
            // anything.
            'mayAdminister' => app(ConsoleScope::class)->mayAdminister(),
            'connections' => $query->paginate(25),
        ];
    }
}; ?>

<div>
    <x-page-header title="Sync users out" :help="\App\Platform\Help\HelpTopic::SyncUsersOut"
                   subtitle="Push your people into the other SaaS products your company uses, so onboarding and offboarding happen once here instead of once per vendor.">
        @if ($mayAdminister)
            <x-slot:actions>
                <a href="{{ route($scopeRoute('provisioning.create')) }}" class="btn btn-primary shrink-0"><x-icon name="plus" class="w-4 h-4" /> New connection</a>
            </x-slot:actions>
        @endif
    </x-page-header>

    <div class="mt-6">
        <input wire:model.live.debounce.300ms="search" type="search" class="input" style="max-width:24rem" placeholder="Search by name" aria-label="Search connections">
        {{-- SC 4.1.3: the list re-renders on a debounced keystroke with no focus
             change, so the result count is the only thing that can report the filter
             narrowed to nothing. --}}
        <p role="status" aria-live="polite" class="sr-only">{{ $connections->total() }} {{ \Illuminate\Support\Str::plural('connection', $connections->total()) }} found.</p>
    </div>

    <div class="mt-4 rounded-xl border overflow-hidden" style="border-color:var(--border)">
        @forelse ($connections as $connection)
            <a href="{{ route($scopeRoute('provisioning.show'), $connection->id) }}"
               class="flex items-center gap-3 p-4 transition-colors hover:bg-[var(--surface-2)] {{ ! $loop->last ? 'border-b' : '' }}" style="border-color:var(--border)">
                <div class="min-w-0 flex-1">
                    <span class="font-medium truncate">{{ $connection->name }}</span>
                    <p class="text-xs truncate mono" style="color:var(--faint)">{{ $connection->base_url }}</p>
                </div>
                <span class="badge">{{ $connection->organization_id === null ? 'Environment-wide' : 'Org-scoped' }}</span>
                @if ($connection->status === \Cbox\Id\Provisioning\Enums\ConnectionStatus::Active)
                    <span class="badge badge-success">Active</span>
                @else
                    <span class="badge badge-warn">Paused</span>
                @endif
                <x-icon name="chevron" class="w-4 h-4 shrink-0" style="color:var(--faint)" />
            </a>
        @empty
            @if (trim($search) !== '')
                <div class="cbx-empty">
                    <div class="cbx-empty-icon"><x-icon name="layers" class="w-5 h-5" /></div>
                    <h3>No matches for "{{ trim($search) }}"</h3>
                    <p>No connections match that name. Try a different search term.</p>
                </div>
            @else
                {{-- The organization plane's empty state, kept: it explains what outbound
                     sync is FOR to someone who has never registered one, which the
                     environment plane's two-line version did not. --}}
                <x-empty-state icon="connections" title="No apps are being kept in sync yet"
                               :help="\App\Platform\Help\HelpTopic::SyncUsersOut"
                               body="Each app you connect here is created, updated and deactivated for you — so a leaver loses their seat everywhere, not just here."
                               :steps="[
                                   'Find the SCIM endpoint in the downstream app’s admin settings, and generate a token there.',
                                   'Register the connection with that URL and token.',
                                   'Watch the first push in the activity log, then leave it to run.',
                               ]" />
            @endif
        @endforelse
    </div>

    <div class="mt-4">{{ $connections->links() }}</div>
</div>
