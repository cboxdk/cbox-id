@php

    // Which realm am I acting in? The environment console renders identically for
    // staging and production apart from a name in the sidebar — and the breadcrumb that
    // carries it is `hidden lg:flex`, so below that breakpoint there was NO indication
    // at all. Two tabs, one staging and one production, were indistinguishable at the
    // moment of hitting Delete.
    // `type` is an EnvironmentType enum (the model casts it), so read its backing
    // value rather than stringifying the object.
    $type = app(App\Platform\CurrentEnvironment::class)->type();
@endphp

@if ($type !== null)
    <span
        class="cbx-env-badge"
        data-env-type="{{ $type }}"
        {{-- Announced, not merely coloured: colour alone is not an indicator (SC 1.4.1). --}}
        title="{{ ucfirst($type) }} environment"
    >{{ strtoupper($type) }}</span>
@endif
