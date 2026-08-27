import { Link, router } from '@inertiajs/react';
import { useEffect, useState } from 'react';
import ConsoleLayout from '@/layouts/ConsoleLayout';
import type { PageProps, Pagination as PaginationState } from '@/types';
import {
    Button,
    CopyButton,
    EmptyState,
    Icon,
    Input,
    PageHeader,
    Pagination,
    Panel,
    Pill,
} from '@/ui';

interface ProviderRow {
    id: string;
    entityId: string;
    active: boolean;
    status: string;
    signedRequests: boolean;
    href: string;
}

type Props = PageProps<{
    providers: ProviderRow[];
    pagination: PaginationState;
    search: string;
    idp: { entityId: string; metadataUrl: string; ssoUrl: string };
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

export default function ServiceProviders({
    providers,
    pagination,
    search,
    idp,
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
                description="Applications that trust this environment as their SAML identity provider. To let people sign in with an account they already have elsewhere, use Sign-in → Single sign-on."
                actions={
                    <Button asChild variant="primary" className="shrink-0">
                        <Link href={createHref}>
                            <Icon name="plus" className="w-4 h-4" />
                            Add application
                        </Link>
                    </Button>
                }
            />

            <div className="mt-6">
                <Panel
                    title="Your identity provider"
                    description="Give these to the application being registered, so it can trust assertions from this environment."
                >
                    <div className="space-y-3">
                        <Coordinate label="IdP entity ID" value={idp.entityId} />
                        <Coordinate label="Metadata URL" value={idp.metadataUrl} />
                        <Coordinate label="Sign-on URL" value={idp.ssoUrl} />
                    </div>
                </Panel>
            </div>

            <div className="mt-6">
                <Input
                    type="search"
                    style={{ maxWidth: '24rem' }}
                    placeholder="Search by entity ID"
                    aria-label="Search SAML applications"
                    value={term}
                    onChange={(event) => setTerm(event.target.value)}
                />
                {/*
                    SC 4.1.3: the list is replaced on a debounced keystroke with no focus
                    change, so the count is the only thing that can report the filter
                    narrowed to nothing.
                */}
                <output className="sr-only">
                    {pagination.total} {pagination.total === 1 ? 'application' : 'applications'}{' '}
                    found.
                </output>
            </div>

            <div
                className="mt-4 rounded-xl border overflow-hidden"
                style={{ borderColor: 'var(--border)' }}
            >
                {providers.length === 0 ? (
                    search !== '' ? (
                        <EmptyState
                            icon="key"
                            title="No matches"
                            description={`No applications match “${search}”. Try a different entity ID.`}
                        />
                    ) : (
                        <EmptyState
                            icon="key"
                            title="No SAML applications yet"
                            description="Register one to let an application sign its users in with the accounts they already have in this environment."
                            actions={
                                <Button asChild variant="primary">
                                    <Link href={createHref}>
                                        <Icon name="plus" className="w-4 h-4" />
                                        Add application
                                    </Link>
                                </Button>
                            }
                        />
                    )
                ) : (
                    providers.map((provider, index) => (
                        <Link
                            key={provider.id}
                            href={provider.href}
                            className="flex items-center gap-3 p-4 transition-colors hover:bg-[var(--surface-2)]"
                            style={
                                index < providers.length - 1
                                    ? { borderBottom: '1px solid var(--border)' }
                                    : undefined
                            }
                        >
                            <div className="min-w-0 flex-1">
                                <span className="font-medium truncate mono">
                                    {provider.entityId}
                                </span>
                                <p
                                    className="text-xs truncate mono"
                                    style={{ color: 'var(--faint)' }}
                                >
                                    {provider.id}
                                </p>
                            </div>

                            {provider.signedRequests && <Pill tone="info">Signed requests</Pill>}

                            <Pill tone={provider.active ? 'success' : 'neutral'}>
                                {provider.active ? 'Active' : provider.status}
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
                noun="application"
                href={(page) => listHref(search, page)}
            />
        </>
    );
}

/** One value somebody is about to paste into another tab, with the button that copies it. */
function Coordinate({ label, value }: { label: string; value: string }) {
    return (
        <div>
            <p className="label">{label}</p>
            <div className="flex items-center gap-2">
                <p
                    className="mono text-xs rounded-lg px-3 py-2 select-all break-all min-w-0 flex-1"
                    style={{ background: 'var(--surface-2)', border: '1px solid var(--border)' }}
                >
                    {value}
                </p>
                <CopyButton value={value} label={`Copy ${label}`} />
            </div>
        </div>
    );
}

ServiceProviders.layout = (page: React.ReactNode) => <ConsoleLayout>{page}</ConsoleLayout>;
