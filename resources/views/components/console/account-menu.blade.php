@props([
    'name',
    'email' => null,
    'initial',
    'logoutRoute',
])
{{-- The account menu that lives in the rail foot on every console plane (the shared
     shell puts identity at the bottom of tier 1, not in a separate sidebar footer).
     Extra plane-specific links go in the default slot, above Toggle theme / Sign out.

     Requires the shell's x-data to provide: account (bool). --}}
<button type="button" class="cbx-railitem" @click="account=!account" title="{{ $name }}" aria-haspopup="true" :aria-expanded="account">
    <span class="cbx-avatar" aria-hidden="true">{{ $initial }}</span>
    <span class="lbl" style="overflow:hidden;text-overflow:ellipsis">{{ $name }}</span>
</button>
<div x-show="account" x-transition.opacity.duration.150ms @click.outside="account=false" x-cloak
     class="cbx-panel" style="position:absolute;bottom:calc(100% + 8px);left:0;min-width:230px;z-index:75;box-shadow:var(--shadow-popover);padding:6px">
    <div style="padding:8px 10px;border-bottom:1px solid var(--border);margin-bottom:4px">
        <p style="font-size:13px;font-weight:600;margin:0" class="truncate">{{ $name }}</p>
        @if ($email !== null)
            <p style="font-size:12px;color:var(--muted-foreground);margin:2px 0 0" class="truncate">{{ $email }}</p>
        @endif
    </div>
    {{ $slot }}
    <button type="button" data-theme-toggle class="cbx-row" style="padding:8px 10px;border-radius:6px;gap:10px;font-size:13px">
        <x-icon name="moon" class="w-4 h-4" /> Toggle theme
    </button>
    <form method="POST" action="{{ route($logoutRoute) }}">@csrf
        <button type="submit" class="cbx-row" style="padding:8px 10px;border-radius:6px;gap:10px;font-size:13px;color:var(--destructive)">
            <x-icon name="logout" class="w-4 h-4" /> Sign out
        </button>
    </form>
</div>
