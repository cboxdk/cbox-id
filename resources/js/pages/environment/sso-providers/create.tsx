import { Link, useForm } from '@inertiajs/react';
import ConsoleLayout from '@/layouts/ConsoleLayout';
import type { PageProps } from '@/types';
import { Button, Icon, PageHeader, type MetadataRow } from '@/ui';
import { ServiceProviderFields } from './fields';

type Props = PageProps<{
    formats: { value: string; label: string }[];
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
                SAML applications
            </Link>

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
                <ServiceProviderFields form={form} formats={formats} hasCertificate={false} />

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
