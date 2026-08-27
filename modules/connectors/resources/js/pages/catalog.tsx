import ConsoleLayout from '@/layouts/ConsoleLayout';
import type { PageProps } from '@/types';
import { PageHeader, Pill } from '@/ui';

interface ConnectorType {
    key: string;
    name: string;
    category: string;
    description: string;
    direction: string;
    /** Some types are configured on their own page and have no count to give. */
    enumerable: boolean;
    active: number | null;
}

type Props = PageProps<{
    types: ConnectorType[];
}>;

export default function Catalog({ types }: Props) {
    return (
        <div className="space-y-6">
            <PageHeader description="The connector types this platform speaks. Each is backed by a platform module you enable and configure on its own page; review them together under Connections." />

            <div className="grid grid-cols-1 gap-4 sm:grid-cols-2">
                {types.map((type) => (
                    <div key={type.key} className="card p-5">
                        <div className="flex items-start justify-between gap-3">
                            <div className="min-w-0">
                                <p className="text-sm font-semibold">{type.name}</p>
                                <p
                                    className="mt-0.5 text-xs font-medium uppercase tracking-wide"
                                    style={{ color: 'var(--muted-foreground)' }}
                                >
                                    {type.category} · {type.direction}
                                </p>
                            </div>

                            {/*
                                "Managed in module" rather than "0 active": a type that is
                                configured on its own page has no count, and printing a zero
                                for it reads as "none configured" — which is a claim about
                                something this page cannot see.
                            */}
                            {type.enumerable ? (
                                <Pill tone="success" className="shrink-0 tabular-nums">
                                    {(type.active ?? 0).toLocaleString()} active
                                </Pill>
                            ) : (
                                <Pill className="shrink-0">Managed in module</Pill>
                            )}
                        </div>

                        <p className="mt-3 text-sm" style={{ color: 'var(--muted-foreground)' }}>
                            {type.description}
                        </p>
                    </div>
                ))}
            </div>

            <p className="text-xs" style={{ color: 'var(--faint)' }}>
                Directory sync is inbound SCIM where the platform is the server; its live
                directories are managed on the Directory pages and are not listed here.
            </p>
        </div>
    );
}

Catalog.layout = (page: React.ReactNode) => <ConsoleLayout>{page}</ConsoleLayout>;
