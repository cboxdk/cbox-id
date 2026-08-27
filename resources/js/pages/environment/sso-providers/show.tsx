import { Link, router, useForm } from '@inertiajs/react';
import { useState } from 'react';
import ConsoleLayout from '@/layouts/ConsoleLayout';
import type { PageProps } from '@/types';
import { Button, ConfirmDelete, Icon, type MetadataRow, Panel, Pill } from '@/ui';
import { ServiceProviderFields } from './fields';

type Props = PageProps<{
    provider: {
        id: string;
        entityId: string;
        acsUrl: string;
        nameIdFormat: string;
        nameIdAttribute: string;
        attributeMappings: MetadataRow[];
        wantAuthnRequestsSigned: boolean;
        /** Whether, never what — the certificate is write-only. */
        hasCertificate: boolean;
        active: boolean;
        status: string;
    };
    formats: { value: string; label: string }[];
    indexHref: string;
    urls: { update: string; destroy: string };
}>;

export default function ServiceProviderDetail({ provider, formats, indexHref, urls }: Props) {
    const [deleting, setDeleting] = useState(false);

    const form = useForm({
        entityId: provider.entityId,
        acsUrl: provider.acsUrl,
        nameIdFormat: provider.nameIdFormat,
        nameIdAttribute: provider.nameIdAttribute,
        attributeMappings: provider.attributeMappings,
        wantAuthnRequestsSigned: provider.wantAuthnRequestsSigned,
        // Always blank: the stored certificate is never echoed back, and a blank field
        // means "keep the one on file".
        certificate: '',
    });

    return (
        <div className="space-y-6">
            <div>
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
                    SAML applications
                </Link>
                <div className="mt-2 flex items-center gap-3 flex-wrap">
                    <h1 className="cbx-page-title mono">{provider.entityId}</h1>
                    {provider.wantAuthnRequestsSigned && <Pill tone="info">Signed requests</Pill>}
                    <Pill tone={provider.active ? 'success' : 'neutral'}>
                        {provider.active ? 'Active' : provider.status}
                    </Pill>
                </div>
                <p className="mt-1 text-sm mono" style={{ color: 'var(--faint)' }}>
                    {provider.id}
                </p>
            </div>

            <form
                className="space-y-6"
                onSubmit={(event) => {
                    event.preventDefault();
                    form.patch(urls.update, {
                        preserveScroll: true,
                        // The replacement has been persisted; never keep it in page state.
                        onSuccess: () => form.setData('certificate', ''),
                    });
                }}
            >
                <ServiceProviderFields
                    form={form}
                    formats={formats}
                    hasCertificate={provider.hasCertificate}
                />

                <Button type="submit" variant="primary" loading={form.processing}>
                    Save changes
                </Button>
            </form>

            <Panel
                title="Remove"
                description="Deleting this application stops it from signing users in through this environment."
            >
                <Button size="sm" variant="danger" onClick={() => setDeleting(true)}>
                    Delete application
                </Button>
            </Panel>

            <ConfirmDelete
                open={deleting}
                onOpenChange={setDeleting}
                name={provider.entityId}
                consequence="The application can no longer sign users in through this environment. This cannot be undone."
                onConfirm={() => {
                    setDeleting(false);
                    router.delete(urls.destroy);
                }}
            />
        </div>
    );
}

ServiceProviderDetail.layout = (page: React.ReactNode) => <ConsoleLayout>{page}</ConsoleLayout>;
