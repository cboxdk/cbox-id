import { EnvironmentReadOnly } from '@/ui/EnvironmentReadOnly';
import { useTranslator as useConsoleTranslator } from '@/i18n';
import { router, useForm } from '@inertiajs/react';
import { useState } from 'react';
import ConsoleLayout from '@/layouts/ConsoleLayout';
import type { PageProps } from '@/types';
import { Breadcrumb, Button, ConfirmDelete, type MetadataRow, Panel, Pill } from '@/ui';
import { type OrganizationOption, ServiceProviderFields } from './fields';

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
        /** The owning organization's id, or '' when it is environment-wide. */
        organizationId: string;
    };
    formats: { value: string; label: string }[];
    organizations: OrganizationOption[];
    indexHref: string;
    urls: { update: string; destroy: string };
}>;

export default function ServiceProviderDetail({
    provider,
    formats,
    organizations,
    indexHref,
    urls,
}: Props) {
    const { t: consoleUiText } = useConsoleTranslator();
    const { t: consoleText } = useConsoleTranslator();
    const { t: consoleT } = useConsoleTranslator();
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
        organizationId: provider.organizationId,
    });

    return (
        <EnvironmentReadOnly>
            <div className="space-y-6">
                <div>
                    <Breadcrumb href={indexHref} label={consoleT('console.saml_apps_072d5a25')} />
                    <div className="mt-2 flex items-center gap-3 flex-wrap">
                        <h1 className="cbx-page-title mono">{provider.entityId}</h1>
                        {provider.wantAuthnRequestsSigned && (
                            <Pill tone="info">{consoleT('console.signed_requests_b6cc80d3')}</Pill>
                        )}
                        <Pill tone={provider.active ? 'success' : 'neutral'}>
                            {provider.active
                                ? consoleText('console.active_92340695')
                                : provider.status}
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
                        organizations={organizations}
                        hasCertificate={provider.hasCertificate}
                    />

                    <Button type="submit" variant="primary" loading={form.processing}>
                        {consoleT('console.save_changes_dd0ae7a5')}
                    </Button>
                </form>

                <Panel
                    title={consoleT('console.remove_c3812fc4')}
                    description={consoleT(
                        'console.deleting_this_application_stops_it_from_signing_users_i_5d28bf4b',
                    )}
                >
                    <Button size="sm" variant="danger" onClick={() => setDeleting(true)}>
                        {consoleT('console.delete_application_0e0e391e')}
                    </Button>
                </Panel>

                <ConfirmDelete
                    open={deleting}
                    onOpenChange={setDeleting}
                    name={provider.entityId}
                    consequence={consoleUiText(
                        'console.the_application_can_no_longer_sign_users_in_through_thi_72052d67',
                    )}
                    onConfirm={() => {
                        setDeleting(false);
                        router.delete(urls.destroy);
                    }}
                />
            </div>
        </EnvironmentReadOnly>
    );
}

ServiceProviderDetail.layout = (page: React.ReactNode) => <ConsoleLayout>{page}</ConsoleLayout>;
