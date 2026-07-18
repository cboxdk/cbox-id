<?php
declare(strict_types=1);
use Cbox\Id\AccessControl\Models\Role;
use Livewire\Attributes\Layout;
use Livewire\Volt\Component;
/** Environment control plane › Roles. The RBAC roles defined across the environment
 *  (env-scoped) — app-declared and custom. */
new #[Layout('components.layouts.environment', ['title' => 'Roles'])] class extends Component {
    public function with(): array { return ['roles' => Role::query()->orderBy('name')->limit(200)->get()]; }
}; ?>
<div>
    <h1 class="font-semibold tracking-tight" style="font-size:1.5rem">Roles</h1>
    <p class="mt-1 text-sm" style="color:var(--muted)">The roles your apps and organizations assign to users for access control.</p>
    <div class="mt-6 rounded-xl border overflow-hidden" style="border-color:var(--border)">
        @forelse ($roles as $r)
            <div class="flex items-center gap-3 p-4 {{ ! $loop->last ? 'border-b' : '' }}" style="border-color:var(--border)">
                <div class="min-w-0 flex-1"><span class="font-medium truncate">{{ $r->name }}</span>
                    <p class="text-sm truncate" style="color:var(--muted)">{{ $r->description ?? $r->key }}</p></div>
                <span class="text-xs rounded-full px-2 py-0.5" style="background:var(--surface-2);color:var(--muted)">{{ $r->source->value }}</span>
            </div>
        @empty
            <p class="p-4 text-sm" style="color:var(--muted)">No roles defined yet.</p>
        @endforelse
    </div>
</div>
