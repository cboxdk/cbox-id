/**
 * THE HOSTED SIGN-IN THEME, computed the way the SERVER computes it.
 *
 * Not to be confused with `lib/theme.ts`, which is the CONSOLE's own light/dark
 * preference. This module is about the theme a customer ships to THEIR users.
 *
 * Every function here has a twin in `App\Platform\Appearance` — `Color`, `ThemeRadius`,
 * `AppearanceCss`. That duplication is deliberate and load-bearing: the editor's whole
 * job is to show what will be shipped, so it cannot ask the server between keystrokes and
 * it cannot approximate. If you change one side, change the other.
 *
 * The last time the two disagreed, the preview set `--radius` alone exactly as the server
 * did, so somebody choosing Square saw squared cards beside still-rounded buttons — and
 * then shipped it, because the preview agreed with the result. Both halves were wrong in
 * the same way, which is precisely why nobody could see it.
 */

export interface ThemeMode {
    primary: string;
    background: string;
    foreground: string;
    muted: string;
}

export interface Theme {
    preset: string;
    radius: string;
    font: string;
    light: ThemeMode;
    dark: ThemeMode;
    /** Editor-only, carried alongside the typed appearance. */
    logo: string;
    name: string;
}

export interface ThemePreset {
    label: string;
    radius: string;
    font: string;
    light: ThemeMode;
    dark: ThemeMode;
}

export type ThemeCatalogue = Record<string, ThemePreset>;
export type FontStacks = Record<string, string>;

export const HEX = /^#[0-9a-fA-F]{6}$/;

const FONT_LABELS: Record<string, string> = {
    system: 'System',
    geometric: 'Geometric',
    serif: 'Serif',
};

const RADIUS_LABELS: Record<string, string> = {
    '0rem': 'Square',
    '0.25rem': 'XS',
    '0.375rem': 'S',
    '0.5rem': 'M',
    '0.75rem': 'L',
    '1rem': 'XL',
};

export function fontLabel(font: string): string {
    return FONT_LABELS[font] ?? font;
}

export function radiusLabel(radius: string): string {
    return RADIUS_LABELS[radius] ?? radius;
}

export function fontStack(fonts: FontStacks, font: string): string {
    return fonts[font] ?? fonts.system ?? 'system-ui, sans-serif';
}

/**
 * Mirrors `ThemeRadius::scale()`. Two-thirds and a half of the chosen radius, which is the
 * ratio the hand-tuned defaults used (12/8/6).
 */
export function radiusScale(radius: string): Record<string, string> {
    const rem = Number.parseFloat(radius) || 0;
    const trim = (n: number): string => `${Number.parseFloat(n.toFixed(4))}rem`;

    return {
        '--radius': radius,
        '--radius-md': trim((rem * 2) / 3),
        '--radius-sm': trim(rem / 2),
    };
}

/* ── WCAG maths — mirrors App\Platform\Appearance\Color ───────────────────────────── */

function channels(hex: string): [number, number, number] {
    const value = hex.replace('#', '');

    return [0, 2, 4].map((i) => Number.parseInt(value.slice(i, i + 2), 16) / 255) as [
        number,
        number,
        number,
    ];
}

function luminance(hex: string): number {
    const [r, g, b] = channels(hex).map((c) =>
        c <= 0.03928 ? c / 12.92 : ((c + 0.055) / 1.055) ** 2.4,
    ) as [number, number, number];

    return 0.2126 * r + 0.7152 * g + 0.0722 * b;
}

export function contrast(a: string, b: string): number {
    const la = luminance(a);
    const lb = luminance(b);

    return (Math.max(la, lb) + 0.05) / (Math.min(la, lb) + 0.05);
}

/**
 * `ratio` of `color` laid over `background`, as an opaque hex.
 *
 * The twin of `Color::mix()` and of CSS `color-mix(in srgb, color N%, background)`. It
 * exists because several tokens are emitted as a `color-mix()` the browser resolves, and
 * their contrast is a question both sides have to be able to ask BEFORE emitting.
 */
export function mix(color: string, background: string, ratio: number): string {
    const a = channels(color);
    const b = channels(background);

    return (
        '#' +
        [0, 1, 2]
            .map((i) =>
                Math.round((a[i]! * ratio + b[i]! * (1 - ratio)) * 255)
                    .toString(16)
                    .padStart(2, '0'),
            )
            .join('')
    );
}

/** Black or white, whichever is legible on this background. */
export function readable(background: string): string {
    return contrast(background, '#ffffff') >= contrast(background, '#151515')
        ? '#ffffff'
        : '#151515';
}

