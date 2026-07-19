<?php
declare(strict_types=1);
use Cbox\Id\Kernel\Usage\Models\UsageCounter;
use Livewire\Attributes\Layout;
use Livewire\Volt\Component;
/** Environment control plane › Analytics. Usage across the environment (env-scoped),
 *  aggregated per metric. */
new #[Layout('components.layouts.environment', ['title' => 'Analytics'])] class extends Component {
    public function with(): array {
        $metrics = UsageCounter::query()->selectRaw('metric, SUM(count) as total')->groupBy('metric')->orderByDesc('total')->get();
        return ['metrics' => $metrics];
    }
}; ?>
<div>
    <x-page-header title="Analytics" subtitle="Usage across this environment — logins, tokens issued, provisioning events and more." />
    @if ($metrics->isEmpty())
        <div class="cbx-empty">
            <div class="cbx-empty-icon"><x-icon name="chart" class="w-5 h-5" /></div>
            <h3>No usage recorded yet</h3>
            <p>Usage appears here as your apps start signing users in and issuing tokens.</p>
        </div>
    @else
        <div class="mt-6 grid gap-4 sm:grid-cols-3">
            @foreach ($metrics as $m)
                <div class="rounded-xl border p-5" style="border-color:var(--border)">
                    <p class="text-sm" style="color:var(--muted)">{{ \Illuminate\Support\Str::headline($m->metric) }}</p>
                    <p class="mt-1 font-semibold tabular-nums" style="font-size:1.75rem">{{ number_format((int) $m->total) }}</p>
                </div>
            @endforeach
        </div>
    @endif
</div>
