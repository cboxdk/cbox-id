@props(['title' => null])
<!DOCTYPE html>
<html lang="en" class="h-full">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <title>{{ ($title ? $title.' · ' : '').config('cbox-id.branding.name', 'Cbox ID') }}</title>
    @vite(['resources/css/app.css', 'resources/js/app.js'])
    {{-- Per-tenant console branding when the whitelabel plugin is installed; inert otherwise. --}}
    @consoleBrandingStyle
</head>
<body class="h-full" style="background:var(--bg);color:var(--text)">
    <a href="#main-content" class="skip-link">Skip to content</a>
    <div class="min-h-full flex flex-col">
        <header class="h-16 flex items-center justify-between px-5 sm:px-8 border-b" style="border-color:var(--border);background:var(--surface)">
            <x-brand />
            <div class="flex items-center gap-3">
                <span class="hidden sm:inline-flex items-center gap-1.5 text-xs" style="color:var(--faint)">
                    <x-icon name="shield" class="w-3.5 h-3.5" /> Admin setup portal
                </span>
                <button type="button" data-theme-toggle class="btn btn-ghost" style="padding:0.4rem" aria-label="Toggle theme">
                    <x-icon name="sun" class="w-[1.1rem] h-[1.1rem]" />
                </button>
            </div>
        </header>

        <main id="main-content" class="flex-1 w-full mx-auto px-5 sm:px-8 py-8 sm:py-12" style="max-width:46rem">
            {{ $slot }}
        </main>

        <footer class="px-5 sm:px-8 py-5 border-t text-xs flex items-center justify-between" style="border-color:var(--border);color:var(--faint)">
            <span class="inline-flex items-center gap-1.5"><x-icon name="shield" class="w-3.5 h-3.5" /> Secured by {{ config('cbox-id.branding.name', 'Cbox ID') }}</span>
            <span>© {{ date('Y') }}</span>
        </footer>
    </div>
    <x-toast />
</body>
</html>