export interface ContrastReadout {
    ratio: string;
    level: string;
    pass: boolean;
}

export function readout(mode: ThemeMode): ContrastReadout {
    const ratio = contrast(mode.primary, mode.background);

    return {
        ratio: ratio.toFixed(2),
        level: ratio >= 7 ? 'AAA' : ratio >= 4.5 ? 'AA' : ratio >= 3 ? 'AA Large' : 'Fail',
        pass: ratio >= 4.5,
    };
}

/**
 * The twin of `Color::readableOn`. The brand colour walked along its own hue until it is
 * legible as TEXT on this background — same 20 steps and the same 4.5 target, so the
 * preview and the injected stylesheet agree.
 */
export function readableOn(color: string, background: string, target = 4.5): string {
    if (contrast(color, background) >= target) {
        return color;
    }

    const toward = luminance(background) < 0.5 ? 1 : 0;
    const source = channels(color);

    let best = color;
    let bestRatio = contrast(color, background);

    for (let step = 1; step <= 20; step++) {
        const t = step / 20;
        const candidate =
            '#' +
            source
                .map((v) =>
                    Math.round(Math.max(0, Math.min(1, v + (toward - v) * t)) * 255)
                        .toString(16)
                        .padStart(2, '0'),
                )
                .join('');

        const ratio = contrast(candidate, background);

        if (ratio > bestRatio) {
            best = candidate;
            bestRatio = ratio;
        }

        if (ratio >= target) {
            return candidate;
        }
    }

    return best;
}

/**
 * The CSS-variable map for one mode — the same derivation `AppearanceCss::modeVars` uses.
 *
 * `--primary` drives `.btn-primary`, so the preview's call to action shows the chosen
 * colour at rest, matching what the server injects.
 */
export function themeVars(
    theme: Theme,
    mode: 'light' | 'dark',
    fonts: FontStacks,
): Record<string, string> {
    const m = theme[mode];
    const on = readable(m.primary);

    return {
        '--primary': m.primary,
        '--primary-foreground': on,
        '--accent': m.primary,
        '--ring': m.primary,
        '--accent-foreground': on,
        /*
         * WALKED AGAINST --accent-soft, NOT THE BACKGROUND — see `AppearanceCss` for why.
         * `.btn-secondary` is this token ON the soft fill, and that fill is the harder
         * ground in both modes because it moves toward the very hue the text is.
         */
        '--accent-strong': readableOn(m.primary, mix(m.primary, m.background, 0.12)),
        '--accent-soft': `color-mix(in srgb, ${m.primary} 12%, transparent)`,
        '--accent-edge': `color-mix(in srgb, ${m.primary} 32%, transparent)`,
        '--background': m.background,
        '--foreground': m.foreground,
        '--card': m.background,
        '--card-foreground': m.foreground,
        '--secondary': `color-mix(in srgb, ${m.foreground} 6%, ${m.background})`,
        '--secondary-foreground': m.foreground,
        '--muted-foreground': m.muted,
        // The quietest text on the page, with a FLOOR under it. A blind 65% wash reads
        // beautifully and measures 2.79:1 for the default preset — well under AA for the
        // 11px metadata this token carries.
        '--faint': readableOn(mix(m.muted, m.background, 0.65), m.background),
        '--border': `color-mix(in srgb, ${m.foreground} 14%, ${m.background})`,
        '--input': `color-mix(in srgb, ${m.foreground} 22%, ${m.background})`,
        ...radiusScale(theme.radius),
        '--font-sans': fontStack(fonts, theme.font),
    };
}

/** The exported stylesheet — the same shape the server injects. */
export function exportedCss(theme: Theme, fonts: FontStacks): string {
    const block = (mode: 'light' | 'dark'): string => {
        const vars = { ...themeVars(theme, mode, fonts) };

        if (mode === 'dark') {
            // The radius and the typeface are not per-mode: emitting them twice would let
            // a future edit change one and not the other.
            delete vars['--radius'];
            delete vars['--radius-md'];
            delete vars['--radius-sm'];
            delete vars['--font-sans'];
        }

        return Object.entries(vars)
            .map(([key, value]) => `${key}:${value}`)
            .join(';');
    };

    return [
        `:root{${block('light')}}`,
        `@media(prefers-color-scheme:dark){:root:not([data-theme='light']){${block('dark')}}}`,
        `:root[data-theme='dark']{${block('dark')}}`,
    ].join('\n');
}
