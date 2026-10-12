import { router, usePage } from '@inertiajs/react';
import { useCallback, useMemo, useRef, useState } from 'react';
import ConsoleLayout from '@/layouts/ConsoleLayout';
import type { FontStacks, Theme, ThemeCatalogue } from '@/lib/appearance';
import { useUnsavedChangesGuard } from '@/lib/unsavedChanges';
import type { HelpContent, PageProps } from '@/types';
import {
    Button,
    Dialog,
    EmptyState,
    Field,
    Icon,
    Input,
    PageHeader,
    Panel,
    Textarea,
    ThemeEditor,
} from '@/ui';
import type { ImageChanges, ImageKind } from '@/ui/ThemeEditor';

type Props = PageProps<{
    help: HelpContent;
    /** NOT `theme`: the shell shares a prop by that name — see the controller. */
    appearance: Theme;
    presets: ThemeCatalogue;
    fonts: FontStacks;
    /** Each face's own name — `ThemeFont::labels()`. */
    fontLabels: Record<string, string>;
    radii: string[];
    /** A legacy remote logo URL is stored here and no longer drawn: ask for an upload. */
    remoteLogoIgnored: boolean;
    /** Whether this install can store images (the white-label module). */
    imagesAccepted: boolean;
    /** Only the environment plane may theme the default every organization inherits. */
    mayThemeEnvironment: boolean;
    environmentDefault: boolean;
    hasTarget: boolean;
    /**
     * Where the page's one Save posts — the theme, its images, and the name & email.
     *
     * Stated by the SERVER rather than resolved from the controller action: the same
     * action is registered on both planes, so the generated helper cannot say which of
     * the two URLs this page belongs to — and guessing would post one plane's theme at
     * the other plane's route.
     */
    saveHref: string;
    /**
     * The white-label half — the product's name, the email sender, the welcome mail and the
     * console palette — or null without the white-label module. Saved with the theme.
     */
    profile: BrandProfile | null;
}>;

interface BrandProfile {
    tokens: string[];
    palette: Record<string, string>;
    appName: string;
    emailFromName: string;
    emailTemplate: string;
}

type ProfileDraft = Omit<BrandProfile, 'tokens'>;

const profileDraft = (profile: BrandProfile | null): ProfileDraft | null =>
    profile === null
        ? null
        : {
              palette: profile.palette,
              appName: profile.appName,
              emailFromName: profile.emailFromName,
              emailTemplate: profile.emailTemplate,
          };

/** The theme without its editor-only fields — the images travel on their own. */
const themeKey = ({ logo: _logo, favicon: _favicon, name: _name, ...theme }: Theme): string =>
    JSON.stringify(theme);

/** The images that differ from what is stored — a pick then a Remove of a new one is none. */
function changedImages(images: ImageChanges, stored: Theme): ImageChanges {
    const changed: ImageChanges = {};

    for (const kind of ['logo', 'favicon'] as ImageKind[]) {
        const next = images[kind];

        if (next !== undefined && (next ?? '') !== stored[kind]) {
            changed[kind] = next;
        }
    }

    return changed;
}

const PROFILE_FIELDS = ['profile.appName', 'profile.emailFromName', 'profile.emailTemplate'];

/**
 * CONSOLE › BRANDING — one page for everything a customer's people see of the brand, and
 * ONE Save for all of it.
 *
 * It was two pages, then one page with two Saves: "Save changes" in the header for the
 * sign-in theme, logo and favicon, and "Save name & email" at the foot of the panel below.
 * An administrator uploaded a logo, scrolled down, pressed the Save in front of them, read
 * "Branding saved." — and lost the logo on the next load, because that button never sent
 * it. Now every edit on the page marks it unsaved, a bar at the bottom of the screen says
 * so wherever the person has scrolled, and its Save sends everything that changed in one
 * request. Leaving with changes unsaved asks first.
 */
