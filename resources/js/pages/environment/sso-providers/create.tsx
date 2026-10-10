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
            <Breadcrumb href={indexHref} label="SAML apps" />

            <div className="mt-2">
                <PageHeader description="Register an application that uses this environment as its SAML identity provider." />
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
                        Register application
                    </Button>
                    <Button asChild>
                        <Link href={indexHref}>Cancel</Link>
                    </Button>
                </div>
            </form>
        </>
    );
}

RegisterServiceProvider.layout = (page: React.ReactNode) => <ConsoleLayout>{page}</ConsoleLayout>;
