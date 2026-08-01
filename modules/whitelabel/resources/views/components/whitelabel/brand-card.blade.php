@php
    /** @var \Cbox\Console\Kit\Branding\Branding $branding */
    $tokens = $branding->tokens();
    $swatch = $tokens['--primary'] ?? ($tokens['--accent'] ?? null);
    $isCustom = ! $branding->isEmpty();
@endphp
<div class="cbx-stat" style="align-items:flex-start">
    <span class="cbx-stat-icon {{ $isCustom ? '' : 'cbx-stat-icon--neutral' }}" aria-hidden="true"
          @if ($swatch) style="background:{{ $swatch }};color:var(--accent-foreground)" @endif>
        <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 20 20" fill="currentColor" class="h-5 w-5">
            <path d="M10 2a8 8 0 0 0 0 16 2 2 0 0 0 2-2c0-.5-.2-1-.5-1.3-.3-.4-.5-.8-.5-1.2a1 1 0 0 1 1-1h1.2A4.8 4.8 0 0 0 18 8.6C18 4.9 14.4 2 10 2Zm-4 8a1.2 1.2 0 1 1 0-2.4A1.2 1.2 0 0 1 6 10Zm2.6-3.4a1.2 1.2 0 1 1 0-2.4 1.2 1.2 0 0 1 0 2.4Zm4.8 0a1.2 1.2 0 1 1 0-2.4 1.2 1.2 0 0 1 0 2.4Z" />
        </svg>
    </span>
    <div style="flex:1">
        <p class="cbx-stat-label" style="margin-top:0">Branding</p>
        <p class="cbx-stat-value" style="font-size:16px">
            {{ $branding->appName ?? 'Cbox ID default' }}
        </p>
        <p class="cbx-stat-label" style="margin-top:4px">
            {{ $isCustom ? count($tokens).' custom '.\Illuminate\Support\Str::plural('colour', count($tokens)) : 'Using the default theme' }}
        </p>
        @if (\Illuminate\Support\Facades\Route::has('whitelabel.branding'))
            <a href="{{ route('whitelabel.branding') }}" class="btn btn-ghost btn-sm" style="margin-top:10px">
                {{ $isCustom ? 'Edit branding' : 'Customize' }}
            </a>
        @endif
    </div>
</div>
