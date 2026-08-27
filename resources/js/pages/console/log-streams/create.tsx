import { Link, useForm } from '@inertiajs/react';
import ConsoleLayout from '@/layouts/ConsoleLayout';
import type { PageProps } from '@/types';
import { Button, Field, Icon, Input, PageHeader, Panel, Select } from '@/ui';

interface Option {
    value: string;
    label: string;
}

type Props = PageProps<{
    destinations: Option[];
    schemes: Option[];
    /** True on the environment plane, where a stream carries EVERY organization's trail. */
    shipsWholeEnvironment: boolean;
    indexHref: string;
    storeHref: string;
}>;

export default function CreateLogStream({
    destinations,
    schemes,
    shipsWholeEnvironment,
    indexHref,
    storeHref,
}: Props) {
    const form = useForm({
        name: '',
        destination: destinations[0]?.value ?? 'generic_json',
        endpointUrl: '',
        scheme: schemes[0]?.value ?? 'none',
        secret: '',
    });

    // Leaving the secret empty on the HMAC scheme is what asks for a generated key, and
    // that key is shown exactly once. Worth saying beside the field rather than leaving
    // somebody to discover it by submitting.
    const generatesKey = form.data.scheme === 'hmac' && form.data.secret === '';

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
                Log streaming
            </Link>

            <div className="mt-2">
                <PageHeader description="Where a copy of every audit entry is delivered as it is written." />
            </div>

            <form
                className="mt-6 space-y-6"
                style={{ maxWidth: '36rem' }}
                onSubmit={(event) => {
                    event.preventDefault();
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
                            ? "Every organization's entries in this environment, including tenants other than your own."
                            : "This organization's entries, and nothing from any other tenant."
                    }
                >
                    <div className="space-y-4">
                        <Field label="Name" error={form.errors.name}>
                            <Input
                                name="name"
                                placeholder="Splunk — production"
                                value={form.data.name}
                                onChange={(event) => form.setData('name', event.target.value)}
                            />
                        </Field>

                        <Field
                            label="Destination"
                            hint="The format your SIEM expects. Generic JSON works with anything that accepts a POST."
                            error={form.errors.destination}
                        >
                            <Select
                                name="destination"
                                value={form.data.destination}
                                onValueChange={(destination) =>
                                    form.setData('destination', destination)
                                }
                                options={destinations.map((destination) => ({
                                    value: destination.value,
                                    label: destination.label,
                                }))}
                            />
                        </Field>

                        <Field label="Endpoint URL" error={form.errors.endpointUrl}>
                            <Input
                                name="endpointUrl"
                                type="url"
                                className="mono"
                                placeholder="https://siem.example.com/services/collector"
                                value={form.data.endpointUrl}
                                onChange={(event) =>
                                    form.setData('endpointUrl', event.target.value)
                                }
                            />
                        </Field>
                    </div>
                </Panel>

                <Panel title="How it authenticates">
                    <div className="space-y-4">
                        <Field label="Auth scheme" error={form.errors.scheme}>
                            <Select
                                name="scheme"
                                value={form.data.scheme}
                                onValueChange={(scheme) => form.setData('scheme', scheme)}
                                options={schemes.map((scheme) => ({
                                    value: scheme.value,
                                    label: scheme.label,
                                }))}
                            />
                        </Field>

                        {form.data.scheme !== 'none' && (
                            <Field
                                label="Secret"
                                optional={form.data.scheme === 'hmac'}
                                hint={
                                    generatesKey
                                        ? 'Left empty, a signing key is generated for you and shown once — it is stored encrypted and cannot be retrieved again.'
                                        : 'The token your SIEM issued you. Stored encrypted and never shown again.'
                                }
                                error={form.errors.secret}
                            >
                                <Input
                                    name="secret"
                                    type="password"
                                    className="mono"
                                    autoComplete="off"
                                    value={form.data.secret}
                                    onChange={(event) => form.setData('secret', event.target.value)}
                                />
                            </Field>
                        )}
                    </div>
                </Panel>

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
