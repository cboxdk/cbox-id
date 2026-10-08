import { Link, useForm } from '@inertiajs/react';
import ConsoleLayout from '@/layouts/ConsoleLayout';
import type { PageProps } from '@/types';
import { Button, Field, Icon, Input, PageHeader, Panel } from '@/ui';
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
    indexHref,
    storeHref,
}: Props) {
    const first = destinations[0];
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
            <Link
                href={indexHref}
                className="text-sm inline-flex items-center gap-1"
                style={{ color: 'var(--muted-foreground)' }}
            >
                <Icon
                    name="chevron"
                    className="w-3.5 h-3.5"
                    style={{ transform: 'rotate(90deg)' }}
                />
                Log streams
            </Link>

            <div className="mt-2">
                <PageHeader description="Where a copy of every audit entry is delivered as it is written." />
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
                    title="What it carries"
                    description={
                        /*
                         * SAID BEFORE IT IS CREATED. The two planes mint materially
                         * different things from an identical form — one stream receives
                         * every tenant's entries — and which one you get depends on a
                         * console you are already inside.
                         */
                        shipsWholeEnvironment
                            ? "Every organization's entries in this environment, including organizations other than your own."
                            : "This organization's entries, and nothing from any other organization."
                    }
                >
                    <Field label="Name" error={form.errors.name}>
                        <Input
                            name="name"
                            placeholder="Splunk — production"
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
                        Create stream
                    </Button>
                    <Button asChild>
                        <Link href={indexHref}>Cancel</Link>
                    </Button>
                </div>
            </form>
        </>
    );
}

CreateLogStream.layout = (page: React.ReactNode) => <ConsoleLayout>{page}</ConsoleLayout>;
