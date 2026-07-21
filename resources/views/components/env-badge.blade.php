@php
    use Cbox\Id\Kernel\Tenancy\Contracts\EnvironmentContext;
    use Cbox\Id\Organization\Models\Environment;

    // Which realm am I acting in? The environment console renders identically for
    // staging and production apart from a name in the sidebar — and the breadcrumb that
    // carries it is `hidden lg:flex`, so below that breakpoint there was NO indication
    // at all. Two tabs, one staging and one production, were indistinguishable at the
    // moment of hitting Delete.
    // `type` is an EnvironmentType enum (the model casts it), so read its backing
    // value rather than stringifying the object.
    $key = app(EnvironmentContext::class)->current()?->environmentKey();
    $environment = $key === null ? null : Environment::query()->whereKey($key)->first(['type']);
    $type = $environment?->type instanceof BackedEnum ? (string) $environment->type->value : null;
@endphp

@if ($type !== null)
    <span
        class="cbx-env-badge"
        data-env-type="{{ $type }}"
        {{-- Announced, not merely coloured: colour alone is not an indicator (SC 1.4.1). --}}
        title="{{ ucfirst($type) }} environment"
    >{{ strtoupper($type) }}</span>
@endif
