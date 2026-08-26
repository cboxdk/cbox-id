import { Link, router } from '@inertiajs/react';
import { useEffect, useState } from 'react';
import ConsoleLayout from '@/layouts/ConsoleLayout';
import type { HelpContent, PageProps, Pagination as PaginationState } from '@/types';
import { Badge, Button, EmptyState, Icon, Input, PageHeader, Pagination } from '@/ui';

interface AppRow {
    id: string;
    name: string;
    clientId: string;
    firstParty: boolean;
    platformOwned: boolean;
    roleCount: number;
    kindLabel: string;
    owner: string | null;
    href: string;
}

type Props = PageProps<{
    help: HelpContent;
    clients: AppRow[];
    pagination: PaginationState;
    platformApps: AppRow[];
    search: string;
    /** No organization is chosen: every app in the environment is in view. */
    showsEveryOrganization: boolean;
    mayAdminister: boolean;
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

export default function Clients({
    help,
    clients,
    pagination,
    platformApps,
    search,
    showsEveryOrganization,
    mayAdminister,
    createHref,
}: Props) {
    const [term, setTerm] = useState(search);

    // Debounced, and back to page one: a filter applied while on page four otherwise asks
    // for a page of a list that no longer has one.
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
                help={help}
                description="Every app that signs people in through Cbox ID, or calls its API, is registered here."
                actions={
                    mayAdminister ? (
                        <Button asChild variant="primary" className="shrink-0">
                            <Link href={createHref}>
                                <Icon name="plus" className="w-4 h-4" />
                                New app
                            </Link>
                        </Button>
                    ) : undefined
                }
            />

            <div className="mt-6">
                <Input
                    type="search"
                    style={{ maxWidth: '24rem' }}
                    placeholder="Search by name or client ID"
                    aria-label="Search apps"
                    value={term}
                    onChange={(event) => setTerm(event.target.value)}
                />
                {/*
                    SC 4.1.3: the list is replaced on a debounced keystroke with no focus
                    change, so the count is the only thing that can report the filter
                    narrowed to nothing.
                */}
                <output className="sr-only">
                    {pagination.total} {pagination.total === 1 ? 'app' : 'apps'} found.
                </output>
            </div>

            <div
                className="mt-4 rounded-xl border overflow-hidden"
                style={{ borderColor: 'var(--border)' }}
            >
                {clients.length === 0 ? (
                    search !== '' ? (
                        <EmptyState
                            icon="clients"
                            title="No matching apps"
                            description={`No app matches "${search}". Try a different name or client ID.`}
                        />
                    ) : (
                        <EmptyState
                            icon="clients"
                            title="No apps of your own yet"
                            help={help}
                            description={
                                'An app registered here can sign your people in and read their roles, so you manage access in one place instead of per product.' +
                                (!showsEveryOrganization && platformApps.length > 0
                                    ? ' The platform apps below belong to this environment rather than to you.'
                                    : '')
                            }
                            steps={[
                                'Register the app — one per app, per environment.',
                                'Copy its client ID and secret into the app’s configuration; the secret is shown once.',
                                'Add the exact URL Cbox ID may send people back to after they sign in.',
                                'Have the app declare the roles it understands, so you can assign them here.',
                            ]}
                        />
                    )
                ) : (
                    clients.map((client, index) => (
                        <AppLink
                            key={client.id}
                            app={client}
                            last={index === clients.length - 1}
                            showOwner={showsEveryOrganization}
                        />
                    ))
                )}
            </div>

            <div className="mt-4">
                <Pagination
                    pagination={pagination}
                    noun="app"
                    href={(page) => listHref(search, page)}
                />
            </div>

            {/*
                Apps the ENVIRONMENT owns rather than the organization being administered:
                not registered here, but a first-party one is in this organization's
                launcher — so the page stays consistent with the dashboard rather than
                looking like it lost an app.
            */}
            {!showsEveryOrganization && platformApps.length > 0 && (
                <section className="mt-8">
                    <h2
                        className="text-xs font-medium uppercase mb-3"
                        style={{ color: 'var(--muted-foreground)', letterSpacing: '0.06em' }}
                    >
                        Platform apps{' '}
                        <span style={{ textTransform: 'none', fontWeight: 400 }}>
                            — owned by this environment, not by this organization. First-party ones
                            appear in every organization's launcher.
                        </span>
                    </h2>
                    <div
                        className="rounded-xl border overflow-hidden"
                        style={{ borderColor: 'var(--border)' }}
                    >
                        {platformApps.map((client, index) => (
                            <AppLink
                                key={client.id}
                                app={client}
                                last={index === platformApps.length - 1}
                                showOwner={false}
                                platformOwned
                            />
                        ))}
                    </div>
                </section>
            )}
        </>
    );
}

function AppLink({
    app,
    last,
    showOwner,
    platformOwned = false,
}: {
    app: AppRow;
    last: boolean;
    showOwner: boolean;
    platformOwned?: boolean;
}) {
    return (
        <Link
            href={app.href}
            className="flex items-center gap-3 p-4 transition-colors hover:bg-[var(--surface-2)]"
            style={last ? undefined : { borderBottom: '1px solid var(--border)' }}
        >
            <div className="min-w-0 flex-1">
                <span className="font-medium truncate">{app.name}</span>
                <p className="text-sm truncate mono" style={{ color: 'var(--muted-foreground)' }}>
                    {app.clientId}
                </p>
            </div>

            {platformOwned && <Badge>Platform-owned</Badge>}
            {showOwner && app.owner !== null && <Badge>{app.owner}</Badge>}
            {app.roleCount > 0 && (
                <Badge>
                    {app.roleCount} {app.roleCount === 1 ? 'role' : 'roles'}
                </Badge>
            )}
            {app.firstParty && !platformOwned && (
                <span
                    className="text-xs rounded-full px-2 py-0.5"
                    style={{ background: 'var(--accent-soft)', color: 'var(--accent-strong)' }}
                >
                    First-party
                </span>
            )}
            <Badge>{app.kindLabel}</Badge>

            <Icon
                name="chevron"
                className="w-4 h-4 shrink-0"
                style={{ color: 'var(--faint)', transform: 'rotate(-90deg)' }}
            />
        </Link>
    );
}

Clients.layout = (page: React.ReactNode) => <ConsoleLayout>{page}</ConsoleLayout>;
