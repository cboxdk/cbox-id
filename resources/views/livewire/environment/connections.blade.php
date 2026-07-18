<?php
declare(strict_types=1);
use Cbox\Id\Federation\Models\Connection;
use Livewire\Attributes\Layout;
use Livewire\Volt\Component;
/** Environment control plane › Single sign-on. Every SSO connection across the
 *  environment's organizations (env-scoped). Setup is per-organization. */
new #[Layout('components.layouts.environment', ['title' => 'Single sign-on'])] class extends Component {
    public function with(): array { return ['connections' => Connection::query()->orderByDesc('id')->limit(100)->get()]; }
}; ?>
<div>
    <h1 class="font-semibold tracking-tight" style="font-size:1.5rem">Single sign-on</h1>
    <p class="mt-1 text-sm" style="color:var(--muted)">Enterprise SSO connections (SAML &amp; OIDC) across this environment's organizations.</p>
    <div class="mt-6 rounded-xl border overflow-hidden" style="border-color:var(--border)">
        @forelse ($connections as $c)
            <div class="flex items-center gap-3 p-4 {{ ! $loop->last ? 'border-b' : '' }}" style="border-color:var(--border)">
                <div class="min-w-0 flex-1">
                    <div class="flex items-center gap-2"><span class="font-medium truncate">{{ $c->name }}</span>
                        <span class="text-xs rounded-full px-2 py-0.5 uppercase" style="background:var(--surface-2);color:var(--muted)">{{ $c->type->value }}</span></div>
                </div>
                <span class="text-xs rounded-full px-2 py-0.5" style="background:var(--surface-2);color:var(--muted)">{{ $c->status->value }}</span>
            </div>
        @empty
            <p class="p-4 text-sm" style="color:var(--muted)">No SSO connections yet. Each organization configures its own connection to its IdP.</p>
        @endforelse
    </div>
</div>
