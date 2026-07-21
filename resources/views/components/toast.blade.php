{{--
    The console's single confirmation surface.

    Livewire does NOT re-render the layout on an action round-trip, so a flash written by
    a non-redirecting action displayed nothing at all — and, because a flash survives to
    the next request, it later surfaced on an unrelated page. 63 components flashed;
    only 7 rendered it themselves.

    This lives in the layout and listens for a browser event, so a component announces a
    result with `$this->dispatch('toast', ...)` and it appears immediately, in place,
    without the component owning any markup.

    It still renders `session('status')` on first paint, so a redirect-then-flash (sign-in,
    invitation acceptance) keeps working unchanged.
--}}
<div
    x-data="{
        toasts: [],
        push(detail) {
            const id = Date.now() + Math.random();
            this.toasts.push({ id, message: detail.message, severity: detail.severity ?? 'success' });
            setTimeout(() => this.dismiss(id), detail.severity === 'error' ? 8000 : 4500);
        },
        dismiss(id) { this.toasts = this.toasts.filter(t => t.id !== id); },
    }"
    @toast.window="push($event.detail)"
    class="pointer-events-none fixed inset-x-0 bottom-0 z-50 flex flex-col items-center gap-2 p-4 sm:items-end"
    style="padding-bottom:calc(1rem + env(safe-area-inset-bottom))"
>
    @if (session('status'))
        <div class="cbx-toast" data-severity="success" role="status" aria-live="polite">{{ session('status') }}</div>
    @endif

    @if (session('error'))
        <div class="cbx-toast" data-severity="error" role="alert" aria-live="assertive">{{ session('error') }}</div>
    @endif

    <template x-for="toast in toasts" :key="toast.id">
        {{--
            Severity drives BOTH the colour and the politeness. An error announced through
            role="status" is read politely and framed as confirmation — which is exactly
            how "an organization must keep at least one owner" used to render: green, with
            a checkmark, for an action that failed.
        --}}
        <div
            class="cbx-toast pointer-events-auto"
            :data-severity="toast.severity"
            :role="toast.severity === 'error' ? 'alert' : 'status'"
            :aria-live="toast.severity === 'error' ? 'assertive' : 'polite'"
            x-transition:enter="transition ease-out duration-200"
            x-transition:enter-start="opacity-0 translate-y-2"
            x-transition:leave="transition ease-in duration-150"
            x-transition:leave-end="opacity-0"
        >
            <span x-text="toast.message"></span>
            <button type="button" class="cbx-toast-close" @click="dismiss(toast.id)" aria-label="Dismiss notification">&times;</button>
        </div>
    </template>
</div>
