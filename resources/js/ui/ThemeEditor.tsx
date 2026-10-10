import { useCallback, useEffect, useMemo, useRef, useState } from 'react';
import {
    type FontStacks,
    HEX,
    type Theme,
    type ThemeCatalogue,
    type ThemeMode,
    exportedCss,
    fontLabel,
    radiusLabel,
    readout,
    themeVars,
} from '@/lib/appearance';
import type { HelpContent } from '@/types';
import { Badge } from './Badge';
import { Button } from './Button';
import { Icon } from './Icon';
import { Input } from './Input';
import { PageHeader } from './PageHeader';

export interface ThemeEditorProps {
    /** The theme as stored. The editor works on a copy until Save. */
    value: Theme;
    presets: ThemeCatalogue;
    fonts: FontStacks;
    radii: string[];
    help?: HelpContent;
    title?: React.ReactNode;
    description?: React.ReactNode;
    /** Which thing is being themed, for the sentence under the preview. */
    scope?: 'environment' | 'organization';
    saving?: boolean;
    /** The server's refusal, if the last save was rejected for contrast. */
    error?: string | null;
    /** The server's refusal of an image, per image. */
    imageErrors?: Partial<Record<ImageKind, string>>;
    /** Each face's own name, from `ThemeFont::labels()`. */
    fontLabels?: Record<string, string>;
    /**
     * A remote logo URL saved before logos became uploads — no longer drawn anywhere. The
     * editor asks for an upload until one is made (or the logo is removed).
     */
    remoteLogoIgnored?: boolean;
    /** Whether this install can store images at all (the white-label module). */
    imagesAccepted?: boolean;
    /**
     * `images` holds only what CHANGED: a data URI to store, null to remove. An image the
     * administrator did not touch is absent, so saving the colours never re-uploads it.
     */
    onSave: (theme: Theme, images: ImageChanges) => void;
}

export type ImageKind = 'logo' | 'favicon';

/** Stable defaults: an object literal as a default prop is a new object every render. */
const NO_IMAGE_ERRORS: Partial<Record<ImageKind, string>> = {};
const NO_FONT_LABELS: Record<string, string> = {};
export type ImageChanges = Partial<Record<ImageKind, string | null>>;

/**
 * The twin of `App\Platform\Appearance\BrandImage`: what the server will accept, checked
 * here first so a wrong file is refused in the dialog rather than after an upload. The
 * server checks the BYTES again — this is a courtesy, not the rule.
 */
const IMAGE_RULES: Record<ImageKind, { maxBytes: number; types: string[]; formats: string }> = {
    logo: {
        maxBytes: 1024 * 1024,
        types: ['image/png', 'image/jpeg', 'image/webp'],
        formats: 'PNG, JPEG or WebP',
    },
    favicon: {
        maxBytes: 256 * 1024,
        types: ['image/png', 'image/webp', 'image/x-icon', 'image/vnd.microsoft.icon'],
        formats: 'PNG, ICO or WebP',
    },
};

/**
 * THE HOSTED SIGN-IN THEME EDITOR — presets, colours, corners and type, against a live
 * preview of the page being themed.
 *
 * Editing and previewing are entirely client-side, and that is the point: a theme picker
 * that round-trips per keystroke is a theme picker nobody explores. The server is asked
 * once, on Save, and it is the server that refuses an unreadable palette — see
 * `AppearanceController` for why that refusal is a refusal and not a warning.
 */
