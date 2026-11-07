import { useTranslator as useConsoleTranslator } from '@/i18n';
import { Link, useForm } from '@inertiajs/react';
import ConsoleLayout from '@/layouts/ConsoleLayout';
import type { PageProps } from '@/types';
import { Breadcrumb, Button, type MetadataRow, PageHeader } from '@/ui';
import { type OrganizationOption, ServiceProviderFields } from './fields';

type Props = PageProps<{
    formats: { value: string; label: string }[];
    organizations: OrganizationOption[];
    defaults: {
        nameIdFormat: string;
        nameIdAttribute: string;
        attributeMappings: MetadataRow[];
    };
    indexHref: string;
    storeHref: string;
}>;

export default function RegisterServiceProvider({
    formats,
    organizations,
    defaults,
    indexHref,
    storeHref,
}: Props) {
    const { t: consoleT } = useConsoleTranslator();
    const form = useForm({
        entityId: '',
        acsUrl: '',
        nameIdFormat: defaults.nameIdFormat,
        nameIdAttribute: defaults.nameIdAttribute,
        attributeMappings: defaults.attributeMappings,
        wantAuthnRequestsSigned: false,
        certificate: '',
        organizationId: '',
    });

    return (
        <>
            <Breadcrumb href={indexHref} label={consoleT('console.saml_apps_072d5a25')} />

            <div className="mt-2">
                <PageHeader
                    description={consoleT(
                        'console.register_an_application_that_uses_this_environment_as_i_8eee8cef',
                    )}
                />
            </div>

            <form
                className="mt-6 space-y-6"
                style={{ maxWidth: '40rem' }}
                onSubmit={(event) => {
                    event.preventDefault();
                    form.post(storeHref);
                }}
            >
                <ServiceProviderFields
                    form={form}
                    formats={formats}
                    organizations={organizations}
                    hasCertificate={false}
                />

                <div className="flex items-center gap-2">
                    <Button type="submit" variant="primary" loading={form.processing}>
                        {consoleT('console.register_application_8ff923b3')}
                    </Button>
                    <Button asChild>
                        <Link href={indexHref}>{consoleT('console.cancel_19766ed6')}</Link>
                    </Button>
                </div>
            </form>
        </>
    );
}

RegisterServiceProvider.layout = (page: React.ReactNode) => <ConsoleLayout>{page}</ConsoleLayout>;
