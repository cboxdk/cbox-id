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
    onSave: (theme: Theme) => void;
}

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
    onSave,
}: ThemeEditorProps) {
    const [draft, setDraft] = useState<Theme>(value);
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
                        onClick={() => onSave(draft)}
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
                                            {radiusLabel(preset.radius)} · {fontLabel(preset.font)}
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
                            <fieldset
                                className="grid grid-cols-3 gap-1.5"
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
                                        className="px-2 py-2 rounded-lg text-[13px] font-medium transition border"
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
                                        {fontLabel(key)}
                                    </button>
                                ))}
                            </fieldset>
                        </div>

                        <div>
                            <label className="label" htmlFor="theme-logo">
                                Logo URL{' '}
                                <span style={{ color: 'var(--faint)' }}>(https, optional)</span>
                            </label>
                            <Input
                                id="theme-logo"
                                type="url"
                                spellCheck={false}
                                placeholder="https://acme.com/logo.svg"
                                value={draft.logo}
                                onChange={(event) =>
                                    setDraft((current) => ({
                                        ...current,
                                        logo: event.target.value,
                                    }))
                                }
                            />
                        </div>
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
                                <Icon name="shield" className="w-3 h-3" />
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
                            style={{
                                ...(vars as React.CSSProperties),
                                background: m.background,
                                color: m.foreground,
                                minHeight: '30rem',
                            }}
                        >
                            <div className="mx-auto w-full" style={{ maxWidth: '22rem' }}>
                                {draft.logo !== '' ? (
                                    <img
                                        src={draft.logo}
                                        alt={draft.name}
                                        style={{ maxHeight: '2rem', maxWidth: '11rem' }}
                                        onError={() =>
                                            setDraft((current) => ({ ...current, logo: '' }))
                                        }
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