export default function BrandingPage({
    help,
    appearance,
    presets,
    fonts,
    fontLabels,
    radii,
    remoteLogoIgnored,
    imagesAccepted,
    mayThemeEnvironment,
    environmentDefault,
    hasTarget,
    saveHref,
    profile,
}: Props) {
    const { errors } = usePage().props;

    // The editor's draft, as it last reported it. A ref AND state: the ref is what Save
    // reads (never a stale closure), the state is what redraws the bar.
    const [theme, setTheme] = useState<{ draft: Theme; images: ImageChanges }>({
        draft: appearance,
        images: {},
    });
    const latest = useRef(theme);

    // Bumped to throw the editor's draft away: on Discard, and after a save, so it starts
    // again from what is now stored.
    const [generation, setGeneration] = useState(0);
    const [names, setNames] = useState<ProfileDraft | null>(() => profileDraft(profile));
    const [saving, setSaving] = useState(false);

    const onThemeChange = useCallback((draft: Theme, images: ImageChanges) => {
        latest.current = { draft, images };
        setTheme(latest.current);
    }, []);

    const images = changedImages(theme.images, appearance);
    const themeDirty =
        themeKey(theme.draft) !== themeKey(appearance) || Object.keys(images).length > 0;
    const storedNames = useMemo(() => JSON.stringify(profileDraft(profile)), [profile]);
    const namesDirty = names !== null && JSON.stringify(names) !== storedNames;
    const dirty = themeDirty || namesDirty;

    const guard = useUnsavedChangesGuard(dirty && !saving);

    const message = (key: string): string | undefined =>
        typeof errors[key] === 'string' ? errors[key] : undefined;

    const refused =
        ['theme', 'logo', 'favicon', ...PROFILE_FIELDS].some((key) => message(key) !== undefined) ||
        Object.keys(errors).some((key) => key.startsWith('profile.palette.'));

    const discard = (): void => {
        setGeneration((current) => current + 1);
        setNames(profileDraft(profile));
    };

    const save = (): void => {
        const current = latest.current;
        const changed = changedImages(current.images, appearance);
        const sendTheme =
            themeKey(current.draft) !== themeKey(appearance) || Object.keys(changed).length > 0;

        const payload = {
            environmentDefault,
            // Only the halves that changed travel. The theme's own `logo`/`favicon` are
            // previews, not input: the images go as `images`, a data URI to store or null
            // to remove, and only when they changed.
            ...(sendTheme ? { theme: current.draft, images: changed } : {}),
            ...(namesDirty && names !== null ? { profile: names } : {}),
        };

        // Plain JSON; the cast is only Inertia's index-signature type for a payload.
        router.post(saveHref, payload as unknown as Parameters<typeof router.post>[1], {
            preserveScroll: true,
            onStart: () => setSaving(true),
            onFinish: () => setSaving(false),
            // A refusal is marked on its field, which may be a long way up the page from
            // the bar that was pressed: take the person to the first one.
            onError: () =>
                requestAnimationFrame(() =>
                    document
                        .querySelector(
                            '#main-content .field-error:not([hidden]), #main-content [role="alert"]:not([hidden])',
                        )
                        ?.scrollIntoView({ block: 'center', behavior: 'smooth' }),
                ),
            onSuccess: (page) => {
                // Everything sent is stored: start again from the server's copy — its
                // own URLs for the images, so a pending upload is never sent twice.
                setGeneration((value) => value + 1);
                setNames(
                    profileDraft(
                        (page.props as unknown as { profile: BrandProfile | null }).profile,
                    ),
                );
            },
        });
    };

    return (
        <>
            {mayThemeEnvironment && environmentDefault && (
                /*
                    WHICH THING IS BEING THEMED is this page's address. This is the
                    environment default every organization inherits; one organization's own
                    theme is its Branding tab, under Organizations — a page of its own, so the
                    editor is never seeded from one record and saved to another.
                */
                <p className="mb-4 text-sm" style={{ color: 'var(--muted-foreground)' }}>
                    The environment default, inherited by every organization that has not set its
                    own. An organization's own brand is on its page, on the Branding tab.
                </p>
            )}

            {hasTarget ? (
                <ThemeEditor
                    // KEYED BY TARGET. The editor holds its draft in local state, so
                    // switching what is being themed has to give it a new one — otherwise
                    // the environment's colours stay on screen above the organization's
                    // Save button.
                    //
                    // …by the STORED images: after a save that uploaded one, the server
                    // hands back its own URL, and the editor starts again from what is now
                    // stored — so the pending upload is spent rather than sent a second time
                    // with the next colour change.
                    //
                    // …and by GENERATION: Discard, and a save that went through.
                    key={`${environmentDefault ? 'environment' : 'organization'}|${appearance.logo}|${appearance.favicon}|${generation}`}
                    value={appearance}
                    presets={presets}
                    fonts={fonts}
                    fontLabels={fontLabels}
                    radii={radii}
                    remoteLogoIgnored={remoteLogoIgnored}
                    imagesAccepted={imagesAccepted}
                    imageErrors={{ logo: message('logo'), favicon: message('favicon') }}
                    help={help}
                    title="Branding"
                    scope={environmentDefault ? 'environment' : 'organization'}
                    error={message('theme') ?? null}
                    description={
                        environmentDefault
                            ? "Your environment's default brand: the hosted sign-in's look, logo and favicon, and the name and sender your people see. Every organization inherits it unless it sets its own."
                            : "This organization's brand — it overrides the environment default. Changes preview live and apply to its hosted sign-in."
                    }
                    onChange={onThemeChange}
                />
            ) : (
                <>
                    <PageHeader
                        help={help}
                        description="The hosted sign-in's look, logo and favicon, and the name and sender your people see."
                    />
                    <EmptyState
                        icon="settings"
                        title="Nothing to theme yet"
                        description="There is no organization or environment here to theme."
                    />
                </>
            )}

            {hasTarget && profile !== null && names !== null && (
                <ProfilePanel
                    tokens={profile.tokens}
                    value={names}
                    errors={errors}
                    onChange={setNames}
                />
            )}

            {hasTarget && (
                <SaveBar
                    dirty={dirty}
                    saving={saving}
                    refused={refused}
                    onDiscard={discard}
                    onSave={save}
                />
            )}

            <Dialog
                open={guard.pending !== null}
                onOpenChange={(open) => {
                    if (!open) {
                        guard.stay();
                    }
                }}
                size="sm"
                title="Leave without saving?"
                description="Your branding changes have not been saved. Leave this page and they are lost."
                footer={
                    <>
                        <Button onClick={guard.stay}>Keep editing</Button>
                        <Button variant="danger" onClick={guard.leave}>
                            Discard and leave
                        </Button>
                    </>
                }
            />
        </>
    );
}

