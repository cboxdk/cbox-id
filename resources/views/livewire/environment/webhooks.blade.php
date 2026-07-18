<?php
declare(strict_types=1);
use Cbox\Id\Webhooks\Models\WebhookEndpoint;
use Livewire\Attributes\Layout;
use Livewire\Volt\Component;
/** Environment control plane › Webhooks. The environment's webhook endpoints —
 *  where identity events (user.created, sso.*, etc.) are delivered. Env-scoped. */
new #[Layout('components.layouts.environment', ['title' => 'Webhooks'])] class extends Component {
    public function with(): array { return ['endpoints' => WebhookEndpoint::query()->orderByDesc('id')->limit(100)->get()]; }
}; ?>
<div>
    <h1 class="font-semibold tracking-tight" style="font-size:1.5rem">Webhooks</h1>
    <p class="mt-1 text-sm" style="color:var(--muted)">Receive identity events (users, sessions, SSO, directory sync) at your own endpoints.</p>
    <div class="mt-6 rounded-xl border overflow-hidden" style="border-color:var(--border)">
        @forelse ($endpoints as $e)
            <div class="flex items-center gap-3 p-4 {{ ! $loop->last ? 'border-b' : '' }}" style="border-color:var(--border)">
                <div class="min-w-0 flex-1"><span class="font-medium truncate mono text-sm">{{ $e->url }}</span>
                    <p class="text-xs truncate" style="color:var(--muted)">{{ count($e->event_types) }} event {{ \Illuminate\Support\Str::plural('type', count($e->event_types)) }}</p></div>
                <span class="text-xs rounded-full px-2 py-0.5" style="background:var(--surface-2);color:var(--muted)">{{ $e->status->value }}</span>
            </div>
        @empty
            <p class="p-4 text-sm" style="color:var(--muted)">No webhook endpoints yet.</p>
        @endforelse
    </div>
</div>
