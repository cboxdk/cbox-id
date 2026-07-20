@props([
    'roles',           // Collection<Role> — the assignable catalog
    'appNames' => [],  // clientId => app name
    'model',           // wire:model target holding the checked role ids
    'label' => 'Access roles',
    'hint' => 'optional',
])

{{--
    Grouped RBAC access-role checkboxes bound to a Livewire array (add-member / invite
    forms). Roles are grouped org-wide vs per declaring app. Renders nothing when the
    environment has no assignable roles yet.
--}}
@if ($roles->isNotEmpty())
    <div>
        <span class="label">{{ $label }} <span style="color:var(--faint);font-weight:400">— {{ $hint }}</span></span>
        @foreach ($roles->groupBy(fn ($r) => $r->client_id ?? '__org') as $groupKey => $group)
            <p class="text-xs font-semibold uppercase mb-1.5 mt-1" style="color:var(--muted);letter-spacing:0.05em">{{ $groupKey === '__org' ? 'Org roles' : ($appNames[$groupKey] ?? $groupKey) }}</p>
            <div class="grid gap-1.5 sm:grid-cols-2 mb-2">
                @foreach ($group as $r)
                    <label class="flex items-center gap-2 text-sm rounded-lg px-2.5 py-1.5 cursor-pointer" style="border:1px solid var(--border);background:var(--card)">
                        <input type="checkbox" wire:model="{{ $model }}" value="{{ $r->id }}" class="rounded">
                        <span class="min-w-0 flex-1 truncate" style="color:var(--foreground)">{{ $r->name }}</span>
                        <span class="badge mono" style="font-size:10px">{{ $r->key ?? 'org' }}</span>
                    </label>
                @endforeach
            </div>
        @endforeach
    </div>
@endif
