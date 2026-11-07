import { useTranslator as useConsoleTranslator } from '@/i18n';
import { router, useForm } from '@inertiajs/react';
import { useState } from 'react';
import ConsoleLayout from '@/layouts/ConsoleLayout';
import type { PageProps } from '@/types';
import { Button, ConfirmDelete, Field, Input, type MetadataRow, MetadataRows, Panel } from '@/ui';

type Props = PageProps<{
    organization: {
        id: string;
        name: string;
        slug: string;
        status: string;
        metadata: MetadataRow[];
    };
    urls: {
        update: string;
        suspend: string;
        reactivate: string;
        destroy: string;
    };
}>;

/**
 * AN ORGANIZATION › SETTINGS — its name, handle and metadata; suspending it; deleting it.
 * The header and tabs are the layout's (`../frame.tsx`).
 */
export default function OrganizationSettings({ organization, urls }: Props) {
    const { t: consoleUiText } = useConsoleTranslator();
    const { t: consoleText } = useConsoleTranslator();
    const { t: consoleT } = useConsoleTranslator();
    const [deleting, setDeleting] = useState(false);

    const suspended = organization.status === 'suspended';

    return (
        <div className="space-y-6">
            <Details organization={organization} href={urls.update} />

            <Panel
                title={
                    suspended
                        ? consoleText('console.reactivate_organization_7a2cb32e')
                        : consoleText('console.suspend_organization_33c2d142')
                }
                description={
                    suspended
                        ? consoleText(
                              'console.its_people_can_sign_in_again_with_the_access_they_had_dceea1d5',
                          )
                        : consoleText(
                              'console.its_people_are_refused_at_every_door_sign_in_the_device_5c2c7648',
                          )
                }
            >
                <Button
                    size="sm"
                    onClick={() =>
                        router.post(
                            suspended ? urls.reactivate : urls.suspend,
                            {},
                            { preserveScroll: true },
                        )
                    }
                >
                    {suspended
                        ? consoleText('console.reactivate_2dc7478f')
                        : consoleText('console.suspend_4948e134')}
                </Button>
            </Panel>

            <Panel
                title={consoleT('console.delete_organization_a70d189a')}
                description={consoleT(
                    'console.it_disappears_from_every_list_and_its_people_are_refuse_49a9035c',
                )}
            >
                <Button size="sm" variant="danger" onClick={() => setDeleting(true)}>
                    {consoleT('console.delete_organization_a70d189a')}
                </Button>
            </Panel>

            <ConfirmDelete
                open={deleting}
                onOpenChange={setDeleting}
                name={organization.name}
                consequence={consoleUiText(
                    'console.everyone_in_this_organization_is_refused_at_every_door_40e035a5',
                )}
                onConfirm={() => {
                    setDeleting(false);
                    router.delete(urls.destroy);
                }}
            />
        </div>
    );
}

function Details({ organization, href }: { organization: Props['organization']; href: string }) {
    const { t: consoleT } = useConsoleTranslator();
    const form = useForm({
        name: organization.name,
        slug: organization.slug,
        metadata: organization.metadata,
    });

    return (
        <Panel title={consoleT('console.details_45989de4')}>
            <form
                className="space-y-4"
                onSubmit={(event) => {
                    event.preventDefault();
                    form.patch(href, { preserveScroll: true });
                }}
            >
                <div className="grid gap-4 sm:grid-cols-2">
                    <Field label={consoleT('console.name_dcd1d522')} error={form.errors.name}>
                        <Input
                            name="name"
                            value={form.data.name}
                            onChange={(event) => form.setData('name', event.target.value)}
                        />
                    </Field>

                    <Field
                        label={consoleT('console.url_handle_0b9f3241')}
                        hint={consoleT(
                            'console.other_systems_may_be_using_this_changing_it_changes_the_41e0f485',
                        )}
                        error={form.errors.slug}
                    >
                        <Input
                            name="slug"
                            className="mono"
                            value={form.data.slug}
                            onChange={(event) => form.setData('slug', event.target.value)}
                        />
                    </Field>
                </div>

                <MetadataRows
                    rows={form.data.metadata}
                    onChange={(rows) => form.setData('metadata', rows)}
                    hint={consoleT(
                        'console.anything_your_own_systems_need_to_keep_against_this_org_c911b38e',
                    )}
                />

                <Button type="submit" variant="primary" loading={form.processing}>
                    {consoleT('console.save_changes_dd0ae7a5')}
                </Button>
            </form>
        </Panel>
    );
}

OrganizationSettings.layout = (page: React.ReactNode) => <ConsoleLayout>{page}</ConsoleLayout>;