export function ThemeEditor({
    value,
    presets,
    fonts,
    radii,
    help,
    title,
    description,
    scope,
    saving = false,
    error = null,
    imageErrors = NO_IMAGE_ERRORS,
    fontLabels = NO_FONT_LABELS,
    remoteLogoIgnored = false,
    imagesAccepted = true,
    onSave,
}: ThemeEditorProps) {
    const [draft, setDraft] = useState<Theme>(value);
    const [images, setImages] = useState<ImageChanges>({});

    const pickImage = useCallback((kind: ImageKind, next: string | null) => {
        setImages((current) => ({ ...current, [kind]: next }));
        setDraft((current) => ({ ...current, [kind]: next ?? '' }));
    }, []);

    const nameOf = (font: string): string => fontLabels[font] ?? fontLabel(font);
    const [mode, setMode] = useState<'light' | 'dark'>('light');
    const [copied, setCopied] = useState<'css' | 'json' | ''>('');
    const copyTimer = useRef<ReturnType<typeof setTimeout>>(undefined);

    useEffect(() => () => clearTimeout(copyTimer.current), []);

    const m = draft[mode];
    const aa = useMemo(() => readout(m), [m]);
    const vars = useMemo(() => themeVars(draft, mode, fonts), [draft, mode, fonts]);

    const applyPreset = useCallback(
        (id: string) => {
            const preset = presets[id];

            if (preset === undefined) {
                return;
            }

            setDraft((current) => ({
                ...current,
                preset: id,
                radius: preset.radius,
                font: preset.font,
                light: { ...preset.light },
                dark: { ...preset.dark },
            }));
        },
        [presets],
    );

    const setColor = useCallback(
        (token: keyof ThemeMode, next: string) => {
            if (!HEX.test(next)) {
                return;
            }

            setDraft((current) => ({
                ...current,
                [mode]: { ...current[mode], [token]: next.toLowerCase() },
            }));
        },
        [mode],
    );

    const copy = useCallback(
        (kind: 'css' | 'json') => {
            const text =
                kind === 'json' ? JSON.stringify(draft, null, 2) : exportedCss(draft, fonts);

            // `?.` because the clipboard API is absent entirely outside a secure context,
            // which is exactly where a self-hosted install over plain http lands.
            const write = navigator.clipboard?.writeText(text);

            if (write === undefined) {
                return;
            }

            write
                .then(() => {
                    setCopied(kind);
                    clearTimeout(copyTimer.current);
                    copyTimer.current = setTimeout(() => setCopied(''), 1500);
                })
                .catch(() => {});
        },
        [draft, fonts],
    );

    const host = (draft.name || 'your-app')
        .toLowerCase()
        .replace(/[^a-z0-9]+/g, '-')
        .replace(/^-|-$/g, '');

    return (
        <div>
            <PageHeader
                title={title}
                help={help}
                description={description}
                actions={
                    <Button
                        variant="primary"
                        className="shrink-0"
                        icon="check"
                        loading={saving}
                        onClick={() => onSave(draft, images)}
                    >
                        Save changes
                    </Button>
                }
            />

            {/*
                The server's refusal, shown where the person who caused it is looking.
                An unreadable palette is rejected rather than warned about: the people who
                cannot then read the sign-in page are not the administrator choosing the
                colours, they are that organization's users.
            */}
            {error !== null && (
                <p
                    className="mb-4 rounded-lg px-3 py-2 text-sm"
                    style={{
                        background: 'var(--destructive-soft)',
                        color: 'var(--destructive)',
                    }}
                    role="alert"
                >
                    {error}
                </p>
            )}

            {/*
                THE OLD REMOTE LOGO. It was an https URL drawn as-is on the sign-in page,
                which made it a beacon: every visitor's browser reported to whoever hosted
                the image. It is no longer drawn, and the page says so here — the one place
                the administrator can fix it — instead of leaving them to find their own
                sign-in page suddenly logo-less.
            */}
            {remoteLogoIgnored && images.logo === undefined && (
                <output
                    className="mb-4 rounded-lg px-3.5 py-3 text-sm flex items-start gap-2.5"
                    style={{
                        background: 'var(--warning-soft)',
                        color: 'var(--warning-strong)',
                        border: '1px solid color-mix(in srgb, var(--warning) 35%, transparent)',
                    }}
                >
                    <Icon name="warning" className="w-4 h-4 mt-0.5 shrink-0" />
                    <span>
                        <b>Upload your logo — remote logo URLs are no longer shown.</b> A logo
                        linked from another site told that site about every person who opened your
                        sign-in page, so hosted pages now draw only images uploaded here. Upload it
                        under <i>Logo &amp; favicon</i> below, or remove it to stop this notice.
                    </span>
                </output>
            )}

            <div className="grid gap-6 lg:grid-cols-[minmax(0,360px)_1fr] items-start">
                <div className="space-y-5 lg:sticky lg:top-6">
                    <section className="card p-4">
                        <p className="cbx-nav-group mb-3">Presets</p>
                        <div className="grid grid-cols-2 gap-2">
                            {Object.entries(presets).map(([id, preset]) => (
                                <button
                                    key={id}
                                    type="button"
                                    onClick={() => applyPreset(id)}
                                    aria-pressed={draft.preset === id}
                                    // Named explicitly: the swatch beside the label is
                                    // aria-hidden, so without this the button's name is
                                    // whatever survives of the visible text.
                                    aria-label={`${preset.label} preset`}
                                    className="group flex items-center gap-2.5 rounded-lg border p-2 text-left transition"
                                    style={
                                        draft.preset === id
                                            ? {
                                                  borderColor: 'var(--accent)',
                                                  boxShadow: '0 0 0 1px var(--accent)',
                                              }
                                            : { borderColor: 'var(--control-border)' }
                                    }
                                >
                                    <span
                                        className="grid grid-cols-2 grid-rows-2 w-8 h-8 rounded-md overflow-hidden shrink-0"
                                        style={{ border: '1px solid var(--border)' }}
                                        aria-hidden="true"
                                    >
                                        <span style={{ background: preset.light.background }} />
                                        <span style={{ background: preset.light.primary }} />
                                        <span style={{ background: preset.dark.background }} />
                                        <span style={{ background: preset.dark.primary }} />
                                    </span>
                                    <span className="min-w-0">
                                        <span className="block text-[13px] font-medium truncate">
                                            {preset.label}
                                        </span>
                                        <span
                                            className="block text-[11px] truncate"
                                            style={{ color: 'var(--muted-foreground)' }}
                                        >
                                            {radiusLabel(preset.radius)} · {nameOf(preset.font)}
                                        </span>
                                    </span>
                                </button>
                            ))}
                        </div>
                    </section>

                    <section className="card p-4">
                        <div className="flex items-center justify-between mb-3">
                            <p className="cbx-nav-group" style={{ margin: 0 }}>
                                Colours
                            </p>
                            <fieldset
                                className="inline-flex rounded-lg p-0.5"
                                style={{
                                    background: 'var(--secondary)',
                                    border: 0,
                                    padding: '2px',
                                }}
                            >
                                <legend className="sr-only">Which mode to edit</legend>
                                {(['light', 'dark'] as const).map((candidate) => (
                                    <button
                                        key={candidate}
                                        type="button"
                                        onClick={() => setMode(candidate)}
                                        aria-pressed={mode === candidate}
                                        className="px-2.5 py-1 rounded-md text-[12px] font-medium transition capitalize"
                                        style={
                                            mode === candidate
                                                ? {
                                                      background: 'var(--card)',
                                                      boxShadow: 'var(--shadow-sm)',
                                                  }
                                                : { color: 'var(--muted-foreground)' }
                                        }
                                    >
                                        {candidate}
                                    </button>
                                ))}
                            </fieldset>
                        </div>

                        <div className="space-y-2.5">
                            {(['primary', 'background', 'foreground', 'muted'] as const).map(
                                (token) => (
                                    <ColorRow
                                        key={token}
                                        token={token}
                                        value={m[token]}
                                        onChange={(next) => setColor(token, next)}
                                    />
                                ),
                            )}
                        </div>

                        <div
                            className="mt-3 flex items-center justify-between rounded-lg px-3 py-2"
                            style={{ background: 'var(--secondary)' }}
                        >
                            <span
                                className="text-[12px]"
                                style={{ color: 'var(--muted-foreground)' }}
                            >
                                Contrast (primary · background)
                            </span>
                            <span className="inline-flex items-center gap-1.5 text-[12px] font-semibold">
                                <span className="mono">{aa.ratio}:1</span>
                                <Badge tone={aa.pass ? 'success' : 'warn'}>{aa.level}</Badge>
                            </span>
                        </div>
                    </section>

                    <section className="card p-4 space-y-4">
                        <div>
                            <p className="cbx-nav-group mb-2">Corners</p>
                            <fieldset
                                className="flex flex-wrap gap-1.5"
                                style={{ border: 0, padding: 0 }}
                            >
                                <legend className="sr-only">Corners</legend>
                                {radii.map((radius) => (
                                    <button
                                        key={radius}
                                        type="button"
                                        onClick={() =>
                                            setDraft((current) => ({ ...current, radius }))
                                        }
                                        aria-pressed={draft.radius === radius}
                                        className="px-2.5 py-1 rounded-md text-[12px] font-medium transition border"
                                        style={
                                            draft.radius === radius
                                                ? {
                                                      borderColor: 'var(--accent)',
                                                      color: 'var(--accent-strong)',
                                                      background: 'var(--accent-soft)',
                                                  }
                                                : {
                                                      borderColor: 'var(--control-border)',
                                                      color: 'var(--muted-foreground)',
                                                  }
                                        }
                                    >
                                        {radiusLabel(radius)}
                                    </button>
                                ))}
                            </fieldset>
                        </div>

                        <div>
                            <p className="cbx-nav-group mb-2">Typeface</p>
                            {/*
                                Each option is drawn IN its face, so the choice is made by
                                looking rather than by reading a name. Every face is
                                self-hosted (public/fonts), so what this button shows is what
                                a visitor's browser will draw, on any operating system.
                            */}
                            <fieldset
                                className="grid grid-cols-2 gap-1.5"
                                style={{ border: 0, padding: 0 }}
                            >
                                <legend className="sr-only">Typeface</legend>
                                {Object.entries(fonts).map(([key, stack]) => (
                                    <button
                                        key={key}
                                        type="button"
                                        onClick={() =>
                                            setDraft((current) => ({ ...current, font: key }))
                                        }
                                        aria-pressed={draft.font === key}
                                        className="flex items-center gap-2 px-2.5 py-2 rounded-lg text-[13px] font-medium transition border text-left"
                                        style={{
                                            fontFamily: stack,
                                            ...(draft.font === key
                                                ? {
                                                      borderColor: 'var(--accent)',
                                                      background: 'var(--accent-soft)',
                                                  }
                                                : { borderColor: 'var(--control-border)' }),
                                        }}
                                    >
                                        <span
                                            aria-hidden="true"
                                            className="text-[17px] leading-none"
                                        >
                                            Aa
                                        </span>
                                        <span className="truncate">{nameOf(key)}</span>
                                    </button>
                                ))}
                            </fieldset>
                        </div>
                    </section>

                    {/*
                        UPLOADS, NOT URLS. An image on the sign-in page is fetched by every
                        visitor, so it is stored here and served by this application — a URL
                        to somebody else's server would tell that server who they all are.
                    */}
                    <section className="card p-4 space-y-4" aria-labelledby="theme-images">
                        <p id="theme-images" className="cbx-nav-group" style={{ margin: 0 }}>
                            Logo &amp; favicon
                        </p>
                        {imagesAccepted ? (
                            <>
                                <ImagePicker
                                    kind="logo"
                                    label="Logo"
                                    hint="Shown above the sign-in form and in emails. PNG, JPEG or WebP, up to 1 MB. A wide image about 36px tall reads best."
                                    value={draft.logo}
                                    error={imageErrors.logo}
                                    onChange={(next) => pickImage('logo', next)}
                                />
                                <ImagePicker
                                    kind="favicon"
                                    label="Favicon"
                                    hint="The browser-tab icon on your sign-in pages. A square PNG, ICO or WebP, up to 256 KB."
                                    value={draft.favicon}
                                    error={imageErrors.favicon}
                                    onChange={(next) => pickImage('favicon', next)}
                                />
                            </>
                        ) : (
                            <p className="text-[13px]" style={{ color: 'var(--muted-foreground)' }}>
                                Image uploads need the white-label module, which is not enabled on
                                this install.
                            </p>
                        )}
                    </section>

                    <section className="card p-4">
                        <p className="cbx-nav-group mb-3">Export &amp; reset</p>
                        <div className="grid grid-cols-2 gap-2">
                            <Button size="sm" icon="copy" onClick={() => copy('css')}>
                                {copied === 'css' ? 'Copied' : 'Copy CSS'}
                            </Button>
                            <Button size="sm" icon="copy" onClick={() => copy('json')}>
                                {copied === 'json' ? 'Copied' : 'Copy JSON'}
                            </Button>
                        </div>
                        <Button
                            size="sm"
                            icon="refresh"
                            className="w-full mt-2"
                            style={{ color: 'var(--muted-foreground)' }}
                            onClick={() => applyPreset(draft.preset)}
                        >
                            Reset to {presets[draft.preset]?.label ?? draft.preset}
                        </Button>
                    </section>
                </div>

                {/* ═══ Live preview ═══ */}
                <div className="lg:sticky lg:top-6">
                    <div className="flex items-center justify-between mb-2">
                        <p className="cbx-nav-group" style={{ margin: 0 }}>
                            Live preview
                        </p>
                        <span className="text-[11px]" style={{ color: 'var(--faint)' }}>
                            Editing the {mode} theme
                        </span>
                    </div>

                    <div
                        className="rounded-2xl overflow-hidden"
                        style={{
                            border: '1px solid var(--border)',
                            boxShadow: 'var(--shadow-lg)',
                        }}
                    >
                        <div
                            className="flex items-center gap-2 px-3.5 h-9 shrink-0"
                            style={{
                                background: 'var(--secondary)',
                                borderBottom: '1px solid var(--border)',
                            }}
                        >
                            <span className="flex gap-1.5" aria-hidden="true">
                                <span
                                    className="w-2.5 h-2.5 rounded-full"
                                    style={{ background: '#ff5f57' }}
                                />
                                <span
                                    className="w-2.5 h-2.5 rounded-full"
                                    style={{ background: '#febc2e' }}
                                />
                                <span
                                    className="w-2.5 h-2.5 rounded-full"
                                    style={{ background: '#28c840' }}
                                />
                            </span>
                            <span
                                className="mx-auto inline-flex items-center gap-1.5 rounded-md px-3 h-5 text-[11px] mono"
                                style={{
                                    background: 'var(--card)',
                                    color: 'var(--muted-foreground)',
                                    border: '1px solid var(--border)',
                                }}
                            >
                                {draft.favicon !== '' ? (
                                    <img
                                        src={draft.favicon}
                                        alt=""
                                        className="w-3 h-3 object-contain"
                                    />
                                ) : (
                                    <Icon name="shield" className="w-3 h-3" />
                                )}
                                {host}.cboxid.com
                            </span>
                        </div>

                        {/*
                            A static mockup of the hosted sign-in screen, not a form: every
                            control inside is tabbable-out and the input is readonly. Exposed
                            to a screen reader it announced a second "Sign in to…" heading
                            and an unusable email field, so it is hidden and described by the
                            line above it instead.
                        */}
                        <p className="sr-only">
                            Live preview of your hosted sign-in screen, in {mode} mode.
                        </p>
                        <div
                            className="p-8 sm:p-12 transition-colors"
                            aria-hidden="true"
                            data-testid="appearance-preview"
                            style={{
                                ...(vars as React.CSSProperties),
                                background: m.background,
                                color: m.foreground,
                                /*
                                 * THE TYPEFACE, APPLIED. The variables above include
                                 * `--font-sans`, but setting a custom property does not
                                 * change any element's font: everything in here inherited
                                 * the COMPUTED family from <body>, which read the console's
                                 * own `--font-sans`. So the typeface buttons changed nothing
                                 * in the preview. The heading below reads `--font-display`,
                                 * which the variables now set too.
                                 */
                                fontFamily: 'var(--font-sans)',
                                minHeight: '30rem',
                            }}
                        >
                            <div className="mx-auto w-full" style={{ maxWidth: '22rem' }}>
                                {draft.logo !== '' ? (
                                    <img
                                        src={draft.logo}
                                        alt={draft.name}
                                        style={{ maxHeight: '2rem', maxWidth: '11rem' }}
                                    />
                                ) : (
                                    <div className="inline-flex items-center gap-2">
                                        <span
                                            className="grid place-items-center w-8 h-8 rounded-lg text-sm font-bold"
                                            style={{
                                                background: 'var(--accent)',
                                                color: 'var(--accent-foreground)',
                                            }}
                                        >
                                            {(draft.name || 'A').charAt(0).toUpperCase()}
                                        </span>
                                        <span className="font-semibold">
                                            {draft.name || 'Acme'}
                                        </span>
                                    </div>
                                )}

                                <div className="mt-8">
                                    <h2
                                        className="text-xl font-bold tracking-tight"
                                        style={{ fontFamily: 'var(--font-display)' }}
                                    >
                                        Sign in to {draft.name || 'Acme'}
                                    </h2>
                                    <p
                                        className="mt-1 text-sm"
                                        style={{ color: 'var(--muted-foreground)' }}
                                    >
                                        Welcome back — please sign in to continue.
                                    </p>

                                    <div className="mt-6 space-y-2.5">
                                        <span
                                            className="btn btn-secondary w-full"
                                            style={{ justifyContent: 'center' }}
                                        >
                                            <Icon name="shield" className="w-4 h-4" />
                                            Continue with SSO
                                        </span>
                                    </div>

                                    <div
                                        className="my-5 flex items-center gap-3 text-[11px] uppercase tracking-wide"
                                        style={{ color: 'var(--faint)' }}
                                    >
                                        <span
                                            className="h-px flex-1"
                                            style={{ background: 'var(--border)' }}
                                        />
                                        or
                                        <span
                                            className="h-px flex-1"
                                            style={{ background: 'var(--border)' }}
                                        />
                                    </div>

                                    <p className="label">Email address</p>
                                    <span className="input block">you@company.com</span>
                                    <span
                                        className="btn btn-primary w-full mt-4"
                                        style={{ justifyContent: 'center' }}
                                    >
                                        Continue
                                    </span>

                                    <p
                                        className="mt-6 text-center text-[13px]"
                                        style={{ color: 'var(--muted-foreground)' }}
                                    >
                                        Don't have an account?{' '}
                                        <span
                                            style={{
                                                color: 'var(--accent-strong)',
                                                fontWeight: 600,
                                            }}
                                        >
                                            Sign up
                                        </span>
                                    </p>
                                </div>

                                <div
                                    className="mt-8 flex items-center gap-1.5 text-[11px]"
                                    style={{ color: 'var(--faint)' }}
                                >
                                    <Icon name="shield" className="w-3 h-3" />
                                    Secured by Cbox ID
                                </div>
                            </div>
                        </div>
                    </div>

                    <p className="mt-3 text-[12px]" style={{ color: 'var(--faint)' }}>
                        {scope === 'environment'
                            ? "This is your environment's default sign-in. An organization can override it with its own theme."
                            : scope === 'organization'
                              ? "This overrides your environment's default for your organization's sign-in."
                              : 'This is exactly how your sign-in renders — the preview shares the resolver that themes the live page.'}
                    </p>
                </div>
            </div>
        </div>
    );
}

