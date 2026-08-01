@props([
    'icon',
    'title',
    // One or two sentences: what this page is for, in the words of someone who has
    // not read the docs. Optional when a help topic carries the explanation.
    'body' => null,
    // A HelpTopic (or its string value) — adds "?" beside the title and, where a
    // guide exists, the link to it.
    'help' => null,
    // The numbered "what to do" list. Keep it to three or four short steps.
    'steps' => [],
])
{{-- An empty state is the first thing an administrator sees on a page they have
     never used, which makes it the most-read explanation in the console — and "No
     connections yet" tells someone who does not already know what a connection is
     precisely nothing. So: what it is, what it gets you, the steps, and the button
     that starts step one. --}}
<div {{ $attributes->merge(['class' => 'cbx-empty']) }}>
    <div class="cbx-empty-icon"><x-icon :name="$icon" class="w-5 h-5" /></div>

    <h3 class="flex items-center gap-1">
        {{ $title }}
        @if ($help !== null)
            <x-help :topic="$help" />
        @endif
    </h3>

    @if ($body !== null)
        <p>{{ $body }}</p>
    @endif

    @if ($steps !== [])
        <ol class="cbx-empty-steps">
            @foreach ($steps as $step)
                <li>{{ $step }}</li>
            @endforeach
        </ol>
    @endif

    @isset($actions)
        <div class="cbx-empty-actions">{{ $actions }}</div>
    @endisset
</div>
