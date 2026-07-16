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

<div class="space-y-6">
    <div class="cbx-page-header mb-6">
        <div class="min-w-0">
            <h1 class="cbx-page-title">Connections</h1>
            <p class="cbx-page-desc">
                Every live connector for this organization, across outbound SCIM, webhooks and SSO federation.
            </p>
        </div>
    </div>

    @if ($this->connections === [])
        <div class="cbx-empty">
            <p class="text-sm" style="color:var(--muted)">No connectors are configured for this organization yet.</p>
        </div>
    @else
        <div class="overflow-x-auto">
            <table class="table">
                <thead>
                    <tr>
                        <th>Type</th>
                        <th>Name</th>
                        <th>Target</th>
                        <th>Status</th>
                        <th>Health</th>
                    </tr>
                </thead>
                <tbody>
                    @foreach ($this->connections as $row)
                        <tr>
                            <td class="whitespace-nowrap" style="color:var(--muted)">{{ $row['category'] }}</td>
                            <td class="font-medium" style="color:var(--foreground)">{{ $row['name'] }}</td>
                            <td style="color:var(--muted)">{{ $row['target'] ?? '—' }}</td>
                            <td class="whitespace-nowrap">
                                @if ($row['status'] === 'active')
                                    <span class="badge badge-success">Active</span>
                                @else
                                    <span class="badge capitalize">{{ $row['status'] }}</span>
                                @endif
                            </td>
                            <td class="whitespace-nowrap">
                                @if ($row['health'] === null)
                                    <span class="text-xs" style="color:var(--faint)">—</span>
                                @elseif ($row['health'] === 'healthy')
                                    <span class="badge badge-success">Healthy</span>
                                @elseif ($row['health'] === 'degraded')
                                    <span class="badge badge-warn">Degraded</span>
                                @else
                                    <span class="badge capitalize">{{ $row['health'] }}</span>
                                @endif
                            </td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
    @endif
</div>
