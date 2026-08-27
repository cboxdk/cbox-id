import { Link, useForm } from '@inertiajs/react';
import { useState } from 'react';
import ConsoleLayout from '@/layouts/ConsoleLayout';
import type { PageProps } from '@/types';
import {
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
    const form = useForm({
        name: '',
        slug: '',
        metadata: [] as MetadataRow[],
    });

    const [advanced, setAdvanced] = useState(false);

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
                Organizations
            </Link>

            <div className="mt-2">
                <PageHeader description="Its ID and URL handle are generated for you." />
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
                        <Field label="Name" error={form.errors.name}>
                            <Input
                                name="name"
                                placeholder="Acme Inc"
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
                                Advanced
                            </Button>

                            {advanced && (
                                <div
                                    className="mt-3 space-y-4 rounded-lg border p-4"
                                    style={{ borderColor: 'var(--border)' }}
                                >
                                    <Field
                                        label="URL handle"
                                        optional
                                        hint="Derived from the name if you leave it blank, and made unique either way."
                                        error={form.errors.slug}
                                    >
                                        <Input
                                            name="slug"
                                            className="mono"
                                            placeholder="acme-inc"
                                            value={form.data.slug}
                                            onChange={(event) =>
                                                form.setData('slug', event.target.value)
                                            }
                                        />
                                    </Field>

                                    <MetadataRows
                                        rows={form.data.metadata}
                                        onChange={(rows) => form.setData('metadata', rows)}
                                        hint="Anything your own systems need to keep against this tenant. Rows with no key are dropped."
                                    />
                                </div>
                            )}
                        </div>
                    </div>
                </Panel>

                <div className="flex items-center gap-2">
                    <Button type="submit" variant="primary" loading={form.processing}>
                        Create organization
                    </Button>
                    <Button asChild>
                        <Link href={indexHref}>Cancel</Link>
                    </Button>
                </div>
            </form>
        </>
    );
}

CreateOrganization.layout = (page: React.ReactNode) => <ConsoleLayout>{page}</ConsoleLayout>;
