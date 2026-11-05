import { useTranslator } from '@/i18n';
import { Link, useForm } from '@inertiajs/react';
import ConsoleLayout from '@/layouts/ConsoleLayout';
import type { PageProps } from '@/types';
import { Breadcrumb, Button, Field, Input, PageHeader, Panel } from '@/ui';
import {
    DestinationFields,
    emptyOptions,
    type StreamChoices,
    type StreamForm,
    type StreamOptionsForm,
    submittable,
} from './fields';

type Props = PageProps<
    StreamChoices & {
        stream: {
            id: string;
            name: string;
            destination: string;
            /** Empty when a cloud destination is on its own endpoint. */
            endpointUrl: string;
            scheme: string;
            options: Partial<StreamOptionsForm>;
            /** Whether a credential is on file — never the credential. */
            hasSecret: boolean;
        };
        showHref: string;
        updateHref: string;
    }
>;

/**
 * Edit a stream — where it goes and the credential it uses. Saving re-checks the settings
 * and resets the stream's failure count, so a stream that needed action is tried again on
 * the next run. The credential field starts empty and empty keeps the one on file.
 */
export default function EditLogStream({
    stream,
    destinations,
    schemes,
    datadogSites,
    assumedRoleAvailable,
    showHref,
    updateHref,
}: Props) {
    const { t: tc } = useTranslator();

    const form = useForm<StreamForm>({
        name: stream.name,
        destination: stream.destination,
        endpointUrl: stream.endpointUrl,
        scheme: stream.scheme,
        secret: '',
        credential: stream.options.role_arn ? 'role' : 'access_key',
        options: { ...emptyOptions, ...stream.options },
    });

    return (
        <>
            <Breadcrumb href={showHref} label={stream.name} />

            <div className="mt-2">
                <PageHeader
                    description={tc(
                        'console.change_where_this_stream_delivers_or_the_cre_e8fc2e8f',
                    )}
                />
            </div>

            <form
                className="mt-6 space-y-6"
                style={{ maxWidth: '36rem' }}
                onSubmit={(event) => {
                    event.preventDefault();
                    form.transform(submittable);
                    form.patch(updateHref, { onFinish: () => form.setData('secret', '') });
                }}
            >
                <Panel title={tc('console.name_dcd1d522')}>
                    <Field label={tc('console.name_dcd1d522')} error={form.errors.name}>
                        <Input
                            name="name"
                            value={form.data.name}
                            onChange={(event) => form.setData('name', event.target.value)}
                        />
                    </Field>
                </Panel>

                <DestinationFields
                    form={form}
                    choices={{ destinations, schemes, datadogSites, assumedRoleAvailable }}
                    hasSecret={stream.hasSecret && form.data.destination === stream.destination}
                />

                <div className="flex items-center gap-2">
                    <Button type="submit" variant="primary" loading={form.processing}>
                        {tc('console.save_stream_0884b3e9')}
                    </Button>
                    <Button asChild>
                        <Link href={showHref}>{tc('console.cancel_19766ed6')}</Link>
                    </Button>
                </div>
            </form>
        </>
    );
}

EditLogStream.layout = (page: React.ReactNode) => <ConsoleLayout>{page}</ConsoleLayout>;
