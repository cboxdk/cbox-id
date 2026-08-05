<?php

declare(strict_types=1);

use App\Platform\Console\ConsoleScope;
use Cbox\Id\ExternalActions\Enums\HookPoint;
use Cbox\Id\ExternalActions\Models\ExternalActionEndpoint;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Url;
use Livewire\Volt\Component;
use Livewire\WithPagination;

/**
 * Console › Inline hooks (list). The external hook registry — every customer HTTPS
 * endpoint the platform calls synchronously at a {@see HookPoint} to enrich or veto an
 * operation. Each row deep-links to its own detail page where the full lifecycle —
 * pause/activate, the one-time signing secret, remove — lives. Registration is a
 * dedicated page; the list only lists.
 *
 * One component, both planes. The capability was called "Inline hooks" on one and "Event
 * hooks" on the other, which put it one word away from Webhooks — a DIFFERENT capability
 * in the same nav area that runs after the fact and cannot hold anything up. These run
 * while someone waits at a sign-in screen, so they get the name that says so.
 *
 * Endpoints are environment-owned (BelongsToEnvironment), so the query only ever sees
 * this plane's endpoints.
 */
new #[Layout('components.layouts.console', ['title' => 'Inline hooks'])] class extends Component
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
        $scope = app(ConsoleScope::class);
        $actingOrganizationId = $scope->organizationId();

        $query = ExternalActionEndpoint::query()
            // An endpoint with no organization is the ENVIRONMENT's own and fires for
            // every organization in it, so it is part of what an administrator of one
            // organization is looking at even though they cannot manage it — the tenant
            // console has always listed those beside its own, and hiding them would mean
            // a hook that can refuse your sign-ins with nothing on screen saying so.
            //
            // With no organization chosen — only possible for an environment
            // administrator — this is every endpoint in the environment, which is a
            // deliberate overview rather than a leak: the model's environment scope still
            // bounds it, and an organization member can never reach this branch because
            // their organization is implicit.
            ->when($actingOrganizationId !== null, fn ($q) => $q->where(
                fn ($scoped) => $scoped->whereNull('organization_id')->orWhere('organization_id', $actingOrganizationId)
            ))
            ->orderByDesc('id');

        $term = trim($this->search);
        if ($term !== '') {
            $query->where('url', 'like', "%{$term}%");
        }

        $rows = $query->paginate(25);

        return [
            // Route names differ per plane; one component, so it asks rather than assumes.
            'scopeRoute' => fn (string $name): string => app(ConsoleScope::class)->routeName($name),
            // The scope's own list, not a bare Organization query: on the organization
            // plane that is the member's one organization, so naming an endpoint's owner
            // can never enumerate the environment's other tenants.
            'orgNames' => $scope->organizationNames($rows->pluck('organization_id')),
            'rows' => $rows,
        ];
    }
}; ?>

<div>
    <x-page-header title="Inline hooks" :help="\App\Platform\Help\HelpTopic::InlineHooks"
                   subtitle="Your endpoint is called in the middle of an operation and its answer changes the outcome — add data to a token, or refuse the sign-in.">
        <x-slot:actions>
            <a href="{{ route($scopeRoute('hooks.create')) }}" class="btn btn-primary shrink-0"><x-icon name="plus" class="w-4 h-4" /> Register endpoint</a>
        </x-slot:actions>
    </x-page-header>

    <div class="mt-6">
        <input wire:model.live.debounce.300ms="search" type="search" class="input" style="max-width:24rem" placeholder="Search by URL" aria-label="Search inline hooks">
        {{-- SC 4.1.3: the list re-renders on a debounced keystroke with no focus
             change, so the result count is the only thing that can report the filter
             narrowed to nothing. --}}
        <p role="status" aria-live="polite" class="sr-only">{{ $rows->total() }} {{ \Illuminate\Support\Str::plural('hook', $rows->total()) }} found.</p>
    </div>

    <div class="mt-4 rounded-xl border overflow-hidden" style="border-color:var(--border)">
        @forelse ($rows as $endpoint)
            <a href="{{ route($scopeRoute('hooks.show'), $endpoint->id) }}"
               class="flex items-center gap-3 p-4 transition-colors hover:bg-[var(--surface-2)] {{ ! $loop->last ? 'border-b' : '' }}" style="border-color:var(--border)">
                <div class="min-w-0 flex-1">
                    <div class="flex items-center gap-2 flex-wrap">
                        <span class="badge" title="{{ $endpoint->hook_point->description() }}">{{ $endpoint->hook_point->label() }}</span>
                        <span class="badge">{{ $endpoint->organization_id !== null ? ($orgNames[$endpoint->organization_id] ?? $endpoint->organization_id) : 'All organizations' }}</span>
                    </div>
                    <p class="mt-1 text-xs truncate mono" style="color:var(--faint)">{{ $endpoint->url }}</p>
                </div>
                @if ($endpoint->status === \Cbox\Id\ExternalActions\Enums\ActionEndpointStatus::Active)
                    <span class="badge badge-success">Active</span>
                @else
                    <span class="badge badge-warn">Paused</span>
                @endif
                <x-icon name="chevron" class="w-4 h-4 shrink-0" style="color:var(--faint)" />
            </a>
        @empty
            @if (trim($search) !== '')
                <div class="cbx-empty">
                    <div class="cbx-empty-icon"><x-icon name="search" class="w-5 h-5" /></div>
                    <h3>No hooks match "{{ trim($search) }}"</h3>
                    <p>No endpoint URL matches that search. Try a different one.</p>
                </div>
            @else
                {{-- The organization console's empty state, kept: an empty page is the
                     most-read explanation in the console, and "No endpoints yet" tells
                     someone who has not read the guide nothing at all. --}}
                <x-empty-state icon="webhooks" title="No inline hooks registered"
                               :help="\App\Platform\Help\HelpTopic::InlineHooks"
                               body="Most integrations want Webhooks instead — those run after the fact and cannot hold anything up. Reach for an inline hook only when your own system must have a say while a sign-in or token is being issued."
                               :steps="[
                                   'Register the endpoint and choose the hook point it answers at.',
                                   'Verify the signature on every call, and answer within the timeout — this runs while someone waits at the sign-in screen.',
                                   'Fail open or closed deliberately: decide now what should happen when your endpoint is down.',
                               ]" />
            @endif
        @endforelse
    </div>

    <div class="mt-4">{{ $rows->links() }}</div>
</div>
