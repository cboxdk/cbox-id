<?php

use Cbox\Id\AuditQuery\Contracts\AuditReader;
use Cbox\Id\AuditQuery\ValueObjects\AuditQueryFilter;
use Cbox\Id\Kernel\Audit\Contracts\AuditLog;

use function Livewire\Volt\{computed, state, layout};
layout('components.layouts.app');

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

<div class="space-y-6">
    <div class="cbx-page-header">
        <div>
            <h1 class="cbx-page-title">Audit trail</h1>
            <p class="cbx-page-desc">
                Search the append-only, hash-chained audit trail. Leave the organization blank for the system trail.
            </p>
        </div>
    </div>

    @php($verification = $this->verification)
    <div class="flex items-center gap-3 rounded-xl border p-4 text-sm"
        style="{{ $verification->valid ? 'border-color:color-mix(in oklch, var(--success) 20%, transparent);background:var(--success-soft);color:var(--success)' : 'border-color:color-mix(in oklch, var(--destructive) 20%, transparent);background:var(--destructive-soft);color:var(--destructive)' }}">
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

    <div class="grid grid-cols-1 gap-3 sm:grid-cols-3">
        <label class="block">
            <span class="label">Organization</span>
            <input type="text" wire:model.live.debounce.400ms="organizationId" placeholder="system trail"
                class="input mt-1 w-full" />
        </label>
        <label class="block">
            <span class="label">Action</span>
            <input type="text" wire:model.live.debounce.400ms="action" placeholder="e.g. auth.login"
                class="input mt-1 w-full" />
        </label>
        <label class="block">
            <span class="label">Actor</span>
            <input type="text" wire:model.live.debounce.400ms="actorId" placeholder="actor id"
                class="input mt-1 w-full" />
        </label>
    </div>

    <div class="card overflow-hidden">
        <div class="overflow-x-auto">
            <table class="table">
                <thead>
                    <tr>
                        <th class="text-right" style="width:1%">Seq</th>
                        <th scope="col">When</th>
                        <th scope="col">Action</th>
                        <th scope="col">Actor</th>
                        <th scope="col">Target</th>
                    </tr>
                </thead>
                <tbody>
                    @forelse ($this->entries as $entry)
                        <tr>
                            <td class="text-right mono text-xs" style="color:var(--faint)">{{ $entry->sequence }}</td>
                            <td class="whitespace-nowrap mono text-xs" style="color:var(--muted)">{{ $entry->recorded_at?->diffForHumans() }}</td>
                            <td class="font-medium whitespace-nowrap" style="color:var(--foreground)">{{ $entry->action }}</td>
                            <td style="color:var(--muted)">{{ $entry->actor_type->value }}{{ $entry->actor_id ? ' · '.$entry->actor_id : '' }}</td>
                            <td style="color:var(--muted)">{{ $entry->target_type ? $entry->target_type.' · '.$entry->target_id : '—' }}</td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="5">
                                <div class="cbx-empty">
                                    <h3>No audit entries match</h3>
                                    <p>Adjust the filters above, or clear them to see the full trail.</p>
                                </div>
                            </td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </div>
</div>
