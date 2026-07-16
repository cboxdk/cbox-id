<?php

use Cbox\Console\Kit\Facades\Console;
use Cbox\Id\Connectors\Catalog\ConnectorCatalog;
use Cbox\Id\Connectors\Connections\ConnectionsOverview;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Layout;
use Livewire\Volt\Component;

new #[Layout('components.layouts.app', ['title' => 'Connectors'])] class extends Component
{
    #[Computed]
    public function catalog(): array
    {
    $organizationId = Console::context()->organizationId();

    $active = [];
    foreach (app(ConnectionsOverview::class)->forOrganization($organizationId) as $summary) {
        $key = $summary->category->value;
        $active[$key] = ($active[$key] ?? 0) + ($summary->isActive() ? 1 : 0);
    }

    $types = [];
    foreach (app(ConnectorCatalog::class)->all() as $descriptor) {
        $types[] = [
            'key' => $descriptor->key,
            'name' => $descriptor->name,
            'category' => $descriptor->category->label(),
            'description' => $descriptor->description,
            'direction' => $descriptor->category->isOutbound() ? 'Outbound' : 'Inbound',
            'enumerable' => $descriptor->enumerable,
            'active' => $descriptor->enumerable ? ($active[$descriptor->category->value] ?? 0) : null,
        ];
    }

    return $types;
    }
};

?>

<div class="mx-auto max-w-5xl px-4 py-8">
    <header class="mb-6">
        <h1 class="text-xl font-semibold text-neutral-900 dark:text-neutral-100">Connectors catalog</h1>
        <p class="mt-1 text-sm text-neutral-500 dark:text-neutral-400">
            The connector types this platform speaks. Each is backed by an existing platform module; enable and configure
            them from their module pages, then review them together under Connections.
        </p>
    </header>

    <div class="grid grid-cols-1 gap-4 sm:grid-cols-2">
        @foreach ($this->catalog as $type)
            <div class="rounded-xl border border-neutral-200 bg-white p-5 dark:border-neutral-800 dark:bg-neutral-900">
                <div class="flex items-start justify-between gap-3">
                    <div>
                        <p class="text-sm font-semibold text-neutral-900 dark:text-neutral-100">{{ $type['name'] }}</p>
                        <p class="mt-0.5 text-xs font-medium uppercase tracking-wide text-neutral-400 dark:text-neutral-500">
                            {{ $type['category'] }} &middot; {{ $type['direction'] }}
                        </p>
                    </div>
                    @if ($type['enumerable'])
                        <span class="shrink-0 rounded-full bg-emerald-100 px-2.5 py-0.5 text-xs font-medium tabular-nums text-emerald-700 dark:bg-emerald-500/15 dark:text-emerald-400">
                            {{ number_format((int) $type['active']) }} active
                        </span>
                    @else
                        <span class="shrink-0 rounded-full bg-neutral-100 px-2.5 py-0.5 text-xs font-medium text-neutral-500 dark:bg-neutral-800 dark:text-neutral-400">
                            Managed in module
                        </span>
                    @endif
                </div>
                <p class="mt-3 text-sm text-neutral-600 dark:text-neutral-300">{{ $type['description'] }}</p>
            </div>
        @endforeach
    </div>

    <p class="mt-6 text-xs text-neutral-400 dark:text-neutral-500">
        Directory sync is inbound SCIM where the platform is the server; its live directories are managed on the
        Directory pages and are not listed here.
    </p>
</div>
