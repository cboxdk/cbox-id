import { Link, useForm } from '@inertiajs/react';
import ConsoleLayout from '@/layouts/ConsoleLayout';
import type { PageProps } from '@/types';
import { Button, Field, Icon, Input, PageHeader, Panel } from '@/ui';
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
            <Link
                href={showHref}
                className="text-sm inline-flex items-center gap-1"
                style={{ color: 'var(--muted-foreground)' }}
            >
                <Icon
                    name="chevron"
                    className="w-3.5 h-3.5"
                    style={{ transform: 'rotate(90deg)' }}
                />
                {stream.name}
            </Link>

            <div className="mt-2">
                <PageHeader description="Change where this stream delivers, or the credential it uses. Saving checks the settings again and retries delivery on the next run." />
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
                <Panel title="Name">
                    <Field label="Name" error={form.errors.name}>
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
                        Save stream
                    </Button>
                    <Button asChild>
                        <Link href={showHref}>Cancel</Link>
                    </Button>
                </div>
            </form>
        </>
    );
}

EditLogStream.layout = (page: React.ReactNode) => <ConsoleLayout>{page}</ConsoleLayout>;
