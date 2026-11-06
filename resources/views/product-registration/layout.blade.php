<!doctype html>
<html lang="{{ app()->getLocale() }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="referrer" content="no-referrer">
    <title>{{ __('registration.page_title') }} · {{ config('service-access.name') }}</title>
    <link rel="icon" href="/favicon.ico">
    <link rel="stylesheet" href="{{ asset('registration/style.css').'?v='.filemtime(public_path('registration/style.css')) }}">
    <script src="{{ asset('registration/combobox.js').'?v='.filemtime(public_path('registration/combobox.js')) }}" type="module"></script>
</head>
<body>
<a class="skip" href="#registration">{{ __('registration.skip') }}</a>
<div class="registration-shell">
    <header class="registration-header">
        <a class="registration-brand" href="/" aria-label="{{ config('service-access.name') }}">
            <img src="{{ asset('registration/cbox-logo.png') }}" alt="Cbox" width="112" height="34">
            <span aria-hidden="true">/</span><strong>{{ config('service-access.short_name') }}</strong>
        </a>
        <a href="{{ route('product-signup.signin') }}">{{ __('registration.sign_in') }} <span aria-hidden="true">↗</span></a>
    </header>
    <main class="registration-grid">
        <section class="registration-story" aria-labelledby="product-heading">
            @include('product-registration.story')
        </section>
        <section class="registration-panel" id="registration" aria-labelledby="registration-heading">
            @yield('content')
        </section>
    </main>
    <footer class="registration-footer"><span>{{ config('service-access.name') }}</span><span>{{ __('registration.footer') }}</span></footer>
</div>
</body>
</html>
