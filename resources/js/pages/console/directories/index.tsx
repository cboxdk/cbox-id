import { Link, router, usePage } from '@inertiajs/react';
import { useEffect, useState } from 'react';
import ConsoleLayout from '@/layouts/ConsoleLayout';
import type { HelpContent, PageProps, Pagination as PaginationState } from '@/types';
import {
    Badge,
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

interface DirectoryRow {
    id: string;
    name: string;
    provider: string;
    pull: boolean;
    active: boolean;
    status: string;
    lastSyncError: string | null;
    owner: string;
    href: string;
}

type Props = PageProps<{
    help: HelpContent;
    directories: DirectoryRow[];
    pagination: PaginationState;
    search: string;
    mayAdminister: boolean;
    organizationChosen: boolean;
    entitled: boolean;
    showsEveryOrganization: boolean;
    scimBaseUrl: string;
    createHref: string;
    inviteHref: string;
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

export default function DirectoriesIndex({
    help,
    directories,
    pagination,
    search,
    mayAdminister,
    organizationChosen,
    entitled,
    showsEveryOrganization,
    scimBaseUrl,
    createHref,
    inviteHref,
}: Props) {
    // The setup link is a credential in a URL, so it rides the flash channel and never
    // the props — props are written into the browser's history entry.
    const portalUrl = usePage().flash.portalUrl;

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

    const canAct = mayAdminister && entitled;

    return (
        <>
            <PageHeader
                help={help}
                description="Let your identity provider create, update and deactivate people here on its own — and map its groups onto your roles."
                actions={
                    canAct ? (
                        <>
                            <Button
                                icon="members"
                                className="shrink-0"
                                onClick={() =>
                                    router.post(inviteHref, {}, { preserveScroll: true })
                                }
                            >
                                Invite your IT admin
                            </Button>
                            <Button asChild variant="primary" className="shrink-0">
                                <Link href={createHref}>
                                    <Icon name="plus" className="w-4 h-4" />
                                    New directory
                                </Link>
                            </Button>
                        </>
                    ) : undefined
                }
            />

            <div className="mt-8 space-y-6">
                {/*
                    Told apart deliberately: "you have not chosen an organization" and
                    "this organization is not entitled" are different problems with
                    different fixes, and the entitlement answers false for both.
                */}
                {!organizationChosen ? (
                    <div className="card">
                        <EmptyState
                            icon="layers"
                            title="Choose an organization"
                            description="Below is every directory in this environment. A directory provisions one tenant's users, so connecting one waits until you pick the organization you are configuring."
                        />
                    </div>
                ) : (
                    !entitled && (
                        <div className="card">
                            <EmptyState
                                icon="directory"
                                title="Syncing users in is an Enterprise feature"
                                help={help}
                                description="Contact your account team to enable it for this organization."
                            />
                        </div>
                    )
                )}

                {portalUrl !== undefined && mayAdminister && (
                    <Panel
                        className="p-5"
                        style={{
                            borderColor: 'color-mix(in oklch, var(--accent) 40%, transparent)',
                        }}
                    >
                        <div className="flex items-center gap-2 font-semibold">
                            <Icon name="members" className="w-4 h-4" />
                            Setup link for your IT admin
                        </div>
                        <p className="mt-1 text-sm" style={{ color: 'var(--muted-foreground)' }}>
                            Send this single-use link to whoever configures your identity provider.
                            It expires soon and works without an account. Copy it now — it is shown
                            only once.
                        </p>
                        <p
                            className="mt-3 mono text-xs rounded-lg px-3 py-2 select-all break-all"
                            style={{
                                background: 'var(--secondary)',
                                border: '1px solid var(--border)',
                            }}
                        >
                            {portalUrl}
                        </p>
                    </Panel>
                )}

                <div>
                    <Input
                        type="search"
                        style={{ maxWidth: '24rem' }}
                        placeholder="Search by name"
                        aria-label="Search directories"
                        value={term}
                        onChange={(event) => setTerm(event.target.value)}
                    />
                    {/*
                        SC 4.1.3: the list is replaced on a debounced keystroke with no focus
                        change, so the count is the only thing that can report the filter
                        narrowed to nothing.
                    */}
                    <output className="sr-only">
                        {pagination.total}{' '}
                        {pagination.total === 1 ? 'directory' : 'directories'} found.
                    </output>
                </div>

                <div
                    className="rounded-xl border overflow-hidden"
                    style={{ borderColor: 'var(--border)' }}
                >
                    {directories.length === 0 ? (
                        search !== '' ? (
                            <EmptyState
                                icon="search"
                                title={`No matches for "${search}"`}
                                description="No directory matches that name. Try a different search term."
                            />
                        ) : (
                            <EmptyState
                                icon="directory"
                                title="No directory connected yet"
                                help={help}
                                description="Today somebody adds and removes people here by hand. Connect the directory your company already keeps and it does both for you — including the removals, which are the ones that get forgotten."
                                steps={[
                                    'Choose SCIM if your provider pushes to us, or Google Workspace or Entra if we should pull.',
                                    'For SCIM: paste the endpoint and the token we mint into your provider.',
                                    'For a pull directory: paste its credentials and we verify them before storing anything.',
                                    'Map the groups you sync onto roles, so access follows the group.',
                                ]}
                                actions={
                                    canAct ? (
                                        <Button asChild variant="primary">
                                            <Link href={createHref}>
                                                <Icon name="plus" className="w-4 h-4" />
                                                New directory
                                            </Link>
                                        </Button>
                                    ) : undefined
                                }
                            />
                        )
                    ) : (
                        directories.map((directory, index) => (
                            <Link
                                key={directory.id}
                                href={directory.href}
                                className="flex items-center gap-3 p-4 transition-colors hover:bg-[var(--surface-2)]"
                                style={
                                    index < directories.length - 1
                                        ? { borderBottom: '1px solid var(--border)' }
                                        : undefined
                                }
                            >
                                <div className="min-w-0 flex-1">
                                    <div className="flex items-center gap-2 flex-wrap">
                                        <span className="font-medium truncate">
                                            {directory.name}
                                        </span>
                                        {showsEveryOrganization && <Badge>{directory.owner}</Badge>}
                                    </div>
                                    {directory.lastSyncError !== null && (
                                        <p
                                            className="text-xs truncate"
                                            style={{ color: 'var(--destructive)' }}
                                        >
                                            {directory.lastSyncError}
                                        </p>
                                    )}
                                </div>
                                <Badge>{directory.provider}</Badge>
                                <Pill tone={directory.active ? 'success' : 'warning'}>
                                    {directory.active ? 'Active' : directory.status}
                                </Pill>
                                <Icon
                                    name="chevron"
                                    className="w-4 h-4 shrink-0"
                                    style={{
                                        color: 'var(--faint)',
                                        transform: 'rotate(-90deg)',
                                    }}
                                />
                            </Link>
                        ))
                    )}
                </div>

                <Pagination
                    pagination={pagination}
                    noun="directory"
                    pluralNoun="directories"
                    href={(page) => listHref(search, page)}
                />

                {/*
                    The endpoint a provider posts to. On the list rather than on one
                    directory's page because it is the same for every SCIM directory in the
                    environment — it is the platform's address, not a property of any row.
                */}
                {organizationChosen && entitled && (
                    <Panel
                        title="SCIM endpoint"
                        description="Paste this into your identity provider, with the bearer token from the directory you connect."
                    >
                        <div className="flex items-center gap-2">
                            <code className="flex-1 min-w-0 truncate mono text-sm">
                                {scimBaseUrl}
                            </code>
                            <CopyButton value={scimBaseUrl} />
                        </div>
                    </Panel>
                )}
            </div>
        </>
    );
}

DirectoriesIndex.layout = (page: React.ReactNode) => <ConsoleLayout>{page}</ConsoleLayout>;
