@props([
    // What is being deleted, and the exact text the operator must type.
    'name',
    // The Livewire action to call once confirmed.
    'action',
    'label' => 'Delete',
    'consequence' => 'This cannot be undone.',
])

@php
    use Cbox\Id\Kernel\Tenancy\Contracts\EnvironmentContext;
    use Cbox\Id\Organization\Models\Environment;

    $id = 'confirm-'.md5($action.$name);

    // Name the environment IN the dialog. The failure being designed against is an
    // admin with staging and production open in two visually identical tabs.
    $key = app(EnvironmentContext::class)->current()?->environmentKey();
    $env = $key === null ? null : Environment::query()->whereKey($key)->first(['name', 'type']);
@endphp

{{--
    Type-to-confirm for irreversible actions.

    A native wire:confirm named neither the resource nor the environment, and Enter
    dismissed it — so the wrong tab deleted the wrong thing with one keystroke. There was
    no type-to-confirm anywhere in the console before this.

    The focus trap is hand-rolled to match components/mobile-nav.blade.php: the Alpine
    Focus plugin (x-trap) is NOT loaded in this app, so using it would have produced a
    dialog that silently failed to trap.
--}}
<div
    x-data="{
        open: false,
        typed: '',
        expected: @js($name),
        prevFocus: null,
        onOpen() {
            this.prevFocus = document.activeElement;
            document.documentElement.style.overflow = 'hidden';
            this.typed = '';
            this.$nextTick(() => this.$refs.field && this.$refs.field.focus());
        },
        onClose() {
            document.documentElement.style.overflow = '';
            if (this.prevFocus && this.prevFocus.focus) this.prevFocus.focus();
            this.prevFocus = null;
        },
        trap(e) {
            const f = [...this.$refs.panel.querySelectorAll('button,input,[href],[tabindex]:not([tabindex=\'-1\'])')]
                .filter(el => !el.disabled && el.offsetParent !== null);
            if (!f.length) return;
            const first = f[0], last = f[f.length - 1];
            if (e.shiftKey && document.activeElement === first) { e.preventDefault(); last.focus(); }
            else if (!e.shiftKey && document.activeElement === last) { e.preventDefault(); first.focus(); }
        },
    }"
    @keydown.escape.window="if (open) { open = false; onClose(); }"
>
    <button type="button" class="btn btn-danger btn-sm" @click="open = true; onOpen()">{{ $label }}</button>

    <template x-if="open">
        <div
            class="fixed inset-0 z-50 flex items-end sm:items-center justify-center p-4"
            style="background:color-mix(in oklch, var(--foreground) 45%, transparent)"
            @click.self="open = false; onClose()"
        >
            <div
                x-ref="panel"
                role="dialog"
                aria-modal="true"
                aria-labelledby="{{ $id }}-title"
                aria-describedby="{{ $id }}-desc"
                class="card w-full max-w-md p-5"
                @keydown.tab="trap($event)"
            >
                <h2 id="{{ $id }}-title" class="text-base font-semibold">{{ $label }} {{ $name }}?</h2>

                <p id="{{ $id }}-desc" class="mt-2 text-sm" style="color:var(--muted)">
                    {{ $consequence }}
                    @if ($env !== null)
                        You are acting in <strong>{{ $env->name }}</strong>
                        <x-env-badge />.
                    @endif
                </p>

                <label for="{{ $id }}-input" class="label mt-4 block">
                    Type <code>{{ $name }}</code> to confirm
                </label>
                <input
                    id="{{ $id }}-input"
                    x-ref="field"
                    x-model="typed"
                    type="text"
                    class="input mt-1"
                    autocomplete="off"
                    spellcheck="false"
                    :aria-invalid="typed !== '' && typed !== expected"
                />

                <div class="mt-5 flex justify-end gap-2">
                    <button type="button" class="btn btn-ghost" @click="open = false; onClose()">Cancel</button>
                    {{-- Disabled until the name matches exactly: the point is that muscle
                         memory (Enter, Enter) cannot complete this. --}}
                    <button
                        type="button"
                        class="btn btn-danger"
                        :disabled="typed !== expected"
                        wire:click="{{ $action }}"
                        wire:loading.attr="disabled"
                        @click="open = false; onClose()"
                    >{{ $label }}</button>
                </div>
            </div>
        </div>
    </template>
</div>