/**
 * One uploaded image: its thumbnail, a button that opens the file picker, and Remove.
 *
 * The file is read as a `data:` URI in the browser — that IS the preview, and it is also
 * what is sent on Save, so the server checks exactly the bytes that were shown. Nothing
 * leaves the page until Save.
 */
function ImagePicker({
    kind,
    label,
    hint,
    value,
    error,
    onChange,
}: {
    kind: ImageKind;
    label: string;
    hint: string;
    value: string;
    error?: string;
    onChange: (next: string | null) => void;
}) {
    const input = useRef<HTMLInputElement>(null);
    const [problem, setProblem] = useState<string | null>(null);
    const rules = IMAGE_RULES[kind];
    const id = `theme-${kind}`;
    const message = problem ?? error ?? null;

    const choose = (file: File | undefined): void => {
        if (file === undefined) {
            return;
        }

        if (!rules.types.includes(file.type)) {
            setProblem(
                file.type.includes('svg')
                    ? `SVG is not accepted — it can carry a script. Use a ${rules.formats} image.`
                    : `Use a ${rules.formats} image.`,
            );

            return;
        }

        if (file.size > rules.maxBytes) {
            setProblem(
                `That file is larger than ${rules.maxBytes / 1024 >= 1024 ? '1 MB' : `${rules.maxBytes / 1024} KB`}.`,
            );

            return;
        }

        const reader = new FileReader();
        reader.onload = () => {
            if (typeof reader.result === 'string') {
                setProblem(null);
                onChange(reader.result);
            }
        };
        reader.readAsDataURL(file);
    };

    return (
        <div>
            <p className="label" id={`${id}-label`}>
                {label}
            </p>
            <div className="flex items-center gap-3">
                <span
                    className="grid place-items-center rounded-lg shrink-0 overflow-hidden"
                    style={{
                        width: kind === 'logo' ? '5.5rem' : '2.5rem',
                        height: '2.5rem',
                        border: '1px solid var(--border)',
                        background: 'var(--secondary)',
                    }}
                >
                    {value !== '' ? (
                        <img
                            src={value}
                            alt={`Current ${label.toLowerCase()}`}
                            className="max-w-full max-h-full object-contain"
                        />
                    ) : (
                        <Icon name="image" className="w-4 h-4" style={{ color: 'var(--faint)' }} />
                    )}
                </span>
                <div className="flex flex-wrap gap-1.5">
                    <Button
                        size="sm"
                        icon="upload"
                        aria-describedby={`${id}-hint`}
                        onClick={() => input.current?.click()}
                    >
                        {value !== '' ? 'Replace' : 'Upload'}
                    </Button>
                    {value !== '' && (
                        <Button
                            size="sm"
                            style={{ color: 'var(--muted-foreground)' }}
                            onClick={() => {
                                setProblem(null);
                                onChange(null);
                            }}
                        >
                            Remove
                        </Button>
                    )}
                </div>
                <input
                    ref={input}
                    id={id}
                    type="file"
                    className="sr-only"
                    tabIndex={-1}
                    aria-labelledby={`${id}-label`}
                    accept={rules.types.join(',')}
                    onChange={(event) => {
                        choose(event.target.files?.[0]);
                        // Cleared so choosing the same file again after a Remove still fires.
                        event.target.value = '';
                    }}
                />
            </div>
            <p id={`${id}-hint`} className="mt-1.5 text-[12px]" style={{ color: 'var(--faint)' }}>
                {hint}
            </p>
            {message !== null && (
                <p className="field-error mt-1" role="alert">
                    {message}
                </p>
            )}
        </div>
    );
}

