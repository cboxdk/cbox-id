import { useTranslator as useConsoleTranslator } from '@/i18n';
import { Link, useForm } from '@inertiajs/react';
import ConsoleLayout from '@/layouts/ConsoleLayout';
import type { PageProps } from '@/types';
import { Breadcrumb, Button, Field, Input, PageHeader, Panel } from '@/ui';
import {
    DestinationFields,
    emptyOptions,
    type StreamChoices,
    type StreamForm,
    submittable,
} from './fields';

type Props = PageProps<
    StreamChoices & {
        /** True on the environment plane, where a stream carries EVERY organization's trail. */
        shipsWholeEnvironment: boolean;
        /** The destination the link asked for (`?destination=`), preselected; null for the default. */
        selected: string | null;
        indexHref: string;
        storeHref: string;
    }
>;

export default function CreateLogStream({
    destinations,
    schemes,
    datadogSites,
    assumedRoleAvailable,
    shipsWholeEnvironment,
    selected,
    indexHref,
    storeHref,
}: Props) {
    const { t: consoleUiText } = useConsoleTranslator();
    const { t: consoleText } = useConsoleTranslator();
    const { t: consoleT } = useConsoleTranslator();
    const first =
        destinations.find((destination) => destination.value === selected) ?? destinations[0];
    const form = useForm<StreamForm>({
        name: '',
        destination: first?.value ?? 'generic_json',
        endpointUrl: '',
        scheme: first?.defaultAuth ?? 'none',
        secret: '',
        credential: 'access_key',
        options: emptyOptions,
    });

    return (
        <>
            <Breadcrumb href={indexHref} label={consoleT('console.log_streams_aff03056')} />

            <div className="mt-2">
                <PageHeader
                    description={consoleT(
                        'console.where_a_copy_of_every_audit_entry_is_delivered_as_it_is_c6b9e401',
                    )}
                />
            </div>

            <form
                className="mt-6 space-y-6"
                style={{ maxWidth: '36rem' }}
                onSubmit={(event) => {
                    event.preventDefault();
                    form.transform(submittable);
                    form.post(storeHref, { onFinish: () => form.setData('secret', '') });
                }}
            >
                <Panel
                    title={consoleT('console.what_it_carries_15f5b403')}
                    description={
                        /*
                         * SAID BEFORE IT IS CREATED. The two planes mint materially
                         * different things from an identical form — one stream receives
                         * every tenant's entries — and which one you get depends on a
                         * console you are already inside.
                         */
                        shipsWholeEnvironment
                            ? consoleText(
                                  'console.every_organization_s_entries_in_this_environment_includ_1e2838e1',
                              )
                            : consoleText(
                                  'console.this_organization_s_entries_and_nothing_from_any_other_1ab06e62',
                              )
                    }
                >
                    <Field label={consoleT('console.name_dcd1d522')} error={form.errors.name}>
                        <Input
                            name="name"
                            placeholder={consoleUiText('console.splunk_production_96e53a09')}
                            value={form.data.name}
                            onChange={(event) => form.setData('name', event.target.value)}
                        />
                    </Field>
                </Panel>

                <DestinationFields
                    form={form}
                    choices={{ destinations, schemes, datadogSites, assumedRoleAvailable }}
                />

                <div className="flex items-center gap-2">
                    <Button type="submit" variant="primary" loading={form.processing}>
                        {consoleT('console.create_stream_cd51d4b0')}
                    </Button>
                    <Button asChild>
                        <Link href={indexHref}>{consoleT('console.cancel_19766ed6')}</Link>
                    </Button>
                </div>
            </form>
        </>
    );
}

CreateLogStream.layout = (page: React.ReactNode) => <ConsoleLayout>{page}</ConsoleLayout>;
