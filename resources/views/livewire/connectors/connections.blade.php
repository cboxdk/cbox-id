<?php

use Cbox\Console\Kit\Facades\Console;
use Cbox\Id\Connectors\Connections\ConnectionsOverview;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Layout;
use Livewire\Volt\Component;

new #[Layout('components.layouts.app', ['title' => 'Connections'])] class extends Component
{
    #[Computed]
    public function connections(): array
    {
        $organizationId = Console::context()->organizationId();

        $rows = [];
        foreach (app(ConnectionsOverview::class)->forOrganization($organizationId) as $summary) {
            $rows[] = [
                'category' => $summary->category->label(),
                'name' => $summary->name,
                'status' => $summary->status,
                'target' => $summary->target,
                'health' => $summary->health?->verdict(),
            ];
        }

        return $rows;
    }
};

?>

<div class="mx-auto max-w-5xl px-4 py-8">
    <header class="mb-6">
        <h1 class="text-xl font-semibold text-neutral-900 dark:text-neutral-100">Connections</h1>
        <p class="mt-1 text-sm text-neutral-500 dark:text-neutral-400">
            Every live connector for this organization, across outbound SCIM, webhooks and SSO federation.
        </p>
    </header>

    @if ($this->connections === [])
        <div class="rounded-xl border border-dashed border-neutral-300 bg-white p-8 text-center dark:border-neutral-700 dark:bg-neutral-900">
            <p class="text-sm text-neutral-500 dark:text-neutral-400">No connectors are configured for this organization yet.</p>
        </div>
    @else
        <div class="overflow-x-auto rounded-xl border border-neutral-200 dark:border-neutral-800">
            <table class="min-w-full divide-y divide-neutral-200 text-sm dark:divide-neutral-800">
                <thead class="bg-neutral-50 text-left text-xs font-medium uppercase tracking-wide text-neutral-500 dark:bg-neutral-900 dark:text-neutral-400">
                    <tr>
                        <th class="px-4 py-3">Type</th>
                        <th class="px-4 py-3">Name</th>
                        <th class="px-4 py-3">Target</th>
                        <th class="px-4 py-3">Status</th>
                        <th class="px-4 py-3">Health</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-neutral-100 bg-white dark:divide-neutral-800/60 dark:bg-neutral-900">
                    @foreach ($this->connections as $row)
                        <tr>
                            <td class="whitespace-nowrap px-4 py-3 text-neutral-500 dark:text-neutral-400">{{ $row['category'] }}</td>
                            <td class="px-4 py-3 font-medium text-neutral-900 dark:text-neutral-100">{{ $row['name'] }}</td>
                            <td class="px-4 py-3 text-neutral-500 dark:text-neutral-400">{{ $row['target'] ?? '—' }}</td>
                            <td class="whitespace-nowrap px-4 py-3">
                                @if ($row['status'] === 'active')
                                    <span class="rounded-full bg-emerald-100 px-2.5 py-0.5 text-xs font-medium text-emerald-700 dark:bg-emerald-500/15 dark:text-emerald-400">Active</span>
                                @else
                                    <span class="rounded-full bg-neutral-100 px-2.5 py-0.5 text-xs font-medium capitalize text-neutral-500 dark:bg-neutral-800 dark:text-neutral-400">{{ $row['status'] }}</span>
                                @endif
                            </td>
                            <td class="whitespace-nowrap px-4 py-3">
                                @if ($row['health'] === null)
                                    <span class="text-xs text-neutral-400 dark:text-neutral-500">—</span>
                                @elseif ($row['health'] === 'healthy')
                                    <span class="rounded-full bg-emerald-100 px-2.5 py-0.5 text-xs font-medium text-emerald-700 dark:bg-emerald-500/15 dark:text-emerald-400">Healthy</span>
                                @elseif ($row['health'] === 'degraded')
                                    <span class="rounded-full bg-amber-100 px-2.5 py-0.5 text-xs font-medium text-amber-700 dark:bg-amber-500/15 dark:text-amber-400">Degraded</span>
                                @else
                                    <span class="rounded-full bg-neutral-100 px-2.5 py-0.5 text-xs font-medium capitalize text-neutral-500 dark:bg-neutral-800 dark:text-neutral-400">{{ $row['health'] }}</span>
                                @endif
                            </td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
    @endif
</div>
