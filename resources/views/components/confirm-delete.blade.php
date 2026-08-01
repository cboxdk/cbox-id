@props([
    // What is being deleted, and the exact text the operator must type.
    'name',
    // The Livewire action to call once confirmed.
    'action',
    'label' => 'Delete',
    'consequence' => 'This cannot be undone.',
    // The verb on the confirm button and the dialog title, when it differs from the
    // trigger's own label ("Rotate secret" opens a dialog that confirms "Rotate").
    'verb' => null,
    // The trigger is not always a red button: it can be a ghost button with an icon
    // (secret rotation) or a row inside a dropdown menu (transfer ownership). Only the
    // chrome varies — the dialog, the typed name and the environment badge never do.
    'triggerClass' => 'btn btn-danger btn-sm',
    'triggerStyle' => null,
])

@php

    $id = 'confirm-'.md5($action.$name);
    $verb = $verb ?? $label;

    // Name the environment IN the dialog. The failure being designed against is an
    // admin with staging and production open in two visually identical tabs.
    $env = app(App\Platform\CurrentEnvironment::class)->get();
@endphp

{{--
    Type-to-confirm for irreversible actions.

    A native wire:confirm named neither the resource nor the environment, and Enter
    dismissed it — so the wrong tab deleted the wrong thing with one keystroke. There was
    no type-to-confirm anywhere in the console before this.

    The focus trap is hand-rolled to match components/mobile-nav.blade.php: the Alpine
    Focus plugin (x-trap) is NOT loaded in this app, so using it would have produced a
    dialog that silently failed to trap.

    The dialog is x-teleport'd to <body>. Several triggers live inside a dropdown that
    closes on click-outside; without the teleport, clicking into the dialog counted as
    outside, the menu hid, and the dialog — a descendant of it — vanished mid-type.

    WHEN TO USE THIS instead of a native wire:confirm. This is for an action that
    destroys a credential, revokes someone else's access, transfers ownership, or is
    otherwise not undoable from the console — where the cost of the wrong tab is real.
    A genuinely reversible toggle (pause/resume an endpoint, disable/re-enable a stream)
    keeps wire:confirm: making a two-way switch cost a typed name trains people to type
    the name without reading it, which is the failure this component exists to prevent.
    Actions on your OWN account (remove your passkey, turn off your own 2FA) also keep
    wire:confirm — the two-identical-tabs hazard does not apply and the environment
    badge would be meaningless there.
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
    {{-- Everything not consumed as a prop lands on the TRIGGER (title, aria-label,
         wire:target, …), which is the element the caller replaced. --}}
    <button
        type="button"
        {{ $attributes->merge(['class' => $triggerClass]) }}
        @if ($triggerStyle) style="{{ $triggerStyle }}" @endif
        @click="open = true; onOpen()"
    >{{ $slot->isEmpty() ? $label : $slot }}</button>

    <template x-teleport="body">
        <div
            x-show="open"
            x-cloak
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
                <h2 id="{{ $id }}-title" class="text-base font-semibold">{{ $verb }} {{ $name }}?</h2>

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
                    >{{ $verb }}</button>
                </div>
            </div>
        </div>
    </template>
</div>