/**
 * THE PAGE'S ONE SAVE, WHERE THE CHANGE WAS MADE.
 *
 * Pinned to the bottom of the scrolling page while anything is unsaved — above the phone's
 * navigation bar on a small screen — so it is in view beside the control just changed and
 * beside the preview, wherever the person has scrolled. It is not there at all while
 * nothing has changed: a Save that is always on screen says nothing about whether it is
 * needed.
 */
function SaveBar({
    dirty,
    saving,
    refused,
    onDiscard,
    onSave,
}: {
    dirty: boolean;
    saving: boolean;
    refused: boolean;
    onDiscard: () => void;
    onSave: () => void;
}) {
    if (!dirty && !saving) {
        return null;
    }

    return (
        <section
            className="cbx-savebar"
            aria-label="Unsaved changes"
            data-testid="branding-save-bar"
        >
            <output className="cbx-savebar-status">
                <Icon
                    name={refused ? 'warning' : 'info'}
                    className="w-4 h-4 shrink-0"
                    style={{ color: refused ? 'var(--destructive)' : 'var(--accent-strong)' }}
                />
                <span className="min-w-0">
                    {refused ? 'Not saved — see the marked fields' : 'Unsaved changes'}
                </span>
            </output>
            <div className="cbx-savebar-actions">
                <Button size="sm" onClick={onDiscard} disabled={saving}>
                    Discard
                </Button>
                <Button size="sm" variant="primary" icon="check" loading={saving} onClick={onSave}>
                    Save changes
                </Button>
            </div>
        </section>
    );
}

