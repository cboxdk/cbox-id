import { useForm, usePage } from '@inertiajs/react';
import { useState } from 'react';
import ConsoleLayout from '@/layouts/ConsoleLayout';
import type { FontStacks, Theme, ThemeCatalogue } from '@/lib/appearance';
import type { HelpContent, PageProps } from '@/types';
import { Button, EmptyState, Field, Input, PageHeader, Panel, Textarea, ThemeEditor } from '@/ui';
import type { ImageChanges } from '@/ui/ThemeEditor';

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
     * Where Save posts.
     *
     * Stated by the SERVER rather than resolved from the controller action: the same
     * action is registered on both planes, so the generated helper cannot say which of
     * the two URLs this page belongs to — and guessing would post one plane's theme at
     * the other plane's route.
     */
    saveHref: string;
    /**
     * The white-label half — the product's name, the email sender, the welcome mail and the
     * console palette — or null without the white-label module. Saved by its own action
     * (`branding.whitelabel.set`) at `profileHref`.
     */
    profile: BrandProfile | null;
    profileHref: string;
}>;

interface BrandProfile {
    tokens: string[];
    palette: Record<string, string>;
    appName: string;
    emailFromName: string;
    emailTemplate: string;
}

/**
 * CONSOLE › BRANDING — one page for everything a customer's people see of the brand.
 *
 * It was two: "Appearance" (the sign-in theme, which took a logo URL) and the white-label
 * module's "Branding" (a palette, a name, a sender, and a logo upload nothing drew), and
 * the rail read "Branding › Branding". Now the sign-in look — theme, logo, favicon, live
 * preview — leads, and the name and email follow beneath it, each saved by its own action.
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
    profileHref,
}: Props) {
    const { errors } = usePage().props;

    const form = useForm<{
        theme: Theme;
        images: ImageChanges;
        environmentDefault: boolean;
    }>({ theme: appearance, images: {}, environmentDefault });

    const message = (key: string): string | undefined =>
        typeof errors[key] === 'string' ? errors[key] : undefined;

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
                    // …and by the STORED images: after a save that uploaded one, the server
                    // hands back its own URL, and the editor starts again from what is now
                    // stored — so the pending upload is spent rather than sent a second time
                    // with the next colour change.
                    key={`${environmentDefault ? 'environment' : 'organization'}|${appearance.logo}|${appearance.favicon}`}
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
                    saving={form.processing}
                    error={typeof errors.theme === 'string' ? errors.theme : null}
                    description={
                        environmentDefault
                            ? "Your environment's default brand: the hosted sign-in's look, logo and favicon, and the name and sender your people see. Every organization inherits it unless it sets its own."
                            : "This organization's brand — it overrides the environment default. Changes preview live and apply to its hosted sign-in."
                    }
                    onSave={(next, images) => {
                        // Only the images that changed travel: a data URI to store, null to
                        // remove. The theme's own `logo`/`favicon` are previews, not input.
                        form.transform(() => ({
                            theme: next,
                            images,
                            environmentDefault,
                        }));

                        form.post(saveHref, { preserveScroll: true });
                    }}
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

            {hasTarget && profile !== null && (
                <ProfilePanel
                    key={environmentDefault ? 'environment' : 'organization'}
                    profile={profile}
                    href={profileHref}
                />
            )}
        </>
    );
}

/**
 * NAME & EMAIL — the white-label module's half of the brand: what the product is called
 * where Cbox ID would otherwise say so, who mail comes from, the welcome mail, and (folded
 * away, because most people never need it) the console's own palette. Its own Save,
 * through its own action; the sign-in theme above is not re-sent with it.
 */
function ProfilePanel({ profile, href }: { profile: BrandProfile; href: string }) {
    const form = useForm({
        palette: profile.palette,
        appName: profile.appName,
        emailFromName: profile.emailFromName,
        emailTemplate: profile.emailTemplate,
    });
    const [paletteOpen, setPaletteOpen] = useState(
        Object.values(profile.palette).some((value) => value !== ''),
    );
    const errors = form.errors as Record<string, string | undefined>;

    return (
        <form
            className="mt-6"
            onSubmit={(event) => {
                event.preventDefault();
                form.post(href, { preserveScroll: true });
            }}
        >
            <Panel
                title="Name & email"
                description="What your people see in place of Cbox ID, and who your mail comes from."
            >
                <div className="space-y-4">
                    <div className="grid gap-3 sm:grid-cols-2">
                        <Field id="brand-app-name" label="Product name" error={form.errors.appName}>
                            <Input
                                placeholder="Acme ID"
                                value={form.data.appName}
                                onChange={(event) => form.setData('appName', event.target.value)}
                            />
                        </Field>
                        <Field
                            id="brand-email-from"
                            label="Email sender name"
                            error={form.errors.emailFromName}
                        >
                            <Input
                                placeholder="Acme Security"
                                value={form.data.emailFromName}
                                onChange={(event) =>
                                    form.setData('emailFromName', event.target.value)
                                }
                            />
                        </Field>
                    </div>

                    <Field
                        id="brand-email-template"
                        label="Welcome email"
                        hint="Sent when someone's account is created. Leave it empty for the default."
                        error={form.errors.emailTemplate}
                    >
                        <Textarea
                            rows={4}
                            placeholder="Welcome to {app}. Your account is ready."
                            value={form.data.emailTemplate}
                            onChange={(event) => form.setData('emailTemplate', event.target.value)}
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
                                    The admin console's own palette, as hex (#0a2540) or oklch(…).
                                    The sign-in page takes its colours from the theme above.
                                </p>
                                <div className="grid gap-3 sm:grid-cols-2">
                                    {profile.tokens.map((token) => (
                                        <Field
                                            key={token}
                                            id={`brand-palette-${token}`}
                                            label={token.charAt(0).toUpperCase() + token.slice(1)}
                                            error={errors[`palette.${token}`]}
                                        >
                                            <Input
                                                className="mono"
                                                spellCheck={false}
                                                placeholder="#0a2540"
                                                value={form.data.palette[token] ?? ''}
                                                onChange={(event) =>
                                                    form.setData('palette', {
                                                        ...form.data.palette,
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

                    <div>
                        <Button type="submit" variant="primary" loading={form.processing}>
                            Save name &amp; email
                        </Button>
                    </div>
                </div>
            </Panel>
        </form>
    );
}

BrandingPage.layout = (page: React.ReactNode) => <ConsoleLayout>{page}</ConsoleLayout>;
