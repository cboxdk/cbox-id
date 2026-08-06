<?php

declare(strict_types=1);

use App\Platform\Console\ConsoleScope;
use Cbox\Id\RiskPlus\Models\RiskEvent;
use Cbox\Id\RiskPlus\Queries\OrganizationRiskEvents;
use Illuminate\Database\Eloquent\Collection;
use Livewire\Attributes\Layout;
use Livewire\Volt\Component;

/**
 * Console › Risk events — one component, both planes. Every flagged sign-in, newest
 * first.
 *
 * Risk events are recorded per ENVIRONMENT and carry an email rather than an
 * organization, so the two planes see genuinely different things here and both are
 * right: an environment administrator sees the whole feed, which is what a feed of
 * credential stuffing against the environment IS; an organization admin sees only the
 * events whose address belongs to one of their own members.
 *
 * Class API rather than Volt's functional one so the authorization guard can live in a
 * real boot() — the functional `boot()` helper compiles to a void method that returns,
 * which is a fatal error. The page's siblings in the other modules are all class API.
 */
new #[Layout('components.layouts.console', ['title' => 'Risk events'])] class extends Component
{
    /**
     * Route middleware does not gate this page by ROLE: the routes carry a session gate
     * (`platform.auth` on one plane, `env.admin` on the other) and `console.feature`, and
     * neither is a role check. The nav hides the area from a plain member, which is
     * styling, not authorization — the URL is typeable, and this page lists flagged
     * sign-ins with the address in the clear. boot(), not mount(), so it re-runs on every
     * Livewire message rather than only the first render.
     */
    public function boot(): void
    {
        app(ConsoleScope::class)->assertMayAdminister();
    }

    /** @return array{events: Collection<int, RiskEvent>, wholeEnvironment: bool} */
    public function with(): array
    {
        return [
            'events' => $this->events(),
            'wholeEnvironment' => app(ConsoleScope::class)->organizationId() === null,
        ];
    }

    /** @return Collection<int, RiskEvent> */
    private function events(): Collection
    {
        // Confined to addresses belonging to the acting organization's members, through
        // {@see OrganizationRiskEvents} — which is where the reasoning for matching on
        // the address, and for fetching the membership ids through the contract rather
        // than as a subquery, is written down.
        //
        // The subquery is the part that mattered. `Membership` is `TenantOwned` and its
        // scope denies by default with no tenant in context, which nothing in this
        // console ever sets — so the filter matched nothing, the page said "No elevated
        // risk events yet" to an organization under credential stuffing, and it did that
        // silently. The devices module had already measured this exact trap and routed
        // around it; this page copied the intent and not the mechanism.
        //
        // Null is reachable only from the environment plane — ConsoleScope refuses rather
        // than answering null on the other one — and there it means the environment's own
        // whole feed, still bounded by RiskEvent's environment scope.
        $organizationId = app(ConsoleScope::class)->organizationId();

        $query = $organizationId === null
            ? RiskEvent::query()
            : app(OrganizationRiskEvents::class)->query($organizationId);

        return $query
            ->latest('created_at')
            ->limit(50)
            ->get();
    }
}; ?>

<div class="space-y-6">
    <x-page-header title="Risk events"
                   subtitle="Sign-ins and requests the platform scored as suspicious enough to flag{{ $wholeEnvironment ? ', across this environment' : ', for this organization\'s members' }}. Newest first." />

    <div class="card" style="overflow-x:auto">
        <table class="table">
            <thead>
                <tr>
                    <th class="px-4 py-3 font-medium">When</th>
                    <th class="px-4 py-3 font-medium">Action</th>
                    <th class="px-4 py-3 font-medium">Outcome</th>
                    <th class="px-4 py-3 font-medium text-right tabular-nums">Score</th>
                    <th class="px-4 py-3 font-medium">Reasons</th>
                </tr>
            </thead>
            <tbody>
                @forelse ($events as $event)
                    <tr>
                        <td class="whitespace-nowrap px-4 py-3 mono" style="color:var(--muted)">
                            {{ $event->created_at?->diffForHumans() }}
                        </td>
                        <td class="px-4 py-3 font-medium" style="color:var(--foreground)">{{ $event->action }}</td>
                        <td class="px-4 py-3">
                            <span class="badge badge-warn">
                                {{ str_replace('_', ' ', $event->outcome) }}
                            </span>
                        </td>
                        <td class="px-4 py-3 text-right tabular-nums mono" style="color:var(--foreground)">{{ (int) round($event->score) }}</td>
                        <td class="px-4 py-3" style="color:var(--muted)">{{ implode('; ', $event->reasons) }}</td>
                    </tr>
                @empty
                    <tr>
                        <td colspan="5" class="px-4 py-10 text-center" style="color:var(--faint)">No elevated risk events yet.</td>
                    </tr>
                @endforelse
            </tbody>
        </table>
    </div>
</div>