/**
 * NAME & EMAIL — the white-label module's half of the brand: what the product is called
 * where Cbox ID would otherwise say so, who mail comes from, the welcome mail, and (folded
 * away, because most people never need it) the console's own palette. Saved with the
 * theme, by the page's one Save.
 */
function ProfilePanel({
    tokens,
    value,
    errors,
    onChange,
}: {
    tokens: string[];
    value: ProfileDraft;
    errors: Record<string, string>;
    onChange: (next: ProfileDraft) => void;
}) {
    const [paletteOpen, setPaletteOpen] = useState(
        Object.values(value.palette).some((colour) => colour !== '') ||
            Object.keys(errors).some((key) => key.startsWith('profile.palette.')),
    );
    const set = <K extends keyof ProfileDraft>(key: K, next: ProfileDraft[K]): void =>
        onChange({ ...value, [key]: next });

    return (
        <Panel
            className="mt-6"
            title="Name & email"
            description="What your people see in place of Cbox ID, and who your mail comes from."
        >
            <div className="space-y-4">
                <div className="grid gap-3 sm:grid-cols-2">
                    <Field
                        id="brand-app-name"
                        label="Product name"
                        error={errors['profile.appName']}
                    >
                        <Input
                            placeholder="Acme ID"
                            value={value.appName}
                            onChange={(event) => set('appName', event.target.value)}
                        />
                    </Field>
                    <Field
                        id="brand-email-from"
                        label="Email sender name"
                        error={errors['profile.emailFromName']}
                    >
                        <Input
                            placeholder="Acme Security"
                            value={value.emailFromName}
                            onChange={(event) => set('emailFromName', event.target.value)}
                        />
                    </Field>
                </div>

                <Field
                    id="brand-email-template"
                    label="Welcome email"
                    hint="Sent when someone's account is created. Leave it empty for the default."
                    error={errors['profile.emailTemplate']}
                >
                    <Textarea
                        rows={4}
                        placeholder="Welcome to {app}. Your account is ready."
                        value={value.emailTemplate}
                        onChange={(event) => set('emailTemplate', event.target.value)}
                    />
                </Field>

                <div>
                    <button
                        type="button"
                        className="text-[13px] font-medium inline-flex items-center gap-1.5"
                        style={{ color: 'var(--accent-strong)' }}
                        aria-expanded={paletteOpen}
                        aria-controls="brand-console-palette"
                        onClick={() => setPaletteOpen((open) => !open)}
                    >
                        {paletteOpen ? 'Hide' : 'Show'} console colours
                    </button>
                    {paletteOpen && (
                        <div id="brand-console-palette" className="mt-3">
                            <p
                                className="text-[12px] mb-3"
                                style={{ color: 'var(--muted-foreground)' }}
                            >
                                The admin console's own palette, as hex (#0a2540) or oklch(…). The
                                sign-in page takes its colours from the theme above.
                            </p>
                            <div className="grid gap-3 sm:grid-cols-2">
                                {tokens.map((token) => (
                                    <Field
                                        key={token}
                                        id={`brand-palette-${token}`}
                                        label={token.charAt(0).toUpperCase() + token.slice(1)}
                                        error={errors[`profile.palette.${token}`]}
                                    >
                                        <Input
                                            className="mono"
                                            spellCheck={false}
                                            placeholder="#0a2540"
                                            value={value.palette[token] ?? ''}
                                            onChange={(event) =>
                                                set('palette', {
                                                    ...value.palette,
                                                    [token]: event.target.value,
                                                })
                                            }
                                        />
                                    </Field>
                                ))}
                            </div>
                        </div>
                    )}
                </div>
            </div>
        </Panel>
    );
}

BrandingPage.layout = (page: React.ReactNode) => <ConsoleLayout>{page}</ConsoleLayout>;
