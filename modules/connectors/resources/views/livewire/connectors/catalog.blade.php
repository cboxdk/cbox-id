<?php

use App\Platform\CurrentUser;
use Cbox\Console\Kit\Facades\Console;
use Cbox\Id\Connectors\Catalog\ConnectorCatalog;
use Cbox\Id\Connectors\Connections\ConnectionsOverview;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Layout;
use Livewire\Volt\Component;

new #[Layout('components.layouts.app', ['title' => 'Catalog'])] class extends Component
{
    /**
     * Route middleware does not gate this page: the module routes carry `platform.auth`
     * (a session exists) and `console.feature` (the flag is on), and neither is a role
     * check. The nav hides the area from a plain member, which is styling, not
     * authorization — the URL is typeable. Guarded in boot() rather than mount() so it
     * re-runs on every Livewire message, not just the first render.
     */
    public function boot(): void
    {
        abort_unless(app(CurrentUser::class)->isAdmin(), 403);
    }
    /**
     * Flattened for the card grid — a rendering boundary, so an array shape is the
     * right shape here; the typed {@see ConnectorDescriptor} is what does the work
     * upstream.
     *
     * @return list<array{key: string, name: string, category: string, description: string, direction: string, enumerable: bool, active: int|null}>
     */
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
    <x-page-header title="Catalog"
                   subtitle="The connector types this platform speaks. Each is backed by a platform module you enable and configure on its own page; review them together under Connections." />

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
