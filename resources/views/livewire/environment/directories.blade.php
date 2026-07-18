<?php
declare(strict_types=1);
use Cbox\Id\Directory\Models\Directory;
use Livewire\Attributes\Layout;
use Livewire\Volt\Component;
/** Environment control plane › Directories. SCIM/pull directory syncs across the
 *  environment's organizations (env-scoped). */
new #[Layout('components.layouts.environment', ['title' => 'Directories'])] class extends Component {
    public function with(): array { return ['directories' => Directory::query()->orderByDesc('id')->limit(100)->get()]; }
}; ?>
<div>
    <h1 class="font-semibold tracking-tight" style="font-size:1.5rem">Directories</h1>
    <p class="mt-1 text-sm" style="color:var(--muted)">Directory sync (SCIM push, or Google/Entra pull) keeps users provisioned automatically.</p>
    <div class="mt-6 rounded-xl border overflow-hidden" style="border-color:var(--border)">
        @forelse ($directories as $d)
            <div class="flex items-center gap-3 p-4 {{ ! $loop->last ? 'border-b' : '' }}" style="border-color:var(--border)">
                <div class="min-w-0 flex-1"><span class="font-medium truncate">{{ $d->name }}</span>
                    <p class="text-sm truncate" style="color:var(--muted)">{{ $d->provider->label() }}</p></div>
                <span class="text-xs rounded-full px-2 py-0.5" style="background:var(--surface-2);color:var(--muted)">{{ $d->status->value }}</span>
            </div>
        @empty
            <p class="p-4 text-sm" style="color:var(--muted)">No directories connected yet.</p>
        @endforelse
    </div>
</div>
