import { useTranslator as usePublicTranslator } from '@/i18n';
import { useTranslator as useConsoleTranslator } from '@/i18n';
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
    /** The tenant base domain, for the preview's address bar; null without subdomains. */
    previewBaseDomain: string | null;
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
    previewBaseDomain,
    remoteLogoIgnored,
    imagesAccepted,
    mayThemeEnvironment,
    environmentDefault,
    hasTarget,
    saveHref,
    profile,
}: Props) {
    const { t: consoleText } = useConsoleTranslator();
    const { t: consoleT } = useConsoleTranslator();
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
                    {consoleT(
                        'console.the_environment_default_inherited_by_every_organization_19ddab20',
                    )}
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
                    baseDomain={previewBaseDomain}
                    remoteLogoIgnored={remoteLogoIgnored}
                    imagesAccepted={imagesAccepted}
                    imageErrors={{ logo: message('logo'), favicon: message('favicon') }}
                    help={help}
                    title={consoleT('console.branding_d417aa16')}
                    scope={environmentDefault ? 'environment' : 'organization'}
                    error={message('theme') ?? null}
                    description={
                        environmentDefault
                            ? consoleText(
                                  'console.your_environment_s_default_brand_the_hosted_sign_in_s_l_aae9270b',
                              )
                            : consoleText(
                                  'console.this_organization_s_brand_it_overrides_the_environment_ffdddbcc',
                              )
                    }
                    onChange={onThemeChange}
                />
            ) : (
                <>
                    <PageHeader
                        help={help}
                        description={consoleT(
                            'console.the_hosted_sign_in_s_look_logo_and_favicon_and_the_name_39304e21',
                        )}
                    />
                    <EmptyState
                        icon="settings"
                        title={consoleT('console.nothing_to_theme_yet_24b02640')}
                        description={consoleT(
                            'console.there_is_no_organization_or_environment_here_to_theme_78caef51',
                        )}
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
                title={consoleT('console.leave_without_saving_2190d03a')}
                description={consoleT(
                    'console.your_branding_changes_have_not_been_saved_leave_this_pa_1a8dbb2f',
                )}
                footer={
                    <>
                        <Button onClick={guard.stay}>
                            {consoleT('console.keep_editing_e76fd2ad')}
                        </Button>
                        <Button variant="danger" onClick={guard.leave}>
                            {consoleT('console.discard_and_leave_07e5b915')}
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
    const { t: consoleText } = useConsoleTranslator();
    const { t: consoleT } = useConsoleTranslator();
    if (!dirty && !saving) {
        return null;
    }

    return (
        <section
            className="cbx-savebar"
            aria-label={consoleT('console.unsaved_changes_a710c2b9')}
            data-testid="branding-save-bar"
        >
            <output className="cbx-savebar-status">
                <Icon
                    name={refused ? 'warning' : 'info'}
                    className="w-4 h-4 shrink-0"
                    style={{ color: refused ? 'var(--destructive)' : 'var(--accent-strong)' }}
                />
                <span className="min-w-0">
                    {refused
                        ? consoleText('console.not_saved_see_the_marked_fields_a29c28a1')
                        : consoleText('console.unsaved_changes_a710c2b9')}
                </span>
            </output>
            <div className="cbx-savebar-actions">
                <Button size="sm" onClick={onDiscard} disabled={saving}>
                    {consoleT('console.discard_eb1a70e3')}
                </Button>
                <Button size="sm" variant="primary" icon="check" loading={saving} onClick={onSave}>
                    {consoleT('console.save_changes_dd0ae7a5')}
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
    const { t: tc } = usePublicTranslator();
    const { t: consoleRuntimeText } = useConsoleTranslator();
    const { t: consoleText } = useConsoleTranslator();
    const { rich: consoleRich } = useConsoleTranslator();
    const { t: consoleT } = useConsoleTranslator();
    const [paletteOpen, setPaletteOpen] = useState(
        Object.values(value.palette).some((colour) => colour !== '') ||
            Object.keys(errors).some((key) => key.startsWith('profile.palette.')),
    );
    const set = <K extends keyof ProfileDraft>(key: K, next: ProfileDraft[K]): void =>
        onChange({ ...value, [key]: next });

    return (
        <Panel
            className="mt-6"
            title={consoleT('console.name_email_fe6014fd')}
            description={consoleT(
                'console.what_your_people_see_in_place_of_cbox_id_and_who_your_m_32cb946d',
            )}
        >
            <div className="space-y-4">
                <div className="grid gap-3 sm:grid-cols-2">
                    <Field
                        id="brand-app-name"
                        label={consoleT('console.product_name_bfa93eb4')}
                        error={errors['profile.appName']}
                    >
                        <Input
                            placeholder={tc('console.acme_id_b3b9b121')}
                            value={value.appName}
                            onChange={(event) => set('appName', event.target.value)}
                        />
                    </Field>
                    <Field
                        id="brand-email-from"
                        label={consoleT('console.email_sender_name_77092ffe')}
                        error={errors['profile.emailFromName']}
                    >
                        <Input
                            placeholder={tc('console.acme_security_ab6e66b5')}
                            value={value.emailFromName}
                            onChange={(event) => set('emailFromName', event.target.value)}
                        />
                    </Field>
                </div>

                <Field
                    id="brand-email-template"
                    label={consoleT('console.welcome_email_339769e7')}
                    hint={consoleT(
                        'console.sent_when_someone_s_account_is_created_leave_it_empty_f_c7c58d7f',
                    )}
                    error={errors['profile.emailTemplate']}
                >
                    <Textarea
                        rows={4}
                        placeholder={consoleRuntimeText(
                            'console.welcome_to_app_your_account_is_ready_33ef53b0',
                        )}
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
                        {consoleRich('console._part1_console_colours_50cf1681', {
                            part1: paletteOpen
                                ? consoleText('console.hide_ac20a57b')
                                : consoleText('console.show_0df6f1ca'),
                        })}
                    </button>
                    {paletteOpen && (
                        <div id="brand-console-palette" className="mt-3">
                            <p
                                className="text-[12px] mb-3"
                                style={{ color: 'var(--muted-foreground)' }}
                            >
                                {consoleT(
                                    'console.the_admin_console_s_own_palette_as_hex_0a2540_or_oklch_71ec4917',
                                )}
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
                                            placeholder={tc('console.0a2540_7028d88f')}
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
