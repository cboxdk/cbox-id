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

<div class="space-y-6">
    <div class="cbx-page-header mb-6">
        <div class="min-w-0">
            <h1 class="cbx-page-title">Connectors catalog</h1>
            <p class="cbx-page-desc">
                The connector types this platform speaks. Each is backed by an existing platform module; enable and configure
                them from their module pages, then review them together under Connections.
            </p>
        </div>
    </div>

    <div class="grid grid-cols-1 gap-4 sm:grid-cols-2">
        @foreach ($this->catalog as $type)
            <div class="card p-5">
                <div class="flex items-start justify-between gap-3">
                    <div>
                        <p class="text-sm font-semibold" style="color:var(--foreground)">{{ $type['name'] }}</p>
                        <p class="mt-0.5 text-xs font-medium uppercase tracking-wide" style="color:var(--muted)">
                            {{ $type['category'] }} &middot; {{ $type['direction'] }}
                        </p>
                    </div>
                    @if ($type['enumerable'])
                        <span class="badge badge-success shrink-0 tabular-nums">
                            {{ number_format((int) $type['active']) }} active
                        </span>
                    @else
                        <span class="badge shrink-0">
                            Managed in module
                        </span>
                    @endif
                </div>
                <p class="mt-3 text-sm" style="color:var(--muted)">{{ $type['description'] }}</p>
            </div>
        @endforeach
    </div>

    <p class="mt-6 text-xs" style="color:var(--faint)">
        Directory sync is inbound SCIM where the platform is the server; its live directories are managed on the
        Directory pages and are not listed here.
    </p>
</div>
