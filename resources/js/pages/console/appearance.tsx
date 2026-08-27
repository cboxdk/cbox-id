import { router, useForm, usePage } from '@inertiajs/react';
import ConsoleLayout from '@/layouts/ConsoleLayout';
import type { FontStacks, Theme, ThemeCatalogue } from '@/lib/appearance';
import type { HelpContent, PageProps } from '@/types';
import { EmptyState, PageHeader, RadioGroup, ThemeEditor } from '@/ui';

type Props = PageProps<{
    help: HelpContent;
    /** NOT `theme`: the shell shares a prop by that name — see the controller. */
    appearance: Theme;
    presets: ThemeCatalogue;
    fonts: FontStacks;
    radii: string[];
    /** Only the environment plane may theme the default every organization inherits. */
    mayThemeEnvironment: boolean;
    environmentDefault: boolean;
    organizationName: string | null;
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
    radii,
    mayThemeEnvironment,
    environmentDefault,
    organizationName,
    hasTarget,
    saveHref,
}: Props) {
    const { errors } = usePage().props;

    const form = useForm<{
        theme: Theme;
        logo: string;
        environmentDefault: boolean;
    }>({ theme: appearance, logo: appearance.logo, environmentDefault });

    return (
        <>
            {mayThemeEnvironment && (
                /*
                    The environment plane's own capability, made explicit. It was never an
                    organization: it is the default every organization here inherits, and
                    the console chrome's organization picker has no way to say that.

                    The choice is in the URL rather than in component state, because it
                    decides WHICH RECORD is loaded — the editor has to be re-seeded from
                    the server when it changes, or the preview shows one thing and Save
                    writes another.
                */
                <div className="card p-4 mb-6">
                    <RadioGroup
                        label="What you are theming"
                        value={environmentDefault ? 'environment' : 'organization'}
                        onValueChange={(target) =>
                            router.get(
                                window.location.pathname,
                                { target },
                                { preserveScroll: true },
                            )
                        }
                        options={[
                            {
                                value: 'environment',
                                label: 'Environment default',
                                hint: 'inherited by every organization that has not set its own',
                            },
                            ...(organizationName !== null
                                ? [
                                      {
                                          value: 'organization',
                                          label: organizationName,
                                          hint: 'overrides the default for this organization alone',
                                      },
                                  ]
                                : []),
                        ]}
                    />
                </div>
            )}

            {hasTarget ? (
                <ThemeEditor
                    // KEYED BY TARGET. The editor holds its draft in local state, so
                    // switching what is being themed has to give it a new one — otherwise
                    // the environment's colours stay on screen above the organization's
                    // Save button.
                    key={environmentDefault ? 'environment' : 'organization'}
                    value={appearance}
                    presets={presets}
                    fonts={fonts}
                    radii={radii}
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
                    onSave={(next) => {
                        form.transform(() => ({
                            theme: next,
                            logo: next.logo,
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
                        description="Choose an organization to theme its sign-in page, or switch to the environment default above to set the theme every organization inherits."
                    />
                </>
            )}
        </>
    );
}

AppearancePage.layout = (page: React.ReactNode) => <ConsoleLayout>{page}</ConsoleLayout>;
