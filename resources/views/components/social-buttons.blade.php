@props(['organizationId' => null])

@php
    /*
     * Two sources, deliberately, because they answer different questions.
     *
     * The OPERATOR's providers (config `services.*`) are the platform's own, offered on
     * every sign-in page in the deployment. A TENANT's are its own credentials with
     * Google or GitHub, set up from its console, and belong only on its branded page.
     *
     * Where both exist for the same provider the tenant's wins. The accounts people end
     * up with should sit with the tenant that invited them, and a button that quietly
     * used the platform's credentials instead of theirs would put those accounts on the
     * wrong side of that line — invisibly, and only discovered later.
     *
     * The marks moved to <x-provider-mark>: the console's catalogue shows the same
     * eleven providers, and an administrator who picks "GitHub" in one list has to
     * recognise it in the other.
     */
    $tenant = [];

    if (is_string($organizationId) && $organizationId !== '') {
        foreach (app(\Cbox\Id\Federation\Contracts\Connections::class)->catalogueProvidersFor($organizationId) as $connection) {
            $tenant[(string) $connection->provider] = [
                'label' => $connection->name,
                'url' => \App\Platform\SsoStart::url($connection),
            ];
        }
    }

    $providers = $tenant;

    foreach (\App\Platform\SocialProviders::configured() as $key => $label) {
        if (! array_key_exists($key, $providers)) {
            $providers[$key] = ['label' => $label, 'url' => route('social.redirect', $key)];
        }
    }
@endphp

@if ($providers !== [])
    <div {{ $attributes->merge(['class' => 'space-y-2.5']) }}>
        @foreach ($providers as $key => $provider)
            <a href="{{ $provider['url'] }}" class="btn btn-ghost w-full" wire:key="social-{{ $key }}">
                <x-provider-mark :provider="$key" />
                <span>Continue with {{ $provider['label'] }}</span>
            </a>
        @endforeach
    </div>
@endif
