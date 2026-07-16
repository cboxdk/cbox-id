<?php

use Cbox\Id\RiskPlus\Models\RiskEvent;

use function Livewire\Volt\{computed, layout};
layout('components.layouts.app');

$events = computed(fn () => RiskEvent::query()->latest('created_at')->limit(50)->get());

?>

<div class="mx-auto max-w-4xl px-4 py-8">
    <header class="mb-6">
        <h1 class="text-xl font-semibold text-neutral-900 dark:text-neutral-100">Risk events</h1>
        <p class="mt-1 text-sm text-neutral-500 dark:text-neutral-400">
            Sign-ins and requests that scored at or above <em>flag</em>. Newest first.
        </p>
    </header>

    <div class="overflow-x-auto rounded-xl border border-neutral-200 dark:border-neutral-800">
        <table class="min-w-full divide-y divide-neutral-200 text-sm dark:divide-neutral-800">
            <thead class="bg-neutral-50 text-left text-xs uppercase tracking-wide text-neutral-500 dark:bg-neutral-900 dark:text-neutral-400">
                <tr>
                    <th class="px-4 py-3 font-medium">When</th>
                    <th class="px-4 py-3 font-medium">Action</th>
                    <th class="px-4 py-3 font-medium">Outcome</th>
                    <th class="px-4 py-3 font-medium text-right tabular-nums">Score</th>
                    <th class="px-4 py-3 font-medium">Reasons</th>
                </tr>
            </thead>
            <tbody class="divide-y divide-neutral-100 dark:divide-neutral-800/60">
                @forelse ($this->events as $event)
                    <tr>
                        <td class="whitespace-nowrap px-4 py-3 text-neutral-500 dark:text-neutral-400">
                            {{ $event->created_at?->diffForHumans() }}
                        </td>
                        <td class="px-4 py-3 font-medium text-neutral-800 dark:text-neutral-200">{{ $event->action }}</td>
                        <td class="px-4 py-3">
                            <span class="inline-flex rounded-full bg-amber-100 px-2 py-0.5 text-xs font-medium text-amber-800 dark:bg-amber-500/15 dark:text-amber-300">
                                {{ str_replace('_', ' ', $event->outcome) }}
                            </span>
                        </td>
                        <td class="px-4 py-3 text-right tabular-nums text-neutral-700 dark:text-neutral-300">{{ (int) round($event->score) }}</td>
                        <td class="px-4 py-3 text-neutral-500 dark:text-neutral-400">{{ implode('; ', $event->reasons) }}</td>
                    </tr>
                @empty
                    <tr>
                        <td colspan="5" class="px-4 py-10 text-center text-neutral-400">No elevated risk events yet.</td>
                    </tr>
                @endforelse
            </tbody>
        </table>
    </div>
</div>
