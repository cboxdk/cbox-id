@props([
    'roles',           // Collection<Role> — the assignable catalog
    'appNames' => [],  // clientId => app name
    'permsByRole' => [], // roleId => list<string> permission names
    'assigned' => [],  // list<string> role ids the subject currently holds
    'toggle',          // Livewire method name: fn(arg, roleId) => void
    'arg',             // the fixed first argument (member/subject id, or org id)
    'subject' => 'this member',
])

{{--
    Immediate-toggle RBAC access-role editor for one subject in one org. Each role is
    grouped org-wide vs per-app, shows the permissions it grants (so an admin sees the
    real effect), and checking/unchecking assigns/revokes on the spot.
--}}
<p class="text-xs mb-3" style="color:var(--muted)">Access roles for <b style="color:var(--foreground)">{{ $subject }}</b> — these ride in the app tokens; the app enforces what each one can do.</p>

@foreach ($roles->groupBy(fn ($r) => $r->client_id ?? '__org') as $groupKey => $group)
    <p class="text-xs font-semibold uppercase mb-1.5 mt-1" style="color:var(--muted);letter-spacing:0.05em">{{ $groupKey === '__org' ? 'Org roles' : ($appNames[$groupKey] ?? $groupKey) }}</p>
    <div class="grid gap-1.5 sm:grid-cols-2 lg:grid-cols-3 mb-3">
        @foreach ($group as $r)
            @php $grants = $permsByRole[$r->id] ?? []; @endphp
            <label class="flex flex-col gap-1 text-sm rounded-lg px-2.5 py-1.5 cursor-pointer" style="border:1px solid var(--border);background:var(--card)" title="{{ implode(', ', $grants) }}">
                <span class="flex items-center gap-2">
                    <input type="checkbox" @checked(in_array($r->id, $assigned, true)) wire:click="{{ $toggle }}('{{ $arg }}', '{{ $r->id }}')">
                    <span class="min-w-0 flex-1 truncate" style="color:var(--foreground)">{{ $r->name }}</span>
                    <span class="badge mono" style="font-size:10px">{{ $r->key ?? 'org' }}</span>
                </span>
                <span class="text-xs truncate" style="color:var(--faint)">{{ count($grants) > 0 ? implode(' · ', array_slice($grants, 0, 4)).(count($grants) > 4 ? ' +'.(count($grants) - 4) : '') : 'No permissions' }}</span>
            </label>
        @endforeach
    </div>
@endforeach
