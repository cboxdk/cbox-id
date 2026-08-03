<?php

declare(strict_types=1);

use App\Platform\Console\ConsolePlane;
use App\Platform\Console\ConsoleScope;
use Cbox\Id\Webhooks\Models\WebhookEndpoint;
use Illuminate\Database\Eloquent\Builder;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Url;
use Livewire\Volt\Component;
use Livewire\WithPagination;

/**
 * Console › Webhooks (list). Every subscriber endpoint that receives a signed message
 * after something happens here. Each row deep-links to its own detail page, where the
 * full lifecycle — the subscription, pause/resume, secret rotation, deliveries and
 * delete — lives. Creation is a dedicated page; the list only lists.
 *
 * One component, both planes. The organization plane had a single page with an inline
 * form and a row-level Pause, and nothing else: a tenant administrator could create an
 * endpoint and pause it, and could never resume it, rotate its signing secret, change
 * which events it subscribed to, or delete it. The environment plane had the routable
 * list → create → detail with all of that. The routable shape wins — an endpoint URL is
 * something you send to whoever runs the receiving system, and the reveal-once signing
 * secret needs somewhere to land that is not the row you just submitted.
 *
 * Endpoints are environment-owned (BelongsToEnvironment), so the query only ever sees
 * this environment's endpoints; the acting organization narrows it further.
 */
new #[Layout('components.layouts.console', ['title' => 'Webhooks'])] class extends Component
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
        $actingOrganizationId = $this->actingOrganizationId();

        $query = WebhookEndpoint::query()
            // An endpoint with no organization is the ENVIRONMENT's own, and matching()
            // delivers every organization's events to it — so it is shipping this
            // tenant's data somewhere, and a tenant administrator has to be able to see
            // that it exists even though it is not theirs to change. Hiding it would mean
            // a subscriber to your members' sign-ins with nothing on screen saying so.
            //
            // With no organization chosen — only possible for an environment
            // administrator — this is every endpoint in the environment, which is a
            // deliberate overview rather than a leak: the model's environment scope still
            // bounds it, and an organization member can never reach that branch (see
            // actingOrganizationId()).
            ->when($actingOrganizationId !== null, fn (Builder $q): Builder => $q->where(
                fn (Builder $scoped): Builder => $scoped->whereNull('organization_id')->orWhere('organization_id', $actingOrganizationId)
            ))
            ->orderByDesc('id');

        $term = trim($this->search);
        if ($term !== '') {
            $query->where('url', 'like', "%{$term}%");
        }

        return [
            // Route names differ per plane; one component, so it asks rather than assumes.
            'scopeRoute' => fn (string $name): string => app(ConsoleScope::class)->routeName($name),
            // The view's own authorization question, asked through the scope so it gets
            // the same answer on both planes. It used to read CurrentUser::isAdmin(),
            // which is a question only the organization plane can answer — on the
            // environment plane that renders a read-only shell with no way to add an
            // endpoint at all.
            'mayAdminister' => $scope->mayAdminister(),
            // The scope's own list, not a bare Organization query: on the organization
            // plane that is the member's one organization, so naming an endpoint's owner
            // can never enumerate the environment's other tenants.
            'orgNames' => $scope->availableOrganizations(),
            'endpoints' => $query->paginate(25),
        ];
    }

    /**
     * The organization whose endpoints this page is about, or null for the
     * whole-environment overview.
     *
     * Null has to mean "an environment administrator has not chosen yet" and never "this
     * member has no organization" — the nullable reader answers null for both, and on the
     * organization plane that second case would widen the list from one organization to
     * every endpoint in the environment.
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
    <x-page-header title="Webhooks" :help="\App\Platform\Help\HelpTopic::Webhooks"
                   subtitle="Your endpoints get a signed message after something happens here, so your systems can react without asking us every minute.">
        @if ($mayAdminister)
            <x-slot:actions>
                <a href="{{ route($scopeRoute('webhooks.create')) }}" class="btn btn-primary shrink-0"><x-icon name="plus" class="w-4 h-4" /> Add endpoint</a>
            </x-slot:actions>
        @endif
    </x-page-header>

    <div class="mt-6">
        <input wire:model.live.debounce.300ms="search" type="search" class="input" style="max-width:24rem" placeholder="Search by URL" aria-label="Search webhook endpoints">
        {{-- SC 4.1.3: the list re-renders on a debounced keystroke with no focus
             change, so the result count is the only thing that can report the filter
             narrowed to nothing. --}}
        <p role="status" aria-live="polite" class="sr-only">{{ $endpoints->total() }} {{ \Illuminate\Support\Str::plural('endpoint', $endpoints->total()) }} found.</p>
    </div>

    <div class="mt-4 rounded-xl border overflow-hidden" style="border-color:var(--border)">
        @forelse ($endpoints as $endpoint)
            <a href="{{ route($scopeRoute('webhooks.show'), $endpoint->id) }}"
               class="flex items-center gap-3 p-4 transition-colors hover:bg-[var(--surface-2)] {{ ! $loop->last ? 'border-b' : '' }}" style="border-color:var(--border)">
                <div class="min-w-0 flex-1">
                    <span class="block font-medium truncate mono">{{ $endpoint->url }}</span>
                    <div class="mt-1 flex items-center gap-2 flex-wrap">
                        <span class="badge">{{ $endpoint->organization_id !== null ? ($orgNames[$endpoint->organization_id] ?? $endpoint->organization_id) : 'All organizations' }}</span>
                        <span class="text-xs" style="color:var(--faint)">{{ count($endpoint->event_types) }} {{ count($endpoint->event_types) === 1 ? 'event' : 'events' }} subscribed</span>
                    </div>
                </div>
                @if ($endpoint->status === \Cbox\Id\Webhooks\Enums\EndpointStatus::Active)
                    <span class="badge badge-success">Active</span>
                @else
                    <span class="badge badge-warn">Paused</span>
                @endif
                <x-icon name="chevron" class="w-4 h-4 shrink-0" style="color:var(--faint)" />
            </a>
        @empty
            @if (trim($search) !== '')
                <div class="cbx-empty">
                    <div class="cbx-empty-icon"><x-icon name="webhooks" class="w-5 h-5" /></div>
                    <h3>No webhooks match "{{ trim($search) }}"</h3>
                    <p>No endpoint URL matches that search. Try a different one.</p>
                </div>
            @else
                {{-- The organization console's empty state, kept: an empty page is the
                     most-read explanation in the console, and "No webhook endpoints yet"
                     tells someone who has not read the guide nothing at all. --}}
                <x-empty-state icon="webhooks" title="Nothing is being notified yet"
                               :help="\App\Platform\Help\HelpTopic::Webhooks"
                               body="Add an endpoint and your own systems hear about members joining, roles changing and sign-ins failing as it happens — no polling, no nightly export."
                               :steps="[
                                   'Add your HTTPS endpoint and pick the events it should receive.',
                                   'Store the signing secret shown once, and verify every delivery against it.',
                                   'Reply 2xx quickly and do the real work in the background — deliveries are retried, so handle repeats safely.',
                               ]" />
            @endif
        @endforelse
    </div>

    <div class="mt-4">{{ $endpoints->links() }}</div>
</div>
