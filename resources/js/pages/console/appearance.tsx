import { useForm, usePage } from '@inertiajs/react';
import ConsoleLayout from '@/layouts/ConsoleLayout';
import type { FontStacks, Theme, ThemeCatalogue } from '@/lib/appearance';
import type { HelpContent, PageProps } from '@/types';
import { EmptyState, PageHeader, ThemeEditor } from '@/ui';
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
}>;

export default function AppearancePage({
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
                    own. An organization's own theme is on its page, under Settings › Branding.
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
                    title="Appearance"
                    scope={environmentDefault ? 'environment' : 'organization'}
                    saving={form.processing}
                    error={typeof errors.theme === 'string' ? errors.theme : null}
                    description={
                        environmentDefault
                            ? "Your environment's default sign-in theme. Every organization inherits it unless it sets its own."
                            : "This organization's sign-in theme — it overrides the environment default. Changes preview live and apply to its hosted sign-in."
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
                        description="The hosted sign-in theme: presets, colours, corners and type."
                    />
                    <EmptyState
                        icon="settings"
                        title="Nothing to theme yet"
                        description="There is no organization or environment here to theme."
                    />
                </>
            )}
        </>
    );
}

AppearancePage.layout = (page: React.ReactNode) => <ConsoleLayout>{page}</ConsoleLayout>;
