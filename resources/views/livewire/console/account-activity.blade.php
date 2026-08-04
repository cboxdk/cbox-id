<?php

declare(strict_types=1);

use App\Platform\AccountActivity;
use App\Platform\AccountAuth;
use App\Platform\Console\ConsoleScope;
use Cbox\Id\Platform\Contracts\AccountMembers;
use Illuminate\Support\Collection;
use Livewire\Attributes\Layout;
use Livewire\Volt\Component;

/**
 * Identity platform › Account activity — the account's activity log: environments created,
 * members invited/removed/re-roled, environment keys minted/revoked, across the
 * whole account. Sourced from the tamper-evident audit chain scoped to this account
 * ({@see AccountActivity}). Admin-only, and re-guarded in boot() so it re-runs on
 * every Livewire interaction, not just first render.
 */
new #[Layout('components.layouts.app', ['title' => 'Account activity'])] class extends Component
{
    public string $filter = '';

    public function boot(ConsoleScope $scope): void
    {
        // Account-wide activity names every actor and target — an admin view. A
        // member who cannot read members cannot read the account's activity either.
        abort_unless($scope->accountRole()?->canReadMembers() === true, 403);
    }

    /**
     * @return array<string, mixed>
     */
    public function with(AccountAuth $auth, AccountMembers $members, AccountActivity $activity): array
    {
        $accountId = $auth->current()->account_id ?? '';

        $entries = $activity->recent($accountId, 200)
            ->when(trim($this->filter) !== '', fn (Collection $rows): Collection => $rows->filter(
                fn ($entry): bool => str_contains($entry->action, strtolower(trim($this->filter)))
            ))
            ->values();

        // Resolve acting members to emails once (no per-row lookup).
        /** @var Collection<int, string> $actorIds */
        $actorIds = $entries->pluck('actor_id')->filter()->unique();

        $actors = $actorIds->mapWithKeys(fn (string $id): array => [$id => $members->find($id)->email ?? $id]);

        return ['entries' => $entries, 'actors' => $actors];
    }
}; ?>

<div>
    {{-- The console's page primitive, so the eyebrow comes from the nav registry rather
         than from a word typed here. Hand-written it said "Account" while the rail entry
         that reaches this page says "Identity platform" — the one label whose whole job is
         to tell you which area you are standing in, disagreeing with the navigation. --}}
    <x-page-header class="flex-wrap" title="Account activity"
                   subtitle="Every change across this account — environments, members and keys — tamper-evident and hash-chained.">
        <x-slot:actions>
            <input wire:model.live.debounce.300ms="filter" type="text" class="input w-full sm:min-w-[16rem]" placeholder="Filter by action…" aria-label="Filter by action">
        </x-slot:actions>
    </x-page-header>

    <div class="card overflow-hidden">
        <div class="overflow-x-auto">
            <table class="table">
                <thead>
                    <tr>
                        <th scope="col">Action</th>
                        <th scope="col">By</th>
                        <th scope="col">Details</th>
                        <th class="text-right">When</th>
                    </tr>
                </thead>
                <tbody>
                    @forelse ($entries as $entry)
                        <tr>
                            <td class="font-medium whitespace-nowrap">{{ str_replace(['account.', '.', '_'], ['', ' · ', ' '], $entry->action) }}</td>
                            <td>
                                <span class="text-sm">{{ $actors[$entry->actor_id] ?? '—' }}</span>
                            </td>
                            <td class="text-sm" style="color:var(--muted)">
                                @php($ctx = collect($entry->context)->except('impersonation', 'impersonated_by'))
                                @if ($entry->target_type)
                                    <span>{{ str_replace('_', ' ', $entry->target_type) }}</span>
                                @endif
                                @foreach ($ctx as $key => $value)
                                    <span class="badge ml-1">{{ $key }}: {{ is_array($value) ? implode(', ', $value) : $value }}</span>
                                @endforeach
                                @if (! $entry->target_type && $ctx->isEmpty())
                                    <span style="color:var(--faint)">—</span>
                                @endif
                            </td>
                            <td class="text-right whitespace-nowrap">
                                <time class="text-xs" style="color:var(--muted)" title="{{ $entry->recorded_at?->toDayDateTimeString() }}">{{ $entry->recorded_at?->diffForHumans() }}</time>
                            </td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="4">
                                <div class="cbx-empty">
                                    <div class="cbx-empty-icon"><x-icon name="audit" class="w-5 h-5" /></div>
                                    <h3>No activity yet</h3>
                                    <p>Changes across your account will appear here as they happen.</p>
                                </div>
                            </td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </div>

    <p class="flex items-center gap-1.5 text-xs mt-4 min-w-0" style="color:var(--faint)"><x-icon name="shield" class="w-3.5 h-3.5 shrink-0" /> Entries are append-only and hash-chained — any tampering breaks the chain.</p>
</div>
