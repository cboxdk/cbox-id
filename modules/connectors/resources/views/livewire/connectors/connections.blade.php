<?php

use App\Platform\Console\ConsoleScope;
use Cbox\Id\Connectors\Connections\ConnectionsOverview;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Layout;
use Livewire\Volt\Component;

new #[Layout('components.layouts.console', ['title' => 'Connections'])] class extends Component
{
    /**
     * Route middleware does not gate this page by ROLE: the routes carry a session gate
     * (`platform.auth` on one plane, `env.admin` on the other) and `console.feature`, and
     * neither is a role check. The nav hides the area from a plain member, which is
     * styling, not authorization — the URL is typeable. boot() rather than mount(), so it
     * re-runs on every Livewire message and not just the first render.
     */
    public function boot(): void
    {
        app(ConsoleScope::class)->assertMayAdminister();
    }

    /**
     * Flattened for the table — a rendering boundary, so an array shape is the right
     * shape here; the typed {@see ConnectionSummary} is what does the work upstream.
     *
     * @return list<array{category: string, name: string, status: string, target: string|null, health: string|null}>
     */
    #[Computed]
    public function connections(): array
    {
        // From the scope, not from console-kit's CurrentContext. That helper answers
        // null whenever no SUBJECT is signed in — which on the environment plane is
        // always — so an environment administrator would silently have been handed the
        // environment-wide branch even after choosing an organization to act on. Here
        // null means one thing: an environment administrator has not chosen yet.
        $organizationId = app(ConsoleScope::class)->organizationId();

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

    /**
     * The view half of the scoping above. Without it the page keeps saying "for this
     * organization" while listing the whole environment — copy that is merely wrong on
     * one plane is how a reader learns to distrust the scoping they cannot see.
     *
     * @return array{wholeEnvironment: bool}
     */
    public function with(): array
    {
        return ['wholeEnvironment' => app(ConsoleScope::class)->organizationId() === null];
    }
};

?>

<div class="space-y-6">
    <x-page-header title="Connections"
                   subtitle="Every live connector {{ $wholeEnvironment ? 'in this environment' : 'for this organization' }}, across outbound SCIM, webhooks and SSO federation." />

    @if ($this->connections === [])
        <div class="cbx-empty">
            <p class="text-sm" style="color:var(--muted)">No connectors are configured {{ $wholeEnvironment ? 'in this environment' : 'for this organization' }} yet.</p>
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
