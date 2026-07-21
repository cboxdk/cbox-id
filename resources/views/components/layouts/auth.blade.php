@props(['title' => null])
@php
    use App\Platform\Appearance\Appearance;
    use App\Platform\Appearance\AppearanceCss;
    use Cbox\Id\Kernel\Tenancy\Contracts\EnvironmentContext;
    use Cbox\Id\Organization\Models\Environment;

    // The signing-in organization's brand (shared as `cboxBrand` when reached via its
    // slug). Its settings can OVERRIDE the environment's default theme.
    $brand = $cboxBrand ?? null;
    $orgSettings = is_array($brand['settings'] ?? null) ? $brand['settings'] : null;

    // The environment's DEFAULT theme — applies to every sign-in on this host unless
    // an organization overrides it. Resolved from the host-pinned environment.
    $envKey = app(EnvironmentContext::class)->current()?->environmentKey();
    $env = $envKey !== null ? Environment::query()->find($envKey) : null;
    $envSettings = is_array($env?->settings) ? $env->settings : null;

    // env default → org override; null when neither customized (platform default stands).
    $effective = Appearance::effective($orgSettings, $envSettings);
    $appearanceCss = $effective !== null ? AppearanceCss::render($effective) : null;

    $brandName = is_array($brand) && is_string($brand['name'] ?? null) ? $brand['name'] : null;
    // Logo: the org's, else the environment's.
    $orgLogo = is_array($orgSettings) && is_string($orgSettings['brand_logo_url'] ?? null) ? $orgSettings['brand_logo_url'] : null;
    $envLogo = is_array($envSettings) && is_string($envSettings['brand_logo_url'] ?? null) ? $envSettings['brand_logo_url'] : null;
    $brandLogo = $orgLogo ?? $envLogo ?? (is_array($brand) && is_string($brand['logo'] ?? null) ? $brand['logo'] : null);
@endphp
<!DOCTYPE html>
<html lang="en" class="h-full">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <link rel="icon" href="/brand/favicon.svg" type="image/svg+xml">
    <link rel="icon" href="/brand/favicon.ico" sizes="any">
    <link rel="apple-touch-icon" href="/brand/cbox-icon-128.png">
    <meta name="theme-color" content="#ffffff" media="(prefers-color-scheme: light)">
    <meta name="theme-color" content="#0b0b0b" media="(prefers-color-scheme: dark)">
    <title>{{ ($brandName ?? config('cbox-id.branding.name', 'Cbox ID')) === null ? '' : (($title ? $title.' · ' : '').($brandName ?? config('cbox-id.branding.name', 'Cbox ID'))) }}</title>
    @vite(['resources/css/app.css', 'resources/js/app.js'])
    {{-- The organization's custom sign-in theme (Theme Editor). Coherent token
         override for light + dark; absent → the platform default stands. --}}
    @if ($appearanceCss){!! $appearanceCss !!}@endif
    {{-- Full per-tenant palette when the whitelabel plugin is installed; inert otherwise. --}}
    @consoleBrandingStyle
</head>
<body class="h-full" style="color:var(--text)">
    <x-sandbox-banner />
    <div class="min-h-full grid lg:grid-cols-[1fr_minmax(0,44%)] xl:grid-cols-[1fr_minmax(0,40%)]">
        <div class="auth-shell flex flex-col justify-center px-6 py-12 sm:px-12">
            <div class="mx-auto w-full" style="max-width:24rem">
                @if ($brandLogo)
                    <img src="{{ $brandLogo }}" alt="{{ $brandName }}" style="max-height:2.25rem;max-width:12rem">
                @else
                    <a href="{{ url('/') }}" class="inline-block"><x-brand /></a>
                @endif

                <div class="mt-9">
                    {{ $slot }}
                </div>

                <div class="mt-10 flex items-center justify-between text-xs" style="color:var(--faint)">
                    <span class="inline-flex items-center gap-1.5">
                        <x-icon name="shield" class="w-3.5 h-3.5" /> Secured by {{ config('cbox-id.branding.name', 'Cbox ID') }}
                    </span>
                    <button type="button" data-theme-toggle aria-label="Toggle light or dark theme"
                            class="inline-flex items-center gap-1.5 rounded-md px-2 py-1 transition hover:opacity-80" style="border:1px solid var(--border)">
                        <x-icon name="sun" class="w-3.5 h-3.5" /> Theme
                    </button>
                </div>
            </div>
        </div>

        <div class="auth-hero hidden lg:flex flex-col justify-between p-12 overflow-hidden">
            <x-brand compact class="opacity-95" />
            <div class="max-w-md">
                <h2 class="font-semibold tracking-tight leading-[1.1]" style="font-size:2.15rem">{{ $brandName ? 'Sign in to '.$brandName.'.' : config('cbox-id.branding.tagline', 'One identity layer for every app you ship.') }}</h2>
                <p class="mt-4 text-sm leading-relaxed" style="opacity:0.82">
                    Enterprise SSO, SCIM directory sync, MFA and passkeys, RBAC, and a
                    tamper-evident audit trail — self-hostable, and yours.
                </p>
                <ul class="mt-9 space-y-3.5">
                    <li class="hero-feature"><span class="tick"><x-icon name="check" class="w-3.5 h-3.5" /></span> SAML &amp; OIDC single sign-on</li>
                    <li class="hero-feature"><span class="tick"><x-icon name="check" class="w-3.5 h-3.5" /></span> SCIM 2.0 directory provisioning</li>
                    <li class="hero-feature"><span class="tick"><x-icon name="check" class="w-3.5 h-3.5" /></span> Passkeys, TOTP, and magic links</li>
                    <li class="hero-feature"><span class="tick"><x-icon name="check" class="w-3.5 h-3.5" /></span> Hash-chained, tamper-evident audit</li>
                </ul>
            </div>
            <div class="flex items-center gap-4 text-xs" style="opacity:0.72">
                <span>© {{ date('Y') }} {{ config('cbox-id.branding.name', 'Cbox ID') }}</span>
                @if (($trust = config('cbox-id.branding.trust_line')) !== '')
                    <span aria-hidden="true">·</span>
                    <span>{{ $trust }}</span>
                @endif
            </div>
        </div>
    </div>
    <x-toast />
</body>
</html>
