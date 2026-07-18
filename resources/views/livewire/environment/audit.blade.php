<?php
declare(strict_types=1);
use Cbox\Id\Kernel\Audit\Models\AuditEntry;
use Livewire\Attributes\Layout;
use Livewire\Volt\Component;
/** Environment control plane › Audit log. The environment's tamper-evident,
 *  hash-chained audit trail (env-scoped), most recent first. */
new #[Layout('components.layouts.environment', ['title' => 'Audit log'])] class extends Component {
    public function with(): array { return ['entries' => AuditEntry::query()->orderByDesc('sequence')->limit(100)->get()]; }
}; ?>
<div>
    <h1 class="font-semibold tracking-tight" style="font-size:1.5rem">Audit log</h1>
    <p class="mt-1 text-sm" style="color:var(--muted)">A tamper-evident, hash-chained record of every security-relevant action in this environment.</p>
    <div class="mt-6 rounded-xl border overflow-hidden" style="border-color:var(--border)">
        @forelse ($entries as $e)
            <div class="flex items-center gap-3 p-4 {{ ! $loop->last ? 'border-b' : '' }}" style="border-color:var(--border)">
                <div class="min-w-0 flex-1"><span class="font-medium truncate mono text-sm">{{ $e->action }}</span>
                    <p class="text-xs truncate" style="color:var(--muted)">{{ $e->actor_type->value }}{{ $e->actor_id ? ' · '.\Illuminate\Support\Str::limit($e->actor_id, 12) : '' }}{{ $e->target_type ? ' → '.$e->target_type : '' }}</p></div>
                <span class="text-xs tabular-nums shrink-0" style="color:var(--faint)">#{{ $e->sequence }}</span>
            </div>
        @empty
            <p class="p-4 text-sm" style="color:var(--muted)">No audit entries yet.</p>
        @endforelse
    </div>
</div>
