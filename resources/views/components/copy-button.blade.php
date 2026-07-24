@props([
    'value' => '',
    'label' => 'Copy',
    'copied' => 'Copied ✓',
])

{{--
    Copy-to-clipboard button. Uses an Alpine x-on:click handler (evaluated under the
    app's CSP 'unsafe-eval') rather than an inline onclick — inline event attributes are
    blocked by our `script-src 'self' 'unsafe-eval'` policy (no 'unsafe-inline'), so an
    onclick copy button silently does nothing. That is data loss on a one-time-shown
    secret: the user believes they copied a key that is never displayed again.
--}}
<button
    type="button"
    x-data="{ copied: false }"
    x-on:click="navigator.clipboard.writeText(@js($value)); copied = true; setTimeout(() => copied = false, 1500)"
    x-text="copied ? @js($copied) : @js($label)"
    {{ $attributes->merge(['class' => 'btn btn-sm shrink-0']) }}
>{{ $label }}</button>
