import { Link, router } from '@inertiajs/react';
import { useEffect, useState } from 'react';
import ConsoleLayout from '@/layouts/ConsoleLayout';
import type { PageProps, Pagination as PaginationState } from '@/types';
import { Badge, Button, EmptyState, Icon, Input, PageHeader, Pagination, Pill } from '@/ui';

interface ConnectionRow {
    id: string;
    name: string;
    baseUrl: string;
    /** The organization it provisions, or null when it covers the whole environment. */
    scope: string | null;
    active: boolean;
    lastError: string | null;
    href: string;
}

type Props = PageProps<{
    connections: ConnectionRow[];
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

export default function OutboundSyncIndex({
    connections,
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
                description="Push your people into the apps that need their own copy — a SCIM endpoint receives every join, change and departure as it happens."
                actions={
                    <Button asChild variant="primary" className="shrink-0">
                        <Link href={createHref}>
                            <Icon name="plus" className="w-4 h-4" />
                            New connection
                        </Link>
                    </Button>
                }
            />

            <div className="mt-6">
                <Input
                    type="search"
                    style={{ maxWidth: '24rem' }}
                    placeholder="Search by name"
                    aria-label="Search outbound connections"
                    value={term}
                    onChange={(event) => setTerm(event.target.value)}
                />
                {/*
                    SC 4.1.3: the list is replaced on a debounced keystroke with no focus
                    change, so the count is the only thing that can report the filter
                    narrowed to nothing.
                */}
                <output className="sr-only">
                    {pagination.total} {pagination.total === 1 ? 'connection' : 'connections'} found.
                </output>
            </div>

            <div
                className="mt-4 rounded-xl border overflow-hidden"
                style={{ borderColor: 'var(--border)' }}
            >
                {connections.length === 0 ? (
                    search !== '' ? (
                        <EmptyState
                            icon="search"
                            title={`No connections match “${search}”`}
                            description="No outbound connection matches that name. Try a different search."
                        />
                    ) : (
                        <EmptyState
                            icon="directory"
                            title="No outbound sync yet"
                            description="Apps that keep their own user list drift out of step the moment somebody joins or leaves. A SCIM connection pushes every change to them as it happens — including the departures, which are the ones that get forgotten."
                            steps={[
                                'Paste the app’s SCIM base URL and the credential it issued you.',
                                'Choose how it authenticates — a bearer token, or OAuth client credentials we fetch per batch.',
                                'Save it: joins, changes and departures start flowing from the next one.',
                            ]}
                            actions={
                                <Button asChild variant="primary">
                                    <Link href={createHref}>
                                        <Icon name="plus" className="w-4 h-4" />
                                        New connection
                                    </Link>
                                </Button>
                            }
                        />
                    )
                ) : (
                    connections.map((connection, index) => (
                        <Link
                            key={connection.id}
                            href={connection.href}
                            className="flex items-center gap-3 p-4 transition-colors hover:bg-[var(--surface-2)]"
                            style={
                                index < connections.length - 1
                                    ? { borderBottom: '1px solid var(--border)' }
                                    : undefined
                            }
                        >
                            <div className="min-w-0 flex-1">
                                <div className="flex items-center gap-2 flex-wrap">
                                    <span className="font-medium truncate">{connection.name}</span>
                                    <Badge>{connection.scope ?? 'Environment-wide'}</Badge>
                                </div>
                                {connection.lastError !== null ? (
                                    // A push that has started failing means people are
                                    // drifting out of step downstream — the one thing worth
                                    // seeing without opening the row.
                                    <p
                                        className="text-xs truncate"
                                        style={{ color: 'var(--destructive)' }}
                                    >
                                        {connection.lastError}
                                    </p>
                                ) : (
                                    <p
                                        className="text-xs truncate mono"
                                        style={{ color: 'var(--faint)' }}
                                    >
                                        {connection.baseUrl}
                                    </p>
                                )}
                            </div>

                            <Pill tone={connection.active ? 'success' : 'warning'}>
                                {connection.active ? 'Active' : 'Paused'}
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
                noun="connection"
                href={(page) => listHref(search, page)}
            />
        </>
    );
}

OutboundSyncIndex.layout = (page: React.ReactNode) => <ConsoleLayout>{page}</ConsoleLayout>;
