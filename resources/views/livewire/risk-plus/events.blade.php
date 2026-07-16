<?php

use Cbox\Id\RiskPlus\Models\RiskEvent;

use function Livewire\Volt\{computed, layout};
layout('components.layouts.app');

$events = computed(fn () => RiskEvent::query()->latest('created_at')->limit(50)->get());

?>

<div class="space-y-6">
    <div class="cbx-page-header mb-6">
        <div class="min-w-0">
            <h1 class="cbx-page-title">Risk events</h1>
            <p class="cbx-page-desc">
                Sign-ins and requests that scored at or above <em>flag</em>. Newest first.
            </p>
        </div>
    </div>

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
                @forelse ($this->events as $event)
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
