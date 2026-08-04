<?php

declare(strict_types=1);

use App\Platform\AppLauncher;
use App\Platform\AuditNames;
use App\Platform\CurrentUser;
use App\Platform\Onboarding\SetupChecklist;
use Cbox\Id\Federation\Contracts\Connections;
use Cbox\Id\Kernel\Audit\Models\AuditEntry;
use Cbox\Id\Organization\Contracts\Memberships;
use Illuminate\Support\Collection;
use Livewire\Attributes\Layout;
use Livewire\Volt\Component;

new #[Layout('components.layouts.app', ['title' => 'Overview'])] class extends Component
{
    /**
     * Put the setup checklist away for this admin. Progress itself is never stored,
     * so an admin who dismisses it and later opens /get-started sees exactly where
     * the organization actually stands — nothing was "reset" by hiding the card.
     */
    public function dismissChecklist(SetupChecklist $checklist): void
    {
        $me = app(CurrentUser::class);
        $orgId = $me->organizationId();

        if ($orgId === null || ! $me->isAdmin()) {
            return;
        }

        $checklist->dismiss($orgId, $me->id());
        $this->dispatch('toast', message: 'Setup guide hidden — reach it any time from Settings.');
    }

    /** @return array<string, mixed> */
    public function with(): array
    {
        $me = app(CurrentUser::class);
        $orgId = $me->organizationId();

        $connection = $orgId !== null ? app(Connections::class)->forOrganization($orgId) : null;

        /** @var Collection<int, AuditEntry> $recent */
        $recent = $orgId !== null
            ? AuditEntry::query()->where('organization_id', $orgId)->orderByDesc('sequence')->limit(6)->get()
            : new Collection;

        // Only an admin can act on any of the steps, so only an admin is measured.
        // Dismissal first: the checklist costs a query per step, and an admin who put it
        // away was paying for all of them on every page load, forever.
        $checklist = app(SetupChecklist::class);
        $shows = $orgId !== null && $me->isAdmin() && ! $checklist->isDismissed($orgId, $me->id());
        $progress = $shows ? $checklist->for($orgId) : null;

        return [
            'me' => $me,
            'apps' => app(AppLauncher::class)->apps(),
            'memberCount' => $orgId !== null ? app(Memberships::class)->countForOrganization($orgId) : 0,
            'ssoActive' => $connection !== null,
            'recent' => $recent,
            // The card earns its place only while there is something left to do and
            // this admin has not put it away.
            'progress' => $progress !== null && ! $progress->isComplete() ? $progress : null,
            // Resolve opaque ids to human names so the feed reads like a story
            // ("member added · Ada Lovelace"), not a wall of ULIDs. Shared with the
            // activity log, which needs the identical mapping — this component used to
            // carry its own copy that resolved targets only, and one query per row.
            'targetLabels' => app(AuditNames::class)->for($recent),
        ];
    }
}; ?>

