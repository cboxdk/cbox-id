<?php

use Cbox\Id\AuditQuery\Contracts\AuditReader;
use Cbox\Id\AuditQuery\ValueObjects\AuditQueryFilter;
use Cbox\Id\Kernel\Audit\Contracts\AuditLog;

use function Livewire\Volt\{computed, state};

state([
    'organizationId' => '',
    'action' => '',
    'actorId' => '',
]);

$scope = computed(fn (): ?string => trim($this->organizationId) === '' ? null : trim($this->organizationId));

$entries = computed(function () {
    $filter = new AuditQueryFilter(
        organizationId: $this->scope,
        action: trim($this->action) === '' ? null : trim($this->action),
        actorId: trim($this->actorId) === '' ? null : trim($this->actorId),
        limit: 50,
    );

    return app(AuditReader::class)->query($filter)->items;
});

// The chain-verification status for the scope currently being viewed.
$verification = computed(fn () => app(AuditLog::class)->verifyChain($this->scope));

?>

<div class="mx-auto max-w-5xl px-4 py-8">
    <header class="mb-6">
        <h1 class="text-xl font-semibold text-neutral-900 dark:text-neutral-100">Audit trail</h1>
        <p class="mt-1 text-sm text-neutral-500 dark:text-neutral-400">
            Search the append-only, hash-chained audit trail. Leave the organization blank for the system trail.
        </p>
    </header>

    @php($verification = $this->verification)
    <div class="mb-6 flex items-center gap-3 rounded-xl border p-4 text-sm {{ $verification->valid ? 'border-emerald-200 bg-emerald-50 text-emerald-800 dark:border-emerald-500/20 dark:bg-emerald-500/10 dark:text-emerald-300' : 'border-rose-200 bg-rose-50 text-rose-800 dark:border-rose-500/20 dark:bg-rose-500/10 dark:text-rose-300' }}">
        <span class="font-medium">
            {{ $verification->valid ? 'Chain verified' : 'Chain broken' }}
        </span>
        <span class="text-xs opacity-80">
            @if ($verification->valid)
                {{ number_format($verification->verifiedCount) }} entrie(s) checked; hashes and linkage intact.
            @else
                Broke at sequence {{ $verification->brokenAtSequence }} — {{ $verification->reason }}.
            @endif
        </span>
    </div>

    <div class="mb-6 grid grid-cols-1 gap-3 sm:grid-cols-3">
        <label class="block">
            <span class="text-xs font-medium uppercase tracking-wide text-neutral-500 dark:text-neutral-400">Organization</span>
            <input type="text" wire:model.live.debounce.400ms="organizationId" placeholder="system trail"
                class="mt-1 w-full rounded-lg border border-neutral-300 bg-white px-3 py-2 text-sm text-neutral-900 dark:border-neutral-700 dark:bg-neutral-900 dark:text-neutral-100" />
        </label>
        <label class="block">
            <span class="text-xs font-medium uppercase tracking-wide text-neutral-500 dark:text-neutral-400">Action</span>
            <input type="text" wire:model.live.debounce.400ms="action" placeholder="e.g. auth.login"
                class="mt-1 w-full rounded-lg border border-neutral-300 bg-white px-3 py-2 text-sm text-neutral-900 dark:border-neutral-700 dark:bg-neutral-900 dark:text-neutral-100" />
        </label>
        <label class="block">
            <span class="text-xs font-medium uppercase tracking-wide text-neutral-500 dark:text-neutral-400">Actor</span>
            <input type="text" wire:model.live.debounce.400ms="actorId" placeholder="actor id"
                class="mt-1 w-full rounded-lg border border-neutral-300 bg-white px-3 py-2 text-sm text-neutral-900 dark:border-neutral-700 dark:bg-neutral-900 dark:text-neutral-100" />
        </label>
    </div>

    <div class="overflow-x-auto rounded-xl border border-neutral-200 dark:border-neutral-800">
        <table class="min-w-full divide-y divide-neutral-200 text-sm dark:divide-neutral-800">
            <thead class="bg-neutral-50 text-left text-xs uppercase tracking-wide text-neutral-500 dark:bg-neutral-900 dark:text-neutral-400">
                <tr>
                    <th class="px-4 py-3 font-medium text-right tabular-nums">Seq</th>
                    <th class="px-4 py-3 font-medium">When</th>
                    <th class="px-4 py-3 font-medium">Action</th>
                    <th class="px-4 py-3 font-medium">Actor</th>
                    <th class="px-4 py-3 font-medium">Target</th>
                </tr>
            </thead>
            <tbody class="divide-y divide-neutral-100 dark:divide-neutral-800/60">
                @forelse ($this->entries as $entry)
                    <tr>
                        <td class="px-4 py-3 text-right tabular-nums text-neutral-500 dark:text-neutral-400">{{ $entry->sequence }}</td>
                        <td class="whitespace-nowrap px-4 py-3 text-neutral-500 dark:text-neutral-400">{{ $entry->recorded_at?->diffForHumans() }}</td>
                        <td class="px-4 py-3 font-medium text-neutral-800 dark:text-neutral-200">{{ $entry->action }}</td>
                        <td class="px-4 py-3 text-neutral-500 dark:text-neutral-400">{{ $entry->actor_type->value }}{{ $entry->actor_id ? ' · '.$entry->actor_id : '' }}</td>
                        <td class="px-4 py-3 text-neutral-500 dark:text-neutral-400">{{ $entry->target_type ? $entry->target_type.' · '.$entry->target_id : '—' }}</td>
                    </tr>
                @empty
                    <tr>
                        <td colspan="5" class="px-4 py-10 text-center text-neutral-400">No audit entries match.</td>
                    </tr>
                @endforelse
            </tbody>
        </table>
    </div>
</div>
