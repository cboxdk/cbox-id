@props([
    'value' => '',
    'label' => 'Copy',
    'copied' => 'Copied ✓',
    'failed' => 'Copy failed — select and copy manually',
])

{{--
    Copy-to-clipboard button. Uses an Alpine x-on:click handler (evaluated under the
    app's CSP 'unsafe-eval') rather than an inline onclick — inline event attributes are
    blocked by our `script-src 'self' 'unsafe-eval'` policy (no 'unsafe-inline'), so an
    onclick copy button silently does nothing. That is data loss on a one-time-shown
    secret: the user believes they copied a key that is never displayed again.

    The same reasoning applies one level deeper, which is what this component used to get
    wrong. `writeText()` returns a PROMISE, and the handler set `copied = true`
    synchronously and unconditionally — so on a non-secure context, a denied permission or
    any rejected write, the button said "Copied ✓" over an empty clipboard. Exactly the
    failure the paragraph above describes, arrived at by a different route.

    The result is also announced: swapping the button's own label via x-text is not
    something assistive technology reliably re-reads, so a sr-only live region carries it.
--}}
<span x-data="{ state: null }" class="inline-flex items-center gap-2">
    <button
        type="button"
        x-on:click="
            navigator.clipboard?.writeText(@js($value))
                .then(() => state = 'ok')
                .catch(() => state = 'fail')
                ?? (state = 'fail');
            setTimeout(() => state = null, 2500)
        "
        x-text="state === 'ok' ? @js($copied) : (state === 'fail' ? @js($failed) : @js($label))"
        {{ $attributes->merge(['class' => 'btn btn-sm shrink-0']) }}
    >{{ $label }}</button>

    <span class="sr-only" role="status" aria-live="polite"
        x-text="state === 'ok' ? @js($copied) : (state === 'fail' ? @js($failed) : '')"></span>
</span>
