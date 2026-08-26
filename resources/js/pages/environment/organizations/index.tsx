import { Link, router } from '@inertiajs/react';
import { useEffect, useState } from 'react';
import ConsoleLayout from '@/layouts/ConsoleLayout';
import type { PageProps, Pagination as PaginationState } from '@/types';
import { Button, EmptyState, Icon, Input, PageHeader, Pagination, Pill } from '@/ui';

interface OrganizationRow {
    id: string;
    name: string;
    slug: string;
    status: string;
    href: string;
}

type Props = PageProps<{
    organizations: OrganizationRow[];
    pagination: PaginationState;
    search: string;
    createHref: string;
}>;

function listHref(search: string, page?: number): string {
    const query = new URLSearchParams();

    if (search !== '') {
        query.set('q', search);
    }

    if (page !== undefined && page > 1) {
        query.set('page', String(page));
    }

    const rest = query.toString();

    return rest === '' ? window.location.pathname : `${window.location.pathname}?${rest}`;
}

/** Suspended is reversible; deleted is not shown here at all. */
function statusTone(status: string): 'success' | 'warning' | 'destructive' {
    if (status === 'suspended') {
        return 'warning';
    }

    return status === 'deleted' ? 'destructive' : 'success';
}

export default function OrganizationsIndex({
    organizations,
    pagination,
    search,
    createHref,
}: Props) {
    const [term, setTerm] = useState(search);

    useEffect(() => {
        if (term === search) {
            return;
        }

        const timer = setTimeout(() => {
            router.get(
                listHref(term),
                {},
                { preserveState: true, preserveScroll: true, replace: true },
            );
        }, 300);

        return () => clearTimeout(timer);
    }, [term, search]);

    return (
        <>
            <PageHeader
                description="Your customers. Each is a company or team using your product, with its own users, roles and SSO."
                actions={
                    <Button asChild variant="primary" className="shrink-0">
                        <Link href={createHref}>
                            <Icon name="plus" className="w-4 h-4" />
                            New organization
                        </Link>
                    </Button>
                }
            />

            {/*
                SAID HERE, BECAUSE HERE IS WHERE THE CONFUSION HAPPENS. The word
                "organization" does two jobs in this platform — up in the platform root it
                names a CUSTOMER OF CBOX ID, and here it names one of that customer's own
                end-user teams — and the two look identical on screen because underneath
                they are the same kind of row. The distinction is easy to state and
                impossible to infer.
            */}
            <div
                className="card p-4 mt-6"
                style={{ background: 'var(--accent-soft)', borderColor: 'var(--accent-edge)' }}
            >
                <div className="flex items-start gap-3">
                    <span
                        className="grid place-items-center rounded-lg shrink-0"
                        style={{
                            width: '2rem',
                            height: '2rem',
                            background: 'var(--card)',
                            color: 'var(--primary)',
                        }}
                    >
                        <Icon name="layers" className="w-4 h-4" />
                    </span>
                    <p className="text-sm">
                        <b>These are your customers, not your Cbox ID account.</b> An organization
                        here is a company or team that uses <em>your</em> product, living inside
                        this environment. Your own account — the one that owns this project and its
                        bill — is a different thing one level up, and it is where "Account settings"
                        lives.
                    </p>
                </div>
            </div>

            <div className="mt-6">
                <Input
                    type="search"
                    style={{ maxWidth: '24rem' }}
                    placeholder="Search by name or handle"
                    aria-label="Search organizations"
                    value={term}
                    onChange={(event) => setTerm(event.target.value)}
                />
                {/*
                    SC 4.1.3: the list is replaced on a debounced keystroke with no focus
                    change, so the count is the only thing that can report the filter
                    narrowed to nothing.
                */}
                <output className="sr-only">
                    {pagination.total} {pagination.total === 1 ? 'organization' : 'organizations'}{' '}
                    found.
                </output>
            </div>

            <div
                className="mt-4 rounded-xl border overflow-hidden"
                style={{ borderColor: 'var(--border)' }}
            >
                {organizations.length === 0 ? (
                    search !== '' ? (
                        <EmptyState
                            icon="search"
                            title="No matches"
                            description={`No organizations match “${search}”. Try a different name or handle.`}
                        />
                    ) : (
                        <EmptyState
                            icon="layers"
                            title="No organizations yet"
                            description="Organizations are the tenants inside this environment — each one a company or team using your product. Create the first one to get started."
                            actions={
                                <Button asChild variant="primary">
                                    <Link href={createHref}>
                                        <Icon name="plus" className="w-4 h-4" />
                                        New organization
                                    </Link>
                                </Button>
                            }
                        />
                    )
                ) : (
                    organizations.map((organization, index) => (
                        <Link
                            key={organization.id}
                            href={organization.href}
                            className="flex items-center gap-3 p-4 transition-colors hover:bg-[var(--surface-2)]"
                            style={
                                index < organizations.length - 1
                                    ? { borderBottom: '1px solid var(--border)' }
                                    : undefined
                            }
                        >
                            <div className="min-w-0 flex-1">
                                <span className="font-medium truncate">{organization.name}</span>
                                <p
                                    className="text-xs truncate mono"
                                    style={{ color: 'var(--faint)' }}
                                >
                                    {organization.slug}
                                </p>
                            </div>

                            <Pill tone={statusTone(organization.status)}>
                                {organization.status}
                            </Pill>

                            <Icon
                                name="chevron"
                                className="w-4 h-4 shrink-0"
                                style={{ color: 'var(--faint)', transform: 'rotate(-90deg)' }}
                            />
                        </Link>
                    ))
                )}
            </div>

            <Pagination
                pagination={pagination}
                noun="organization"
                href={(page) => listHref(search, page)}
            />
        </>
    );
}

OrganizationsIndex.layout = (page: React.ReactNode) => <ConsoleLayout>{page}</ConsoleLayout>;