/**
 * One colour: a swatch that opens the native picker, and the hex beside it.
 *
 * The text field holds its own draft. Committing on every keystroke would reject "#0e" as
 * an invalid hex and snap the value back while somebody was still typing it.
 */
function ColorRow({
    token,
    value,
    onChange,
}: {
    token: keyof ThemeMode;
    value: string;
    onChange: (next: string) => void;
}) {
    const [typed, setTyped] = useState(value);
    const [committed, setCommitted] = useState(value);

    // Adjusted DURING RENDER rather than in an effect, which is React's own idiom for
    // "this state derives from a prop": an effect would paint the stale hex for one frame
    // every time a preset changes all four colours at once.
    if (value !== committed) {
        setCommitted(value);
        setTyped(value);
    }

    return (
        <div className="flex items-center gap-3">
            <label className="flex items-center gap-2.5 flex-1 min-w-0 cursor-pointer">
                <span
                    className="relative w-8 h-8 rounded-lg shrink-0 overflow-hidden"
                    style={{ border: '1px solid var(--border)' }}
                >
                    <span className="absolute inset-0" style={{ background: value }} />
                    <input
                        type="color"
                        className="absolute inset-0 opacity-0 cursor-pointer"
                        aria-label={`${token} colour`}
                        value={value}
                        onChange={(event) => onChange(event.target.value.toLowerCase())}
                    />
                </span>
                <span className="text-[13px] capitalize">{token}</span>
            </label>

            <Input
                className="mono"
                style={{ width: '6.5rem', height: '2rem', fontSize: '12px' }}
                spellCheck={false}
                aria-label={`${token} hex`}
                value={typed}
                onChange={(event) => {
                    setTyped(event.target.value);
                    onChange(event.target.value);
                }}
                onBlur={() => setTyped(value)}
            />
        </div>
    );
}
