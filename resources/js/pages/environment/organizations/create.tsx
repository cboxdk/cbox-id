import { useTranslator } from '@/i18n';
import { Link, useForm } from '@inertiajs/react';
import { useState } from 'react';
import ConsoleLayout from '@/layouts/ConsoleLayout';
import type { PageProps } from '@/types';
import {
    Breadcrumb,
    Button,
    Field,
    Icon,
    Input,
    type MetadataRow,
    MetadataRows,
    PageHeader,
    Panel,
} from '@/ui';

type Props = PageProps<{
    indexHref: string;
    storeHref: string;
}>;

export default function CreateOrganization({ indexHref, storeHref }: Props) {
    const { t: tc } = useTranslator();

    const form = useForm({
        name: '',
        slug: '',
        metadata: [] as MetadataRow[],
    });

    const [advanced, setAdvanced] = useState(false);

    return (
        <>
            <Breadcrumb href={indexHref} label={tc('console.organizations_2730183d')} />

            <div className="mt-2">
                <PageHeader
                    description={tc('console.its_id_and_url_handle_are_generated_for_you_9f822704')}
                />
            </div>

            <form
                className="mt-6 space-y-6"
                style={{ maxWidth: '36rem' }}
                onSubmit={(event) => {
                    event.preventDefault();
                    form.post(storeHref);
                }}
            >
                <Panel>
                    <div className="space-y-4">
                        <Field label={tc('console.name_dcd1d522')} error={form.errors.name}>
                            <Input
                                name="name"
                                placeholder={tc('console.acme_inc_42cf7a26')}
                                value={form.data.name}
                                onChange={(event) => form.setData('name', event.target.value)}
                            />
                        </Field>

                        {/*
                            FOLDED AWAY, because the whole point of this form is that a
                            name is enough — the handle is derived and the metadata is for
                            people who already know they want it. Opening it is a choice,
                            not a step.
                        */}
                        <div>
                            <Button
                                type="button"
                                size="sm"
                                aria-expanded={advanced}
                                onClick={() => setAdvanced((open) => !open)}
                            >
                                <Icon name="settings" className="w-3.5 h-3.5" />
                                {tc('console.advanced_9f088dbe')}
                            </Button>

                            {advanced && (
                                <div
                                    className="mt-3 space-y-4 rounded-lg border p-4"
                                    style={{ borderColor: 'var(--border)' }}
                                >
                                    <Field
                                        label={tc('console.url_handle_0b9f3241')}
                                        optional
                                        hint={tc(
                                            'console.derived_from_the_name_if_you_leave_it_blank__0e837564',
                                        )}
                                        error={form.errors.slug}
                                    >
                                        <Input
                                            name="slug"
                                            className="mono"
                                            placeholder={tc('console.acme_inc_0efa1595')}
                                            value={form.data.slug}
                                            onChange={(event) =>
                                                form.setData('slug', event.target.value)
                                            }
                                        />
                                    </Field>

                                    <MetadataRows
                                        rows={form.data.metadata}
                                        onChange={(rows) => form.setData('metadata', rows)}
                                        hint={tc(
                                            'console.anything_your_own_systems_need_to_keep_again_c911b38e',
                                        )}
                                    />
                                </div>
                            )}
                        </div>
                    </div>
                </Panel>

                <div className="flex items-center gap-2">
                    <Button type="submit" variant="primary" loading={form.processing}>
                        {tc('console.create_organization_f14e1b22')}
                    </Button>
                    <Button asChild>
                        <Link href={indexHref}>{tc('console.cancel_19766ed6')}</Link>
                    </Button>
                </div>
            </form>
        </>
    );
}

CreateOrganization.layout = (page: React.ReactNode) => <ConsoleLayout>{page}</ConsoleLayout>;