<div>
    <x-page-header :title="'Welcome back, '.\Illuminate\Support\Str::before($me->name(), ' ')" :help="\App\Platform\Help\HelpTopic::Overview"
                   subtitle="Here's what's happening across {{ $me->organization()?->name ?? 'your organization' }}." />

    @if (count($apps) > 0)
        <section class="mb-6">
            <h2 class="text-xs font-medium uppercase mb-3" style="color:var(--muted);letter-spacing:0.06em">Your apps</h2>
            <div class="grid gap-3 grid-cols-1 sm:grid-cols-2 lg:grid-cols-3">
                @foreach ($apps as $app)
                    <a href="{{ $app['url'] }}" class="cbx-app-tile">
                        <span class="cbx-app-logo" aria-hidden="true"
                              style="background:linear-gradient(135deg, oklch(0.63 0.15 {{ $app['hue'] }}), oklch(0.5 0.17 {{ ($app['hue'] + 28) % 360 }}))">{{ $app['initial'] }}</span>
                        <span class="min-w-0 flex-1">
                            <span class="block truncate font-semibold" style="color:var(--foreground)">{{ $app['name'] }}</span>
                            <span class="block truncate text-xs mono" style="color:var(--muted)">{{ $app['host'] }}</span>
                        </span>
                        <svg class="cbx-app-go" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M5 12h14M13 6l6 6-6 6"/></svg>
                    </a>
                @endforeach
            </div>
        </section>
    @endif

    @if ($me->isAdmin())
    {{-- First run. An organization where nothing has been set up yet has an empty
         activity feed, no apps and a member count of one — a dashboard that reports
         accurately that nothing has happened, which is no help to the person who
         just arrived. Until the first step is done, the guide leads instead.

         It is a banner rather than a redirect on purpose: hijacking /dashboard would
         take a deep link away from someone who typed it, and there is no reliable
         "this is their very first visit" signal to hang that on. --}}
    @if ($progress !== null && $progress->completed() === 0)
        <div class="card p-5 mb-4" style="border-color:var(--accent-edge);background:var(--accent-soft)">
            <div class="flex flex-wrap items-center justify-between gap-4">
                <div class="flex items-start gap-3 min-w-0">
                    <span class="grid place-items-center rounded-lg shrink-0" style="width:2rem;height:2rem;background:var(--card);color:var(--primary)"><x-icon name="rocket" class="w-4 h-4" /></span>
                    <div class="min-w-0">
                        <p class="font-semibold">Nothing is set up yet</p>
                        <p class="mt-1 text-sm" style="color:var(--muted-foreground)">{{ $progress->total() }} steps, in the order that makes sense — starting with {{ \Illuminate\Support\Str::lcfirst($progress->next()?->title() ?? 'the basics') }}.</p>
                    </div>
                </div>
                <a href="{{ route('get-started') }}" wire:navigate class="btn btn-primary shrink-0">Start setting up</a>
            </div>
        </div>
    @endif

    {{-- Plugins (billing, …) contribute cards here; each returns a `.card` block, so
         they tile as a responsive row alongside the native stat cards below.

         Inside the admin branch, and that placement is AUTHORIZATION rather than layout.
         A card is arbitrary module HTML rendered through an ungated slot registry, so
         whatever it decides to read is read for whoever this page renders for. Every card
         also narrows to the acting organization itself — four of them did not, and showed
         one tenant the whole environment's sign-in volume, risk events and audit backlog —
         but this branch is the layer that keeps a plain member out of the whole row, and
         moving the slot out of it would silently hand each module a wider audience than
         its own guard assumes. --}}
    <div class="grid gap-4 sm:grid-cols-2 lg:grid-cols-3 [&:empty]:hidden mb-4">
        @consoleSlot('console.dashboard.cards')
    </div>

    <div class="grid gap-4 sm:grid-cols-3">
        <div class="card p-5">
            <div class="flex items-center gap-2 text-sm" style="color:var(--muted)"><x-icon name="members" class="w-4 h-4" /> Members</div>
            <p class="mt-2 text-3xl font-semibold tracking-tight mono">{{ $memberCount }}</p>
        </div>
        <div class="card p-5">
            <div class="flex items-center gap-2 text-sm" style="color:var(--muted)"><x-icon name="connections" class="w-4 h-4" /> Enterprise SSO</div>
            <p class="mt-2 text-lg font-semibold">
                @if ($ssoActive) <span class="badge badge-success">Active</span> @else <span class="badge">Not configured</span> @endif
            </p>
        </div>
        <div class="card p-5">
            <div class="flex items-center gap-2 text-sm" style="color:var(--muted)"><x-icon name="shield" class="w-4 h-4" /> Your role</div>
            <p class="mt-2 text-lg font-semibold">{{ $me->role()?->label() ?? 'Member' }}</p>
        </div>
    </div>

    <div class="grid gap-4 lg:grid-cols-3 mt-4">
        {{-- Widens to the full row once the checklist beside it is gone, rather than
             leaving a third of the grid empty for the rest of the org's life. --}}
        {{-- `min-w-0`, because a grid item's default `min-width: auto` floors it at its
             own min-content width. Without it these two cards forced the row 36px wider
             than a 375px viewport — and because the scroll container is <main>, not the
             document, every "the page does not scroll sideways" check passed while the
             console panned under the user's thumb. --}}
        <div class="card min-w-0 {{ $progress !== null ? 'lg:col-span-2' : 'lg:col-span-3' }}">
            <div class="px-5 py-4 border-b flex items-center justify-between" style="border-color:var(--border)">
                <h3 class="font-semibold">Recent activity</h3>
                <a href="{{ route('audit') }}" class="text-sm" style="color:var(--accent-strong)">View activity log</a>
            </div>
            @if ($recent->isEmpty())
                <div class="px-5 py-10 text-center text-sm" style="color:var(--faint)">No activity recorded yet.</div>
            @else
                <ul>
                    @foreach ($recent as $entry)
                        <li class="px-5 py-3 border-b flex items-center justify-between gap-4" style="border-color:var(--border)">
                            <div class="min-w-0">
                                <p class="text-sm font-medium truncate">{{ str_replace(['.', '_'], [' · ', ' '], $entry->action) }}</p>
                                @php $label = $targetLabels[$entry->target_id] ?? null; @endphp
                                <p class="text-xs truncate" style="color:var(--faint)">
                                    @if ($label)
                                        {{ $label }}
                                    @elseif ($entry->target_id)
                                        {{ \Illuminate\Support\Str::headline((string) $entry->target_type) }}
                                        <span class="mono">{{ \Illuminate\Support\Str::limit($entry->target_id, 24) }}</span>
                                    @endif
                                </p>
                            </div>
                            <time class="text-xs whitespace-nowrap" style="color:var(--faint)">{{ $entry->recorded_at?->diffForHumans() }}</time>
                        </li>
                    @endforeach
                </ul>
            @endif
        </div>

        {{-- The setup checklist, measured — every tick here corresponds to something
             that is actually true of this organization. It disappears for good once
             the last step is done, or when this admin puts it away. --}}
        @if ($progress !== null)
            <div class="card min-w-0 p-5">
                <div class="flex items-start justify-between gap-2">
                    <h3 class="font-semibold">Finish setting up</h3>
                    <button type="button" wire:click="dismissChecklist" class="cbx-help-link" style="color:var(--muted-foreground)">Hide</button>
                </div>

                <div class="mt-3 flex items-center gap-3">
                    <div class="cbx-progress" role="progressbar" aria-valuemin="0" aria-valuemax="100"
                         aria-valuenow="{{ $progress->percent() }}" aria-label="Setup progress">
                        <span style="width:{{ $progress->percent() }}%"></span>
                    </div>
                    <span class="text-xs mono shrink-0" style="color:var(--muted-foreground)">{{ $progress->completed() }}/{{ $progress->total() }}</span>
                </div>

                <ul class="mt-4 space-y-3">
                    @foreach ($progress->steps as $step)
                        <li class="flex items-start gap-3">
                            <span class="grid place-items-center rounded-full mt-0.5 shrink-0" style="width:1.25rem;height:1.25rem;{{ $step->done ? 'background:var(--success-soft);color:var(--success-strong)' : 'border:1px solid var(--border)' }}">
                                @if ($step->done)<x-icon name="check" class="w-3 h-3" />@endif
                            </span>
                            <div class="min-w-0">
                                @if ($step->done)
                                    <p class="text-sm" style="color:var(--muted-foreground)">{{ $step->title() }}</p>
                                @else
                                    <a href="{{ route($step->route()) }}" wire:navigate class="text-sm font-medium inline-flex items-center" style="color:var(--accent-strong);min-height:1.5rem">{{ $step->title() }} →</a>
                                @endif
                            </div>
                        </li>
                    @endforeach
                </ul>

                <a href="{{ route('get-started') }}" wire:navigate class="btn btn-ghost btn-sm mt-4 w-full justify-center">
                    <x-icon name="rocket" class="w-4 h-4" /> Open the setup guide
                </a>
            </div>
        @endif
    </div>
    @else
        {{-- Member overview: their apps (above) plus a nudge to their own security.
             Org stats, the org-wide audit feed and the setup checklist are admin
             surfaces — a plain member neither manages nor needs to see them. --}}
        <div class="grid gap-4 sm:grid-cols-2">
            <div class="card p-5">
                <div class="flex items-center gap-2 text-sm" style="color:var(--muted)"><x-icon name="shield" class="w-4 h-4" /> Your role</div>
                {{-- The separator belongs to the org name, not to the role: printed unconditionally
                     it left a bare "Member ·" trailing into nothing whenever the member has no
                     organization resolved. --}}
                <p class="mt-2 text-lg font-semibold">{{ $me->role()?->label() ?? 'Member' }}@if ($me->organization()?->name)<span style="color:var(--muted);font-weight:400"> · {{ $me->organization()->name }}</span>@endif</p>
            </div>
            <div class="card p-5 flex flex-col">
                <div class="flex items-center gap-2 text-sm" style="color:var(--muted)"><x-icon name="key" class="w-4 h-4" /> Your security</div>
                <p class="mt-2 text-sm" style="color:var(--foreground)">Add a passkey or two-factor authentication to keep your account safe.</p>
                <a href="{{ route('account') }}" class="btn btn-primary btn-sm mt-3 self-start">Manage security</a>
            </div>
        </div>
    @endif
</div>
