@props(['title' => null])
<!DOCTYPE html>
<html lang="en" class="h-full">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <title>{{ $title ? $title.' · Cbox ID' : 'Cbox ID' }}</title>
    @vite(['resources/css/app.css', 'resources/js/app.js'])
</head>
<body class="h-full" style="background:var(--bg);color:var(--text)">
    <div class="min-h-full grid lg:grid-cols-2">
        <div class="flex flex-col justify-center px-6 py-12 sm:px-12">
            <div class="mx-auto w-full" style="max-width:24rem">
                <a href="{{ url('/') }}" class="inline-block"><x-brand /></a>

                <div class="mt-10">
                    {{ $slot }}
                </div>

                <p class="mt-10 text-xs" style="color:var(--faint)">
                    Protected by Cbox ID · <button type="button" data-theme-toggle class="underline underline-offset-2">toggle theme</button>
                </p>
            </div>
        </div>

        <div class="hidden lg:flex flex-col justify-between p-12 relative overflow-hidden"
             style="background:linear-gradient(160deg,var(--accent) 0%, color-mix(in srgb, var(--accent) 70%, #000) 100%);color:var(--accent-fg)">
            <x-brand compact class="opacity-90" />
            <div class="max-w-md">
                <h2 class="text-3xl font-semibold tracking-tight leading-tight">One identity layer for every app you ship.</h2>
                <p class="mt-4 text-sm opacity-80 leading-relaxed">
                    Enterprise SSO, SCIM directory sync, MFA and passkeys, RBAC, and a
                    tamper-evident audit trail — self-hostable, and yours.
                </p>
                <ul class="mt-8 space-y-2.5 text-sm opacity-90">
                    <li class="flex items-center gap-2"><x-icon name="check" class="w-4 h-4" /> SAML &amp; OIDC single sign-on</li>
                    <li class="flex items-center gap-2"><x-icon name="check" class="w-4 h-4" /> SCIM 2.0 directory provisioning</li>
                    <li class="flex items-center gap-2"><x-icon name="check" class="w-4 h-4" /> Passkeys, TOTP, and magic links</li>
                    <li class="flex items-center gap-2"><x-icon name="check" class="w-4 h-4" /> Hash-chained, tamper-evident audit</li>
                </ul>
            </div>
            <p class="text-xs opacity-70">© {{ date('Y') }} Cbox</p>
        </div>
    </div>
</body>
</html>
