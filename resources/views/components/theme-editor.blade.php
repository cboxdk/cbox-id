@props(['appearance', 'title' => 'Appearance', 'subtitle' => null, 'scope' => null])
@php
    use App\Platform\Appearance\ThemeFont;
    use App\Platform\Appearance\ThemePresets;
    use App\Platform\Appearance\ThemeRadius;

    // Serialization boundary: the client editor (Alpine) works in plain JSON. The
    // typed catalogue is converted to payloads here, once.
    $presets = ThemePresets::toPayload();
    $fonts = ThemeFont::stacks();
    $radii = ThemeRadius::values();
@endphp

{{-- The shared hosted-sign-in Theme Editor. Editing + preview are entirely
     client-side (Alpine `themeEditor`, in bundled app.js) for zero latency; the
     host Volt component only needs a `save(array $theme)` action. Used by both the
     organization and environment appearance pages. --}}
<div x-data="themeEditor(@js($appearance), @js($presets), @js($fonts), @js($radii))" x-cloak>
    <x-page-header :title="$title" :subtitle="$subtitle">
        <x-slot:actions>
            <button type="button" @click="save()" class="btn btn-primary shrink-0" :disabled="saved">
                <template x-if="!saved"><span class="inline-flex items-center gap-1.5"><x-icon name="check" class="w-4 h-4" /> Save changes</span></template>
                <template x-if="saved"><span class="inline-flex items-center gap-1.5"><x-icon name="check" class="w-4 h-4" /> Saved</span></template>
            </button>
        </x-slot:actions>
    </x-page-header>

    <div class="grid gap-6 lg:grid-cols-[minmax(0,360px)_1fr] items-start">

        {{-- ═══ Controls ═══ --}}
        <div class="space-y-5 lg:sticky lg:top-6">

            <section class="card p-4">
                <p class="cbx-nav-group mb-3">Presets</p>
                <div class="grid grid-cols-2 gap-2">
                    <template x-for="(p, id) in presets" :key="id">
                        <button type="button" @click="applyPreset(id)"
                                class="group flex items-center gap-2.5 rounded-lg border p-2 text-left transition"
                                :style="draft.preset === id ? 'border-color:var(--accent);box-shadow:0 0 0 1px var(--accent)' : 'border-color:var(--border)'">
                            <span class="grid grid-cols-2 grid-rows-2 w-8 h-8 rounded-md overflow-hidden shrink-0" style="border:1px solid var(--border)">
                                <span :style="`background:${p.light.background}`"></span>
                                <span :style="`background:${p.light.primary}`"></span>
                                <span :style="`background:${p.dark.background}`"></span>
                                <span :style="`background:${p.dark.primary}`"></span>
                            </span>
                            <span class="min-w-0">
                                <span class="block text-[13px] font-medium truncate" x-text="p.label"></span>
                                <span class="block text-[11px] truncate" style="color:var(--muted-foreground)" x-text="radiusLabel(p.radius) + ' · ' + fontLabel(p.font)"></span>
                            </span>
                        </button>
                    </template>
                </div>
            </section>

            <section class="card p-4">
                <div class="flex items-center justify-between mb-3">
                    <p class="cbx-nav-group" style="margin:0">Colours</p>
                    <div class="inline-flex rounded-lg p-0.5" style="background:var(--secondary)">
                        <button type="button" @click="mode='light'" class="px-2.5 py-1 rounded-md text-[12px] font-medium transition"
                                :style="mode==='light' ? 'background:var(--card);box-shadow:var(--shadow-sm)' : 'color:var(--muted-foreground)'">Light</button>
                        <button type="button" @click="mode='dark'" class="px-2.5 py-1 rounded-md text-[12px] font-medium transition"
                                :style="mode==='dark' ? 'background:var(--card);box-shadow:var(--shadow-sm)' : 'color:var(--muted-foreground)'">Dark</button>
                    </div>
                </div>

                <div class="space-y-2.5">
                    <template x-for="token in ['primary','background','foreground','muted']" :key="token">
                        <div class="flex items-center gap-3">
                            <label class="flex items-center gap-2.5 flex-1 min-w-0 cursor-pointer">
                                <span class="relative w-8 h-8 rounded-lg shrink-0 overflow-hidden" style="border:1px solid var(--border)">
                                    <span class="absolute inset-0" :style="`background:${m[token]}`"></span>
                                    <input type="color" :value="m[token]" @input="m[token] = $event.target.value.toLowerCase()"
                                           class="absolute inset-0 opacity-0 cursor-pointer" :aria-label="`${token} colour`">
                                </span>
                                <span class="text-[13px] capitalize" x-text="token"></span>
                            </label>
                            <input type="text" :value="m[token]" @input="setColor(token, $event.target.value)" @blur="$event.target.value = m[token]"
                                   class="input mono" style="width:6.5rem;height:2rem;font-size:12px" spellcheck="false" :aria-label="`${token} hex`">
                        </div>
                    </template>
                </div>

                <div class="mt-3 flex items-center justify-between rounded-lg px-3 py-2" style="background:var(--secondary)">
                    <span class="text-[12px]" style="color:var(--muted-foreground)">Contrast (primary · background)</span>
                    <span class="inline-flex items-center gap-1.5 text-[12px] font-semibold">
                        <span x-text="aa.ratio + ':1'" class="mono"></span>
                        <span class="badge" :class="aa.pass ? 'badge-success' : 'badge-warn'" x-text="aa.level"></span>
                    </span>
                </div>
            </section>

            <section class="card p-4 space-y-4">
                <div>
                    <p class="cbx-nav-group mb-2">Corners</p>
                    <div class="flex flex-wrap gap-1.5">
                        <template x-for="r in radii" :key="r">
                            <button type="button" @click="draft.radius = r"
                                    class="px-2.5 py-1 rounded-md text-[12px] font-medium transition border"
                                    :style="draft.radius === r ? 'border-color:var(--accent);color:var(--accent);background:var(--accent-soft)' : 'border-color:var(--border);color:var(--muted-foreground)'"
                                    x-text="radiusLabel(r)"></button>
                        </template>
                    </div>
                </div>
                <div>
                    <p class="cbx-nav-group mb-2">Typeface</p>
                    <div class="grid grid-cols-3 gap-1.5">
                        <template x-for="(stack, key) in fonts" :key="key">
                            <button type="button" @click="draft.font = key"
                                    class="px-2 py-2 rounded-lg text-[13px] font-medium transition border"
                                    :style="`${draft.font === key ? 'border-color:var(--accent);background:var(--accent-soft)' : 'border-color:var(--border)'};font-family:${stack}`"
                                    x-text="fontLabel(key)"></button>
                        </template>
                    </div>
                </div>
                <div>
                    <label class="label" for="theme-logo">Logo URL <span style="color:var(--faint)">(https, optional)</span></label>
                    <input id="theme-logo" type="url" x-model="draft.logo" class="input" placeholder="https://acme.com/logo.svg" spellcheck="false">
                </div>
            </section>

            <section class="card p-4">
                <p class="cbx-nav-group mb-3">Export &amp; reset</p>
                <div class="grid grid-cols-2 gap-2">
                    <button type="button" @click="copy('css')" class="btn btn-ghost btn-sm">
                        <x-icon name="copy" class="w-3.5 h-3.5" /> <span x-text="copied==='css' ? 'Copied' : 'Copy CSS'"></span>
                    </button>
                    <button type="button" @click="copy('json')" class="btn btn-ghost btn-sm">
                        <x-icon name="copy" class="w-3.5 h-3.5" /> <span x-text="copied==='json' ? 'Copied' : 'Copy JSON'"></span>
                    </button>
                </div>
                <button type="button" @click="applyPreset(draft.preset)" class="btn btn-ghost btn-sm w-full mt-2" style="color:var(--muted-foreground)">
                    <x-icon name="refresh" class="w-3.5 h-3.5" /> Reset to <span class="capitalize" x-text="presets[draft.preset]?.label"></span>
                </button>
            </section>
        </div>

        {{-- ═══ Live preview ═══ --}}
        <div class="lg:sticky lg:top-6">
            <div class="flex items-center justify-between mb-2">
                <p class="cbx-nav-group" style="margin:0">Live preview</p>
                <span class="text-[11px]" style="color:var(--faint)">Editing the <span x-text="mode"></span> theme</span>
            </div>

            <div class="rounded-2xl overflow-hidden" style="border:1px solid var(--border);box-shadow:var(--shadow-lg)">
                <div class="flex items-center gap-2 px-3.5 h-9 shrink-0" style="background:var(--secondary);border-bottom:1px solid var(--border)">
                    <span class="flex gap-1.5" aria-hidden="true">
                        <span class="w-2.5 h-2.5 rounded-full" style="background:#ff5f57"></span>
                        <span class="w-2.5 h-2.5 rounded-full" style="background:#febc2e"></span>
                        <span class="w-2.5 h-2.5 rounded-full" style="background:#28c840"></span>
                    </span>
                    <span class="mx-auto inline-flex items-center gap-1.5 rounded-md px-3 h-5 text-[11px] mono" style="background:var(--card);color:var(--muted-foreground);border:1px solid var(--border)">
                        <x-icon name="shield" class="w-3 h-3" /> <span x-text="(draft.name || 'your-app').toLowerCase().replace(/[^a-z0-9]+/g,'-').replace(/^-|-$/g,'')"></span>.cboxid.com
                    </span>
                </div>

                <div class="p-8 sm:p-12 transition-colors" :style="vars(mode)" style="min-height:30rem">
                    <div class="mx-auto w-full" style="max-width:22rem">
                        <template x-if="draft.logo">
                            <img :src="draft.logo" :alt="draft.name" style="max-height:2rem;max-width:11rem" x-on:error="draft.logo=''">
                        </template>
                        <template x-if="!draft.logo">
                            <div class="inline-flex items-center gap-2">
                                <span class="grid place-items-center w-8 h-8 rounded-lg text-sm font-bold" style="background:var(--accent);color:var(--accent-foreground)" x-text="(draft.name || 'A').charAt(0).toUpperCase()"></span>
                                <span class="font-semibold" x-text="draft.name || 'Acme'"></span>
                            </div>
                        </template>

                        <div class="mt-8">
                            <h2 class="text-xl font-bold tracking-tight" style="font-family:var(--font-display)">Sign in to <span x-text="draft.name || 'Acme'"></span></h2>
                            <p class="mt-1 text-sm" style="color:var(--muted-foreground)">Welcome back — please sign in to continue.</p>

                            <div class="mt-6 space-y-2.5">
                                <button type="button" class="btn btn-secondary w-full" style="justify-content:center" tabindex="-1">
                                    <x-icon name="shield" class="w-4 h-4" /> Continue with SSO
                                </button>
                            </div>

                            <div class="my-5 flex items-center gap-3 text-[11px] uppercase tracking-wide" style="color:var(--faint)">
                                <span class="h-px flex-1" style="background:var(--border)"></span>or<span class="h-px flex-1" style="background:var(--border)"></span>
                            </div>

                            <label class="label">Email address</label>
                            <input type="email" class="input" placeholder="you@company.com" tabindex="-1" readonly>
                            <button type="button" class="btn btn-primary w-full mt-4" style="justify-content:center" tabindex="-1">Continue</button>

                            <p class="mt-6 text-center text-[13px]" style="color:var(--muted-foreground)">
                                Don't have an account? <span style="color:var(--accent);font-weight:600">Sign up</span>
                            </p>
                        </div>

                        <div class="mt-8 flex items-center gap-1.5 text-[11px]" style="color:var(--faint)">
                            <x-icon name="shield" class="w-3 h-3" /> Secured by Cbox ID
                        </div>
                    </div>
                </div>
            </div>
            <p class="mt-3 text-[12px]" style="color:var(--faint)">
                @if ($scope === 'environment')
                    This is your environment's default sign-in. An organization can override it with its own theme.
                @elseif ($scope === 'organization')
                    This overrides your environment's default for your organization's sign-in.
                @else
                    This is exactly how your sign-in renders — the preview shares the resolver that themes the live page.
                @endif
            </p>
        </div>
    </div>
</div>
