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
    const [deleting, setDeleting] = useState(false);

    const suspended = organization.status === 'suspended';

    return (
        <div className="space-y-6">
            <Details organization={organization} href={urls.update} />

            <Panel
                title={suspended ? 'Reactivate organization' : 'Suspend organization'}
                description={
                    suspended
                        ? 'Its people can sign in again, with the access they had.'
                        : 'Its people are refused at every door — sign-in, the device flow, the consent screen — until it is reactivated. Nothing is deleted.'
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
                    {suspended ? 'Reactivate' : 'Suspend'}
                </Button>
            </Panel>

            <Panel
                title="Delete organization"
                description="It disappears from every list and its people are refused everywhere, exactly as a suspension does. The records stay."
            >
                <Button size="sm" variant="danger" onClick={() => setDeleting(true)}>
                    Delete organization
                </Button>
            </Panel>

            <ConfirmDelete
                open={deleting}
                onOpenChange={setDeleting}
                name={organization.name}
                consequence="Everyone in this organization is refused at every door immediately, and it disappears from every list. This cannot be undone from the console."
                onConfirm={() => {
                    setDeleting(false);
                    router.delete(urls.destroy);
                }}
            />
        </div>
    );
}

function Details({ organization, href }: { organization: Props['organization']; href: string }) {
    const form = useForm({
        name: organization.name,
        slug: organization.slug,
        metadata: organization.metadata,
    });

    return (
        <Panel title="Details">
            <form
                className="space-y-4"
                onSubmit={(event) => {
                    event.preventDefault();
                    form.patch(href, { preserveScroll: true });
                }}
            >
                <div className="grid gap-4 sm:grid-cols-2">
                    <Field label="Name" error={form.errors.name}>
                        <Input
                            name="name"
                            value={form.data.name}
                            onChange={(event) => form.setData('name', event.target.value)}
                        />
                    </Field>

                    <Field
                        label="URL handle"
                        hint="Other systems may be using this — changing it changes the URLs they hold."
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
                    hint="Anything your own systems need to keep against this organization. Rows with no key are dropped."
                />

                <Button type="submit" variant="primary" loading={form.processing}>
                    Save changes
                </Button>
            </form>
        </Panel>
    );
}

OrganizationSettings.layout = (page: React.ReactNode) => <ConsoleLayout>{page}</ConsoleLayout>;
