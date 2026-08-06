@props(['title' => null])

{{--
    One layout name for every capability page, so one component can serve both planes.

    A Volt component declares its layout statically, which is why the console was built
    twice: a page that had to render inside the account chrome on one plane and the
    environment chrome on the other could not be the same page. That is a rendering
    detail, and it forced thirteen capabilities to be written twice — with the drift that
    followed.

    So the chrome is chosen here, from the same ConsoleScope the page itself uses, and
    the page declares this. Both layouts already take the identical contract (a title and
    a slot), which is what makes the delegation a three-line file rather than a merge.
--}}

@php
    $plane = app(\App\Platform\Console\ConsoleScope::class)->plane();
@endphp

@if ($plane === \App\Platform\Console\ConsolePlane::Environment)
    <x-layouts.environment :title="$title">{{ $slot }}</x-layouts.environment>
@else
    <x-layouts.app :title="$title">{{ $slot }}</x-layouts.app>
@endif
