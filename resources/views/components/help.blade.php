@props([
    // A HelpTopic, or its string value.
    'topic',
    // Renders a text trigger ("What's this?") instead of the round ? button. Use it
    // next to a form field, where a floating icon has nothing to sit against.
    'label' => null,
    // Which side of the trigger the panel opens on. 'end' pins its right edge to the
    // trigger — correct for a trigger sitting at the right of a row.
    'align' => 'start',
])
@php
    use App\Platform\Help\DocsLinks;
    use App\Platform\Help\HelpTopic;

    $topic = $topic instanceof HelpTopic ? $topic : HelpTopic::from($topic);
    $url = app(DocsLinks::class)->url($topic);
    $panelId = 'help-'.$topic->value.'-'.\Illuminate\Support\Str::random(6);
@endphp
{{-- The console's explanation affordance: a quiet trigger that opens two or three
     plain sentences about the concept in front of you, plus a link to the full guide
     when one exists.

     Self-contained x-data on purpose — this lands inside page headers, empty states,
     table rows and modals, none of which share a wrapper scope. Escape and a click
     outside both close it, and the trigger keeps aria-expanded in sync so a screen
     reader is told the panel opened. --}}
<span x-data="{ open: false }" @keydown.escape.window="open = false" class="cbx-help">
    @if ($label !== null)
        <button type="button" class="cbx-help-link" @click="open = !open"
                :aria-expanded="open" aria-controls="{{ $panelId }}">{{ $label }}</button>
    @else
        <button type="button" class="cbx-help-btn" @click="open = !open"
                :aria-expanded="open" aria-controls="{{ $panelId }}"
                aria-label="What is {{ $topic->title() }}?" title="What is this?">
            <x-icon name="help" class="w-4 h-4" />
        </button>
    @endif

    <span x-show="open" x-transition.opacity.duration.150ms @click.outside="open = false" x-cloak
          {{-- A disclosure, not a dialog. It never takes focus, traps nothing and restores
               nothing, so role="dialog" made NVDA and JAWS treat it as something it is
               not. The button already owns it via aria-controls + aria-expanded, which
               is the correct relationship for a panel that just appears. --}}
          id="{{ $panelId }}"
          class="cbx-panel cbx-help-panel {{ $align === 'end' ? 'is-end' : '' }}">
        <span class="cbx-help-title">{{ $topic->title() }}</span>
        <span class="cbx-help-body">{{ $topic->summary() }}</span>
        @if ($url !== null)
            <a href="{{ $url }}" target="_blank" rel="noopener noreferrer" class="cbx-help-more">
                Read the guide
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true" style="width:13px;height:13px"><path d="M5 12h14M13 6l6 6-6 6"/></svg>
                <span class="sr-only">(opens in a new tab)</span>
            </a>
        @endif
    </span>
</span>
