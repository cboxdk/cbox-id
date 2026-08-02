<?php

declare(strict_types=1);

use App\Platform\CurrentUser;
use Cbox\Id\Identity\Models\User;
use Cbox\Id\Organization\Models\Membership;
use Cbox\Id\RiskPlus\Models\RiskEvent;
use Illuminate\Database\Eloquent\Collection;
use Livewire\Attributes\Layout;
use Livewire\Volt\Component;

/**
 * Every flagged sign-in in this environment, newest first.
 *
 * Class API rather than Volt's functional one so the authorization guard can live in a
 * real boot() — the functional `boot()` helper compiles to a void method that returns,
 * which is a fatal error. The page's siblings in the other modules are all class API.
 */
new #[Layout('components.layouts.app', ['title' => 'Risk events'])] class extends Component
{
    /**
     * Route middleware does not gate this page: the module routes carry `platform.auth`
     * (a session exists) and `console.feature` (the flag is on), and neither is a role
     * check. The nav hides the area from a plain member, which is styling, not
     * authorization — the URL is typeable, and this page lists every flagged sign-in in
     * the environment with the address in the clear. boot(), not mount(), so it re-runs
     * on every Livewire message rather than only the first render.
     */
    public function boot(): void
    {
        abort_unless(app(CurrentUser::class)->isAdmin(), 403);
    }

    /** @return array<string, mixed> */
    public function with(): array
    {
        return ['events' => $this->events()];
    }

    /** @return Collection<int, RiskEvent> */
    private function events(): Collection
    {
        // Confined to addresses belonging to this organization's members.
        //
        // `RiskEvent` carries no organization — only an email — so an unqualified read on
        // an ORGANIZATION-gated page showed an admin of one tenant every flagged sign-in
        // in the environment, with the address in the clear. That is a live feed of when
        // another tenant is under credential stuffing, and of who their users are.
        //
        // Matching on the email is the narrowest thing available without a schema change.
        // It means an event for an address that belongs to nobody here is not shown at
        // all, which is the correct direction: an org admin has no standing to see a
        // stranger's failed sign-in.
        $organizationId = app(CurrentUser::class)->organization()?->id;

        abort_if($organizationId === null, 403);

        $memberEmails = User::query()
            ->select('email')
            ->whereIn('id', Membership::query()->select('user_id')->where('organization_id', $organizationId));

        return RiskEvent::query()
            ->whereNotNull('email')
            ->whereIn('email', $memberEmails)
            ->latest('created_at')
            ->limit(50)
            ->get();
    }
}; ?>

<div class="space-y-6">
    <x-page-header title="Risk events"
                   subtitle="Sign-ins and requests the platform scored as suspicious enough to flag. Newest first." />

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
