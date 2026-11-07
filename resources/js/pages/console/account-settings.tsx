import { useTranslator as usePublicTranslator } from '@/i18n';
import { useTranslator as useConsoleTranslator } from '@/i18n';
import { useForm } from '@inertiajs/react';
import ConsoleLayout from '@/layouts/ConsoleLayout';
import type { HelpContent, PageProps } from '@/types';
import { Button, Field, Input, PageHeader, Panel } from '@/ui';
import { update } from '@routes/organization-settings';

type Props = PageProps<{ name: string; help: HelpContent }>;

export default function AccountSettings({ name, help }: Props) {
    const { t: tc } = usePublicTranslator();
    const { t: consoleT } = useConsoleTranslator();
    const form = useForm({ name });

    return (
        <>
            <PageHeader
                help={help}
                description={consoleT(
                    'console.the_name_of_the_workspace_these_identity_providers_are_7bb1773d',
                )}
            />

            <form
                className="mt-6"
                onSubmit={(event) => {
                    event.preventDefault();
                    form.patch(update.url());
                }}
            >
                <Panel>
                    <div className="flex items-start gap-2">
                        <Field
                            label={consoleT('console.workspace_name_9619649d')}
                            hint={consoleT('console.shown_across_the_console_fe4b6d94')}
                            error={form.errors.name}
                            className="flex-1 max-w-sm"
                        >
                            <Input
                                name="name"
                                placeholder={tc('console.acme_inc_ee60673d')}
                                value={form.data.name}
                                onChange={(event) => form.setData('name', event.target.value)}
                            />
                        </Field>

                        <Button
                            type="submit"
                            variant="primary"
                            loading={form.processing}
                            style={{ marginTop: '1.9rem' }}
                        >
                            {consoleT('console.save_1509f561')}
                        </Button>
                    </div>
                </Panel>
            </form>

            {/*
                DELETION IS NOT A BUTTON, and its absence is the design. Deleting an
                account tears down every project and environment it owns — live identity
                providers other people's users are signing in through — so it is a
                conversation, not a control somebody would then have to be talked out of.
            */}
            <div className="mt-4">
                <Panel title={consoleT('console.delete_workspace_55f033db')}>
                    <p className="text-sm" style={{ color: 'var(--muted-foreground)' }}>
                        {consoleT(
                            'console.deleting_this_workspace_tears_down_every_project_and_en_f26b2e2f',
                        )}
                    </p>
                </Panel>
            </div>
        </>
    );
}

AccountSettings.layout = (page: React.ReactNode) => <ConsoleLayout>{page}</ConsoleLayout>;
